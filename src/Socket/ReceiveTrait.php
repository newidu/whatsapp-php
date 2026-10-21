<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Proto\WAProto;
use BaileysPHP\Utils\Generics;
use BaileysPHP\Utils\Messages;
use BaileysPHP\Utils\SignalUtils;
use BaileysPHP\Utils\ValidateConnection;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Incoming-message processing — port of Baileys
 * Utils/process-message.ts + Utils/history.ts + retry/placeholder parts of messages-recv.ts.
 *
 * Config hooks used:
 *   'getMessage'      => fn(array $key): ?array   (proto Message of a stored message; for retries/polls/events)
 *   'shouldProcessHistoryMsg' => bool (default: config syncFullHistory / first sync)
 *   'maxMsgRetryCount' => int (default 5)
 */
trait ReceiveTrait
{
    /** @var array<string,int> retry counters "msgId:participant" */
    private array $msgRetryCounter = [];
    /** @var array<string,array|true> placeholder resend cache by msg id */
    private array $placeholderResendCache = [];
    /** @var array<string,array> call offer cache by call id */
    private array $callOffersCache = [];

    // -------------------------------------------------------------- helpers
    public static function getKeyAuthor(?array $key, string $meId = 'me'): string
    {
        if ($key === null) {
            return '';
        }
        return (!empty($key['fromMe'])
            ? $meId
            : ($key['participantAlt'] ?? $key['remoteJidAlt'] ?? $key['participant'] ?? $key['remoteJid'] ?? '')) ?: '';
    }

    private function getStoredMessage(array $key): ?array
    {
        $cb = $this->config['getMessage'] ?? null;
        if (is_callable($cb)) {
            $msg = $cb($key);
            return is_array($msg) ? $msg : null;
        }
        return null;
    }

    /** cleanMessage: normalise remoteJid/participant + reaction/poll keys. */
    public function cleanMessage(array $message): array
    {
        $meId = $this->creds()->me['id'] ?? '';
        $meLid = $this->creds()->me['lid'] ?? '';
        $norm = function (?string $jid): ?string {
            if ($jid === null || $jid === '') {
                return $jid;
            }
            if (Jid::isHostedPnUser($jid) || Jid::isHostedLidUser($jid)) {
                $dec = Jid::decode($jid);
                return Jid::encode($dec['user'] ?? '', Jid::isHostedPnUser($jid) ? 's.whatsapp.net' : 'lid');
            }
            return Jid::normalizedUser($jid);
        };
        $message['key']['remoteJid'] = $norm($message['key']['remoteJid'] ?? '');
        if (isset($message['key']['participant'])) {
            $message['key']['participant'] = $norm($message['key']['participant']);
        }
        $content = Messages::normalizeMessageContent($message['message'] ?? null);
        $normaliseKey = function (array $msgKey) use ($message, $meId, $meLid): array {
            if (empty($message['key']['fromMe'])) {
                $author = $msgKey['participant'] ?? $msgKey['remoteJid'] ?? '';
                $msgKey['fromMe'] = empty($msgKey['fromMe'])
                    ? (Jid::areSameUser($author, $meId) || Jid::areSameUser($author, $meLid))
                    : false;
                $msgKey['remoteJid'] = $message['key']['remoteJid'];
                $msgKey['participant'] = $msgKey['participant'] ?? ($message['key']['participant'] ?? null);
            }
            return $msgKey;
        };
        // apply to the live message (through wrappers we mutate a reference copy)
        $apply = function (array &$msgArr) use (&$apply, $normaliseKey) {
            foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
                if (isset($msgArr[$w]['message'])) {
                    $apply($msgArr[$w]['message']);
                    return;
                }
            }
            if (isset($msgArr['reactionMessage']['key'])) {
                $msgArr['reactionMessage']['key'] = $normaliseKey($msgArr['reactionMessage']['key']);
            }
            if (isset($msgArr['pollUpdateMessage']['pollCreationMessageKey'])) {
                $msgArr['pollUpdateMessage']['pollCreationMessageKey'] = $normaliseKey($msgArr['pollUpdateMessage']['pollCreationMessageKey']);
            }
        };
        if (isset($message['message']) && is_array($message['message'])) {
            $apply($message['message']);
        }
        return $message;
    }

    public static function isRealMessage(array $message): bool
    {
        $normalized = Messages::normalizeMessageContent($message['message'] ?? null);
        $hasContent = Messages::getContentType($normalized) !== null;
        $realStubs = [
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_PARTICIPANT_ADD'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_CREATE'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_CHANGE_SUBJECT'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_PARTICIPANT_LEAVE'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_PARTICIPANT_REMOVE'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_CHANGE_ANNOUNCE'),
            WAProto::enumValue('WebMessageInfo.StubType', 'GROUP_CHANGE_RESTRICT'),
        ];
        $hasSome = $normalized !== null || in_array($message['messageStubType'] ?? -1, $realStubs, true);
        return $hasSome && $hasContent
            && !isset($normalized['protocolMessage'])
            && !isset($normalized['reactionMessage'])
            && !isset($normalized['pollUpdateMessage']);
    }

    private function getChatId(array $key): string
    {
        $remoteJid = $key['remoteJid'] ?? '';
        if (Jid::isBroadcast($remoteJid) && !Jid::isStatusBroadcast($remoteJid) && empty($key['fromMe'])) {
            return (string)($key['participant'] ?? $remoteJid);
        }
        return $remoteJid;
    }

    // ------------------------------------------------------- poll / event crypto
    /** decryptPollVote(encValue, ctx) → PollVoteMessage array */
    public static function decryptPollVote(array $vote, string $pollCreatorJid, string $pollMsgId, string $pollEncKey, string $voterJid): array
    {
        return self::decryptEncValue($vote, 'Poll Vote', 'Message.PollVoteMessage', $pollCreatorJid, $pollMsgId, $pollEncKey, $voterJid);
    }

    /** decryptEventResponse(encValue, ctx) → EventResponseMessage array */
    public static function decryptEventResponse(array $resp, string $eventCreatorJid, string $eventMsgId, string $eventEncKey, string $responderJid): array
    {
        return self::decryptEncValue($resp, 'Event Response', 'Message.EventResponseMessage', $eventCreatorJid, $eventMsgId, $eventEncKey, $responderJid);
    }

    private static function decryptEncValue(array $enc, string $label, string $proto, string $creatorJid, string $msgId, string $encKey, string $actorJid): array
    {
        $sign = $msgId . $creatorJid . $actorJid . $label . "\x01";
        $key0 = hash_hmac('sha256', str_repeat("\0", 32), $encKey, true);
        $decKey = hash_hmac('sha256', $sign, $key0, true);
        $aad = $msgId . "\0" . $actorJid;
        $plain = \BaileysPHP\Utils\aesDecryptGCM((string)$enc['encPayload'], $decKey, (string)$enc['encIv'], $aad);
        return WAProto::decodeProto($proto, $plain);
    }

    // ------------------------------------------------------------- main entry
    /**
     * processMessage — derive chats.update + protocol side-effects for one WAMessage.
     * Called after 'messages.upsert' emission for live traffic (Baileys calls it inside upsertMessage).
     */
    public function processMessage(array $message): void
    {
        $meId = $this->creds()->me['id'] ?? '';
        $chat = ['id' => Jid::normalizedUser($this->getChatId($message['key']))];
        $isReal = self::isRealMessage($message);
        if ($isReal) {
            $chat['messages'] = [['message' => $message]];
            $chat['conversationTimestamp'] = (int)($message['messageTimestamp'] ?? time());
            if (empty($message['key']['fromMe']) && empty($message['messageStubType'])) {
                $chat['unreadCount'] = ($chat['unreadCount'] ?? 0) + 1;
            }
        }
        $content = Messages::normalizeMessageContent($message['message'] ?? null);
        if (($isReal || !empty($content['reactionMessage']['key']['fromMe']))
            && !empty($this->creds()->accountSettings['unarchiveChats'])) {
            $chat['archived'] = false;
            $chat['readOnly'] = false;
        }

        if (isset($content['protocolMessage'])) {
            $this->processProtocolMessage($message, $content['protocolMessage'], $chat);
        } elseif (isset($content['reactionMessage'])) {
            $reaction = $content['reactionMessage'];
            $reaction['key'] = $message['key'];
            $this->ev->emit('messages.reaction', [[
                'reaction' => $reaction,
                'key' => $content['reactionMessage']['key'] ?? null,
            ]]);
        } elseif (isset($content['pollUpdateMessage'])) {
            $this->processPollUpdate($message, $content['pollUpdateMessage']);
        } elseif (isset($content['encEventResponseMessage'])) {
            $this->processEventResponse($message, $content['encEventResponseMessage']);
        } elseif (isset($content['encReactionMessage'])) {
            // encrypted reactions need the creation message secret; emitted raw in upsert
            $this->logger->debug('encReactionMessage received (left encrypted)');
        } elseif (!empty($message['messageStubType'])) {
            $this->processStubMessage($message, $chat);
        }

        if (count($chat) > 1) {
            $this->ev->emit('chats.update', [$chat]);
        }
    }

    private function processProtocolMessage(array $message, array $protocolMsg, array &$chat): void
    {
        $pt = WAProto::enumName('Message.ProtocolMessage.Type', $protocolMsg['type'] ?? null);
        $selfOnly = ['HISTORY_SYNC_NOTIFICATION', 'APP_STATE_SYNC_KEY_SHARE', 'LID_MIGRATION_MAPPING_SYNC', 'PEER_DATA_OPERATION_REQUEST_RESPONSE_MESSAGE'];
        if ($pt !== null && in_array($pt, $selfOnly, true) && empty($message['key']['fromMe'])) {
            $this->logger->warn(['type' => $pt], 'dropping spoofed self-only protocolMessage from non-self origin');
            return;
        }

        switch ($pt) {
            case 'HISTORY_SYNC_NOTIFICATION':
                $this->processHistorySyncNotification($message, $protocolMsg['historySyncNotification'] ?? []);
                break;
            case 'APP_STATE_SYNC_KEY_SHARE':
                $keys = $protocolMsg['appStateSyncKeyShare']['keys'] ?? [];
                $newId = '';
                foreach ($keys as $entry) {
                    $idRaw = $entry['keyId']['keyId'] ?? $entry['keyId']['id'] ?? null;
                    $keyData = $entry['keyData'] ?? null;
                    if (!is_string($idRaw) || !is_array($keyData)) {
                        continue;
                    }
                    $newId = base64_encode($idRaw);
                    $this->authState->keys->set(['app-state-sync-key' => [$newId => $keyData]]);
                }
                if ($keys !== []) {
                    $this->logger->info(['newAppStateSyncKeyId' => $newId], 'injecting new app state sync keys');
                    $this->creds()->myAppStateKeyId = $newId;
                    $this->ev->emit('creds.update', ['myAppStateKeyId' => $newId]);
                    $this->authState->saveCreds();
                    if ($this->blockedAppStateCollections !== []) {
                        $cols = array_keys($this->blockedAppStateCollections);
                        $this->blockedAppStateCollections = [];
                        $this->resyncAppState($cols, false);
                    }
                    $this->doInitialAppStateSync();
                }
                break;
            case 'REVOKE':
                $this->ev->emit('messages.update', [[
                    'key' => array_merge($message['key'], ['id' => $protocolMsg['key']['id'] ?? null]),
                    'update' => [
                        'message' => null,
                        'messageStubType' => WAProto::enumValue('WebMessageInfo.StubType', 'REVOKE'),
                        'key' => $message['key'],
                    ],
                ]]);
                break;
            case 'EPHEMERAL_SETTING':
                $chat['ephemeralSettingTimestamp'] = (int)($message['messageTimestamp'] ?? time());
                $chat['ephemeralExpiration'] = $protocolMsg['ephemeralExpiration'] ?? null;
                break;
            case 'PEER_DATA_OPERATION_REQUEST_RESPONSE_MESSAGE':
                $this->processPeerDataOperationResponse($message, $protocolMsg['peerDataOperationRequestResponseMessage'] ?? []);
                break;
            case 'MESSAGE_EDIT':
                $this->ev->emit('messages.update', [[
                    'key' => array_merge($message['key'], ['id' => $protocolMsg['key']['id'] ?? null]),
                    'update' => [
                        'message' => ['editedMessage' => ['message' => $protocolMsg['editedMessage'] ?? null]],
                        'messageTimestamp' => isset($protocolMsg['timestampMs'])
                            ? intdiv((int)$protocolMsg['timestampMs'], 1000)
                            : ($message['messageTimestamp'] ?? null),
                    ],
                ]]);
                break;
            case 'GROUP_MEMBER_LABEL_CHANGE':
                $label = $protocolMsg['memberLabel']['label'] ?? null;
                if ($label) {
                    $this->ev->emit('group.member-tag.update', [
                        'groupId' => $chat['id'],
                        'label' => $label,
                        'participant' => $message['key']['participant'] ?? null,
                        'participantAlt' => $message['key']['participantAlt'] ?? null,
                        'messageTimestamp' => (int)($message['messageTimestamp'] ?? time()),
                    ]);
                }
                break;
            case 'LID_MIGRATION_MAPPING_SYNC':
                $payload = (string)($protocolMsg['lidMigrationMappingSyncMessage']['encodedMappingPayload'] ?? '');
                if ($payload !== '') {
                    $decoded = WAProto::decodeProto('LIDMigrationMappingSyncPayload', $payload);
                    $pairs = [];
                    foreach (($decoded['pnToLidMappings'] ?? []) as $m) {
                        $lid = $m['latestLid'] ?? $m['assignedLid'] ?? null;
                        if ($lid && isset($m['pn'])) {
                            $pairs[] = ['lid' => $lid . '@lid', 'pn' => $m['pn'] . '@s.whatsapp.net'];
                        }
                    }
                    if ($pairs !== []) {
                        $this->signalRepository->lidMapping->storeLIDPNMappings($pairs);
                        foreach ($pairs as $pair) {
                            $this->signalRepository->migrateSession($pair['pn'], $pair['lid']);
                            $this->ev->emit('lid-mapping.update', $pair);
                        }
                    }
                }
                break;
            case 'SHARE_PHONE_NUMBER':
                $this->ev->emit('messages.update', [[
                    'key' => $message['key'],
                    'update' => ['messageStubType' => WAProto::enumValue('WebMessageInfo.StubType', 'UNKNOWN')],
                ]]);
                break;
        }
    }

    private function processPollUpdate(array $message, array $pollUpdate): void
    {
        $creationKey = $pollUpdate['pollCreationMessageKey'] ?? null;
        if (!$creationKey || empty($pollUpdate['vote'])) {
            return;
        }
        $pollMsg = $this->getStoredMessage($creationKey);
        if ($pollMsg === null) {
            $this->logger->warn(['creationKey' => $creationKey], 'poll creation message not found (set config getMessage), cannot decrypt vote');
            return;
        }
        try {
            $meNorm = Jid::normalizedUser($this->creds()->me['id'] ?? '');
            $creator = self::getKeyAuthor($creationKey, $meNorm);
            $voter = self::getKeyAuthor($message['key'], $meNorm);
            $encKey = $pollMsg['messageContextInfo']['messageSecret'] ?? null;
            if (!is_string($encKey)) {
                throw new \RuntimeException('poll creation message has no messageSecret');
            }
            $vote = self::decryptPollVote($pollUpdate['vote'], Jid::normalizedUser($creator), (string)$creationKey['id'], $encKey, $voter);
            $this->ev->emit('messages.update', [[
                'key' => $creationKey,
                'update' => [
                    'pollUpdates' => [[
                        'pollUpdateMessageKey' => $message['key'],
                        'vote' => $vote,
                        'senderTimestampMs' => (int)($pollUpdate['senderTimestampMs'] ?? 0),
                    ]],
                ],
            ]]);
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'failed to decrypt poll vote');
        }
    }

    private function processEventResponse(array $message, array $encResponse): void
    {
        $creationKey = $encResponse['eventCreationMessageKey'] ?? null;
        if (!$creationKey) {
            return;
        }
        $eventMsg = $this->getStoredMessage($creationKey);
        if ($eventMsg === null) {
            $this->logger->warn('event creation message not found (set config getMessage), cannot decrypt response');
            return;
        }
        try {
            $meNorm = Jid::normalizedUser($this->creds()->me['id'] ?? '');
            $creatorKey = $creationKey['participant'] ?? $creationKey['remoteJid'] ?? '';
            $creatorPn = Jid::isLidUser($creatorKey)
                ? ($this->signalRepository->lidMapping->getPNForLID($creatorKey) ?? $creatorKey)
                : $creatorKey;
            $creatorJid = self::getKeyAuthor(
                ['remoteJid' => Jid::normalizedUser($creatorPn), 'fromMe' => $meNorm === Jid::normalizedUser($creatorPn)],
                $meNorm
            );
            $responder = self::getKeyAuthor($message['key'], $meNorm);
            $encKey = $eventMsg['messageContextInfo']['messageSecret'] ?? null;
            if (!is_string($encKey)) {
                throw new \RuntimeException('missing messageSecret');
            }
            $resp = self::decryptEventResponse($encResponse, $creatorJid, (string)$creationKey['id'], $encKey, $responder);
            $this->ev->emit('messages.update', [[
                'key' => $creationKey,
                'update' => [
                    'eventResponses' => [[
                        'eventResponseMessageKey' => $message['key'],
                        'senderTimestampMs' => (int)($resp['timestampMs'] ?? 0),
                        'response' => $resp,
                    ]],
                ],
            ]]);
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'failed to decrypt event response');
        }
    }

    private function processStubMessage(array $message, array &$chat): void
    {
        $stub = WAProto::enumName('WebMessageInfo.StubType', $message['messageStubType'] ?? null);
        $jid = $message['key']['remoteJid'] ?? '';
        $params = $message['messageStubParameters'] ?? [];
        $participants = array_map(fn($a) => is_string($a) ? (json_decode($a, true) ?: ['id' => $a]) : $a, $params);
        $author = [
            'id' => $jid,
            'author' => $message['key']['participant'] ?? null,
            'authorPn' => $message['key']['participantAlt'] ?? null,
            'authorUsername' => $message['key']['participantUsername'] ?? null,
        ];
        switch ($stub) {
            case 'GROUP_PARTICIPANT_CHANGE_NUMBER':
                $this->ev->emit('group-participants.update', $author + ['participants' => $participants, 'action' => 'modify']);
                break;
            case 'GROUP_PARTICIPANT_LEAVE':
            case 'GROUP_PARTICIPANT_REMOVE':
                $this->ev->emit('group-participants.update', $author + ['participants' => $participants, 'action' => 'remove']);
                break;
            case 'GROUP_PARTICIPANT_ADD':
            case 'GROUP_PARTICIPANT_INVITE':
            case 'GROUP_PARTICIPANT_ADD_REQUEST_JOIN':
                $this->ev->emit('group-participants.update', $author + ['participants' => $participants, 'action' => 'add']);
                break;
            case 'GROUP_PARTICIPANT_DEMOTE':
                $this->ev->emit('group-participants.update', $author + ['participants' => $participants, 'action' => 'demote']);
                break;
            case 'GROUP_PARTICIPANT_PROMOTE':
                $this->ev->emit('group-participants.update', $author + ['participants' => $participants, 'action' => 'promote']);
                break;
            case 'GROUP_CHANGE_ANNOUNCE':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'announce' => in_array($params[0] ?? '', ['true', 'on'], true)] + $author]);
                break;
            case 'GROUP_CHANGE_RESTRICT':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'restrict' => in_array($params[0] ?? '', ['true', 'on'], true)] + $author]);
                break;
            case 'GROUP_CHANGE_SUBJECT':
                $chat['name'] = $params[0] ?? null;
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'subject' => $params[0] ?? null] + $author]);
                break;
            case 'GROUP_CHANGE_DESCRIPTION':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'desc' => $params[0] ?? null] + $author]);
                break;
            case 'GROUP_CHANGE_INVITE_LINK':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'inviteCode' => $params[0] ?? null] + $author]);
                break;
            case 'GROUP_MEMBER_ADD_MODE':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'memberAddMode' => ($params[0] ?? '') === 'all_member_add'] + $author]);
                break;
            case 'GROUP_MEMBERSHIP_JOIN_APPROVAL_MODE':
                $this->ev->emit('groups.update', [[ 'id' => $jid, 'joinApprovalMode' => ($params[0] ?? '') === 'on'] + $author]);
                break;
            case 'GROUP_MEMBERSHIP_JOIN_APPROVAL_REQUEST_NON_ADMIN_ADD':
                $participant = is_string($params[0] ?? null) ? (json_decode($params[0], true) ?: []) : [];
                $this->ev->emit('group.join-request', [
                    'id' => $jid,
                    'author' => $author['author'],
                    'authorPn' => $author['authorPn'],
                    'authorUsername' => $author['authorUsername'],
                    'participant' => $participant['lid'] ?? null,
                    'participantPn' => $participant['pn'] ?? null,
                    'action' => $params[1] ?? null,
                    'method' => $params[2] ?? null,
                ]);
                break;
        }
    }

    // ------------------------------------------------------------ history sync
    /** downloadHistory: decrypt (md-msg-hist) + inflate + decode HistorySync. */
    public function downloadHistory(array $notification): array
    {
        $compressed = $this->downloadContentFromMessage($notification, 'md-msg-hist');
        $inflated = @gzuncompress($compressed);
        if ($inflated === false) {
            throw new \RuntimeException('failed to inflate history sync data');
        }
        return WAProto::decodeProto('HistorySync', $inflated);
    }

    /** processHistoryMessage — split a HistorySync proto into chats/contacts/messages. */
    public function processHistoryMessage(array $item): array
    {
        $messages = [];
        $contacts = [];
        $chats = [];
        $lidPnMappings = [];
        foreach (($item['phoneNumberToLidMappings'] ?? []) as $m) {
            if (!empty($m['lidJid']) && !empty($m['pnJid'])) {
                $lidPnMappings[] = ['lid' => $m['lidJid'], 'pn' => $m['pnJid']];
            }
        }
        $syncType = WAProto::enumName('HistorySync.HistorySyncType', $item['syncType'] ?? null);
        if (in_array($syncType, ['INITIAL_BOOTSTRAP', 'RECENT', 'FULL', 'ON_DEMAND'], true)) {
            $bizToBsp = WAProto::enumValue('WebMessageInfo.StubType', 'BIZ_PRIVACY_MODE_TO_BSP');
            $bizToFb = WAProto::enumValue('WebMessageInfo.StubType', 'BIZ_PRIVACY_MODE_TO_FB');
            foreach (($item['conversations'] ?? []) as $chat) {
                $contacts[] = [
                    'id' => $chat['id'] ?? null,
                    'name' => $chat['displayName'] ?? $chat['name'] ?? $chat['username'] ?? null,
                    'username' => $chat['username'] ?? null,
                    'lid' => $chat['lidJid'] ?? $chat['accountLid'] ?? null,
                    'phoneNumber' => $chat['pnJid'] ?? null,
                ];
                $chatId = $chat['id'] ?? '';
                $isLid = Jid::isLidUser($chatId) || Jid::isHostedLidUser($chatId);
                $isPn = Jid::isPnUser($chatId) || Jid::isHostedPnUser($chatId);
                if ($isLid && !empty($chat['pnJid'])) {
                    $lidPnMappings[] = ['lid' => $chatId, 'pn' => $chat['pnJid']];
                } elseif ($isPn && !empty($chat['lidJid'])) {
                    $lidPnMappings[] = ['lid' => $chat['lidJid'], 'pn' => $chatId];
                }
                $msgs = $chat['messages'] ?? [];
                unset($chat['messages']);
                foreach ($msgs as $msgItem) {
                    $message = $msgItem['message'] ?? null;
                    if (!is_array($message)) {
                        continue;
                    }
                    $messages[] = $message;
                    if (empty($chat['messages'])) {
                        $chat['messages'] = [['message' => $message]];
                    }
                    if (empty($message['key']['fromMe']) && empty($chat['lastMessageRecvTimestamp'])) {
                        $chat['lastMessageRecvTimestamp'] = (int)($message['messageTimestamp'] ?? 0);
                    }
                    if (in_array($message['messageStubType'] ?? -1, [$bizToBsp, $bizToFb], true)
                        && !empty($message['messageStubParameters'][0])) {
                        $contacts[] = [
                            'id' => $message['key']['participant'] ?? $message['key']['remoteJid'] ?? null,
                            'verifiedName' => $message['messageStubParameters'][0],
                        ];
                    }
                }
                $chats[] = $chat;
            }
        } elseif ($syncType === 'PUSH_NAME') {
            foreach (($item['pushnames'] ?? []) as $c) {
                $contacts[] = ['id' => $c['id'] ?? null, 'notify' => $c['pushname'] ?? null];
            }
        }
        return [
            'chats' => $chats,
            'contacts' => $contacts,
            'messages' => $messages,
            'lidPnMappings' => $lidPnMappings,
            'pastParticipants' => $item['pastParticipants'] ?? null,
            'syncType' => $item['syncType'] ?? null,
            'progress' => $item['progress'] ?? null,
        ];
    }

    /** downloadAndProcessHistorySyncNotification */
    public function downloadAndProcessHistorySyncNotification(array $notification): array
    {
        if (!empty($notification['initialHistBootstrapInlinePayload'])) {
            $inflated = @gzuncompress((string)$notification['initialHistBootstrapInlinePayload']);
            if ($inflated === false) {
                throw new \RuntimeException('failed to inflate inline history payload');
            }
            $history = WAProto::decodeProto('HistorySync', $inflated);
        } else {
            $history = $this->downloadHistory($notification);
        }
        return $this->processHistoryMessage($history);
    }

    private function processHistorySyncNotification(array $message, array $histNotification): void
    {
        $process = $this->config['shouldProcessHistoryMsg'] ?? true;
        $isLatest = ($this->creds()->processedHistoryMessages ?? []) === [];
        if (!$process) {
            return;
        }
        $onDemand = WAProto::enumValue('HistorySync.HistorySyncType', 'ON_DEMAND');
        $isOnDemand = (int)($histNotification['syncType'] ?? -1) === $onDemand;
        if (!$isOnDemand) {
            $this->creds()->processedHistoryMessages = array_merge($this->creds()->processedHistoryMessages ?? [], [[
                'key' => $message['key'],
                'messageTimestamp' => $message['messageTimestamp'] ?? null,
            ]]);
            $this->ev->emit('creds.update', ['processedHistoryMessages' => $this->creds()->processedHistoryMessages]);
            $this->authState->saveCreds();
        }
        try {
            $data = $this->downloadAndProcessHistorySyncNotification($histNotification);
        } catch (\Throwable $e) {
            $this->logger->error(['err' => $e->getMessage()], 'failed to download/process history sync');
            return;
        }
        if (!empty($data['lidPnMappings'])) {
            $this->signalRepository->lidMapping->storeLIDPNMappings($data['lidPnMappings']);
        }
        // store tc tokens carried in history chats (chat.tcToken / tcTokenSenderTimestamp)
        $entries = [];
        foreach (($data['chats'] ?? []) as $chat) {
            $cid = $chat['id'] ?? '';
            if (!empty($chat['tcToken']) && $cid !== '') {
                $storage = $this->resolveTcTokenJid(Jid::normalizedUser($cid));
                $entries[$storage] = [
                    'token' => (string)$chat['tcToken'],
                    'timestamp' => (string)($chat['tcTokenTimestamp'] ?? time()),
                ];
                if (!empty($chat['tcTokenSenderTimestamp'])) {
                    $entries[$storage]['senderTimestamp'] = (int)$chat['tcTokenSenderTimestamp'];
                }
            }
        }
        if ($entries !== []) {
            $this->authState->keys->set(['tctoken' => $entries]);
        }
        $this->doInitialAppStateSync();
        $this->ev->emit('messaging-history.set', array_merge($data, [
            'isLatest' => $isOnDemand ? null : $isLatest,
            'chunkOrder' => $histNotification['chunkOrder'] ?? null,
            'peerDataRequestSessionId' => $histNotification['peerDataRequestSessionId'] ?? null,
        ]));
    }

    private function processPeerDataOperationResponse(array $message, array $response): void
    {
        foreach (($response['peerDataOperationResult'] ?? []) as $result) {
            $retryResponse = $result['placeholderMessageResendResponse'] ?? null;
            if (empty($retryResponse['webMessageInfoBytes'])) {
                continue;
            }
            try {
                $webInfo = WAProto::decodeWebMessageInfo((string)$retryResponse['webMessageInfoBytes']);
                $msgId = $webInfo['key']['id'] ?? null;
                $cached = $msgId ? ($this->placeholderResendCache[$msgId] ?? null) : null;
                if ($msgId) {
                    unset($this->placeholderResendCache[$msgId]);
                }
                if (is_array($cached)) {
                    $cached['message'] = $webInfo['message'] ?? null;
                    if (!empty($webInfo['messageTimestamp'])) {
                        $cached['messageTimestamp'] = $webInfo['messageTimestamp'];
                    }
                    $final = $cached;
                } else {
                    $final = $webInfo;
                }
                $this->ev->emit('messages.upsert', [
                    'messages' => [$final],
                    'type' => 'notify',
                    'requestId' => $response['stanzaId'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage()], 'failed to decode placeholder resend response');
            }
        }
    }

    // -------------------------------------------------------------- PDO sends
    /** sendPeerDataOperationMessage(pdoMessage) → message id */
    public function sendPeerDataOperationMessage(array $pdoMessage): string
    {
        $meId = $this->creds()->me['id'] ?? null;
        if (!$meId) {
            throw new \RuntimeException('Not authenticated');
        }
        $protocolMessage = [
            'protocolMessage' => [
                'peerDataOperationRequestMessage' => $pdoMessage,
                'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'PEER_DATA_OPERATION_REQUEST_MESSAGE'),
            ],
        ];
        return $this->relayMessage(Jid::normalizedUser($meId), $protocolMessage, [
            'additionalAttributes' => ['category' => 'peer', 'push_priority' => 'high_force'],
            'additionalNodes' => [new BinaryNode('meta', ['appdata' => 'default'])],
        ]);
    }

    /** fetchMessageHistory(count, oldestMsgKey, oldestMsgTimestampSeconds) → request id */
    public function fetchMessageHistory(int $count, array $oldestMsgKey, int $oldestMsgTimestamp): string
    {
        return $this->sendPeerDataOperationMessage([
            'historySyncOnDemandRequest' => [
                'chatJid' => $oldestMsgKey['remoteJid'] ?? null,
                'oldestMsgFromMe' => !empty($oldestMsgKey['fromMe']),
                'oldestMsgId' => $oldestMsgKey['id'] ?? null,
                'oldestMsgTimestampMs' => $oldestMsgTimestamp * 1000,
                'onDemandMsgCount' => $count,
            ],
            'peerDataOperationRequestType' => WAProto::enumValue('Message.PeerDataOperationRequestType', 'HISTORY_SYNC_ON_DEMAND'),
        ]);
    }

    /** requestPlaceholderResend(messageKey, msgData?) → request id */
    public function requestPlaceholderResend(array $messageKey, ?array $msgData = null): ?string
    {
        $id = $messageKey['id'] ?? '';
        if ($id === '') {
            return null;
        }
        if (isset($this->placeholderResendCache[$id])) {
            return null; // already requested
        }
        $this->placeholderResendCache[$id] = $msgData ?? true;
        return $this->sendPeerDataOperationMessage([
            'placeholderMessageResendRequest' => [['messageKey' => $messageKey]],
            'peerDataOperationRequestType' => WAProto::enumValue('Message.PeerDataOperationRequestType', 'PLACEHOLDER_MESSAGE_RESEND'),
        ]);
    }

    /** updateMemberLabel(groupJid, label) */
    public function updateMemberLabel(string $jid, string $memberLabel): string
    {
        return $this->relayMessage($jid, [
            'protocolMessage' => [
                'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'GROUP_MEMBER_LABEL_CHANGE'),
                'memberLabel' => [
                    'label' => mb_substr($memberLabel, 0, 30),
                    'labelTimestamp' => time(),
                ],
            ],
        ], [
            'additionalNodes' => [new BinaryNode('meta', ['tag_reason' => 'user_update', 'appdata' => 'member_tag'])],
        ]);
    }

    // --------------------------------------------------------------- retries
    /**
     * Send a retry receipt for a message we failed to decrypt
     * (messages-recv sendRetryRequest, without the scheduled-PDO extras).
     */
    public function sendRetryRequest(BinaryNode $stanza, bool $forceIncludeKeys = false): void
    {
        $msgId = $stanza->attrs['id'] ?? '';
        $participant = $stanza->attrs['participant'] ?? '';
        $cacheKey = $msgId . ':' . $participant;

        if ($this->retryManager !== null) {
            if ($this->retryManager->hasExceededMaxRetries($msgId)) {
                $this->logger->debug(['msgId' => $msgId], 'reached retry limit with retry manager, clearing');
                $this->retryManager->markRetryFailed($msgId);
                return;
            }
            $count = $this->retryManager->incrementRetryCount($msgId);
            $this->msgRetryCounter[$cacheKey] = $count;
        } else {
            $max = (int)($this->config['maxMsgRetryCount'] ?? 5);
            $count = $this->msgRetryCounter[$cacheKey] ?? 0;
            if ($count >= $max) {
                unset($this->msgRetryCounter[$cacheKey]);
                $this->logger->debug(['msgId' => $msgId], 'reached retry limit, clearing');
                return;
            }
            $count++;
            $this->msgRetryCounter[$cacheKey] = $count;
        }

        // session recreation (messages-recv: retryCount > 1)
        $shouldRecreateSession = false;
        $fromJid = $stanza->attrs['from'] ?? '';
        $sessionJid = $participant !== '' ? $participant : $fromJid;
        if (($this->config['enableAutoSessionRecreation'] ?? true) && $this->retryManager !== null && $count > 1) {
            try {
                $hasSession = $this->signalRepository->validateSession($sessionJid);
                $result = $this->retryManager->shouldRecreateSession($sessionJid, $hasSession['exists'] ?? false);
                $shouldRecreateSession = $result['recreate'];
                if ($shouldRecreateSession) {
                    $this->logger->debug(
                        ['fromJid' => $sessionJid, 'retryCount' => $count, 'reason' => $result['reason']],
                        'recreating session for retry'
                    );
                    $this->signalRepository->deleteSession([$sessionJid]);
                    $forceIncludeKeys = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage(), 'jid' => $sessionJid], 'failed to check session recreation');
            }
        }

        // ask the phone to resend after a short delay (whatsmeow-style)
        if ($count <= 2) {
            $meId = $this->creds()->me['id'] ?? '';
            $origKey = [
                'remoteJid' => $fromJid,
                'id' => $msgId,
                'participant' => $participant !== '' ? $participant : null,
                'fromMe' => Jid::areSameUser($sessionJid, $meId)
                    || (!empty($this->creds()->me['lid']) && Jid::areSameUser($sessionJid, $this->creds()->me['lid'])),
            ];
            if ($this->retryManager !== null) {
                $this->retryManager->schedulePhoneRequest($msgId, function () use ($origKey, $msgId) {
                    try {
                        $reqId = $this->requestPlaceholderResend($origKey);
                        $this->logger->debug("sendRetryRequest: requested placeholder resend ($reqId) for message $msgId (scheduled)");
                    } catch (\Throwable $e) {
                        $this->logger->warn(['err' => $e->getMessage(), 'msgId' => $msgId], 'failed to send scheduled phone request');
                    }
                });
            } else {
                $this->requestPlaceholderResend($origKey);
            }
        }

        $content = [
            new BinaryNode('retry', [
                'count' => (string)$count,
                'id' => $msgId,
                't' => (string)($stanza->attrs['t'] ?? time()),
                'v' => '1',
                'error' => '0',
            ]),
            new BinaryNode('registration', [], new Bytes(Generics::encodeBigEndian($this->creds()->registrationId))),
        ];
        if ($count > 1 || $forceIncludeKeys || $shouldRecreateSession) {
            ['update' => $update, 'preKeys' => $preKeys] = SignalUtils::getNextPreKeys($this->authState, 1);
            $keyId = (int)array_key_first($preKeys);
            $content[] = new BinaryNode('keys', [], [
                new BinaryNode('type', [], new Bytes("\x05")),
                new BinaryNode('identity', [], new Bytes($this->creds()->signedIdentityKey->public)),
                SignalUtils::xmppPreKey($preKeys[$keyId], $keyId),
                SignalUtils::xmppSignedPreKey($this->creds()->signedPreKey),
                new BinaryNode('device-identity', [], new Bytes(
                    ValidateConnection::encodeSignedDeviceIdentity($this->creds()->account ?? [], true)
                )),
            ]);
            $this->creds()->applyUpdate([
                'nextPreKeyId' => $update['nextPreKeyId'] ?? $this->creds()->nextPreKeyId,
                'firstUnuploadedPreKeyId' => $update['firstUnuploadedPreKeyId'] ?? $this->creds()->firstUnuploadedPreKeyId,
            ]);
            $this->ev->emit('creds.update', $update);
            $this->authState->saveCreds();
        }
        $attrs = ['id' => $msgId, 'type' => 'retry', 'to' => $stanza->attrs['from'] ?? ''];
        if (isset($stanza->attrs['recipient'])) {
            $attrs['recipient'] = $stanza->attrs['recipient'];
        }
        if (isset($stanza->attrs['participant'])) {
            $attrs['participant'] = $stanza->attrs['participant'];
        }
        $this->sendNode(new BinaryNode('receipt', $attrs, $content));
        $this->logger->info(['msgId' => $msgId, 'retryCount' => $count], 'sent retry receipt');
    }

    /** Handle an incoming retry receipt: re-send the original message(s). */
    private function handleRetryReceipt(BinaryNode $node): void
    {
        $retryNode = GenericUtils::getBinaryNodeChild($node, 'retry');
        if ($retryNode === null) {
            return;
        }
        $from = $node->attrs['from'] ?? '';
        $participantAttr = $node->attrs['participant'] ?? null;
        $ids = [];
        if (!empty($node->attrs['id'])) {
            $ids[] = $node->attrs['id'];
        }
        $list = GenericUtils::getBinaryNodeChild($node, 'list');
        foreach (GenericUtils::getBinaryNodeChildren($list, 'item') as $item) {
            if (!empty($item->attrs['id'])) {
                $ids[] = $item->attrs['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $remoteJid = Jid::isGroup($from) || Jid::isStatusBroadcast($from) ? $from : ($node->attrs['recipient'] ?? $from);
        $requester = $participantAttr ?? $from;
        $count = (int)($retryNode->attrs['count'] ?? 1);
        if ($count < 1) {
            $count = 1;
        }
        $msgId = $ids[0];

        // ---- resolve messages: recent retry cache first, then config getMessage
        $msgs = [];
        foreach ($ids as $id) {
            $msg = null;
            if ($this->retryManager !== null) {
                $cached = $this->retryManager->getRecentMessage($remoteJid, $id);
                if ($cached !== null) {
                    $msg = $cached['message'];
                    $this->logger->debug(['jid' => $remoteJid, 'id' => $id], 'found message in retry cache');
                    $this->retryManager->markRetrySuccess($id);
                }
            }
            if ($msg === null) {
                $msg = $this->getStoredMessage(['remoteJid' => $remoteJid, 'id' => $id, 'fromMe' => true, 'participant' => $requester]);
                if ($msg !== null) {
                    $this->logger->debug(['jid' => $remoteJid, 'id' => $id], 'found message via getMessage');
                    $this->retryManager?->markRetrySuccess($id);
                }
            }
            $msgs[] = $msg;
        }

        // primary-device requester → resend to everyone (prevents first-msg decrypt failure)
        $reqDevice = Jid::decode($requester)['device'] ?? null;
        $sendToAll = $reqDevice === null || $reqDevice === 0;
        $sessionAddr = \BaileysPHP\Signal\SignalRepository::signalAddressString($requester);

        // ---- session from retry key bundle
        $injectedFromBundle = false;
        $bundle = $this->extractE2ESessionFromRetryReceipt($node);
        if ($bundle !== null) {
            try {
                $this->signalRepository->injectE2ESession(['jid' => $requester, 'session' => $bundle]);
                $injectedFromBundle = true;
                $this->logger->debug(['participant' => $requester, 'retryCount' => $count], 'injected session from retry receipt key bundle');
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage()], 'failed to inject session from retry receipt');
            }
        }

        if (!$injectedFromBundle) {
            // registration-id mismatch → stale session, delete it
            $receivedRegId = GenericUtils::getBinaryNodeChildUInt($node, 'registration', 4);
            if ($receivedRegId !== null) {
                $info = $this->signalRepository->getSessionInfo($requester);
                if ($info !== null && $info['registrationId'] !== 0 && $info['registrationId'] !== $receivedRegId) {
                    $this->logger->info(
                        ['participant' => $requester, 'stored' => $info['registrationId'], 'received' => $receivedRegId],
                        'reg id mismatch on retry without bundle, deleting session'
                    );
                    $this->signalRepository->deleteSession([$requester]);
                }
            }
        }

        // ---- base-key collision detection
        if ($this->retryManager !== null) {
            $info = $this->signalRepository->getSessionInfo($requester);
            if ($info !== null) {
                if ($count === 2) {
                    $this->retryManager->saveBaseKey($sessionAddr, $msgId, $info['baseKey']);
                } elseif ($count > 2) {
                    if ($this->retryManager->hasSameBaseKey($sessionAddr, $msgId, $info['baseKey'])) {
                        $this->logger->warn(['participant' => $requester, 'retryCount' => $count], 'base key collision on retry, forcing fresh session');
                        $this->signalRepository->deleteSession([$requester]);
                    }
                    $this->retryManager->deleteBaseKey($sessionAddr, $msgId);
                }
            }
        }

        // ---- session recreation
        $shouldRecreateSession = false;
        if (($this->config['enableAutoSessionRecreation'] ?? true) && $this->retryManager !== null && $count > 1 && !$injectedFromBundle) {
            try {
                $hasSession = $this->signalRepository->validateSession($requester);
                $errorCode = $this->retryManager->parseRetryErrorCode($retryNode->attrs['error'] ?? null);
                $result = $this->retryManager->shouldRecreateSession($requester, $hasSession['exists'] ?? false, $errorCode);
                $shouldRecreateSession = $result['recreate'];
                if ($shouldRecreateSession) {
                    $this->logger->debug(
                        ['participant' => $requester, 'retryCount' => $count, 'reason' => $result['reason']],
                        'recreating session for outgoing retry'
                    );
                    $this->signalRepository->deleteSession([$requester]);
                }
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage(), 'participant' => $requester], 'failed to check session recreation for outgoing retry');
            }
        }

        if (!$injectedFromBundle) {
            $this->assertSessions([$requester], true);
        }
        if (Jid::isGroup($remoteJid)) {
            // force fresh sender-key distribution to everyone in the group
            $this->authState->keys->set(['sender-key-memory' => [$remoteJid => null]]);
        }

        // ---- resend
        $max = (int)($this->config['maxMsgRetryCount'] ?? 5);
        foreach ($ids as $i => $id) {
            $msg = $msgs[$i] ?? null;
            $againKey = $id . ':' . $requester;
            $willSend = $msg !== null && (($this->msgRetryCounter[$againKey] ?? 0) < $max);
            if (!$willSend) {
                $this->logger->debug(['jid' => $remoteJid, 'id' => $id], 'recv retry request, but message not available');
                continue;
            }
            $this->msgRetryCounter[$againKey] = ($this->msgRetryCounter[$againKey] ?? 0) + 1;
            $opts = ['messageId' => $id];
            if (!$sendToAll) {
                $opts['participant'] = ['jid' => $requester, 'count' => $count];
            }
            try {
                $this->relayMessage($remoteJid, $msg, $opts);
            } catch (\Throwable $e) {
                $this->logger->error(['err' => $e->getMessage(), 'id' => $id], 'failed to resend message on retry');
            }
        }
    }

    /** Parse a <keys> bundle inside a retry receipt into an E2E session array. */
    private function extractE2ESessionFromRetryReceipt(BinaryNode $node): ?array
    {
        $keysNode = GenericUtils::getBinaryNodeChild($node, 'keys');
        if ($keysNode === null) {
            return null;
        }
        $identity = GenericUtils::getBinaryNodeChild($keysNode, 'identity')?->contentString();
        $registration = GenericUtils::getBinaryNodeChild($node, 'registration')?->contentString();
        $preKeyNode = null;
        $signedNode = null;
        foreach (GenericUtils::getAllBinaryNodeChildren($keysNode) as $child) {
            if ($child->tag === 'key') {
                $preKeyNode = $child;
            } elseif ($child->tag === 'skey') {
                $signedNode = $child;
            }
        }
        if ($identity === null || $signedNode === null) {
            return null;
        }
        $getChildBytes = fn(?BinaryNode $n, string $tag) => $n === null ? null : GenericUtils::getBinaryNodeChild($n, $tag)?->contentString();
        $parseId = fn(?BinaryNode $n) => Generics::readBigEndian(
            (string)(GenericUtils::getBinaryNodeChild($n, 'id')?->contentString() ?? "\0\0\0"), 3
        );
        $bundle = [
            'registrationId' => $registration !== null ? Generics::readBigEndian($registration, 4) : 0,
            'identityKey' => $identity,
            'signedPreKey' => [
                'keyId' => $parseId($signedNode),
                'publicKey' => $getChildBytes($signedNode, 'value') ?? '',
                'signature' => $getChildBytes($signedNode, 'signature') ?? '',
            ],
        ];
        if ($preKeyNode !== null) {
            $bundle['preKey'] = [
                'keyId' => $parseId($preKeyNode),
                'publicKey' => $getChildBytes($preKeyNode, 'value') ?? '',
            ];
        }
        return $bundle;
    }
}
