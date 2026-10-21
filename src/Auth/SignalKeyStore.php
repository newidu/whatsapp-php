<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/**
 * Port of Baileys SignalKeyStore (+ transaction capability).
 *
 * Value types per category (SignalDataTypeMap):
 *   'pre-key'            => KeyPair
 *   'session'            => string (bytes)
 *   'sender-key'         => string (bytes)
 *   'identity-key'       => string (bytes, 33B prefixed pubkey)
 *   'device-list'        => list<string>
 *   'lid-mapping'        => string
 *   'sender-key-memory'  => array<string,bool>
 *   'tctoken'            => array (kept as-is / JSON)
 *   'app-state-sync-key' / 'app-state-sync-version' => array (Basic: unused)
 *
 * PHP is single-threaded per CLI process, so transaction() simply runs the
 * callback (there is no interleaving to guard against). This matches the
 * observable behaviour Baileys code relies on.
 */
interface SignalKeyStore
{
    /**
     * @param list<string> $ids
     * @return array<string,mixed> id => value (missing ids absent, like the file store returning undefined)
     */
    public function get(string $type, array $ids): array;

    /** @param array<string,array<string,mixed>> $data type => (id => value|null) ; null deletes */
    public function set(array $data): void;

    public function clear(): void;

    public function isInTransaction(): bool;

    /** @template T @param callable():T $work @return T */
    public function transaction(callable $work, string $key): mixed;
}
