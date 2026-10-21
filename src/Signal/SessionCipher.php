<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

use BaileysPHP\Utils\Curve;
use function BaileysPHP\Utils\generateSignalPubKey;

/** Port of libsignal session_cipher.js (Double Ratchet encrypt/decrypt). */
final class SessionCipher
{
    private const VERSION = 3;

    public function __construct(
        private SignalStorage $storage,
        private ProtocolAddress $addr,
    ) {}

    private function encodeTupleByte(int $n1, int $n2): int
    {
        return ($n1 << 4) | $n2;
    }

    /** @return array{0:int,1:int} */
    private function decodeTupleByte(int $byte): array
    {
        return [$byte >> 4, $byte & 0xf];
    }

    private function getRecord(): ?SessionRecord
    {
        return $this->storage->loadSession($this->addr->toString());
    }

    private function storeRecord(SessionRecord $record): void
    {
        $record->removeOldSessions();
        $this->storage->storeSession($this->addr->toString(), $record);
    }

    /**
     * @return array{type:int,body:string,registrationId:?int} type 3 = pkmsg, 1 = msg
     */
    public function encrypt(string $data): array
    {
        $ourIdentityKey = $this->storage->getOurIdentity();
        $record = $this->getRecord();
        if ($record === null) {
            throw new \RuntimeException('No sessions');
        }
        $session = $record->getOpenSession();
        if ($session === null) {
            throw new \RuntimeException('No open session');
        }
        $chain = $session->getChain($session->currentRatchet['ephemeralKeyPair']['pubKey']);
        if ($chain === null) {
            throw new \RuntimeException('No sending chain');
        }
        if ($chain->chainType === Chain::RECEIVING) {
            throw new \RuntimeException('Tried to encrypt on a receiving chain');
        }
        $this->fillMessageKeys($chain, $chain->counter + 1);
        $keys = SignalCrypto::deriveSecrets($chain->messageKeys[$chain->counter], str_repeat("\0", 32), 'WhisperMessageKeys');
        unset($chain->messageKeys[$chain->counter]);

        $msgBuf = WhisperProto::encodeWhisperMessage([
            'ephemeralKey' => $session->currentRatchet['ephemeralKeyPair']['pubKey'],
            'counter' => $chain->counter,
            'previousCounter' => $session->currentRatchet['previousCounter'],
            'ciphertext' => SignalCrypto::encrypt($keys[0], $data, substr($keys[2], 0, 16)),
        ]);

        $macInput = $ourIdentityKey['pubKey'] . $session->indexInfo['remoteIdentityKey']
            . chr($this->encodeTupleByte(self::VERSION, self::VERSION)) . $msgBuf;
        $mac = SignalCrypto::calculateMAC($keys[1], $macInput);
        $result = chr($this->encodeTupleByte(self::VERSION, self::VERSION)) . $msgBuf . substr($mac, 0, 8);

        $this->storeRecord($record);

        if ($session->pendingPreKey !== null) {
            $preKeyMsg = [
                'identityKey' => $ourIdentityKey['pubKey'],
                'registrationId' => $this->storage->getOurRegistrationId(),
                'baseKey' => $session->pendingPreKey['baseKey'],
                'signedPreKeyId' => $session->pendingPreKey['signedKeyId'],
                'message' => $result,
                'preKeyId' => $session->pendingPreKey['preKeyId'] ?? null,
            ];
            $body = chr($this->encodeTupleByte(self::VERSION, self::VERSION))
                . WhisperProto::encodePreKeyWhisperMessage($preKeyMsg);
            return ['type' => 3, 'body' => $body, 'registrationId' => $session->registrationId];
        }
        return ['type' => 1, 'body' => $result, 'registrationId' => $session->registrationId];
    }

    /** @param list<SessionEntry> $sessions */
    private function decryptWithSessions(string $data, array $sessions): array
    {
        if ($sessions === []) {
            throw new \RuntimeException('No sessions available');
        }
        $errs = [];
        foreach ($sessions as $session) {
            try {
                $plaintext = $this->doDecryptWhisperMessage($data, $session);
                $session->indexInfo['used'] = (int)(microtime(true) * 1000);
                return ['session' => $session, 'plaintext' => $plaintext];
            } catch (\Throwable $e) {
                $errs[] = $e;
            }
        }
        throw new \RuntimeException('No matching sessions found for message (' . implode('; ', array_map(fn($e) => $e->getMessage(), $errs)) . ')');
    }

    public function decryptWhisperMessage(string $data): string
    {
        $record = $this->getRecord();
        if ($record === null) {
            throw new \RuntimeException('No session record');
        }
        $result = $this->decryptWithSessions($data, $record->getSessions());
        $this->storeRecord($record);
        return $result['plaintext'];
    }

    public function decryptPreKeyWhisperMessage(string $data): string
    {
        $versions = $this->decodeTupleByte(ord($data[0]));
        if ($versions[1] > 3 || $versions[0] < 3) {
            throw new \RuntimeException('Incompatible version number on PreKeyWhisperMessage');
        }
        $record = $this->getRecord();
        $preKeyProto = WhisperProto::decodePreKeyWhisperMessage(substr($data, 1));
        if ($record === null) {
            if (($preKeyProto['registrationId'] ?? null) === null) {
                throw new \RuntimeException('No registrationId');
            }
            $record = new SessionRecord();
        }
        $builder = new SessionBuilder($this->storage, $this->addr);
        $preKeyId = $builder->initIncoming($record, $preKeyProto);
        $session = $record->getSession($preKeyProto['baseKey'] ?? '');
        if ($session === null) {
            throw new \RuntimeException('Session not found after initIncoming');
        }
        $plaintext = $this->doDecryptWhisperMessage($preKeyProto['message'] ?? '', $session);
        $this->storeRecord($record);
        if ($preKeyId) {
            $this->storage->removePreKey($preKeyId);
        }
        return $plaintext;
    }

    public function doDecryptWhisperMessage(string $messageBuffer, SessionEntry $session): string
    {
        $versions = $this->decodeTupleByte(ord($messageBuffer[0]));
        if ($versions[1] > 3 || $versions[0] < 3) {
            throw new \RuntimeException('Incompatible version number on WhisperMessage');
        }
        $messageProto = substr($messageBuffer, 1, strlen($messageBuffer) - 1 - 8);
        $message = WhisperProto::decodeWhisperMessage($messageProto);
        $this->maybeStepRatchet($session, $message['ephemeralKey'] ?? '', $message['previousCounter']);
        $chain = $session->getChain($message['ephemeralKey'] ?? '');
        if ($chain === null) {
            throw new \RuntimeException('No chain for ephemeral key');
        }
        if ($chain->chainType === Chain::SENDING) {
            throw new \RuntimeException('Tried to decrypt on a sending chain');
        }
        $this->fillMessageKeys($chain, $message['counter']);
        if (!array_key_exists($message['counter'], $chain->messageKeys)) {
            throw new \RuntimeException('Key used already or never filled');
        }
        $messageKey = $chain->messageKeys[$message['counter']];
        unset($chain->messageKeys[$message['counter']]);
        $keys = SignalCrypto::deriveSecrets($messageKey, str_repeat("\0", 32), 'WhisperMessageKeys');

        $ourIdentityKey = $this->storage->getOurIdentity();
        $macInput = $session->indexInfo['remoteIdentityKey'] . $ourIdentityKey['pubKey']
            . chr($this->encodeTupleByte(self::VERSION, self::VERSION)) . $messageProto;
        SignalCrypto::verifyMAC($macInput, $keys[1], substr($messageBuffer, -8), 8);

        $plaintext = SignalCrypto::decrypt($keys[0], $message['ciphertext'] ?? '', substr($keys[2], 0, 16));
        $session->pendingPreKey = null;
        return $plaintext;
    }

    private function fillMessageKeys(Chain $chain, int $counter): void
    {
        while ($chain->counter < $counter) {
            if ($counter - $chain->counter > 2000) {
                throw new \RuntimeException('Over 2000 messages into the future!');
            }
            if ($chain->key === null) {
                throw new \RuntimeException('Chain closed');
            }
            $key = $chain->key;
            $chain->messageKeys[$chain->counter + 1] = SignalCrypto::calculateMAC($key, chr(1));
            $chain->key = SignalCrypto::calculateMAC($key, chr(2));
            $chain->counter += 1;
        }
    }

    private function maybeStepRatchet(SessionEntry $session, string $remoteKey, int $previousCounter): void
    {
        if ($session->getChain($remoteKey) !== null) {
            return;
        }
        $ratchet = $session->currentRatchet;
        $previousRatchet = $session->getChain($ratchet['lastRemoteEphemeralKey']);
        if ($previousRatchet !== null) {
            $this->fillMessageKeys($previousRatchet, $previousCounter);
            $previousRatchet->key = null; // close
        }
        $this->calculateRatchet($session, $remoteKey, false);
        $prevCounterChain = $session->getChain($session->currentRatchet['ephemeralKeyPair']['pubKey']);
        if ($prevCounterChain !== null) {
            $session->currentRatchet['previousCounter'] = $prevCounterChain->counter;
            $session->deleteChain($session->currentRatchet['ephemeralKeyPair']['pubKey']);
        }
        $kp = Curve::generateKeyPair();
        $session->currentRatchet['ephemeralKeyPair'] = ['pubKey' => generateSignalPubKey($kp->public), 'privKey' => $kp->private];
        $this->calculateRatchet($session, $remoteKey, true);
        $session->currentRatchet['lastRemoteEphemeralKey'] = $remoteKey;
    }

    private function calculateRatchet(SessionEntry $session, string $remoteKey, bool $sending): void
    {
        $ratchet = $session->currentRatchet;
        $shared = Curve::sharedKey($ratchet['ephemeralKeyPair']['privKey'], $remoteKey);
        $masterKey = SignalCrypto::deriveSecrets($shared, $ratchet['rootKey'], 'WhisperRatchet', 2);
        $chainKeyId = $sending ? $ratchet['ephemeralKeyPair']['pubKey'] : $remoteKey;
        $session->addChain($chainKeyId, new Chain($sending ? Chain::SENDING : Chain::RECEIVING, -1, $masterKey[1], []));
        $session->currentRatchet['rootKey'] = $masterKey[0];
    }

    public function hasOpenSession(): bool
    {
        $record = $this->getRecord();
        return $record !== null && $record->haveOpenSession();
    }

    public function closeOpenSession(): void
    {
        $record = $this->getRecord();
        if ($record !== null) {
            $open = $record->getOpenSession();
            if ($open !== null) {
                $record->closeSession($open);
                $this->storeRecord($record);
            }
        }
    }
}
