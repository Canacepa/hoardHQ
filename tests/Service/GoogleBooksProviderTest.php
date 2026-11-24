<?php

namespace App\Tests\Service;

use App\Service\GoogleBooksProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleBooksProviderTest extends TestCase
{
    private array $urls = [];

    private function volume(string $id = 'abc123'): array
    {
        return ['id' => $id, 'volumeInfo' => [
            'title' => 'One Piece, Vol. 1', 'subtitle' => 'Romance Dawn', 'authors' => ['Eiichiro Oda', 'B', 'C', 'D'], 'publisher' => 'Glénat',
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
        }), $key);
    }

    public function testSearchNormalizesVolumes(): void
    {
        $found = $this->provider(fn () => new MockResponse(json_encode(['items' => [$this->volume(), ['id' => 'bare', 'volumeInfo' => ['title' => 'Bare']], ['volumeInfo' => ['title' => 'No id']]]])))->search('one piece');

        $this->assertCount(2, $found); // the item with no id is dropped
        $this->assertSame('book:abc123', $found[0]['externalId']);
        $this->assertSame('One Piece, Vol. 1: Romance Dawn', $found[0]['name']);
        $this->assertSame(['type' => 'book', 'year' => '2000', 'overview' => "Luffy & crew.\nGo!", 'poster' => 'https://books.google.com/c?id=abc123&zoom=1', 'authors' => 'Eiichiro Oda, B, C', 'publisher' => 'Glénat', 'isbn' => '9782723499996'], $found[0]['attributes']);
        $this->assertSame(['type' => 'book', 'year' => null, 'overview' => null, 'poster' => null, 'authors' => null, 'publisher' => null, 'isbn' => null], $found[1]['attributes']);
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
}
