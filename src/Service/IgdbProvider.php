<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks up video games on IGDB and returns them in the app's own shape (same as TmdbProvider): "game:<id>".
 *
 * IGDB is owned by Twitch: a client id + secret are exchanged for an app access token (cached until shortly before it
 * expires), and queries are sent as a text body in IGDB's "Apicalypse" syntax.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 */
final class IgdbProvider
{
    public const SOURCE = 'igdb';

    private const API = 'https://api.igdb.com/v4';
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const IMAGE = 'https://images.igdb.com/igdb/image/upload/t_cover_big/';
    private const FIELDS = 'name,first_release_date,summary,cover.image_id,platforms.abbreviation,platforms.name';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        #[Autowire(env: 'IGDB_CLIENT_ID')] private string $clientId,
        #[Autowire(env: 'IGDB_CLIENT_SECRET')] private string $clientSecret,
    ) {
    }

    /**
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function search(string $query, ?string $type = null): array
    {
        // version_parent = null leaves out editions/bundles that repeat a main game
        $body = 'search "' . addcslashes($query, '"\\') . '"; fields ' . self::FIELDS . '; where version_parent = null; limit 20;';

        return array_map($this->normalize(...), $this->query($body));
    }

    /**
     * Loads games by "game:<id>" (anything else is skipped), in the order asked, each once, in a single request.
     *
     * @param string[] $externalIds
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function fetchMany(array $externalIds, ?string $type = null): array
    {
        $ids = [];
        foreach (array_unique($externalIds) as $externalId) {
            if (preg_match('/^game:(\d+)$/', $externalId, $m)) {
                $ids[] = (int) $m[1];
            }
        }
        if (!$ids) {
            return [];
        }

        $byId = [];
        foreach ($this->query('fields ' . self::FIELDS . '; where id = (' . implode(',', $ids) . '); limit ' . \count($ids) . ';') as $game) {
            $byId[$game['id']] = $this->normalize($game);
        }

        // a game IGDB no longer lists is simply not found
        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    private function query(string $body): array
    {
        return $this->http->request('POST', self::API . '/games', [
            'headers' => ['Client-ID' => $this->clientId],
            'auth_bearer' => $this->token(),
            'body' => $body,
            'timeout' => 10,
        ])->toArray();
    }

    private function token(): string
    {
        return $this->cache->get('igdb_token', function (ItemInterface $item): string {
            $data = $this->http->request('POST', self::TOKEN_URL, ['query' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ], 'timeout' => 10])->toArray();
            $item->expiresAfter(max(60, (int) ($data['expires_in'] ?? 0) - 300));

            return $data['access_token'];
        });
    }

    private function normalize(array $game): array
    {
        $platforms = array_values(array_filter(array_map(static fn (array $p) => $p['abbreviation'] ?? $p['name'] ?? null, $game['platforms'] ?? [])));

        return [
            'externalId' => 'game:' . $game['id'],
            'name' => mb_substr($game['name'] ?? '', 0, 255),
            'attributes' => [
                'type' => 'game',
                'year' => isset($game['first_release_date']) ? gmdate('Y', $game['first_release_date']) : null,
                'overview' => ($game['summary'] ?? '') ?: null,
                'poster' => isset($game['cover']['image_id']) ? self::IMAGE . $game['cover']['image_id'] . '.jpg' : null,
                'platforms' => $platforms ?: null,
            ],
        ];
    }
}
