<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Looks up books, manga volumes and comics (anything with a Google Books record) and returns them in the app's own
 * shape (same as TmdbProvider and IgdbProvider): "book:<volume id>". Every volume is its own item, so a manga series
 * is added volume by volume, or all at once through a series ("bookseries:<key>", see seriesVolumes()).
 *
 * A search text that is an ISBN (10 or 13 digits, hyphens allowed) is looked up as one, so a barcode scanner can feed
 * it. The API key is optional: without one Google applies a small shared daily quota.
 *
 * Google has no usable "series" endpoint (those need OAuth), so a series is worked out from titles: "One Piece - Tome 3"
 * is volume 3 of the series "One Piece". Searches only return part of a long series, so the numbers missing between 1 and
 * the highest one found are looked up one by one.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 * shortcut: the series lookup stops after SERIES_BUDGET seconds (the native HTTP client is not concurrent without
 * curl.cainfo set in php.ini), and a series is recognised from its titles only; upgrade to a real series source if one appears.
 */
final class GoogleBooksProvider
{
    public const SOURCE = 'googlebooks';

    private const API = 'https://www.googleapis.com/books/v1/volumes';
    private const LIMIT = 20;
    private const SERIES_PAGES = 10;      // 10 x 20 results
    private const SERIES_MAX_VOLUME = 300;
    private const SERIES_BUDGET = 20;     // seconds
    // "One Piece - Chapitre 734" is a chapter, not a volume
    private const NOT_A_VOLUME = '/\b(chapitres?|chapters?|episodes?|épisodes?|parts?|parties?|season|saison)\W*$/iu';
    private const VOLUME_PATTERN = '/^(.+?)[\s,:\-–]*(?:tome|vol(?:ume)?\.?|t\.|#|n°|no\.?)?\s*0*(\d{1,3})$/iu';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private HardcoverProvider $hardcover,
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

        return $this->volumes(['q' => $q, 'maxResults' => self::LIMIT, 'printType' => 'books']);
    }

    /**
     * Series that several of these search results belong to, most volumes first.
     *
     * @param list<array{externalId: string, name: string, attributes: array<string, mixed>}> $results
     * @return list<array{key: string, name: string, summary: string, poster: string|null}>
     */
    public function seriesIn(array $results): array
    {
        $groups = [];
        foreach ($results as $result) {
            $a = $result['attributes'];
            if (($a['series'] ?? null) !== null && ($a['volume'] ?? null) !== null) {
                $key = $this->seriesKey($a['series'], $a['language'] ?? null);
                $groups[$key]['name'] ??= $a['series'];
                $groups[$key]['language'] ??= $a['language'] ?? null;
                $groups[$key]['poster'] ??= $a['poster'];
                $groups[$key]['volumes'][$a['volume']] = true;
            }
        }

        $series = [];
        foreach ($groups as $key => $g) {
            if (\count($g['volumes']) >= 2) {
                $series[] = ['key' => $key, 'name' => $g['name'], 'summary' => ($g['language'] ? strtoupper($g['language']) . ' · ' : '') . \count($g['volumes']) . ' volumes in these results', 'poster' => $g['poster'], 'found' => \count($g['volumes'])];
            }
        }
        usort($series, static fn (array $a, array $b) => $b['found'] <=> $a['found']);

        return $series;
    }

    /**
     * Novel series that match a search text, from Hardcover (novels have no volume numbers in their titles to recognise a
     * series by). Nothing when Hardcover is not set up or cannot be reached: the search itself still works without it.
     *
     * @return list<array{key: string, name: string, summary: string, poster: string|null}>
     */
    public function novelSeriesIn(string $query): array
    {
        if (!$this->hardcover->isConfigured()) {
            return [];
        }
        try {
            $found = $this->hardcover->searchSeries($query);
        } catch (ExceptionInterface) {
            return [];
        }

        return array_map(static fn (array $s) => [
            'key' => 'hc' . $s['id'],
            'name' => $s['name'],
            'summary' => ($s['author'] ? $s['author'] . ' · ' : '') . $s['books'] . ' books',
            'poster' => null,
        ], $found);
    }

    /**
     * Every volume of a series that Google Books knows, by volume number: the volumes its search returns for the series
     * title, then one lookup per number missing below the highest found. Cached for an hour once complete.
     *
     * @param string $key from seriesIn()
     * @return array{volumes: list<array{externalId: string, name: string, attributes: array<string, mixed>}>, missing: list<int>, complete: bool}
     */
    public function seriesVolumes(string $key): array
    {
        if (preg_match('/^hc(\d{1,9})$/', $key, $m)) {
            return $this->cache->get('gb_novels_' . $m[1], function (ItemInterface $item) use ($m) {
                $series = $this->collectNovelSeries((int) $m[1]);
                $item->expiresAfter($series['complete'] ? 3600 : 60);

                return $series;
            });
        }

        [$language, $stem] = $this->decodeKey($key) ?? [null, null];
        if ($stem === null) {
            return ['volumes' => [], 'missing' => [], 'complete' => true];
        }

        return $this->cache->get('gb_series_' . sha1($key), function (ItemInterface $item) use ($language, $stem) {
            $series = $this->collectSeries($language, $stem);
            $item->expiresAfter($series['complete'] ? 3600 : 60);

            return $series;
        });
    }

    /**
     * Loads volumes by "book:<id>" (one request each, all started together) and series by "bookseries:<key>" (all their
     * volumes). Anything else is skipped. Each volume comes once, in the order asked; a volume Google no longer has is skipped.
     *
     * @param string[] $externalIds
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function fetchMany(array $externalIds, ?string $type = null): array
    {
        $responses = [];
        $series = [];
        foreach (array_unique($externalIds) as $externalId) {
            if (preg_match('/^book:([A-Za-z0-9_-]{1,40})$/', $externalId, $m)) {
                $responses['book:' . $m[1]] = $this->http->request('GET', self::API . '/' . $m[1], ['query' => $this->withKey([]), 'timeout' => 10]);
            } elseif (preg_match('/^bookseries:([A-Za-z0-9_-]{1,300})$/', $externalId, $m)) { // title-based ("<base64>") or novel ("hc<id>")
                $series[$externalId] = $m[1];
            }
        }

        $items = [];
        foreach (array_unique($externalIds) as $externalId) {
            if (isset($responses[$externalId])) {
                if ($responses[$externalId]->getStatusCode() !== 404) {
                    $volume = $this->normalize($responses[$externalId]->toArray());
                    $items[$volume['externalId']] ??= $volume;
                }
            } elseif (isset($series[$externalId])) {
                foreach ($this->seriesVolumes($series[$externalId])['volumes'] as $volume) {
                    $items[$volume['externalId']] ??= $volume;
                }
            }
        }

        return array_values($items);
    }

    /**
     * A novel series: Hardcover says which books it has and an ISBN for each, Google Books supplies the books. A book whose
     * first ISBN Google does not know is tried with its second; one Google has neither of is reported as missing (by title).
     *
     * @return array{volumes: list<array>, missing: list<string>, complete: bool}
     */
    private function collectNovelSeries(int $id): array
    {
        $started = microtime(true);
        $series = $this->hardcover->seriesBooks($id);

        $lookup = function (string $isbn): ?array {
            $found = $this->readVolumes($this->volumesRequest(['q' => 'isbn:' . $isbn, 'maxResults' => 1, 'printType' => 'books']));

            return $found[0] ?? null;
        };

        $volumes = [];
        $missing = [];
        $complete = true;
        foreach ($series['books'] as $book) {
            if (microtime(true) - $started > self::SERIES_BUDGET) {
                $complete = false;
                break;
            }
            $volume = null;
            foreach ($book['isbns'] as $isbn) {
                if ($volume = $lookup($isbn)) {
                    break;
                }
            }
            if ($volume === null) {
                $missing[] = $book['title'];
                continue;
            }
            $volume['attributes']['series'] = $series['name'] ?: null;
            $volume['attributes']['volume'] = $book['position'] == (int) $book['position'] ? (int) $book['position'] : $book['position'];
            unset($volume['_template']);
            $volumes[$volume['externalId']] ??= $volume;
        }

        return ['volumes' => array_values($volumes), 'missing' => $missing, 'complete' => $complete];
    }

    /** @return array{volumes: list<array>, missing: list<int>, complete: bool} */
    private function collectSeries(?string $language, string $stem): array
    {
        $started = microtime(true);
        $norm = $this->normalizeStem($stem);
        $accept = fn (array $v) => ($v['attributes']['volume'] ?? null) !== null && $this->normalizeStem($v['attributes']['series'] ?? '') === $norm;
        $byNumber = [];
        $template = null;
        $take = function (array $found) use (&$byNumber, &$template, $accept): void {
            foreach (array_filter($found, $accept) as $v) {
                $n = $v['attributes']['volume'];
                // two records for a volume: keep the one with a cover
                if (!isset($byNumber[$n]) || (!$byNumber[$n]['attributes']['poster'] && $v['attributes']['poster'])) {
                    $byNumber[$n] = $v;
                }
                $template ??= $v['_template'] ?? null;
            }
        };

        // the pages of the search for the series title, all started before any is read
        $pages = [];
        for ($i = 0; $i < self::SERIES_PAGES; ++$i) {
            $pages[] = $this->volumesRequest($this->seriesQuery('intitle:"' . $stem . '"', $language) + ['startIndex' => $i * self::LIMIT, 'maxResults' => self::LIMIT]);
        }
        foreach ($pages as $page) {
            $take($this->readVolumes($page));
        }

        // the numbers a long series is missing, looked up by their full title, a few at a time
        $max = $byNumber ? min(max(array_keys($byNumber)), self::SERIES_MAX_VOLUME) : 0;
        $missing = array_values(array_diff(range(1, max($max, 1)), array_keys($byNumber)));
        $complete = true;
        if ($template !== null && $max > 0) {
            foreach (array_chunk($missing, 10) as $batch) {
                if (microtime(true) - $started > self::SERIES_BUDGET) {
                    $complete = false;
                    break;
                }
                $requests = [];
                foreach ($batch as $n) {
                    $requests[$n] = $this->volumesRequest($this->seriesQuery('intitle:"' . str_replace('{n}', $this->padded($template, $n), $template['text']) . '"', $language) + ['maxResults' => 5]);
                }
                foreach ($requests as $response) {
                    $take($this->readVolumes($response));
                }
            }
        }

        ksort($byNumber);

        return [
            'volumes' => array_values($byNumber),
            'missing' => array_values(array_diff($missing, array_keys($byNumber))),
            'complete' => $complete,
        ];
    }

    private function seriesQuery(string $q, ?string $language): array
    {
        return ['q' => $q, 'printType' => 'books'] + ($language ? ['langRestrict' => $language] : []);
    }

    /** The number written like the sample title writes it ("03" when the sample is zero-padded). */
    private function padded(array $template, int $n): string
    {
        return str_pad((string) $n, $template['width'], '0', STR_PAD_LEFT);
    }

    /** @return list<array{externalId: string, name: string, attributes: array<string, mixed>}> */
    private function volumes(array $query): array
    {
        return $this->readVolumes($this->volumesRequest($query));
    }

    private function volumesRequest(array $query): ResponseInterface
    {
        return $this->http->request('GET', self::API, ['query' => $this->withKey($query), 'timeout' => 10]);
    }

    private function readVolumes(ResponseInterface $response): array
    {
        $data = $response->toArray();

        return array_map($this->normalize(...), array_values(array_filter($data['items'] ?? [], static fn (array $v) => isset($v['id']))));
    }

    private function withKey(array $query): array
    {
        return $this->key !== '' ? $query + ['key' => $this->key] : $query;
    }

    /** "One Piece - Tome 03" => ["One Piece", 3], null when the title carries no volume number. */
    private function parseVolume(string $title): ?array
    {
        if (!preg_match(self::VOLUME_PATTERN, trim($title), $m) || ($stem = trim($m[1], " \t,:-–")) === '' || mb_strlen($stem) < 2
            || (int) $m[2] > self::SERIES_MAX_VOLUME || preg_match(self::NOT_A_VOLUME, $stem)) {
            return null;
        }

        return [$stem, (int) $m[2], \strlen($this->trailingDigits($title))];
    }

    private function trailingDigits(string $title): string
    {
        preg_match('/(\d+)\s*$/', $title, $m);

        return $m[1] ?? '';
    }

    private function normalizeStem(string $stem): string
    {
        $stem = mb_strtolower($stem);
        if (class_exists(\Normalizer::class)) {
            $stem = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($stem, \Normalizer::FORM_D));
        }

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $stem));
    }

    private function seriesKey(string $stem, ?string $language): string
    {
        return rtrim(strtr(base64_encode(($language ?? '') . '|' . $stem), '+/', '-_'), '=');
    }

    /** @return array{0: ?string, 1: string}|null */
    private function decodeKey(string $key): ?array
    {
        $raw = base64_decode(strtr($key, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|') || !mb_check_encoding($raw, 'UTF-8') || mb_strlen($raw) > 200) {
            return null;
        }
        [$language, $stem] = explode('|', $raw, 2);
        // the stem goes into a search query between quotes
        $stem = trim(str_replace('"', '', $stem));

        return $stem === '' || !preg_match('/^[a-z]{0,3}$/', $language) ? null : [$language ?: null, $stem];
    }

    private function normalize(array $volume): array
    {
        $info = $volume['volumeInfo'] ?? [];
        $rawTitle = trim($info['title'] ?? '');
        $title = trim($rawTitle . (isset($info['subtitle']) ? ': ' . $info['subtitle'] : ''));
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
        $parsed = $this->parseVolume($rawTitle);

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
                'language' => ($info['language'] ?? '') ?: null,
                'series' => $parsed[0] ?? null,
                'volume' => $parsed[1] ?? null,
            ],
            // only for looking up a series' other volumes, never stored: the title with {n} where the number is
            '_template' => $parsed ? ['text' => preg_replace('/\d+\s*$/', '{n}', $rawTitle), 'width' => $parsed[2]] : null,
        ];
    }
}
