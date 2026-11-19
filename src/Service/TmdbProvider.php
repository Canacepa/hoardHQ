<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Looks up movies and TV seasons on TMDB and returns them in the app's own shape.
 *
 * What ends up in a collection: a movie ("movie:603") or a TV SEASON ("tv:1396:s2"), which is its own item with its own
 * poster and episode count. Asking for the full show ("tv:1396") means all of its seasons: it expands into one item per
 * season, so a show and some of its seasons asked for together never add anything twice.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 */
final class TmdbProvider
{
    public const SOURCE = 'tmdb';

    private const API = 'https://api.themoviedb.org/3';
    private const IMAGE = 'https://image.tmdb.org/t/p/w342';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'TMDB_API_TOKEN')] private string $token,
    ) {
    }

    /**
     * Search results. For 'tv' these are shows ("tv:<id>"), which open into their seasons.
     *
     * @param 'movie'|'tv' $type
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function search(string $query, string $type): array
    {
        $this->assertType($type);

        // The type-specific endpoints don't tag their results with a media_type, so the type is passed along.
        return array_map(
            fn (array $result) => $this->normalize($type, $result),
            $this->get("/search/$type", ['query' => $query, 'include_adult' => 'false'])['results'] ?? [],
        );
    }

    /**
     * The seasons of a TV show, as importable items. Specials (season 0) and seasons with no episodes are left out.
     *
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function seasonsOf(int $tvId): array
    {
        return $this->seasonItems($this->get("/tv/$tvId"));
    }

    /**
     * Loads several items with all requests in flight at once. Accepts "movie:<id>", "tv:<id>" (all seasons of the show) and
     * "tv:<id>:s<season>"; anything else is skipped, and so is the other kind when $type is given (a Movies collection
     * never takes TV items and vice versa). A show and its seasons cost a single request, and an item asked for twice
     * (a season on its own and again through its show) comes back once. If a request fails, the exception is thrown and
     * nothing is returned. Items come back in the order they were asked for.
     *
     * @param string[] $externalIds
     * @param 'movie'|'tv'|null $type
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function fetchMany(array $externalIds, ?string $type = null): array
    {
        $wanted = [];
        $responses = [];
        foreach (array_unique($externalIds) as $externalId) {
            if ($type !== 'tv' && preg_match('/^movie:(\d+)$/', $externalId, $m)) {
                $wanted[] = ['movie', (int) $m[1], null];
                $responses["movie/$m[1]"] ??= $this->request("/movie/$m[1]");
            } elseif ($type !== 'movie' && preg_match('/^tv:(\d+)$/', $externalId, $m)) {
                $wanted[] = ['show', (int) $m[1], null];
                $responses["tv/$m[1]"] ??= $this->request("/tv/$m[1]");
            } elseif ($type !== 'movie' && preg_match('/^tv:(\d+):s(\d+)$/', $externalId, $m)) {
                $wanted[] = ['season', (int) $m[1], (int) $m[2]];
                $responses["tv/$m[1]"] ??= $this->request("/tv/$m[1]");
            }
        }

        $data = [];
        $items = [];
        foreach ($wanted as [$kind, $id, $season]) {
            $key = $kind === 'movie' ? "movie/$id" : "tv/$id";
            $data[$key] ??= $responses[$key]->toArray();

            // a season TMDB doesn't list (any more) simply isn't found
            $found = $kind === 'movie'
                ? [$this->normalize('movie', $data[$key])]
                : array_filter($this->seasonItems($data[$key]), static fn (array $item) => $kind === 'show' || $item['attributes']['season_number'] === $season);
            foreach ($found as $item) {
                $items[$item['externalId']] ??= $item;
            }
        }

        return array_values($items);
    }

    private function assertType(string $type): void
    {
        if (!\in_array($type, ['movie', 'tv'], true)) {
            throw new \InvalidArgumentException("Unknown TMDB type \"$type\".");
        }
    }

    private function get(string $path, array $query = []): array
    {
        return $this->request($path, $query)->toArray();
    }

    private function request(string $path, array $query = []): ResponseInterface
    {
        return $this->http->request('GET', self::API . $path, ['auth_bearer' => $this->token, 'query' => $query, 'timeout' => 10]);
    }

    /** A movie, or a TV show as a search result. */
    private function normalize(string $type, array $data): array
    {
        $date = $data['release_date'] ?? $data['first_air_date'] ?? '';

        return [
            // movie and TV ids are separate sequences on TMDB, so the type is part of the id.
            'externalId' => $type . ':' . $data['id'],
            'name' => mb_substr($data['title'] ?? $data['name'] ?? '', 0, 255),
            'attributes' => [
                'type' => $type,
                'year' => substr($date, 0, 4) ?: null,
                'overview' => ($data['overview'] ?? '') ?: null,
                'poster' => isset($data['poster_path']) ? self::IMAGE . $data['poster_path'] : null,
            ],
        ];
    }

    /**
     * The regular seasons of a /tv/{id} response: specials (season 0) and seasons that have not aired are left out, so that
     * "watched" stays meaningful.
     *
     * @param array<string, mixed> $show
     * @return list<array{number: int, name: string, episodes: int, raw: array<string, mixed>}>
     */
    private function regularSeasons(array $show): array
    {
        $seasons = [];
        foreach ($show['seasons'] ?? [] as $season) {
            $number = (int) ($season['season_number'] ?? 0);
            $episodes = (int) ($season['episode_count'] ?? 0);
            if ($number >= 1 && $episodes >= 1) {
                $seasons[] = ['number' => $number, 'name' => mb_substr($season['name'] ?? '', 0, 100) ?: "Season $number", 'episodes' => $episodes, 'raw' => $season];
            }
        }

        return $seasons;
    }

    /**
     * @param array<string, mixed> $show a /tv/{id} response
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    private function seasonItems(array $show): array
    {
        $items = [];
        foreach ($this->regularSeasons($show) as ['number' => $number, 'name' => $seasonName, 'episodes' => $episodes, 'raw' => $season]) {
            // a season often has no poster or overview of its own: fall back to the show's
            $poster = ($season['poster_path'] ?? null) ?: ($show['poster_path'] ?? null);
            $items[] = [
                'externalId' => "tv:{$show['id']}:s$number",
                'name' => mb_substr(($show['name'] ?? '') . ' — ' . $seasonName, 0, 255),
                'attributes' => [
                    'type' => 'tv',
                    'year' => substr($season['air_date'] ?? '', 0, 4) ?: null,
                    'overview' => (($season['overview'] ?? '') ?: ($show['overview'] ?? '')) ?: null,
                    'poster' => $poster ? self::IMAGE . $poster : null,
                    'episodes' => $episodes,
                    'season_number' => $number,
                    // one season, so the existing per-episode tracking works on it unchanged
                    'season_list' => [['number' => $number, 'name' => $seasonName, 'episodes' => $episodes]],
                ],
            ];
        }

        return $items;
    }
}
