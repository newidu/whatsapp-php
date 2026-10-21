<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

use BaileysPHP\Utils\Curve;
use function BaileysPHP\Utils\generateSignalPubKey;

/** Port of libsignal session_builder.js (X3DH session establishment). */
final class SessionBuilder
{
    public function __construct(
        private SignalStorage $storage,
        private ProtocolAddress $addr,
    ) {}

    /** libsignal KeyPair shape with 33-byte pubkey. @return array{pubKey:string,privKey:string} */
    private static function libsignalKeyPair(): array
    {
        $kp = Curve::generateKeyPair();
        return ['pubKey' => generateSignalPubKey($kp->public), 'privKey' => $kp->private];
    }

    /**
     * initOutgoing(device) — device is a retrieved pre-key bundle:
     * @param array{
     *   registrationId:int, identityKey:string,
     *   signedPreKey:array{keyId:int,publicKey:string,signature:string},
     *   preKey?:array{keyId:int,publicKey:string}
     * } $device
     */
    public function initOutgoing(array $device): void
    {
        // Node passes isInit=true to verifySignature which *skips* verification in this
        // libsignal fork (verify returns true when isInit). We do a real verify instead —
        // a bundle with a bad signed-prekey signature must not be trusted.
        if (!Curve::verify($device['identityKey'], $device['signedPreKey']['publicKey'], $device['signedPreKey']['signature'])) {
            throw new \RuntimeException('Signed pre-key signature verification failed');
        }
        $baseKey = self::libsignalKeyPair();
        $devicePreKey = $device['preKey']['publicKey'] ?? null;
        $session = $this->initSession(
            true,
            $baseKey,
            null,
            $device['identityKey'],
            $devicePreKey,
            $device['signedPreKey']['publicKey'],
            $device['registrationId']
        );
        $session->pendingPreKey = [
            'signedKeyId' => $device['signedPreKey']['keyId'],
            'baseKey' => $baseKey['pubKey'],
        ];
        if (isset($device['preKey'])) {
            $session->pendingPreKey['preKeyId'] = $device['preKey']['keyId'];
        }
        $fqAddr = $this->addr->toString();
        $record = $this->storage->loadSession($fqAddr);
        if ($record === null) {
            $record = new SessionRecord();
        } else {
            $open = $record->getOpenSession();
            if ($open !== null) {
                $record->closeSession($open);
            }
        }
        $record->setSession($session);
        $this->storage->storeSession($fqAddr, $record);
    }

    /**
     * initIncoming(record, message) — message is a decoded PreKeyWhisperMessage.
     * @param array{preKeyId:?int,baseKey:?string,identityKey:?string,registrationId:?int,signedPreKeyId:?int} $message
     * @return ?int the one-time preKeyId consumed (to delete), or null
     */
    public function initIncoming(SessionRecord $record, array $message): ?int
    {
        $baseKey = $message['baseKey'] ?? '';
        // getSession throws for "ours" — incoming baseKey is theirs, so it is safe.
        try {
            $existing = $record->getSession($baseKey);
        } catch (\Throwable) {
            $existing = null;
        }
        if ($existing !== null) {
            return null; // we just haven't replied yet
        }
        $preKeyPair = !empty($message['preKeyId']) ? $this->storage->loadPreKey($message['preKeyId']) : null;
        if (!empty($message['preKeyId']) && $preKeyPair === null) {
            throw new \RuntimeException('Invalid PreKey ID');
        }
        $signedPreKeyPair = $this->storage->loadSignedPreKey();
        if (!$signedPreKeyPair) {
            throw new \RuntimeException('Missing SignedPreKey');
        }
        $existingOpen = $record->getOpenSession();
        if ($existingOpen !== null) {
            $record->closeSession($existingOpen);
        }
        $record->setSession($this->initSession(
            false,
            $preKeyPair,
            $signedPreKeyPair,
            $message['identityKey'] ?? '',
            $baseKey,
            null,
            $message['registrationId'] ?? 0
        ));
        return $message['preKeyId'] ?? null;
    }

    /**
     * @param null|array{pubKey:string,privKey:string} $ourEphemeralKey
     * @param null|array{pubKey:string,privKey:string} $ourSignedKey
     */
    private function initSession(
        bool $isInitiator,
        ?array $ourEphemeralKey,
        ?array $ourSignedKey,
        string $theirIdentityPubKey,
        ?string $theirEphemeralPubKey,
        ?string $theirSignedPubKey,
        int $registrationId
    ): SessionEntry {
        if ($isInitiator) {
            if ($ourSignedKey !== null) {
                throw new \RuntimeException('Invalid call to initSession');
            }
            $ourSignedKey = $ourEphemeralKey;
        } else {
            if ($theirSignedPubKey !== null) {
                throw new \RuntimeException('Invalid call to initSession');
            }
            $theirSignedPubKey = $theirEphemeralPubKey;
        }
        $hasBothEphemeral = $ourEphemeralKey !== null && $theirEphemeralPubKey !== null;
        $sharedSecret = str_repeat("\0", $hasBothEphemeral ? 32 * 5 : 32 * 4);
        for ($i = 0; $i < 32; $i++) {
            $sharedSecret[$i] = "\xff";
        }
        $ourIdentityKey = $this->storage->getOurIdentity();
        $a1 = Curve::sharedKey($ourIdentityKey['privKey'], $theirSignedPubKey ?? '');
        $a2 = Curve::sharedKey($ourSignedKey['privKey'], $theirIdentityPubKey);
        $a3 = Curve::sharedKey($ourSignedKey['privKey'], $theirSignedPubKey ?? '');
        if ($isInitiator) {
            $sharedSecret = substr_replace($sharedSecret, $a1, 32, 32);
            $sharedSecret = substr_replace($sharedSecret, $a2, 32 * 2, 32);
        } else {
            $sharedSecret = substr_replace($sharedSecret, $a1, 32 * 2, 32);
            $sharedSecret = substr_replace($sharedSecret, $a2, 32, 32);
        }
        $sharedSecret = substr_replace($sharedSecret, $a3, 32 * 3, 32);
        if ($hasBothEphemeral) {
            $a4 = Curve::sharedKey($ourEphemeralKey['privKey'], $theirEphemeralPubKey);
            $sharedSecret = substr_replace($sharedSecret, $a4, 32 * 4, 32);
        }
        $masterKey = SignalCrypto::deriveSecrets($sharedSecret, str_repeat("\0", 32), 'WhisperText');

        $now = (int)(microtime(true) * 1000);
        $session = SessionRecord::createEntry();
        $session->registrationId = $registrationId;
        $session->currentRatchet = [
            'rootKey' => $masterKey[0],
            'ephemeralKeyPair' => $isInitiator ? self::libsignalKeyPair() : ['pubKey' => $ourSignedKey['pubKey'], 'privKey' => $ourSignedKey['privKey']],
            'lastRemoteEphemeralKey' => $theirSignedPubKey ?? '',
            'previousCounter' => 0,
        ];
        $session->indexInfo = [
            'created' => $now,
            'used' => $now,
            'remoteIdentityKey' => $theirIdentityPubKey,
            'baseKey' => $isInitiator ? $ourEphemeralKey['pubKey'] : ($theirEphemeralPubKey ?? ''),
            'baseKeyType' => $isInitiator ? SessionEntry::BASEKEY_OURS : SessionEntry::BASEKEY_THEIRS,
            'closed' => -1,
        ];
        if ($isInitiator) {
            $this->calculateSendingRatchet($session, $theirSignedPubKey ?? '');
        }
        return $session;
    }

    private function calculateSendingRatchet(SessionEntry $session, string $remoteKey): void
    {
        $ratchet = $session->currentRatchet;
        $shared = Curve::sharedKey($ratchet['ephemeralKeyPair']['privKey'], $remoteKey);
        $masterKey = SignalCrypto::deriveSecrets($shared, $ratchet['rootKey'], 'WhisperRatchet');
        $session->addChain($ratchet['ephemeralKeyPair']['pubKey'], new Chain(Chain::SENDING, -1, $masterKey[1], []));
        $session->currentRatchet['rootKey'] = $masterKey[0];
    }
}
