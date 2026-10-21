<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

/**
 * Port of Baileys src/Signal/Group/sender-key-record.ts
 *
 * Wire/storage format (matches Baileys): a UTF-8 JSON string of the state
 * structure list, binary fields as {type:'Buffer',data:base64|[ints]}.
 * The SignalKeyStore then stores those bytes under key type 'sender-key'.
 */
final class SenderKeyRecord
{
    private const MAX_STATES = 5;

    /** @var list<SenderKeyState> */
    private array $senderKeyStates = [];

    /** @param null|list<array> $serialized structures (see SenderKeyState) */
    public function __construct(?array $serialized = null)
    {
        if ($serialized !== null) {
            foreach ($serialized as $structure) {
                $this->senderKeyStates[] = new SenderKeyState(structure: $structure);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->senderKeyStates === [];
    }

    public function getSenderKeyState(?int $keyId = null): ?SenderKeyState
    {
        if ($keyId === null && $this->senderKeyStates !== []) {
            return $this->senderKeyStates[count($this->senderKeyStates) - 1];
        }
        foreach ($this->senderKeyStates as $state) {
            if ($state->getKeyId() === $keyId) {
                return $state;
            }
        }
        return null;
    }

    public function addSenderKeyState(int $id, int $iteration, string $chainKey, string $signatureKey): void
    {
        $this->senderKeyStates[] = new SenderKeyState($id, $iteration, $chainKey, null, $signatureKey);
        if (count($this->senderKeyStates) > self::MAX_STATES) {
            array_shift($this->senderKeyStates);
        }
    }

    /** @param array{public:string,private:string} $keyPair */
    public function setSenderKeyState(int $id, int $iteration, string $chainKey, array $keyPair): void
    {
        $this->senderKeyStates = [new SenderKeyState($id, $iteration, $chainKey, $keyPair)];
    }

    /** @return list<array> */
    public function serialize(): array
    {
        return array_map(fn(SenderKeyState $s) => $s->getStructure(), $this->senderKeyStates);
    }

    /** Serialize to the exact bytes Baileys stores (JSON + BufferJSON fields). */
    public function serializeToBytes(): string
    {
        $encode = function (string $bin): array {
            return ['type' => 'Buffer', 'data' => base64_encode($bin)];
        };
        $out = [];
        foreach ($this->serialize() as $st) {
            $out[] = [
                'senderKeyId' => $st['senderKeyId'],
                'senderChainKey' => [
                    'iteration' => $st['senderChainKey']['iteration'],
                    'seed' => $encode($st['senderChainKey']['seed']),
                ],
                'senderSigningKey' => [
                    'public' => $encode($st['senderSigningKey']['public']),
                    'private' => $encode($st['senderSigningKey']['private'] ?? ''),
                ],
                'senderMessageKeys' => array_map(fn($k) => [
                    'iteration' => $k['iteration'],
                    'seed' => $encode($k['seed']),
                ], $st['senderMessageKeys']),
            ];
        }
        return json_encode($out);
    }

    public static function deserialize(string $data): self
    {
        $parsed = json_decode($data, true) ?? [];
        $decode = function (mixed $v): string {
            if (is_string($v)) {
                return $v;
            }
            if (is_array($v) && ($v['type'] ?? '') === 'Buffer') {
                $d = $v['data'] ?? '';
                if (is_string($d)) {
                    return base64_decode($d, true) ?: '';
                }
                if (is_array($d)) { // legacy number[]
                    return implode('', array_map('chr', $d));
                }
            }
            if (is_array($v)) { // Uint8Array-as-object {"0":..,"1":..}
                ksort($v);
                return implode('', array_map('chr', array_values($v)));
            }
            return '';
        };
        $structures = [];
        foreach ((array)$parsed as $st) {
            $structures[] = [
                'senderKeyId' => (int)($st['senderKeyId'] ?? 0),
                'senderChainKey' => [
                    'iteration' => (int)($st['senderChainKey']['iteration'] ?? 0),
                    'seed' => $decode($st['senderChainKey']['seed'] ?? ''),
                ],
                'senderSigningKey' => [
                    'public' => $decode($st['senderSigningKey']['public'] ?? ''),
                    'private' => $decode($st['senderSigningKey']['private'] ?? ''),
                ],
                'senderMessageKeys' => array_map(fn($k) => [
                    'iteration' => (int)($k['iteration'] ?? 0),
                    'seed' => $decode($k['seed'] ?? ''),
                ], $st['senderMessageKeys'] ?? []),
            ];
        }
        return new self($structures);
    }
}
