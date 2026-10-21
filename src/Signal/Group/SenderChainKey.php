<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Signal\SignalCrypto;

/** Port of Baileys src/Signal/Group/sender-chain-key.ts */
final class SenderChainKey
{
    public function __construct(
        private int $iteration,
        private string $chainKey,
    ) {}

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getSenderMessageKey(): SenderMessageKey
    {
        return new SenderMessageKey($this->iteration, SignalCrypto::calculateMAC($this->chainKey, "\x01"));
    }

    public function getNext(): self
    {
        return new self($this->iteration + 1, SignalCrypto::calculateMAC($this->chainKey, "\x02"));
    }

    public function getSeed(): string
    {
        return $this->chainKey;
    }
}
