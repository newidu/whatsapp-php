<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

use BaileysPHP\Auth\AuthState;
use BaileysPHP\Signal\Group\GroupCipher;
use BaileysPHP\Signal\Group\GroupSessionBuilder;
use BaileysPHP\Signal\Group\SenderKeyDistributionMessage as GroupSKDM;
use BaileysPHP\Signal\Group\SenderKeyName;
use BaileysPHP\Signal\Group\SenderKeyRecord;
use BaileysPHP\Signal\Group\SenderKeyStore;
use BaileysPHP\Utils\Logger;
use BaileysPHP\WABinary\Jid;
use function BaileysPHP\Utils\generateSignalPubKey;

/**
 * Port of Baileys makeLibSignalRepository() + signalStorage() adapter
 * (src/Signal/libsignal.ts), Basic scope:
 *  - 1-to-1 encrypt/decrypt, session injection/validation, LID mapping store
 *  - Group sender-keys (encrypt/decrypt/process SKDM) via Signal\Group
 */
final class SignalRepository implements SignalStorage, SenderKeyStore
{
    public LIDMappingStore $lidMapping;
    private Logger $logger;

    public function __construct(private AuthState $auth, ?Logger $logger = null, ?callable $pnToLIDFunc = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->lidMapping = new LIDMappingStore($auth->keys, $this->logger, $pnToLIDFunc);
    }

    // ---------------------------------------------------------- address mapping
    public static function jidToProtocolAddress(string $jid): ProtocolAddress
    {
        $decoded = Jid::decode($jid);
        if ($decoded === null || $decoded['user'] === '') {
            throw new \RuntimeException("JID decoded but user is empty: \"$jid\"");
        }
        $signalUser = $decoded['domainType'] !== Jid::DOMAIN_WHATSAPP
            ? $decoded['user'] . '_' . $decoded['domainType']
            : $decoded['user'];
        return new ProtocolAddress($signalUser, $decoded['device'] ?? 0);
    }

    private function resolveLIDSignalAddress(string $id): string
    {
        if (str_contains($id, '.')) {
            $pos = strrpos($id, '.');
            $deviceIdPart = substr($id, 0, $pos);
            $device = substr($id, $pos + 1);
            $uParts = explode('_', $deviceIdPart, 2);
            $user = $uParts[0];
            $domainType = isset($uParts[1]) ? (int)$uParts[1] : 0;
            if ($domainType === Jid::DOMAIN_LID || $domainType === Jid::DOMAIN_HOSTED_LID) {
                return $id;
            }
            $server = $domainType === Jid::DOMAIN_HOSTED ? 'hosted' : 's.whatsapp.net';
            $pnJid = Jid::encode($user, $server, $device !== '0' ? (int)$device : null);
            $lid = $this->lidMapping->getLIDForPN($pnJid);
            if ($lid !== null) {
                return self::jidToProtocolAddress($lid)->toString();
            }
        }
        return $id;
    }

    // ---------------------------------------------------------- SignalStorage
    public function loadSession(string $id): ?SessionRecord
    {
        try {
            $wire = $this->resolveLIDSignalAddress($id);
            $data = $this->auth->keys->get('session', [$wire]);
            $sess = $data[$wire] ?? null;
            if (is_string($sess) && $sess !== '') {
                return SessionRecord::deserialize($sess);
            }
            if (is_array($sess)) { // tolerate records stored as arrays
                return SessionRecord::deserialize(json_encode($sess));
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    public function storeSession(string $id, SessionRecord $record): void
    {
        $wire = $this->resolveLIDSignalAddress($id);
        $this->auth->keys->set(['session' => [$wire => $record->serialize()]]);
    }

    public function isTrustedIdentity(string $id, string $identityKey): bool
    {
        return true; // TOFU, same as WhatsApp Web / Baileys
    }

    public function loadPreKey(int|string $id): ?array
    {
        $data = $this->auth->keys->get('pre-key', [(string)$id]);
        $key = $data[(string)$id] ?? null;
        if ($key !== null) {
            return ['privKey' => $key->private, 'pubKey' => $key->public];
        }
        return null;
    }

    public function removePreKey(int $id): void
    {
        $this->auth->keys->set(['pre-key' => [$id => null]]);
    }

    public function loadSignedPreKey(): array
    {
        $key = $this->auth->creds->signedPreKey;
        return ['privKey' => $key->keyPair->private, 'pubKey' => $key->keyPair->public];
    }

    public function getOurRegistrationId(): int
    {
        return $this->auth->creds->registrationId;
    }

    public function getOurIdentity(): array
    {
        $k = $this->auth->creds->signedIdentityKey;
        return ['privKey' => $k->private, 'pubKey' => generateSignalPubKey($k->public)];
    }

    /** saveIdentity equivalent — tracks identity changes & clears stale sessions. */
    public function saveIdentity(string $id, string $identityKey): bool
    {
        $wire = $this->resolveLIDSignalAddress($id);
        $data = $this->auth->keys->get('identity-key', [$wire]);
        $existing = $data[$wire] ?? null;
        if (is_string($existing) && $existing !== $identityKey) {
            $this->auth->keys->set(['session' => [$wire => null], 'identity-key' => [$wire => $identityKey]]);
            return true;
        }
        if ($existing === null) {
            $this->auth->keys->set(['identity-key' => [$wire => $identityKey]]);
            return true;
        }
        return false;
    }

    // ---------------------------------------------------------- Repository API
    /**
     * @param array{jid:string,type:string,ciphertext:string} $opts type: 'pkmsg'|'msg'
     */
    public function decryptMessage(array $opts): string
    {
        $addr = self::jidToProtocolAddress($opts['jid']);
        $session = new SessionCipher($this, $addr);
        if ($opts['type'] === 'pkmsg') {
            $identityKey = self::extractIdentityFromPkmsg($opts['ciphertext']);
            if ($identityKey !== null) {
                $changed = $this->saveIdentity($addr->toString(), $identityKey);
                if ($changed) {
                    $this->logger->info(['jid' => $opts['jid']], 'identity key changed or new contact');
                }
            }
            return $session->decryptPreKeyWhisperMessage($opts['ciphertext']);
        }
        return $session->decryptWhisperMessage($opts['ciphertext']);
    }

    private static function extractIdentityFromPkmsg(string $ciphertext): ?string
    {
        try {
            if (strlen($ciphertext) < 2 || (ord($ciphertext[0]) & 0xf) !== 3) {
                return null;
            }
            $proto = WhisperProto::decodePreKeyWhisperMessage(substr($ciphertext, 1));
            $k = $proto['identityKey'] ?? null;
            return (is_string($k) && strlen($k) === 33) ? $k : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{jid:string,data:string} $opts
     * @return array{type:string,ciphertext:string} type 'pkmsg'|'msg'
     */
    public function encryptMessage(array $opts): array
    {
        $addr = self::jidToProtocolAddress($opts['jid']);
        $cipher = new SessionCipher($this, $addr);
        $r = $cipher->encrypt($opts['data']);
        return ['type' => $r['type'] === 3 ? 'pkmsg' : 'msg', 'ciphertext' => $r['body']];
    }

    /**
     * @param array{jid:string,session:array} $opts
     */
    public function injectE2ESession(array $opts): void
    {
        $builder = new SessionBuilder($this, self::jidToProtocolAddress($opts['jid']));
        $builder->initOutgoing($opts['session']);
    }

    /** @return array{exists:bool,reason?:string} */
    public function validateSession(string $jid): array
    {
        try {
            $addr = self::jidToProtocolAddress($jid);
            $session = $this->loadSession($addr->toString());
            if ($session === null) {
                return ['exists' => false, 'reason' => 'no session'];
            }
            if (!$session->haveOpenSession()) {
                return ['exists' => false, 'reason' => 'no open session'];
            }
            return ['exists' => true];
        } catch (\Throwable) {
            return ['exists' => false, 'reason' => 'validation error'];
        }
    }

    /** Signal protocol address string for a jid (e.g. "9477xx.1:2"). */
    public static function signalAddressString(string $jid): string
    {
        return self::jidToProtocolAddress($jid)->toString();
    }

    /**
     * Info about the open session with a jid (libsignal getSessionInfo).
     * @return null|array{baseKey:string,registrationId:int}
     */
    public function getSessionInfo(string $jid): ?array
    {
        try {
            $addr = self::jidToProtocolAddress($jid)->toString();
            $session = $this->loadSession($addr);
            $open = $session?->getOpenSession();
            $baseKey = $open?->indexInfo['baseKey'] ?? null;
            $registrationId = $open?->registrationId ?? null;
            if (!is_string($baseKey) || $baseKey === '' || !is_int($registrationId)) {
                return null;
            }
            return ['baseKey' => $baseKey, 'registrationId' => $registrationId];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Move a Signal session from one jid to another (LID migration). */
    public function migrateSession(string $fromJid, string $toJid): void
    {
        try {
            $fromAddr = self::jidToProtocolAddress($fromJid)->toString();
            $toAddr = self::jidToProtocolAddress($toJid)->toString();
            if ($fromAddr === $toAddr) {
                return;
            }
            $session = $this->loadSession($fromAddr);
            if ($session !== null) {
                $this->storeSession($toAddr, $session);
                $this->auth->keys->set(['session' => [$fromAddr => null]]);
            }
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $jids */
    public function deleteSession(array $jids): void
    {
        $updates = [];
        foreach ($jids as $jid) {
            $updates[self::jidToProtocolAddress($jid)->toString()] = null;
        }
        if ($updates !== []) {
            $this->auth->keys->set(['session' => $updates]);
        }
    }

    // ---------------------------------------------------------- Group sender keys
    public static function jidToSignalSenderKeyName(string $group, string $user): SenderKeyName
    {
        return new SenderKeyName($group, self::jidToProtocolAddress($user));
    }

    public function loadSenderKey(SenderKeyName $senderKeyName): SenderKeyRecord
    {
        $keyId = $senderKeyName->toString();
        $data = $this->auth->keys->get('sender-key', [$keyId]);
        $key = $data[$keyId] ?? null;
        if (is_string($key) && $key !== '') {
            return SenderKeyRecord::deserialize($key);
        }
        if (is_array($key)) {
            return SenderKeyRecord::deserialize(json_encode($key));
        }
        return new SenderKeyRecord();
    }

    public function storeSenderKey(SenderKeyName $senderKeyName, SenderKeyRecord $record): void
    {
        $keyId = $senderKeyName->toString();
        $this->auth->keys->set(['sender-key' => [$keyId => $record->serializeToBytes()]]);
    }

    private function ensureSenderKeyAndCreateSkdm(string $group, string $meId): array
    {
        $senderName = self::jidToSignalSenderKeyName($group, $meId);
        $senderNameStr = $senderName->toString();
        $data = $this->auth->keys->get('sender-key', [$senderNameStr]);
        if (!isset($data[$senderNameStr])) {
            $this->storeSenderKey($senderName, new SenderKeyRecord());
        }
        $skdm = (new GroupSessionBuilder($this))->create($senderName);
        return ['senderName' => $senderName, 'skdm' => $skdm];
    }

    /**
     * @param array{group:string,authorJid:string,msg:string} $opts
     */
    public function decryptGroupMessage(array $opts): string
    {
        $senderName = self::jidToSignalSenderKeyName($opts['group'], $opts['authorJid']);
        return (new GroupCipher($this, $senderName))->decrypt($opts['msg']);
    }

    /**
     * @param array{group:string,meId:string,data:string} $opts
     * @return array{ciphertext:string,senderKeyDistributionMessage:string}
     */
    public function encryptGroupMessage(array $opts): array
    {
        ['senderName' => $senderName, 'skdm' => $skdm] = $this->ensureSenderKeyAndCreateSkdm($opts['group'], $opts['meId']);
        $ciphertext = (new GroupCipher($this, $senderName))->encrypt($opts['data']);
        return ['ciphertext' => $ciphertext, 'senderKeyDistributionMessage' => $skdm->serialize()];
    }

    /** @param array{group:string,meId:string} $opts */
    public function getSenderKeyDistributionMessage(array $opts): string
    {
        ['skdm' => $skdm] = $this->ensureSenderKeyAndCreateSkdm($opts['group'], $opts['meId']);
        return $skdm->serialize();
    }

    /** @param array{group:string,meId:string} $opts */
    public function hasSenderKey(array $opts): bool
    {
        $name = self::jidToSignalSenderKeyName($opts['group'], $opts['meId'])->toString();
        $data = $this->auth->keys->get('sender-key', [$name]);
        return isset($data[$name]);
    }

    /**
     * @param array{item:array{groupId:?string,axolotlSenderKeyDistributionMessage:?string},authorJid:string} $opts
     */
    public function processSenderKeyDistributionMessage(array $opts): void
    {
        $item = $opts['item'];
        if (empty($item['groupId'])) {
            throw new \RuntimeException('Group ID is required for sender key distribution message');
        }
        $senderName = self::jidToSignalSenderKeyName($item['groupId'], $opts['authorJid']);
        $senderMsg = new GroupSKDM(null, null, null, null, $item['axolotlSenderKeyDistributionMessage'] ?? '');
        $senderNameStr = $senderName->toString();
        $data = $this->auth->keys->get('sender-key', [$senderNameStr]);
        if (!isset($data[$senderNameStr])) {
            $this->storeSenderKey($senderName, new SenderKeyRecord());
        }
        (new GroupSessionBuilder($this))->process($senderName, $senderMsg);
    }
}
