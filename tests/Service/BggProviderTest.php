<?php

namespace App\Tests\Service;

use App\Service\BggProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BggProviderTest extends TestCase
{
    private array $requests = [];

    private function provider(string $search, string $thing): BggProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($search, $thing) {
            $this->requests[] = [$url, $options['headers']];

            return new MockResponse(str_contains($url, '/search') ? $search : $thing);
        });

        return new BggProvider($client, 'secret');
    }

    private function things(): string
    {
        return '<items>'
            . '<item type="boardgame" id="13"><thumbnail>https://cf.geekdo-images.com/catan.jpg</thumbnail><image>https://cf.geekdo-images.com/catan-big.jpg</image>'
            . '<name type="primary" sortindex="1" value="CATAN"/><name type="alternate" value="Les Colons de Catane"/>'
            . '<description>Trade &amp;amp; build.&amp;#10;Second line.</description><yearpublished value="1995"/><minplayers value="3"/><maxplayers value="4"/><playingtime value="120"/></item>'
            . '<item type="boardgame" id="7"><name type="primary" value="Bare"/><yearpublished value="0"/><minplayers value="2"/><maxplayers value="2"/><playingtime value="0"/></item>'
            . '</items>';
    }

    public function testSearchLooksUpTheMatchesAndNormalizesThem(): void
    {
        $found = $this->provider('<items><item type="boardgame" id="13"/><item type="boardgame" id="7"/></items>', $this->things())->search('catan');

        $this->assertSame('boardgame:13', $found[0]['externalId']);
        $this->assertSame('CATAN', $found[0]['name']); // the primary name, not the French alternate
        $this->assertSame(['type' => 'boardgame', 'year' => '1995', 'overview' => "Trade & build.\nSecond line.", 'poster' => 'https://cf.geekdo-images.com/catan.jpg', 'players' => '3–4', 'minutes' => 120], $found[0]['attributes']);
        $this->assertSame(['type' => 'boardgame', 'year' => null, 'overview' => null, 'poster' => null, 'players' => '2', 'minutes' => null], $found[1]['attributes']);
        $this->assertStringContainsString('/xmlapi2/search?query=catan&type=boardgame', $this->requests[0][0]);
        $this->assertStringContainsString('/xmlapi2/thing?id=13%2C7', $this->requests[1][0]);
        $this->assertContains('Authorization: Bearer secret', $this->requests[0][1]);
    }

    public function testNothingFoundMakesNoSecondRequest(): void
    {
        $this->assertSame([], $this->provider('<items total="0"/>', '')->search('zzzz'));
        $this->assertCount(1, $this->requests);
    }

    public function testFetchManyKeepsTheOrderAskedLoadsOnceAndIgnoresOtherIds(): void
    {
        $found = $this->provider('', $this->things())->fetchMany(['boardgame:7', 'game:13', 'boardgame:13', 'boardgame:7', 'boardgame:x', 'boardgame:99']);

        $this->assertSame(['boardgame:7', 'boardgame:13'], array_column($found, 'externalId')); // 99 is not on BGG
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('id=7%2C13%2C99', $this->requests[0][0]);
    }

    public function testFetchManyWithNothingValidMakesNoRequest(): void
    {
        $this->assertSame([], $this->provider('', '')->fetchMany(['game:1', 'boardgame:', '']));
        $this->assertSame([], $this->requests);
    }

    public function testAnswerThatIsNotXmlIsATransportError(): void
    {
        $this->expectException(TransportException::class);
        $this->provider('Rate limit exceeded', '')->search('catan');
    }
}
