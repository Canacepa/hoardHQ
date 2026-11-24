<?php

namespace App\Tests\Service;

use App\Service\GoogleBooksProvider;
use App\Service\HardcoverProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleBooksProviderTest extends TestCase
{
    private array $urls = [];
    private ?HardcoverProvider $hardcover = null;

    private function volume(string $id = 'abc123'): array
    {
        return ['id' => $id, 'volumeInfo' => [
            'language' => 'fr', 'title' => 'One Piece, Vol. 1', 'subtitle' => 'Romance Dawn', 'authors' => ['Eiichiro Oda', 'B', 'C', 'D'], 'publisher' => 'Glénat',
            'publishedDate' => '2000-06', 'description' => '<p>Luffy &amp; crew.</p><br>Go!',
            'industryIdentifiers' => [['type' => 'ISBN_10', 'identifier' => '2723499999'], ['type' => 'ISBN_13', 'identifier' => '9782723499996']],
            'imageLinks' => ['thumbnail' => 'http://books.google.com/c?id=abc123&zoom=1&edge=curl'],
        ]];
    }

    private function provider(callable $answer, string $key = ''): GoogleBooksProvider
    {
        return new GoogleBooksProvider(new MockHttpClient(function (string $method, string $url) use ($answer) {
            $this->urls[] = $url;

            return $answer($url);
        }), new ArrayAdapter(), $this->hardcover ?? new HardcoverProvider(new MockHttpClient(fn () => $this->fail('no Hardcover request expected')), new ArrayAdapter(), ''), $key);
    }

    public function testSearchNormalizesVolumes(): void
    {
        $found = $this->provider(fn () => new MockResponse(json_encode(['items' => [$this->volume(), ['id' => 'bare', 'volumeInfo' => ['title' => 'Bare']], ['volumeInfo' => ['title' => 'No id']]]])))->search('one piece');

        $this->assertCount(2, $found); // the item with no id is dropped
        $this->assertSame('book:abc123', $found[0]['externalId']);
        $this->assertSame('One Piece, Vol. 1: Romance Dawn', $found[0]['name']);
        $this->assertSame(['type' => 'book', 'year' => '2000', 'overview' => "Luffy & crew.\nGo!", 'poster' => 'https://books.google.com/c?id=abc123&zoom=1', 'authors' => 'Eiichiro Oda, B, C', 'publisher' => 'Glénat', 'isbn' => '9782723499996', 'language' => 'fr', 'series' => 'One Piece', 'volume' => 1], $found[0]['attributes']);
        $this->assertSame(['type' => 'book', 'year' => null, 'overview' => null, 'poster' => null, 'authors' => null, 'publisher' => null, 'isbn' => null, 'language' => null, 'series' => null, 'volume' => null], $found[1]['attributes']);
        $this->assertStringContainsString('q=one%20piece', $this->urls[0]);
        $this->assertStringNotContainsString('key=', $this->urls[0]);
    }

    public function testAnIsbnIsLookedUpAsOneAndTheKeyIsSentWhenSet(): void
    {
        $this->provider(fn () => new MockResponse('{}'), 'k3y')->search('978-2-7234-9999-6');
        $this->provider(fn () => new MockResponse('{}'))->search('12345');

        $this->assertStringContainsString('q=isbn:9782723499996', $this->urls[0]);
        $this->assertStringContainsString('key=k3y', $this->urls[0]);
        $this->assertStringContainsString('q=12345', $this->urls[1]); // too short for an ISBN: plain text
    }

    public function testFetchManyKeepsTheOrderAskedLoadsOnceSkipsMissingAndIgnoresOtherIds(): void
    {
        $found = $this->provider(fn (string $url) => str_ends_with($url, '/gone') ? new MockResponse('{}', ['http_code' => 404]) : new MockResponse(json_encode($this->volume(basename($url)))))
            ->fetchMany(['book:b', 'movie:603', 'book:a', 'book:b', 'book:gone', 'book:', 'book:a/../x']);

        $this->assertSame(['book:b', 'book:a'], array_column($found, 'externalId'));
        $this->assertCount(3, $this->urls); // b, a, gone: once each
    }

    public function testFetchManyWithNothingValidMakesNoRequest(): void
    {
        $this->assertSame([], $this->provider(fn () => $this->fail('no request expected'))->fetchMany(['tv:1', 'book:', '']));
    }

    /** A volume of "One Piece - Tome NN" (zero-padded like the real titles). */
    private function tome(int $n, ?string $title = null): array
    {
        return ['id' => "v$n", 'volumeInfo' => ['language' => 'fr', 'title' => $title ?? sprintf('One Piece - Tome %02d', $n), 'imageLinks' => ['thumbnail' => "http://x/$n.jpg"]]];
    }

    /** Google as it behaves for a long series: the paged search knows some volumes, a lookup by full title finds the others. */
    private function seriesProvider(): GoogleBooksProvider
    {
        return $this->provider(function (string $url) {
            if (preg_match('~/volumes/v(\d+)~', $url, $m)) { // one volume by id
                return new MockResponse(json_encode($this->tome((int) $m[1])));
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            if (isset($q['startIndex'])) { // the search for the series title
                return new MockResponse(json_encode(['items' => match ((int) $q['startIndex']) {
                    0 => [$this->tome(1), $this->tome(5), $this->tome(1), $this->tome(1, 'One Piece Doors - Tome 01')],
                    20 => [$this->tome(3)],
                    default => [],
                }]));
            }
            // a lookup by title: "Tome 02" finds that volume, "Tome 04" is not on Google
            return new MockResponse(json_encode(['items' => preg_match('/Tome (02)"/', $q['q']) ? [$this->tome(2)] : []]));
        });
    }

    public function testSeriesAreRecognisedFromTheVolumesInTheResults(): void
    {
        $provider = $this->provider(fn () => new MockResponse(json_encode(['items' => [$this->tome(1), $this->tome(2), ['id' => 'x', 'volumeInfo' => ['title' => 'Smile', 'language' => 'fr']], ['id' => 'y', 'volumeInfo' => ['title' => 'Lone - Tome 4', 'language' => 'fr']],
            $this->tome(0, 'One Piece - Chapitre 734'), $this->tome(0, 'One Piece - Chapitre 735')]])));
        $series = $provider->seriesIn($provider->search('one piece'));

        $this->assertCount(1, $series); // one volume alone is not a series
        $this->assertSame(['One Piece', 'FR · 2 volumes in these results'], [$series[0]['name'], $series[0]['summary']]);
    }

    public function testASeriesIsFilledInVolumeByVolumeAndCached(): void
    {
        $provider = $this->seriesProvider();
        $key = $provider->seriesIn([$this->normalized(1), $this->normalized(2)])[0]['key'];

        $series = $provider->seriesVolumes($key);

        $this->assertSame([1, 2, 3, 5], array_map(fn ($v) => $v['attributes']['volume'], $series['volumes'])); // "Doors" is another series
        $this->assertSame([4], $series['missing']);
        $this->assertTrue($series['complete']);
        $requests = \count($this->urls);
        $this->assertSame($series, $provider->seriesVolumes($key));
        $this->assertCount($requests, $this->urls); // second time: from the cache
        $this->assertSame(['book:v1', 'book:v2', 'book:v3', 'book:v5'], array_column($provider->fetchMany(['bookseries:' . $key, 'book:v1']), 'externalId'));
    }

    private function normalized(int $n): array
    {
        $found = $this->provider(fn () => new MockResponse(json_encode(['items' => [$this->tome($n)]])))->search('x');

        return $found[0];
    }

    public function testASeriesKeyThatIsNotOneMakesNoRequest(): void
    {
        $provider = $this->provider(fn () => $this->fail('no request expected'));

        $this->assertSame([], $provider->seriesVolumes('not base64!')['volumes']);
        $this->assertSame([], $provider->seriesVolumes(rtrim(base64_encode('no separator'), '='))['volumes']);
        $this->assertSame([], $provider->fetchMany(['bookseries:', 'bookseries:../x']));
    }

    public function testNovelSeriesComeFromHardcoverAndTheirBooksFromGoogleByIsbn(): void
    {
        $this->hardcover = new HardcoverProvider(new MockHttpClient(function (string $method, string $url, array $options) {
            $body = json_decode($options['body'], true);
            if (str_contains($body['query'], 'search(')) {
                return new MockResponse(json_encode(['data' => ['search' => ['results' => ['hits' => [['document' => ['id' => '9', 'name' => 'Saga', 'author_name' => 'A. Author', 'primary_books_count' => 3]]]]]]]));
            }
            $row = fn (float $p, string $t, string ...$isbns) => ['position' => $p, 'book' => ['title' => $t, 'users_count' => 1, 'editions' => array_map(fn ($i) => ['isbn_13' => $i], $isbns)]];

            return new MockResponse(json_encode(['data' => ['series_by_pk' => ['name' => 'Saga'], 'book_series' => $body['variables']['l'] === 'fr'
                ? [$row(1, 'Un', '111'), $row(1.5, 'Entre', '222', '223'), $row(2, 'Deux', '333')] : []]]));
        }), new ArrayAdapter(), 'tok');
        // Google knows 111, and 223 (the second ISBN of "Entre") but not 333
        $provider = $this->provider(function (string $url) {
            preg_match('/isbn:(\d+)/', urldecode($url), $m);

            return new MockResponse(json_encode(['items' => in_array($m[1] ?? '', ['111', '223'], true) ? [['id' => 'g' . $m[1], 'volumeInfo' => ['title' => 'Livre ' . $m[1]]]] : []]));
        });

        $cards = $provider->novelSeriesIn('saga');
        $this->assertSame([['key' => 'hc9', 'name' => 'Saga', 'summary' => 'A. Author · 3 books', 'poster' => null]], $cards);

        $series = $provider->seriesVolumes('hc9');

        $this->assertSame(['book:g111', 'book:g223'], array_column($series['volumes'], 'externalId'));
        $this->assertSame([['Saga', 1], ['Saga', 1.5]], array_map(fn ($v) => [$v['attributes']['series'], $v['attributes']['volume']], $series['volumes']));
        $this->assertSame(['Deux'], $series['missing']);
        $this->assertSame(['book:g111', 'book:g223'], array_column($provider->fetchMany(['bookseries:hc9']), 'externalId'));
        $this->assertArrayNotHasKey('_template', $series['volumes'][0]);
    }

    public function testNovelSeriesAreSkippedWhenHardcoverIsNotSetUp(): void
    {
        $this->assertSame([], $this->provider(fn () => $this->fail('no request expected'))->novelSeriesIn('saga'));
    }
}
