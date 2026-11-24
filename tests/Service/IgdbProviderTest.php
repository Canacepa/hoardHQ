<?php

namespace App\Tests\Service;

use App\Service\IgdbProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgdbProviderTest extends TestCase
{
    private array $requests = [];

    private function provider(array $games): IgdbProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($games) {
            $this->requests[] = [$method, $url, $options['body'] ?? '', $options['headers']];

            return str_contains($url, 'twitch.tv')
                ? new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 5000000]))
                : new MockResponse(json_encode($games));
        });

        return new IgdbProvider($client, new ArrayAdapter(), 'cid', 'secret');
    }

    private function zelda(): array
    {
        return ['id' => 1022, 'name' => 'The Legend of Zelda', 'first_release_date' => 525916800, 'summary' => 'Hyrule.',
            'cover' => ['image_id' => 'co1abc'], 'platforms' => [['abbreviation' => 'NES', 'name' => 'Nintendo Entertainment System'], ['name' => 'Switch']]];
    }

    public function testSearchNormalizesGamesAndAuthenticatesThroughTwitch(): void
    {
        $found = $this->provider([$this->zelda(), ['id' => 5, 'name' => 'Bare']])->search('zel"da');

        $this->assertSame('game:1022', $found[0]['externalId']);
        $this->assertSame(['type' => 'game', 'year' => '1986', 'overview' => 'Hyrule.', 'poster' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1abc.jpg', 'platforms' => ['NES', 'Switch']], $found[0]['attributes']);
        $this->assertSame(['type' => 'game', 'year' => null, 'overview' => null, 'poster' => null, 'platforms' => null], $found[1]['attributes']);
        $this->assertStringContainsString('client_secret=secret', $this->requests[0][1]);
        $this->assertSame('POST', $this->requests[1][0]);
        $this->assertStringContainsString('search "zel\"da";', $this->requests[1][2]); // quotes can't break out of the query
    }

    public function testTheTokenIsReusedAcrossRequests(): void
    {
        $provider = $this->provider([]);
        $provider->search('a');
        $provider->search('b');

        $this->assertCount(1, array_filter($this->requests, fn ($r) => str_contains($r[1], 'twitch.tv')));
    }

    public function testFetchManyKeepsTheOrderAskedLoadsOnceAndIgnoresOtherIds(): void
    {
        $games = [['id' => 1, 'name' => 'One'], $this->zelda()];
        $found = $this->provider($games)->fetchMany(['game:1022', 'movie:603', 'game:1', 'game:1022', 'game:x', 'game:9']);

        $this->assertSame(['game:1022', 'game:1'], array_column($found, 'externalId')); // game:9 is not on IGDB
        $this->assertStringContainsString('where id = (1022,1,9);', $this->requests[1][2]);
    }

    public function testFetchManyWithNothingValidMakesNoRequest(): void
    {
        $this->assertSame([], $this->provider([])->fetchMany(['movie:1', 'game:', '']));
        $this->assertSame([], $this->requests);
    }
}
