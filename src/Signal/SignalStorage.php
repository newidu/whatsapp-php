<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/**
 * The storage interface libsignal's Session* classes use (SignalStorage in JS),
 * implemented by SignalRepository over the Baileys auth state.
 * Key material here follows libsignal shapes: ['privKey'=>32B,'pubKey'=>key]
 * where pubKey may be 32B or 33B-prefixed depending on the loader (see repository).
 */
interface SignalStorage
{
    public function loadSession(string $id): ?SessionRecord;

    public function storeSession(string $id, SessionRecord $record): void;

    /** TOFU — always true in Baileys. */
    public function isTrustedIdentity(string $id, string $identityKey): bool;

    /** @return null|array{privKey:string,pubKey:string} */
    public function loadPreKey(int|string $id): ?array;

    public function removePreKey(int $id): void;

    /** @return array{privKey:string,pubKey:string} */
    public function loadSignedPreKey(): array;

    public function getOurRegistrationId(): int;

    /** @return array{privKey:string,pubKey:string} pubKey is 33-byte prefixed */
    public function getOurIdentity(): array;
}
