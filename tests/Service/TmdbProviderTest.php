<?php

namespace App\Tests\Service;

use App\Service\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TmdbProviderTest extends TestCase
{
    /** A /tv/{id} response: a special, two regular seasons (the second with no name, poster or overview) and an unaired one. */
    private function show(): array
    {
        return [
            'id' => 1396, 'name' => 'Breaking Bad', 'overview' => 'A teacher turns to crime.', 'poster_path' => '/show.jpg',
            'seasons' => [
                ['season_number' => 0, 'name' => 'Specials', 'episode_count' => 3, 'poster_path' => '/sp.jpg'],
                ['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 7, 'air_date' => '2008-01-20', 'overview' => 'The pilot season.', 'poster_path' => '/s1.jpg'],
                ['season_number' => 2, 'name' => '', 'episode_count' => 13, 'air_date' => '2009-03-08', 'overview' => '', 'poster_path' => null],
                ['season_number' => 3, 'name' => 'Not aired', 'episode_count' => 0],
            ],
        ];
    }

    public function testSearchAsksForOneKindAndNormalizesIt(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$url, $options['headers']];

            // like the real type-specific endpoints: results carry no media_type
            return new MockResponse(json_encode(['results' => [
                ['id' => 11, 'title' => 'Star Wars', 'release_date' => '1977-05-25', 'overview' => 'Far away.', 'poster_path' => '/sw.jpg'],
                ['id' => 12, 'title' => 'No details', 'release_date' => '', 'overview' => ''],
            ]]));
        });

        $found = (new TmdbProvider($client, 'secret'))->search('star wars', 'movie');

        $this->assertCount(2, $found);
        $this->assertSame('movie:11', $found[0]['externalId']);
        $this->assertSame(['type' => 'movie', 'year' => '1977', 'overview' => 'Far away.', 'poster' => 'https://image.tmdb.org/t/p/w342/sw.jpg'], $found[0]['attributes']);
        $this->assertSame(['type' => 'movie', 'year' => null, 'overview' => null, 'poster' => null], $found[1]['attributes']);
        $this->assertStringContainsString('/search/movie?', $requests[0][0]);
        $this->assertStringContainsString('query=star%20wars', $requests[0][0]);
        $this->assertContains('Authorization: Bearer secret', $requests[0][1]);
    }

    public function testTvSearchReturnsShowsToOpenNotSeasonsToAdd(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $u) use (&$url) {
            $url = $u;

            return new MockResponse(json_encode(['results' => [['id' => 11, 'name' => 'Some Show', 'first_air_date' => '2008-01-20']]]));
        });

        $found = (new TmdbProvider($client, 'secret'))->search('show', 'tv');

        $this->assertStringContainsString('/search/tv?', $url);
        $this->assertSame(['tv:11', 'Some Show', ['type' => 'tv', 'year' => '2008', 'overview' => null, 'poster' => null]], [$found[0]['externalId'], $found[0]['name'], $found[0]['attributes']]);
    }

    public function testSearchRejectsAnUnknownType(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('no request expected'));

        $this->expectException(\InvalidArgumentException::class);
        (new TmdbProvider($client, 'secret'))->search('x', 'person');
    }

    public function testSeasonsOfTurnsEachRegularSeasonIntoItsOwnItem(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $u) use (&$url) {
            $url = $u;

            return new MockResponse(json_encode($this->show()));
        });

        $seasons = (new TmdbProvider($client, 'secret'))->seasonsOf(1396);

        $this->assertSame('https://api.themoviedb.org/3/tv/1396', $url);
        $this->assertSame(['tv:1396:s1', 'tv:1396:s2'], array_column($seasons, 'externalId')); // no specials, no unaired season
        $this->assertSame(['Breaking Bad — Season 1', 'Breaking Bad — Season 2'], array_column($seasons, 'name')); // empty season name -> "Season 2"

        $this->assertSame([
            'type' => 'tv', 'year' => '2008', 'overview' => 'The pilot season.', 'poster' => 'https://image.tmdb.org/t/p/w342/s1.jpg',
            'episodes' => 7, 'season_number' => 1, 'season_list' => [['number' => 1, 'name' => 'Season 1', 'episodes' => 7]],
        ], $seasons[0]['attributes']);

        // season 2 has no poster or overview of its own: the show's are used
        $this->assertSame(['https://image.tmdb.org/t/p/w342/show.jpg', 'A teacher turns to crime.'], [$seasons[1]['attributes']['poster'], $seasons[1]['attributes']['overview']]);
        $this->assertSame([['number' => 2, 'name' => 'Season 2', 'episodes' => 13]], $seasons[1]['attributes']['season_list']);
    }

    public function testFetchManySkipsMalformedIdsWithoutCallingTheApi(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('no request expected'));

        $this->assertSame([], (new TmdbProvider($client, 'secret'))->fetchMany(['11', 'movie:', 'movie:1/../2', 'person:1', 'tv:', 'tv:abc', 'tv:1396:s', 'tv:1396:sx', 'tv::s1', 'tv:1396:s1:x', 'tv:1396/../2', '']));
    }

    public function testTheFullShowExpandsIntoOneItemPerRegularSeason(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $u) use (&$urls) {
            $urls[] = $u;

            return new MockResponse(json_encode($this->show()));
        });

        $items = (new TmdbProvider($client, 'secret'))->fetchMany(['tv:1396']);

        $this->assertSame(['https://api.themoviedb.org/3/tv/1396'], $urls); // one request for the whole show
        $this->assertSame(['tv:1396:s1', 'tv:1396:s2'], array_column($items, 'externalId')); // no specials, no unaired season, and no combined "tv:1396" item
        $this->assertSame(['Breaking Bad — Season 1', 'Breaking Bad — Season 2'], array_column($items, 'name'));
        $this->assertSame([7, 13], array_column(array_column($items, 'attributes'), 'episodes'));
        $this->assertSame([[['number' => 1, 'name' => 'Season 1', 'episodes' => 7]], [['number' => 2, 'name' => 'Season 2', 'episodes' => 13]]], array_column(array_column($items, 'attributes'), 'season_list'));
    }

    public function testAShowAndItsSeasonsAreNotAddedTwiceNorMixedWithOtherShows(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $u) use (&$urls) {
            $urls[] = $u;

            return new MockResponse(json_encode(str_ends_with($u, '/tv/77') ? ['id' => 77, 'name' => 'Other Show'] + $this->show() : $this->show()));
        });

        // season 1 on its own, then the whole show (which includes seasons 1 and 2), then season 2 again, then another show's season 1
        $items = (new TmdbProvider($client, 'secret'))->fetchMany(['tv:1396:s1', 'tv:1396', 'tv:1396:s2', 'tv:77:s1']);

        $this->assertSame(['tv:1396:s1', 'tv:1396:s2', 'tv:77:s1'], array_column($items, 'externalId')); // each exactly once, in the order first asked for
        $this->assertSame(['https://api.themoviedb.org/3/tv/1396', 'https://api.themoviedb.org/3/tv/77'], $urls);
    }

    public function testFetchManyLoadsMoviesAndSeasonsInTheOrderAskedAndAShowOnce(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $u) use (&$urls) {
            $urls[] = $u;

            return new MockResponse(json_encode(str_contains($u, '/tv/') ? $this->show() : ['id' => 603, 'title' => 'The Matrix', 'release_date' => '1999-03-31']));
        });

        $data = (new TmdbProvider($client, 'secret'))->fetchMany(['tv:1396:s2', 'movie:603', 'tv:1396:s1', 'tv:1396:s2', 'bad']);

        $this->assertSame(['https://api.themoviedb.org/3/tv/1396', 'https://api.themoviedb.org/3/movie/603'], $urls); // the show is requested once for both seasons
        $this->assertSame(['tv:1396:s2', 'movie:603', 'tv:1396:s1'], array_column($data, 'externalId'));
        $this->assertSame('Breaking Bad — Season 2', $data[0]['name']);
    }

    public function testFetchManySkipsASeasonTmdbDoesNotList(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse(json_encode($this->show())));

        $data = (new TmdbProvider($client, 'secret'))->fetchMany(['tv:1396:s1', 'tv:1396:s9', 'tv:1396:s0', 'tv:1396:s3']); // s9 doesn't exist, s0 = specials, s3 has no episodes

        $this->assertSame(['tv:1396:s1'], array_column($data, 'externalId'));
    }

    public function testFetchManyWithATypeSkipsTheOtherKind(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $u) use (&$urls) {
            $urls[] = $u;

            return new MockResponse(json_encode(['id' => 603, 'title' => 'The Matrix']));
        });
        $tmdb = new TmdbProvider($client, 'secret');

        $this->assertSame(['movie:603'], array_column($tmdb->fetchMany(['tv:1396:s1', 'tv:1396', 'movie:603', 'tv:5:s2'], 'movie'), 'externalId'));
        $this->assertSame(['https://api.themoviedb.org/3/movie/603'], $urls);   // the TV ids never reach TMDB

        $none = new TmdbProvider(new MockHttpClient(fn () => $this->fail('no request expected')), 'secret');
        $this->assertSame([], $none->fetchMany(['movie:1', 'movie:2'], 'tv'));   // and movies never go into a TV collection
    }
}
