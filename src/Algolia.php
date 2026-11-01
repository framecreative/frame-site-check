<?php

namespace Frame\Testbed;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Algolia's REST API directly, so it works whichever Algolia client version a site has installed.
 */
class Algolia
{
    public function __construct(protected string $appId, protected string $key)
    {
    }

    public static function admin(): self
    {
        return new self((string) config('scout.algolia.id'), (string) config('scout.algolia.secret'));
    }

    public static function search(): self
    {
        return new self((string) config('scout.algolia.id'), (string) config('scout.algolia.search'));
    }

    public function listIndexes(): Response
    {
        return $this->request('read', fn ($http) => $http->get('/1/indexes'));
    }

    /** @return array<int, string> */
    public function indexNames(): array
    {
        $names = [];
        $page = 0;

        do {
            $items = $this->request('read', fn ($http) => $http->get('/1/indexes', ['page' => $page]))->throw()->json('items', []);
            $names = array_merge($names, array_column($items, 'name'));
            $page++;
        } while (count($items) > 0);

        return $names;
    }

    public function addObject(string $index, array $object): Response
    {
        return $this->request('write', fn ($http) => $http->post('/1/indexes/'.rawurlencode($index), $object));
    }

    public function deleteIndex(string $index): Response
    {
        return $this->request('write', fn ($http) => $http->delete('/1/indexes/'.rawurlencode($index)));
    }

    /**
     * Tries Algolia's hosts in turn, as its own clients do: the primary host's DNS can fail intermittently
     * inside a container while the fallback hosts resolve fine.
     */
    protected function request(string $type, callable $send): Response
    {
        $primary = $type === 'read' ? "{$this->appId}-dsn.algolia.net" : "{$this->appId}.algolia.net";
        $hosts = [$primary, "{$this->appId}-1.algolianet.com", "{$this->appId}-2.algolianet.com", "{$this->appId}-3.algolianet.com"];

        foreach ($hosts as $index => $host) {
            try {
                return $send($this->client("https://{$host}"));
            } catch (ConnectionException $e) {
                if ($index === count($hosts) - 1) {
                    throw $e;
                }
            }
        }
    }

    protected function client(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withHeaders([
                'X-Algolia-Application-Id' => $this->appId,
                'X-Algolia-API-Key' => $this->key,
            ])
            ->acceptJson()
            ->timeout(15);
    }
}
