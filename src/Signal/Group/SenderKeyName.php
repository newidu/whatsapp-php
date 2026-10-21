<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Signal\ProtocolAddress;

/** Port of Baileys src/Signal/Group/sender-key-name.ts */
final class SenderKeyName
{
    public function __construct(
        public readonly string $groupId,
        public readonly ProtocolAddress $sender,
    ) {}

    public function serialize(): string
    {
        return "{$this->groupId}::{$this->sender->id}::{$this->sender->deviceId}";
    }

    public function toString(): string
    {
        return $this->serialize();
    }

    public function __toString(): string
    {
        return $this->serialize();
    }

    public function equals(?SenderKeyName $other): bool
    {
        return $other !== null && $this->groupId === $other->groupId
            && $this->sender->toString() === $other->sender->toString();
    }
}
