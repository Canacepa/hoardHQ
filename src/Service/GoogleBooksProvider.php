<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks up books, manga volumes and comics (anything with a Google Books record) and returns them in the app's own
 * shape (same as TmdbProvider and IgdbProvider): "book:<volume id>". Every volume is its own item, so a manga series
 * is added volume by volume.
 *
 * A search text that is an ISBN (10 or 13 digits, hyphens allowed) is looked up as one, so a barcode scanner can feed
 * it. The API key is optional: without one Google applies a small shared daily quota.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 * shortcut: Books and Comics & Manga collections search the same catalog, add a subject filter if that gets confusing.
 */
final class GoogleBooksProvider
{
    public const SOURCE = 'googlebooks';

    private const API = 'https://www.googleapis.com/books/v1/volumes';
    private const LIMIT = 20;

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'GOOGLE_BOOKS_API_KEY')] private string $key,
    ) {
    }

    /**
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function search(string $query, ?string $type = null): array
    {
        $isbn = preg_replace('/[\s-]/', '', $query);
        $q = preg_match('/^(\d{13}|\d{9}[\dXx])$/', $isbn) ? 'isbn:' . $isbn : $query;

        $data = $this->http->request('GET', self::API, ['query' => $this->withKey(['q' => $q, 'maxResults' => self::LIMIT, 'printType' => 'books']), 'timeout' => 10])->toArray();

        return array_map($this->normalize(...), array_values(array_filter($data['items'] ?? [], static fn (array $v) => isset($v['id']))));
    }

    /**
     * Loads volumes by "book:<id>" (anything else is skipped), in the order asked, each once, with all requests in flight
     * at once. A volume Google no longer has is skipped; any other failure throws.
     *
     * @param string[] $externalIds
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function fetchMany(array $externalIds, ?string $type = null): array
    {
        $responses = [];
        foreach (array_unique($externalIds) as $externalId) {
            if (preg_match('/^book:([A-Za-z0-9_-]{1,40})$/', $externalId, $m)) {
                $responses[$m[1]] = $this->http->request('GET', self::API . '/' . $m[1], ['query' => $this->withKey([]), 'timeout' => 10]);
            }
        }

        $items = [];
        foreach ($responses as $response) {
            if ($response->getStatusCode() !== 404) {
                $items[] = $this->normalize($response->toArray());
            }
        }

        return $items;
    }

    private function withKey(array $query): array
    {
        return $this->key !== '' ? $query + ['key' => $this->key] : $query;
    }

    private function normalize(array $volume): array
    {
        $info = $volume['volumeInfo'] ?? [];
        $title = trim(($info['title'] ?? '') . (isset($info['subtitle']) ? ': ' . $info['subtitle'] : ''));
        $isbn = null;
        foreach ($info['industryIdentifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === 'ISBN_13') {
                $isbn = $identifier['identifier'];
            }
        }
        $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
        // covers come over http, with a page-curl effect added to the picture
        $cover = $cover ? str_replace(['http://', '&edge=curl'], ['https://', ''], $cover) : null;
        $overview = trim(preg_replace('/\n{2,}/', "\n", html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $info['description'] ?? '')), ENT_QUOTES | ENT_HTML5)));

        return [
            'externalId' => 'book:' . $volume['id'],
            'name' => mb_substr($title, 0, 255),
            'attributes' => [
                'type' => 'book',
                'year' => preg_match('/^\d{4}/', $info['publishedDate'] ?? '', $m) ? $m[0] : null,
                'overview' => $overview ?: null,
                'poster' => $cover,
                'authors' => ($info['authors'] ?? []) ? implode(', ', \array_slice($info['authors'], 0, 3)) : null,
                'publisher' => ($info['publisher'] ?? '') ?: null,
                'isbn' => $isbn,
            ],
        ];
    }
}
