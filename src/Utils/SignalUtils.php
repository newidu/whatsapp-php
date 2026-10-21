<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Auth\AuthState;
use BaileysPHP\Auth\Creds;
use BaileysPHP\Auth\KeyPair;
use BaileysPHP\Auth\SignedKeyPair;
use BaileysPHP\Defaults;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;

/** Port of (Basic parts of) Baileys: src/Utils/signal.ts */
final class SignalUtils
{
    /** @return array{identifier:array{name:string,deviceId:int},identifierKey:string} */
    public static function createSignalIdentity(string $wid, string $accountSignatureKey): array
    {
        return [
            'identifier' => ['name' => $wid, 'deviceId' => 0],
            'identifierKey' => generateSignalPubKey($accountSignatureKey),
        ];
    }

    /** @return array<string,KeyPair> */
    public static function getPreKeys(AuthState $state, int $min, int $limit): array
    {
        $ids = [];
        for ($id = $min; $id < $limit; $id++) {
            $ids[] = (string)$id;
        }
        return $state->keys->get('pre-key', $ids);
    }

    /**
     * @return array{newPreKeys:array<int,KeyPair>,lastPreKeyId:int,preKeysRange:array{0:int,1:int}}
     */
    public static function generateOrGetPreKeys(Creds $creds, int $range): array
    {
        $available = $creds->nextPreKeyId - $creds->firstUnuploadedPreKeyId;
        $remaining = $range - $available;
        $lastPreKeyId = $creds->nextPreKeyId + $remaining - 1;
        $newPreKeys = [];
        if ($remaining > 0) {
            for ($i = $creds->nextPreKeyId; $i <= $lastPreKeyId; $i++) {
                $newPreKeys[$i] = Curve::generateKeyPair();
            }
        }
        return [
            'newPreKeys' => $newPreKeys,
            'lastPreKeyId' => $lastPreKeyId,
            'preKeysRange' => [$creds->firstUnuploadedPreKeyId, $range],
        ];
    }

    public static function xmppSignedPreKey(SignedKeyPair $key): BinaryNode
    {
        return new BinaryNode('skey', [], [
            new BinaryNode('id', [], new Bytes(Generics::encodeBigEndian($key->keyId, 3))),
            new BinaryNode('value', [], new Bytes($key->keyPair->public)),
            new BinaryNode('signature', [], new Bytes($key->signature)),
        ]);
    }

    public static function xmppPreKey(KeyPair $pair, int $id): BinaryNode
    {
        return new BinaryNode('key', [], [
            new BinaryNode('id', [], new Bytes(Generics::encodeBigEndian($id, 3))),
            new BinaryNode('value', [], new Bytes($pair->public)),
        ]);
    }

    /**
     * getNextPreKeys(state, count)
     * @return array{update:array<string,int>,preKeys:array<string,KeyPair>}
     */
    public static function getNextPreKeys(AuthState $state, int $count): array
    {
        ['newPreKeys' => $newPreKeys, 'lastPreKeyId' => $lastPreKeyId, 'preKeysRange' => $range] =
            self::generateOrGetPreKeys($state->creds, $count);
        $update = [
            'nextPreKeyId' => max($lastPreKeyId + 1, $state->creds->nextPreKeyId),
            'firstUnuploadedPreKeyId' => max($state->creds->firstUnuploadedPreKeyId, $lastPreKeyId + 1),
        ];
        if ($newPreKeys !== []) {
            $state->keys->set(['pre-key' => $newPreKeys]);
        }
        $preKeys = self::getPreKeys($state, $range[0], $range[0] + $range[1]);
        return ['update' => $update, 'preKeys' => $preKeys];
    }

    /**
     * getNextPreKeysNode(state, count)
     * @return array{update:array<string,int>,node:BinaryNode}
     */
    public static function getNextPreKeysNode(AuthState $state, int $count): array
    {
        ['update' => $update, 'preKeys' => $preKeys] = self::getNextPreKeys($state, $count);
        $creds = $state->creds;
        $list = [];
        foreach ($preKeys as $k => $pair) {
            $list[] = self::xmppPreKey($pair, (int)$k);
        }
        $node = new BinaryNode('iq', ['xmlns' => 'encrypt', 'type' => 'set', 'to' => 's.whatsapp.net'], [
            new BinaryNode('registration', [], new Bytes(Generics::encodeBigEndian($creds->registrationId))),
            new BinaryNode('type', [], new Bytes(Defaults::KEY_BUNDLE_TYPE)),
            new BinaryNode('identity', [], new Bytes($creds->signedIdentityKey->public)),
            new BinaryNode('list', [], $list),
            self::xmppSignedPreKey($creds->signedPreKey),
        ]);
        return ['update' => $update, 'node' => $node];
    }
}
