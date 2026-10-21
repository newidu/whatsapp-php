<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

use BaileysPHP\Utils\Curve;
use function BaileysPHP\Utils\generateSignalPubKey;

/** Port of Baileys src/Signal/Group/group-session-builder.ts (+ keyhelper). */
final class GroupSessionBuilder
{
    public function __construct(private SenderKeyStore $senderKeyStore) {}

    public function process(SenderKeyName $senderKeyName, SenderKeyDistributionMessage $skdm): void
    {
        $record = $this->senderKeyStore->loadSenderKey($senderKeyName);
        $record->addSenderKeyState(
            $skdm->getId(),
            $skdm->getIteration(),
            $skdm->getChainKey(),
            $skdm->getSignatureKey()
        );
        $this->senderKeyStore->storeSenderKey($senderKeyName, $record);
    }

    public function create(SenderKeyName $senderKeyName): SenderKeyDistributionMessage
    {
        $record = $this->senderKeyStore->loadSenderKey($senderKeyName);

        if ($record->isEmpty()) {
            $keyId = random_int(1, 2147483646);
            $senderKey = random_bytes(32);
            $kp = Curve::generateKeyPair();
            $signingKey = ['public' => generateSignalPubKey($kp->public), 'private' => $kp->private];
            $record->setSenderKeyState($keyId, 0, $senderKey, $signingKey);
            $this->senderKeyStore->storeSenderKey($senderKeyName, $record);
        }

        $state = $record->getSenderKeyState();
        if ($state === null) {
            throw new \RuntimeException('No session state available');
        }
        return new SenderKeyDistributionMessage(
            $state->getKeyId(),
            $state->getSenderChainKey()->getIteration(),
            $state->getSenderChainKey()->getSeed(),
            $state->getSigningKeyPublic()
        );
    }
}
