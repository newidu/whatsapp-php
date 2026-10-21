<?php
declare(strict_types=1);

namespace BaileysPHP\Signal\Group;

/** The storage interface GroupCipher/GroupSessionBuilder use (implemented by SignalRepository). */
interface SenderKeyStore
{
    public function loadSenderKey(SenderKeyName $senderKeyName): SenderKeyRecord;

    public function storeSenderKey(SenderKeyName $senderKeyName, SenderKeyRecord $record): void;
}
