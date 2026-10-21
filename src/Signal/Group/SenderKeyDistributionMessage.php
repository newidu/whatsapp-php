<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Proto\WAProto;

/** Port of Baileys src/Signal/Group/sender-key-distribution-message.ts (type 5). */
final class SenderKeyDistributionMessage
{
    private const CURRENT_VERSION = 3;

    private int $id;
    private int $iteration;
    private string $chainKey;
    private string $signatureKey;
    private string $serialized;

    public function __construct(
        ?int $id = null,
        ?int $iteration = null,
        ?string $chainKey = null,
        ?string $signatureKey = null,
        ?string $serialized = null,
    ) {
        if ($serialized !== null) {
            $message = substr($serialized, 1);
            $decoded = WAProto::decodeProto('SenderKeyDistributionMessage', $message);
            $this->serialized = $serialized;
            $this->id = (int)($decoded['id'] ?? 0);
            $this->iteration = (int)($decoded['iteration'] ?? 0);
            $this->chainKey = (string)($decoded['chainKey'] ?? '');
            $this->signatureKey = (string)($decoded['signingKey'] ?? '');
        } else {
            $version = ((self::CURRENT_VERSION << 4) | self::CURRENT_VERSION) & 0xff;
            $this->id = (int)$id;
            $this->iteration = (int)$iteration;
            $this->chainKey = (string)$chainKey;
            $this->signatureKey = (string)$signatureKey;
            $message = WAProto::encodeProto('SenderKeyDistributionMessage', [
                'id' => $id,
                'iteration' => $iteration,
                'chainKey' => $chainKey,
                'signingKey' => $signatureKey,
            ]);
            $this->serialized = chr($version) . $message;
        }
    }

    public function serialize(): string
    {
        return $this->serialized;
    }

    public function getType(): int
    {
        return 5;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getChainKey(): string
    {
        return $this->chainKey;
    }

    public function getSignatureKey(): string
    {
        return $this->signatureKey;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
