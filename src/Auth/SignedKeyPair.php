<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/** Baileys SignedKeyPair: { keyPair: KeyPair, signature: Buffer, keyId: number } */
final class SignedKeyPair
{
    public function __construct(
        public KeyPair $keyPair,
        public string $signature,
        public int $keyId,
    ) {}
}
