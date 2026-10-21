<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

use BaileysPHP\Utils\Logger;

/**
 * Port of Baileys makeCacheableSignalKeyStore (Utils/auth-utils.ts):
 * wraps another SignalKeyStore with an in-memory TTL cache (5 minutes),
 * which speeds up the multi-file store considerably.
 *
 * Usage:
 *   $auth = useMultiFileAuthState('auth');
 *   $auth->state = new AuthState($auth->state->creds, makeCacheableSignalKeyStore($auth->state->keys));
 */
final class CacheableSignalKeyStore implements SignalKeyStore
{
    /** @var array<string,array{value:mixed,expires:float}> */
    private array $cache = [];

    public function __construct(
        private SignalKeyStore $store,
        private ?Logger $logger = null,
        private int $ttlSeconds = 300,
    ) {}

    private function key(string $type, string $id): string
    {
        return $type . '.' . $id;
    }

    public function get(string $type, array $ids): array
    {
        $data = [];
        $idsToFetch = [];
        $now = microtime(true);
        foreach ($ids as $id) {
            $k = $this->key($type, $id);
            $hit = $this->cache[$k] ?? null;
            if ($hit !== null && $hit['expires'] > $now) {
                if ($hit['value'] !== null) {
                    $data[$id] = $hit['value'];
                }
            } else {
                if ($hit !== null) {
                    unset($this->cache[$k]);
                }
                $idsToFetch[] = $id;
            }
        }
        if ($idsToFetch !== []) {
            $this->logger?->trace(['items' => count($idsToFetch)], 'loading from store');
            $fetched = $this->store->get($type, $idsToFetch);
            foreach ($idsToFetch as $id) {
                if (isset($fetched[$id])) {
                    $data[$id] = $fetched[$id];
                    $this->cache[$this->key($type, $id)] = [
                        'value' => $fetched[$id],
                        'expires' => microtime(true) + $this->ttlSeconds,
                    ];
                }
            }
        }
        return $data;
    }

    public function set(array $data): void
    {
        $keys = 0;
        foreach ($data as $type => $entries) {
            foreach ($entries as $id => $value) {
                $k = $this->key((string)$type, (string)$id);
                if ($value === null) {
                    unset($this->cache[$k]);
                } else {
                    $this->cache[$k] = ['value' => $value, 'expires' => microtime(true) + $this->ttlSeconds];
                }
                $keys++;
            }
        }
        $this->logger?->trace(['keys' => $keys], 'updated cache');
        $this->store->set($data);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->store->clear();
    }

    public function isInTransaction(): bool
    {
        return $this->store->isInTransaction();
    }

    public function transaction(callable $work, string $key): mixed
    {
        return $this->store->transaction($work, $key);
    }
}
