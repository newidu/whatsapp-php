<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/** In-memory store (useful for tests, and as the cache layer). */
final class InMemorySignalKeyStore implements SignalKeyStore
{
    /** @var array<string,array<string,mixed>> */
    public array $data = [];
    private bool $inTx = false;

    public function get(string $type, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $this->data[$type] ?? [])) {
                $out[$id] = $this->data[$type][$id];
            }
        }
        return $out;
    }

    public function set(array $data): void
    {
        foreach ($data as $type => $items) {
            foreach ($items as $id => $value) {
                if ($value === null) {
                    unset($this->data[$type][$id]);
                } else {
                    $this->data[$type][$id] = $value;
                }
            }
        }
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function isInTransaction(): bool
    {
        return $this->inTx;
    }

    public function transaction(callable $work, string $key): mixed
    {
        if ($this->inTx) {
            return $work();
        }
        $this->inTx = true;
        try {
            return $work();
        } finally {
            $this->inTx = false;
        }
    }
}
