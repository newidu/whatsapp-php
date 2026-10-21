<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Proto\WAProto;
use BaileysPHP\Utils\Curve;

/** Port of Baileys src/Signal/Group/sender-key-message.ts (type 4, version 3). */
final class SenderKeyMessage
{
    private const SIGNATURE_LENGTH = 64;
    private const CURRENT_VERSION = 3;

    private int $messageVersion;
    private int $keyId;
    private int $iteration;
    private string $ciphertext;
    private string $signature;
    private string $serialized;

    public function __construct(
        ?int $keyId = null,
        ?int $iteration = null,
        ?string $ciphertext = null,
        ?string $signatureKey = null,
        ?string $serialized = null,
    ) {
        if ($serialized !== null) {
            $version = ord($serialized[0]);
            $message = substr($serialized, 1, strlen($serialized) - 1 - self::SIGNATURE_LENGTH);
            $decoded = WAProto::decodeProto('SenderKeyMessage', $message);
            $this->serialized = $serialized;
            $this->messageVersion = ($version & 0xff) >> 4;
            $this->keyId = (int)($decoded['id'] ?? 0);
            $this->iteration = (int)($decoded['iteration'] ?? 0);
            $this->ciphertext = (string)($decoded['ciphertext'] ?? '');
            $this->signature = substr($serialized, -self::SIGNATURE_LENGTH);
        } else {
            $version = ((self::CURRENT_VERSION << 4) | self::CURRENT_VERSION) & 0xff;
            $message = WAProto::encodeProto('SenderKeyMessage', [
                'id' => $keyId,
                'iteration' => $iteration,
                'ciphertext' => $ciphertext,
            ]);
            $signature = Curve::sign((string)$signatureKey, chr($version) . $message);
            $this->serialized = chr($version) . $message . $signature;
            $this->messageVersion = self::CURRENT_VERSION;
            $this->keyId = (int)$keyId;
            $this->iteration = (int)$iteration;
            $this->ciphertext = (string)$ciphertext;
            $this->signature = $signature;
        }
    }

    public function getKeyId(): int
    {
        return $this->keyId;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getCipherText(): string
    {
        return $this->ciphertext;
    }

    public function verifySignature(string $signatureKey): void
    {
        $part1 = substr($this->serialized, 0, strlen($this->serialized) - self::SIGNATURE_LENGTH);
        $part2 = substr($this->serialized, -self::SIGNATURE_LENGTH);
        if (!Curve::verify($signatureKey, $part1, $part2)) {
            throw new \RuntimeException('Invalid signature!');
        }
    }

    public function serialize(): string
    {
        return $this->serialized;
    }

    public function getType(): int
    {
        return 4;
    }
}
