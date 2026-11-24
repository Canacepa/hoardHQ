<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Knows which books make up a novel series, in order, from Hardcover (GraphQL). It only supplies the list: the books
 * themselves are then looked up on Google Books by ISBN (see GoogleBooksProvider), so what lands in a collection is
 * always a Google Books item.
 *
 * Every book comes with the ISBNs of its edition in the first LANGUAGES language that has one, so a series is added in
 * French when French editions exist and in English otherwise. Hardcover lists translations as books of their own at the
 * same position of a series; one book per position is kept, the one most readers have.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 * shortcut: the languages are fixed, make them a setting if the app gets other users.
 */
final class HardcoverProvider
{
    /** Languages (ISO 639-1) to take editions in, best first. */
    public const LANGUAGES = ['fr', 'en'];

    private const API = 'https://api.hardcover.app/v1/graphql';
    private const MAX_BOOKS = 60;

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        #[Autowire(env: 'HARDCOVER_API_TOKEN')] private string $token,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Series that match a search text and have at least two main books, best match first. Cached for ten minutes
     * (Hardcover allows 60 requests a minute).
     *
     * @return list<array{id: int, name: string, author: ?string, books: int}>
     */
    public function searchSeries(string $query): array
    {
        $query = trim($query);

        return $query === '' ? [] : $this->cache->get('hc_search_' . sha1(mb_strtolower($query)), function (ItemInterface $item) use ($query) {
            $item->expiresAfter(600);
            $data = $this->graphql('query($q: String!) { search(query: $q, query_type: "Series", per_page: 5, page: 1) { results } }', ['q' => $query]);

            $series = [];
            foreach ($data['search']['results']['hits'] ?? [] as $hit) {
                $d = $hit['document'] ?? [];
                if (isset($d['id'], $d['name']) && (int) ($d['primary_books_count'] ?? 0) >= 2) {
                    $series[] = ['id' => (int) $d['id'], 'name' => (string) $d['name'], 'author' => ($d['author_name'] ?? '') ?: null, 'books' => (int) $d['primary_books_count']];
                }
            }

            return \array_slice($series, 0, 3);
        });
    }

    /**
     * The books of a series, in order, one per position (a prequel at 0.5 or a story at 1.5 counts), with the ISBNs of the
     * edition to look up. Books with no position, compilations and books with no edition in LANGUAGES are left out.
     *
     * @return array{name: string, books: list<array{position: float, title: string, isbns: list<string>}>}
     */
    public function seriesBooks(int $id): array
    {
        return $this->cache->get('hc_series_' . $id, function (ItemInterface $item) use ($id) {
            $item->expiresAfter(3600);

            $name = '';
            $byPosition = [];
            foreach (self::LANGUAGES as $rank => $language) {
                $data = $this->graphql(
                    'query($id: Int!, $l: String!) {
                        series_by_pk(id: $id) { name }
                        book_series(where: {series_id: {_eq: $id}, compilation: {_eq: false}, position: {_gt: 0},
                                            book: {editions: {language: {code2: {_eq: $l}}, isbn_13: {_is_null: false}}}}, order_by: [{position: asc}]) {
                            position
                            book { id title users_count
                                   editions(where: {language: {code2: {_eq: $l}}, isbn_13: {_is_null: false}}, order_by: [{lists_count: desc_nulls_last}], limit: 2) { isbn_13 } }
                        }
                    }',
                    ['id' => $id, 'l' => $language],
                );
                $name = $data['series_by_pk']['name'] ?? $name;
                foreach ($data['book_series'] ?? [] as $row) {
                    $isbns = array_values(array_filter(array_column($row['book']['editions'] ?? [], 'isbn_13')));
                    $position = (string) (float) $row['position'];
                    if (!$isbns) {
                        continue;
                    }
                    $candidate = ['position' => (float) $row['position'], 'title' => (string) $row['book']['title'], 'isbns' => $isbns, 'readers' => (int) ($row['book']['users_count'] ?? 0), 'rank' => $rank];
                    $byPosition[$position][] = $candidate;
                }
            }

            ksort($byPosition, SORT_NUMERIC);

            $books = [];
            foreach ($byPosition as $candidates) {
                // an earlier language first; within a language, the book most readers have
                usort($candidates, static fn (array $a, array $b) => [$a['rank'], $b['readers']] <=> [$b['rank'], $a['readers']]);
                $books[] = [
                    'position' => $candidates[0]['position'],
                    'title' => $candidates[0]['title'],
                    // the other languages' editions follow, for when Google Books doesn't know the first choice
                    'isbns' => \array_slice(array_values(array_unique(array_merge(...array_column($candidates, 'isbns')))), 0, 4),
                ];
            }

            return ['name' => $name, 'books' => \array_slice($books, 0, self::MAX_BOOKS)];
        });
    }

    private function graphql(string $query, array $variables): array
    {
        $response = $this->http->request('POST', self::API, [
            // the token is shown on Hardcover's settings page with "Bearer " in front
            'auth_bearer' => preg_replace('/^Bearer\s+/i', '', $this->token),
            'json' => ['query' => $query, 'variables' => (object) $variables],
            'timeout' => 10,
        ]);
        $data = $response->toArray(false);
        if (isset($data['errors']) || isset($data['error'])) {
            throw new TransportException('Hardcover: ' . ($data['errors'][0]['message'] ?? $data['error'] ?? 'error'));
        }

        return $data['data'] ?? [];
    }
}
