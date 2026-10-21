<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/** A Double-Ratchet chain (libsignal chain object). */
final class Chain
{
    /** @param array<int,string> $messageKeys counter => 32-byte message key */
    public function __construct(
        public int $chainType,           // 1=SENDING, 2=RECEIVING
        public int $counter = -1,        // chainKey.counter
        public ?string $key = null,      // chainKey.key (32 bytes) — null when closed
        public array $messageKeys = [],
    ) {}

    public const SENDING = 1;
    public const RECEIVING = 2;
}

