<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use function BaileysPHP\Utils\generateSignalPubKey;

/**
 * Port of Baileys src/Signal/Group/sender-key-state.ts
 * Structure mirrors the TS SenderKeyStateStructure:
 * { senderKeyId, senderChainKey{iteration,seed}, senderSigningKey{public,private}, senderMessageKeys[[iteration,seed]...] }
 */
final class SenderKeyState
{
    private const MAX_MESSAGE_KEYS = 2000;

    /** @var array{senderKeyId:int,senderChainKey:array{iteration:int,seed:string},senderSigningKey:array{public:string,private:string},senderMessageKeys:list<array{iteration:int,seed:string}>} */
    private array $structure;

    /** @param null|array{public:string,private:string} $signingKeyPair */
    public function __construct(
        ?int $id = null,
        ?int $iteration = null,
        ?string $chainKey = null,
        ?array $signingKeyPair = null,
        ?string $signingKeyPublic = null,
        ?string $signingKeyPrivate = null,
        ?array $structure = null,
    ) {
        if ($structure !== null) {
            $structure['senderMessageKeys'] = is_array($structure['senderMessageKeys'] ?? null)
                ? $structure['senderMessageKeys'] : [];
            $this->structure = $structure;
            return;
        }
        if ($signingKeyPair !== null) {
            $signingKeyPublic = $signingKeyPair['public'];
            $signingKeyPrivate = $signingKeyPair['private'];
        }
        $this->structure = [
            'senderKeyId' => $id ?? 0,
            'senderChainKey' => ['iteration' => $iteration ?? 0, 'seed' => $chainKey ?? ''],
            'senderSigningKey' => ['public' => $signingKeyPublic ?? '', 'private' => $signingKeyPrivate ?? ''],
            'senderMessageKeys' => [],
        ];
    }

    public function getKeyId(): int
    {
        return $this->structure['senderKeyId'];
    }

    public function getSenderChainKey(): SenderChainKey
    {
        return new SenderChainKey(
            $this->structure['senderChainKey']['iteration'],
            $this->structure['senderChainKey']['seed']
        );
    }

    public function setSenderChainKey(SenderChainKey $chainKey): void
    {
        $this->structure['senderChainKey'] = [
            'iteration' => $chainKey->getIteration(),
            'seed' => $chainKey->getSeed(),
        ];
    }

    public function getSigningKeyPublic(): string
    {
        $pub = $this->structure['senderSigningKey']['public'];
        return strlen($pub) === 32 ? generateSignalPubKey($pub) : $pub;
    }

    public function getSigningKeyPrivate(): string
    {
        return $this->structure['senderSigningKey']['private'] ?? '';
    }

    public function hasSenderMessageKey(int $iteration): bool
    {
        foreach ($this->structure['senderMessageKeys'] as $k) {
            if ($k['iteration'] === $iteration) {
                return true;
            }
        }
        return false;
    }

    public function addSenderMessageKey(SenderMessageKey $key): void
    {
        $this->structure['senderMessageKeys'][] = [
            'iteration' => $key->getIteration(),
            'seed' => $key->getSeed(),
        ];
        if (count($this->structure['senderMessageKeys']) > self::MAX_MESSAGE_KEYS) {
            array_shift($this->structure['senderMessageKeys']);
        }
    }

    public function removeSenderMessageKey(int $iteration): ?SenderMessageKey
    {
        foreach ($this->structure['senderMessageKeys'] as $i => $k) {
            if ($k['iteration'] === $iteration) {
                array_splice($this->structure['senderMessageKeys'], $i, 1);
                return new SenderMessageKey($k['iteration'], $k['seed']);
            }
        }
        return null;
    }

    /** @return array{senderKeyId:int,senderChainKey:array{iteration:int,seed:string},senderSigningKey:array{public:string,private:string},senderMessageKeys:list<array{iteration:int,seed:string}>} */
    public function getStructure(): array
    {
        return $this->structure;
    }
}
