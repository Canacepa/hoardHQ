<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks up board games on BoardGameGeek (XML API2) and returns them in the app's own shape (same as TmdbProvider and
 * IgdbProvider): "boardgame:<id>". Expansions are not included.
 *
 * BGG's search only returns names and years, so a search is two requests: the search, then one "thing" request for the
 * first matches to get their cover and details. Requests carry a bearer token of a registered BGG application.
 *
 * HTTP errors are not caught here: callers handle Symfony\Contracts\HttpClient\Exception\ExceptionInterface.
 * shortcut: no caching, BGG allows about one request per second per client; add a cache if searches get rate limited.
 */
final class BggProvider
{
    public const SOURCE = 'bgg';

    private const API = 'https://boardgamegeek.com/xmlapi2'; // not www., BGG asks clients to leave it out
    private const LIMIT = 20;

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'BGG_API_TOKEN')] private string $token,
    ) {
    }

    /**
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function search(string $query, ?string $type = null): array
    {
        $found = $this->xml('/search', ['query' => $query, 'type' => 'boardgame']);
        $ids = [];
        foreach ($found->item as $item) {
            $ids[] = (int) $item['id'];
        }

        return $this->things(array_slice(array_unique($ids), 0, self::LIMIT));
    }

    /**
     * Loads games by "boardgame:<id>" (anything else is skipped), in the order asked, each once, in a single request.
     *
     * @param string[] $externalIds
     * @return list<array{externalId: string, name: string, attributes: array<string, mixed>}>
     */
    public function fetchMany(array $externalIds, ?string $type = null): array
    {
        $ids = [];
        foreach (array_unique($externalIds) as $externalId) {
            if (preg_match('/^boardgame:(\d+)$/', $externalId, $m)) {
                $ids[] = (int) $m[1];
            }
        }

        return $this->things(array_slice($ids, 0, self::LIMIT));
    }

    /** @param int[] $ids */
    private function things(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $byId = [];
        foreach ($this->xml('/thing', ['id' => implode(',', $ids)])->item as $item) {
            $byId[(int) $item['id']] = $this->normalize($item);
        }

        // a game BGG no longer lists is simply not found
        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    private function xml(string $path, array $query): \SimpleXMLElement
    {
        $body = $this->http->request('GET', self::API . $path, ['auth_bearer' => $this->token, 'query' => $query, 'timeout' => 10])->getContent();

        return @simplexml_load_string($body) ?: throw new TransportException('BGG returned something that is not XML.');
    }

    private function normalize(\SimpleXMLElement $item): array
    {
        $name = '';
        foreach ($item->name as $n) {
            if ((string) $n['type'] === 'primary') {
                $name = (string) $n['value'];
                break;
            }
        }
        $year = (int) $item->yearpublished['value'];
        $min = (int) $item->minplayers['value'];
        $max = (int) $item->maxplayers['value'];
        $minutes = (int) $item->playingtime['value'];
        // the description is HTML written as entities, which XML decoding leaves once more encoded
        $overview = trim(html_entity_decode(strip_tags((string) $item->description), ENT_QUOTES | ENT_HTML5));
        $poster = (string) $item->thumbnail ?: (string) $item->image;

        return [
            'externalId' => 'boardgame:' . (int) $item['id'],
            'name' => mb_substr($name, 0, 255),
            'attributes' => [
                'type' => 'boardgame',
                'year' => $year > 0 ? (string) $year : null,
                'overview' => $overview ?: null,
                'poster' => trim($poster) ?: null,
                'players' => $max > 0 ? ($min > 0 && $min !== $max ? "{$min}–{$max}" : (string) $max) : null,
                'minutes' => $minutes > 0 ? $minutes : null,
            ],
        ];
    }
}
