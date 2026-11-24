<?php

namespace App\Tests\Service;

use App\Service\HardcoverProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HardcoverProviderTest extends TestCase
{
    private array $requests = [];

    private function provider(callable $answer, string $token = 'Bearer secret'): HardcoverProvider
    {
        return new HardcoverProvider(new MockHttpClient(function (string $method, string $url, array $options) use ($answer) {
            $body = json_decode($options['body'], true);
            $this->requests[] = [$options['headers'], $body];

            return new MockResponse(json_encode($answer($body)));
        }), new ArrayAdapter(), $token);
    }

    private function row(float $position, string $title, int $readers, string ...$isbns): array
    {
        return ['position' => $position, 'book' => ['id' => 1, 'title' => $title, 'users_count' => $readers, 'editions' => array_map(fn ($i) => ['isbn_13' => $i], $isbns)]];
    }

    public function testSearchKeepsRealSeriesAndSendsTheTokenWithoutItsBearerPrefix(): void
    {
        $provider = $this->provider(fn () => ['data' => ['search' => ['results' => ['hits' => [
            ['document' => ['id' => '1185', 'name' => 'Harry Potter', 'author_name' => 'J.K. Rowling', 'primary_books_count' => 7]],
            ['document' => ['id' => '2', 'name' => 'A single book', 'primary_books_count' => 1]],
        ]]]]]);

        $this->assertSame([['id' => 1185, 'name' => 'Harry Potter', 'author' => 'J.K. Rowling', 'books' => 7]], $provider->searchSeries('harry potter'));
        $this->assertContains('Authorization: Bearer secret', $this->requests[0][0]);
        $this->assertSame([], $this->provider(fn () => $this->fail('no request expected'))->searchSeries('  '));
    }

    public function testABookIsTakenInTheBestLanguageWithTheOtherAsFallback(): void
    {
        $provider = $this->provider(fn (array $body) => ['data' => ['series_by_pk' => ['name' => 'Saga'], 'book_series' => match ($body['variables']['l']) {
            'fr' => [$this->row(1, 'Un', 5, '9782000000001'), $this->row(2, 'Deux', 5, '9782000000002')],
            default => [$this->row(2, 'Two', 9, '9780000000002'), $this->row(0.5, 'Half', 1, '9780000000005'), $this->row(1, 'One', 99, '9780000000001')],
        }]]);

        $series = $provider->seriesBooks(7);

        $this->assertSame('Saga', $series['name']);
        $this->assertSame([0.5, 1.0, 2.0], array_column($series['books'], 'position'));
        $this->assertSame(['Half', 'Un', 'Deux'], array_column($series['books'], 'title')); // French where there is a French edition
        $this->assertSame(['9782000000001', '9780000000001'], $series['books'][1]['isbns']); // then the English one as fallback
        $this->assertCount(2, $this->requests); // one request per language
        $provider->seriesBooks(7);
        $this->assertCount(2, $this->requests); // cached
    }

    public function testAnErrorFromHardcoverIsATransportError(): void
    {
        $this->expectException(TransportException::class);
        $this->provider(fn () => ['errors' => [['message' => 'bad']]])->seriesBooks(1);
    }
}
