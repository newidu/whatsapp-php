<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/** One session entry inside a SessionRecord (libsignal SessionEntry). */
final class SessionEntry
{
    public ?int $registrationId = null;
    /** @var array{ephemeralKeyPair:array{pubKey:string,privKey:string},lastRemoteEphemeralKey:string,previousCounter:int,rootKey:string} */
    public array $currentRatchet = [];
    /** @var array{baseKey:string,baseKeyType:int,closed:int,used:int,created:int,remoteIdentityKey:string} */
    public array $indexInfo = [];
    /** @var array<string,Chain> base64(pubKey) => Chain */
    public array $chains = [];
    /** @var null|array{signedKeyId:int,baseKey:string,preKeyId?:int} */
    public ?array $pendingPreKey = null;

    public const BASEKEY_OURS = 1;
    public const BASEKEY_THEIRS = 2;

    public function addChain(string $key, Chain $chain): void
    {
        $id = base64_encode($key);
        if (isset($this->chains[$id])) {
            throw new \RuntimeException('Overwrite attempt');
        }
        $this->chains[$id] = $chain;
    }

    public function getChain(string $key): ?Chain
    {
        return $this->chains[base64_encode($key)] ?? null;
    }

    public function deleteChain(string $key): void
    {
        $id = base64_encode($key);
        if (!isset($this->chains[$id])) {
            throw new \RuntimeException('Not Found');
        }
        unset($this->chains[$id]);
    }

    /** @return array<string,mixed> */
    public function serialize(): array
    {
        $chains = [];
        foreach ($this->chains as $id => $c) {
            $mks = [];
            foreach ($c->messageKeys as $idx => $k) {
                $mks[(string)$idx] = base64_encode($k);
            }
            $chains[$id] = [
                'chainKey' => [
                    'counter' => $c->counter,
                    'key' => $c->key !== null ? base64_encode($c->key) : null,
                ],
                'chainType' => $c->chainType,
                'messageKeys' => $mks,
            ];
        }
        $data = [
            'registrationId' => $this->registrationId,
            'currentRatchet' => [
                'ephemeralKeyPair' => [
                    'pubKey' => base64_encode($this->currentRatchet['ephemeralKeyPair']['pubKey']),
                    'privKey' => base64_encode($this->currentRatchet['ephemeralKeyPair']['privKey']),
                ],
                'lastRemoteEphemeralKey' => base64_encode($this->currentRatchet['lastRemoteEphemeralKey']),
                'previousCounter' => $this->currentRatchet['previousCounter'],
                'rootKey' => base64_encode($this->currentRatchet['rootKey']),
            ],
            'indexInfo' => [
                'baseKey' => base64_encode($this->indexInfo['baseKey']),
                'baseKeyType' => $this->indexInfo['baseKeyType'],
                'closed' => $this->indexInfo['closed'],
                'used' => $this->indexInfo['used'],
                'created' => $this->indexInfo['created'],
                'remoteIdentityKey' => base64_encode($this->indexInfo['remoteIdentityKey']),
            ],
            '_chains' => $chains,
        ];
        if ($this->pendingPreKey !== null) {
            $p = $this->pendingPreKey;
            $p['baseKey'] = base64_encode($p['baseKey']);
            $data['pendingPreKey'] = $p;
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function deserialize(array $data): self
    {
        $o = new self();
        $o->registrationId = $data['registrationId'] ?? null;
        $o->currentRatchet = [
            'ephemeralKeyPair' => [
                'pubKey' => base64_decode($data['currentRatchet']['ephemeralKeyPair']['pubKey']),
                'privKey' => base64_decode($data['currentRatchet']['ephemeralKeyPair']['privKey']),
            ],
            'lastRemoteEphemeralKey' => base64_decode($data['currentRatchet']['lastRemoteEphemeralKey']),
            'previousCounter' => (int)$data['currentRatchet']['previousCounter'],
            'rootKey' => base64_decode($data['currentRatchet']['rootKey']),
        ];
        $o->indexInfo = [
            'baseKey' => base64_decode($data['indexInfo']['baseKey']),
            'baseKeyType' => (int)$data['indexInfo']['baseKeyType'],
            'closed' => (int)$data['indexInfo']['closed'],
            'used' => (int)($data['indexInfo']['used'] ?? 0),
            'created' => (int)($data['indexInfo']['created'] ?? 0),
            'remoteIdentityKey' => base64_decode($data['indexInfo']['remoteIdentityKey']),
        ];
        foreach (($data['_chains'] ?? []) as $id => $c) {
            $mks = [];
            foreach (($c['messageKeys'] ?? []) as $idx => $k) {
                $mks[(int)$idx] = base64_decode($k);
            }
            $o->chains[$id] = new Chain(
                (int)$c['chainType'],
                (int)$c['chainKey']['counter'],
                isset($c['chainKey']['key']) && $c['chainKey']['key'] !== null ? base64_decode($c['chainKey']['key']) : null,
                $mks
            );
        }
        if (!empty($data['pendingPreKey'])) {
            $p = $data['pendingPreKey'];
            $p['baseKey'] = base64_decode($p['baseKey']);
            $o->pendingPreKey = $p;
        }
        return $o;
    }
}

