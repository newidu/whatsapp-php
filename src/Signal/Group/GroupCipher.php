<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Signal\SignalCrypto;

/** Port of Baileys src/Signal/Group/group_cipher.ts */
final class GroupCipher
{
    public function __construct(
        private SenderKeyStore $senderKeyStore,
        private SenderKeyName $senderKeyName,
    ) {}

    public function encrypt(string $paddedPlaintext): string
    {
        $record = $this->senderKeyStore->loadSenderKey($this->senderKeyName);
        $senderKeyState = $record->getSenderKeyState();
        if ($senderKeyState === null) {
            throw new \RuntimeException('No session to encrypt message');
        }
        $iteration = $senderKeyState->getSenderChainKey()->getIteration();
        $senderKey = $this->getSenderKey($senderKeyState, $iteration === 0 ? 0 : $iteration + 1);

        try {
            $ciphertext = SignalCrypto::encrypt($senderKey->getCipherKey(), $paddedPlaintext, $senderKey->getIv());
        } catch (\Throwable) {
            throw new \RuntimeException('InvalidMessageException');
        }

        $senderKeyMessage = new SenderKeyMessage(
            $senderKeyState->getKeyId(),
            $senderKey->getIteration(),
            $ciphertext,
            $senderKeyState->getSigningKeyPrivate()
        );

        $this->senderKeyStore->storeSenderKey($this->senderKeyName, $record);
        return $senderKeyMessage->serialize();
    }

    public function decrypt(string $senderKeyMessageBytes): string
    {
        $record = $this->senderKeyStore->loadSenderKey($this->senderKeyName);
        $senderKeyMessage = new SenderKeyMessage(null, null, null, null, $senderKeyMessageBytes);
        $senderKeyState = $record->getSenderKeyState($senderKeyMessage->getKeyId());
        if ($senderKeyState === null) {
            throw new \RuntimeException('No session found to decrypt message');
        }
        $senderKeyMessage->verifySignature($senderKeyState->getSigningKeyPublic());
        $senderKey = $this->getSenderKey($senderKeyState, $senderKeyMessage->getIteration());

        try {
            $plaintext = SignalCrypto::decrypt(
                $senderKey->getCipherKey(),
                $senderKeyMessage->getCipherText(),
                $senderKey->getIv()
            );
        } catch (\Throwable) {
            throw new \RuntimeException('InvalidMessageException');
        }

        $this->senderKeyStore->storeSenderKey($this->senderKeyName, $record);
        return $plaintext;
    }

    private function getSenderKey(SenderKeyState $senderKeyState, int $iteration): SenderMessageKey
    {
        $senderChainKey = $senderKeyState->getSenderChainKey();
        if ($senderChainKey->getIteration() > $iteration) {
            if ($senderKeyState->hasSenderMessageKey($iteration)) {
                $messageKey = $senderKeyState->removeSenderMessageKey($iteration);
                if ($messageKey === null) {
                    throw new \RuntimeException('No sender message key found for iteration');
                }
                return $messageKey;
            }
            throw new \RuntimeException("Received message with old counter: {$senderChainKey->getIteration()}, $iteration");
        }
        if ($iteration - $senderChainKey->getIteration() > 2000) {
            throw new \RuntimeException('Over 2000 messages into the future!');
        }
        while ($senderChainKey->getIteration() < $iteration) {
            $senderKeyState->addSenderMessageKey($senderChainKey->getSenderMessageKey());
            $senderChainKey = $senderChainKey->getNext();
        }
        $senderKeyState->setSenderChainKey($senderChainKey->getNext());
        return $senderChainKey->getSenderMessageKey();
    }
}
