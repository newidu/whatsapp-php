<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Signal\SignalCrypto;

/** Port of Baileys src/Signal/Group/sender-message-key.ts */
final class SenderMessageKey
{
    private string $iv;
    private string $cipherKey;

    public function __construct(
        private int $iteration,
        private string $seed,
    ) {
        $derivative = SignalCrypto::deriveSecrets($seed, str_repeat("\0", 32), 'WhisperGroup');
        $keys = substr($derivative[0], 16) . substr($derivative[1], 0, 16);
        $this->iv = substr($derivative[0], 0, 16);
        $this->cipherKey = $keys;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getIv(): string
    {
        return $this->iv;
    }

    public function getCipherKey(): string
    {
        return $this->cipherKey;
    }

    public function getSeed(): string
    {
        return $this->seed;
    }
}
