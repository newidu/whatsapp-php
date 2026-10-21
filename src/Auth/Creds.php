<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

use BaileysPHP\Utils\Curve;
use BaileysPHP\Utils\Generics;
use function BaileysPHP\Utils\signedKeyPair;

/**
 * Port of Baileys AuthenticationCreds + initAuthCreds() (src/Utils/auth-utils.ts).
 * Serializes to creds.json in Baileys' BufferJSON format so the file structure
 * looks familiar:  {"type":"Buffer","data":"<base64>"}
 */
final class Creds
{
    public KeyPair $noiseKey;
    public KeyPair $pairingEphemeralKeyPair;
    public KeyPair $signedIdentityKey;
    public SignedKeyPair $signedPreKey;
    public int $registrationId;
    /** base64 string, exactly like Baileys */
    public string $advSecretKey;
    /** @var list<mixed> */
    public array $processedHistoryMessages = [];
    public int $nextPreKeyId = 1;
    public int $firstUnuploadedPreKeyId = 1;
    public int $accountSyncCounter = 0;
    /** @var array{unarchiveChats:bool} */
    public array $accountSettings = ['unarchiveChats' => false];
    public ?string $pairingCode = null;
    public ?string $lastPropHash = null;
    public ?string $routingInfo = null; // raw bytes
    /** @var null|array{id:string,name?:?string,lid?:?string} */
    public ?array $me = null;
    /** @var null|array{details:?string,accountSignatureKey:?string,accountSignature:?string,deviceSignature:?string} */
    public ?array $account = null;
    /** @var list<array{identifier:array{name:string,deviceId:int},identifierKey:string}> */
    public array $signalIdentities = [];
    public ?string $platform = null;
    public bool $registered = false;
    /** base64 id of my own app-state sync key */
    public ?string $myAppStateKeyId = null;
    /** last history sync timestamp (seconds), like Baileys */
    public ?int $lastAccountSyncTimestamp = null;
    public ?bool $accountSyncPaused = null;

    public static function init(): self
    {
        $c = new self();
        $identityKey = Curve::generateKeyPair();
        $c->noiseKey = Curve::generateKeyPair();
        $c->pairingEphemeralKeyPair = Curve::generateKeyPair();
        $c->signedIdentityKey = $identityKey;
        $c->signedPreKey = signedKeyPair($identityKey, 1);
        $c->registrationId = Generics::generateRegistrationId();
        $c->advSecretKey = base64_encode(random_bytes(32));
        return $c;
    }

    /** Apply a partial update like Baileys' Object.assign(creds, update). */
    public function applyUpdate(array $update): void
    {
        foreach ($update as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

    private static function keyPairToJson(KeyPair $kp): array
    {
        return ['private' => Generics::bufferToJson($kp->private), 'public' => Generics::bufferToJson($kp->public)];
    }

    private static function keyPairFromJson(array $a): KeyPair
    {
        return new KeyPair(Generics::bufferFromJson($a['private']) ?? '', Generics::bufferFromJson($a['public']) ?? '');
    }

    /** @return array<string,mixed> JSON-ready */
    public function toJsonArray(): array
    {
        $out = [
            'noiseKey' => self::keyPairToJson($this->noiseKey),
            'pairingEphemeralKeyPair' => self::keyPairToJson($this->pairingEphemeralKeyPair),
            'signedIdentityKey' => self::keyPairToJson($this->signedIdentityKey),
            'signedPreKey' => [
                'keyPair' => self::keyPairToJson($this->signedPreKey->keyPair),
                'signature' => Generics::bufferToJson($this->signedPreKey->signature),
                'keyId' => $this->signedPreKey->keyId,
            ],
            'registrationId' => $this->registrationId,
            'advSecretKey' => $this->advSecretKey,
            'processedHistoryMessages' => $this->processedHistoryMessages,
            'nextPreKeyId' => $this->nextPreKeyId,
            'firstUnuploadedPreKeyId' => $this->firstUnuploadedPreKeyId,
            'accountSyncCounter' => $this->accountSyncCounter,
            'accountSettings' => $this->accountSettings,
            'registered' => $this->registered,
        ];
        if ($this->me !== null) $out['me'] = $this->me;
        if ($this->pairingCode !== null) $out['pairingCode'] = $this->pairingCode;
        if ($this->lastPropHash !== null) $out['lastPropHash'] = $this->lastPropHash;
        if ($this->routingInfo !== null) $out['routingInfo'] = Generics::bufferToJson($this->routingInfo);
        if ($this->platform !== null) $out['platform'] = $this->platform;
        if ($this->myAppStateKeyId !== null) $out['myAppStateKeyId'] = $this->myAppStateKeyId;
        if ($this->lastAccountSyncTimestamp !== null) $out['lastAccountSyncTimestamp'] = $this->lastAccountSyncTimestamp;
        if ($this->accountSyncPaused !== null) $out['accountSyncPaused'] = $this->accountSyncPaused;
        if ($this->account !== null) {
            $a = $this->account;
            $out['account'] = [
                'details' => isset($a['details']) ? Generics::bufferToJson($a['details']) : null,
                'accountSignatureKey' => !empty($a['accountSignatureKey']) ? Generics::bufferToJson($a['accountSignatureKey']) : null,
                'accountSignature' => isset($a['accountSignature']) ? Generics::bufferToJson($a['accountSignature']) : null,
                'deviceSignature' => isset($a['deviceSignature']) ? Generics::bufferToJson($a['deviceSignature']) : null,
            ];
        }
        if ($this->signalIdentities !== []) {
            $out['signalIdentities'] = array_map(fn($i) => [
                'identifier' => $i['identifier'],
                'identifierKey' => Generics::bufferToJson($i['identifierKey']),
            ], $this->signalIdentities);
        }
        return $out;
    }

    /** @param array<string,mixed> $a decoded JSON */
    public static function fromJsonArray(array $a): self
    {
        $c = new self();
        $c->noiseKey = self::keyPairFromJson($a['noiseKey']);
        $c->pairingEphemeralKeyPair = self::keyPairFromJson($a['pairingEphemeralKeyPair']);
        $c->signedIdentityKey = self::keyPairFromJson($a['signedIdentityKey']);
        $c->signedPreKey = new SignedKeyPair(
            self::keyPairFromJson($a['signedPreKey']['keyPair']),
            Generics::bufferFromJson($a['signedPreKey']['signature']) ?? '',
            (int)$a['signedPreKey']['keyId']
        );
        $c->registrationId = (int)$a['registrationId'];
        $c->advSecretKey = (string)$a['advSecretKey'];
        $c->processedHistoryMessages = $a['processedHistoryMessages'] ?? [];
        $c->nextPreKeyId = (int)($a['nextPreKeyId'] ?? 1);
        $c->firstUnuploadedPreKeyId = (int)($a['firstUnuploadedPreKeyId'] ?? 1);
        $c->accountSyncCounter = (int)($a['accountSyncCounter'] ?? 0);
        $c->accountSettings = $a['accountSettings'] ?? ['unarchiveChats' => false];
        $c->registered = (bool)($a['registered'] ?? false);
        $c->me = $a['me'] ?? null;
        $c->pairingCode = $a['pairingCode'] ?? null;
        $c->lastPropHash = $a['lastPropHash'] ?? null;
        $c->routingInfo = isset($a['routingInfo']) ? Generics::bufferFromJson($a['routingInfo']) : null;
        $c->platform = $a['platform'] ?? null;
        $c->myAppStateKeyId = $a['myAppStateKeyId'] ?? null;
        $c->lastAccountSyncTimestamp = isset($a['lastAccountSyncTimestamp']) ? (int)$a['lastAccountSyncTimestamp'] : null;
        $c->accountSyncPaused = $a['accountSyncPaused'] ?? null;
        if (isset($a['account']) && is_array($a['account'])) {
            $acc = $a['account'];
            $c->account = [
                'details' => isset($acc['details']) ? Generics::bufferFromJson($acc['details']) : null,
                'accountSignatureKey' => isset($acc['accountSignatureKey']) ? Generics::bufferFromJson($acc['accountSignatureKey']) : null,
                'accountSignature' => isset($acc['accountSignature']) ? Generics::bufferFromJson($acc['accountSignature']) : null,
                'deviceSignature' => isset($acc['deviceSignature']) ? Generics::bufferFromJson($acc['deviceSignature']) : null,
            ];
        }
        if (!empty($a['signalIdentities']) && is_array($a['signalIdentities'])) {
            $c->signalIdentities = array_map(fn($i) => [
                'identifier' => $i['identifier'],
                'identifierKey' => Generics::bufferFromJson($i['identifierKey']) ?? '',
            ], $a['signalIdentities']);
        }
        return $c;
    }
}
