<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/** Port of libsignal ProtocolAddress. String form: "{id}.{deviceId}" */
final class ProtocolAddress
{
    public function __construct(public string $id, public int $deviceId)
    {
        if (str_contains($id, '.')) {
            // NOTE: LID-mapped ids in Baileys can be "user_1" etc. — plain user ids have no dot.
            // Keep the libsignal guard only for already-encoded "id.device" strings with numeric suffix.
            if (preg_match('/\.\d+$/', $id)) {
                throw new \InvalidArgumentException('encoded addr detected');
            }
        }
    }

    public static function from(string $encoded): self
    {
        if (!preg_match('/.*\.\d+$/', $encoded)) {
            throw new \RuntimeException('Invalid address encoding');
        }
        $pos = strrpos($encoded, '.');
        return new self(substr($encoded, 0, $pos), (int)substr($encoded, $pos + 1));
    }

    public function toString(): string
    {
        return "{$this->id}.{$this->deviceId}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
