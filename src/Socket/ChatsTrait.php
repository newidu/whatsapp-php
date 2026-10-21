<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\ConnectionException;
use BaileysPHP\Utils\Media;
use BaileysPHP\WAUSync\USyncQuery;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Port of Baileys src/Socket/chats.ts (non app-state parts;
 * appPatch/chatModify live in AppStateTrait).
 */
trait ChatsTrait
{
    private ?array $privacySettingsCache = null;

    /** Server AB props relevant to protocol behaviour. */
    private array $serverProps = [
        'privacyTokenOn1to1' => true,
        'profilePicPrivacyToken' => true,
        'lidTrustedTokenIssueToLid' => false,
    ];

    public function serverProps(): array
    {
        return $this->serverProps;
    }

    /** @return array<string,string> */
    public function fetchPrivacySettings(bool $force = false): array
    {
        if ($this->privacySettingsCache === null || $force) {
            $result = $this->query(new BinaryNode('iq', [
                'xmlns' => 'privacy',
                'to' => 's.whatsapp.net',
                'type' => 'get',
            ], [new BinaryNode('privacy')]));
            $this->privacySettingsCache = self::reduceBinaryNodeToDictionary(
                GenericUtils::getBinaryNodeChild($result, 'privacy'),
                'category'
            );
        }
        return $this->privacySettingsCache;
    }

    public static function reduceBinaryNodeToDictionary(?BinaryNode $node, string $tag): array
    {
        $out = [];
        foreach (GenericUtils::getBinaryNodeChildren($node, $tag) as $child) {
            $name = $child->attrs['name'] ?? null;
            if ($name !== null) {
                $out[$name] = $child->attrs['value'] ?? '';
            }
        }
        return $out;
    }

    private function privacyQuery(string $name, string $value): void
    {
        $this->query(new BinaryNode('iq', [
            'xmlns' => 'privacy',
            'to' => 's.whatsapp.net',
            'type' => 'set',
        ], [
            new BinaryNode('privacy', [], [new BinaryNode('category', ['name' => $name, 'value' => $value])]),
        ]));
        if ($this->privacySettingsCache !== null) {
            $this->privacySettingsCache[$name] = $value;
        }
    }

    public function updateMessagesPrivacy(string $value): void { $this->privacyQuery('messages', $value); }
    public function updateCallPrivacy(string $value): void { $this->privacyQuery('calladd', $value); }
    public function updateLastSeenPrivacy(string $value): void { $this->privacyQuery('last', $value); }
    public function updateOnlinePrivacy(string $value): void { $this->privacyQuery('online', $value); }
    public function updateProfilePicturePrivacy(string $value): void { $this->privacyQuery('profile', $value); }
    public function updateStatusPrivacy(string $value): void { $this->privacyQuery('status', $value); }
    public function updateReadReceiptsPrivacy(string $value): void { $this->privacyQuery('readreceipts', $value); }
    public function updateGroupsAddPrivacy(string $value): void { $this->privacyQuery('groupadd', $value); }

    public function updateDefaultDisappearingMode(int $duration): void
    {
        $this->query(new BinaryNode('iq', [
            'xmlns' => 'disappearing_mode',
            'to' => 's.whatsapp.net',
            'type' => 'set',
        ], [new BinaryNode('disappearing_mode', ['duration' => (string)$duration])]));
    }

    /** @return list<array{jid:?string,personaId:?string}> */
    public function getBotListV2(): array
    {
        $resp = $this->query(new BinaryNode('iq', [
            'xmlns' => 'bot',
            'to' => 's.whatsapp.net',
            'type' => 'get',
        ], [new BinaryNode('bot', ['v' => '2'])]));
        $botNode = GenericUtils::getBinaryNodeChild($resp, 'bot');
        $list = [];
        foreach (GenericUtils::getBinaryNodeChildren($botNode, 'section') as $section) {
            if (($section->attrs['type'] ?? '') === 'all') {
                foreach (GenericUtils::getBinaryNodeChildren($section, 'bot') as $bot) {
                    $list[] = ['jid' => $bot->attrs['jid'] ?? null, 'personaId' => $bot->attrs['persona_id'] ?? null];
                }
            }
        }
        return $list;
    }

    /** fetchStatus(...jids) — USync status protocol. */
    public function fetchStatus(string ...$jids): ?array
    {
        $query = (new USyncQuery())->withStatusProtocol();
        foreach ($jids as $jid) {
            $query->withUser(USyncQuery::user($jid));
        }
        $result = $this->executeUSyncQuery($query);
        return $result['list'] ?? null;
    }

    /** fetchDisappearingDuration(...jids) — USync disappearing-mode protocol. */
    public function fetchDisappearingDuration(string ...$jids): ?array
    {
        $query = (new USyncQuery())->withDisappearingModeProtocol();
        foreach ($jids as $jid) {
            $query->withUser(USyncQuery::user($jid));
        }
        $result = $this->executeUSyncQuery($query);
        return $result['list'] ?? null;
    }

    private function profilePictureTarget(string $jid): ?string
    {
        if ($jid === '') {
            throw new \InvalidArgumentException('Illegal no-jid profile update. Please specify either your ID or the ID of the chat you wish to update');
        }
        $meId = $this->creds()->me['id'] ?? '';
        return Jid::normalizedUser($jid) !== Jid::normalizedUser($meId) ? Jid::normalizedUser($jid) : null;
    }

    /** Update the profile picture for yourself or a group (image bytes / ['file'=>..] / ['url'=>..]). */
    public function updateProfilePicture(string $jid, mixed $content, ?array $dimensions = null): void
    {
        $target = $this->profilePictureTarget($jid);
        $bytes = Media::generateProfilePicture(Media::resolveMediaInput($content), (int)($dimensions['width'] ?? 640));
        $attrs = ['to' => 's.whatsapp.net', 'type' => 'set', 'xmlns' => 'w:profile:picture'];
        if ($target !== null) {
            $attrs['target'] = $target;
        }
        $this->query(new BinaryNode('iq', $attrs, [
            new BinaryNode('picture', ['type' => 'image'], new Bytes($bytes)),
        ]));
    }

    /** Remove the profile picture for yourself or a group. */
    public function removeProfilePicture(string $jid): void
    {
        $target = $this->profilePictureTarget($jid);
        $attrs = ['to' => 's.whatsapp.net', 'type' => 'set', 'xmlns' => 'w:profile:picture'];
        if ($target !== null) {
            $attrs['target'] = $target;
        }
        $this->query(new BinaryNode('iq', $attrs));
    }

    /** profilePictureUrl(jid, 'preview'|'image') */
    public function profilePictureUrl(string $jid, string $type = 'preview'): ?string
    {
        $jid = Jid::normalizedUser($jid);
        $content = [new BinaryNode('picture', ['query' => $type])];
        // privacy token for user jids (not self/groups/newsletters), when we hold one
        $me = $this->creds()->me ?? [];
        $isSelf = $jid === Jid::normalizedUser($me['id'] ?? '') || $jid === Jid::normalizedUser($me['lid'] ?? '');
        if ($this->serverProps['profilePicPrivacyToken'] && !$isSelf
            && (Jid::isPnUser($jid) || Jid::isLidUser($jid))) {
            $tokenNode = $this->buildTcTokenNode($jid);
            if ($tokenNode !== null) {
                $content[] = $tokenNode;
            }
        }
        $result = $this->query(new BinaryNode('iq', [
            'target' => $jid,
            'to' => 's.whatsapp.net',
            'type' => 'get',
            'xmlns' => 'w:profile:picture',
        ], $content));
        return GenericUtils::getBinaryNodeChild($result, 'picture')?->attrs['url'] ?? null;
    }

    public function updateProfileStatus(string $status): void
    {
        $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'status',
        ], [new BinaryNode('status', [], new Bytes($status))]));
    }

    public function updateProfileName(string $name): void
    {
        $this->chatModify(['pushNameSetting' => $name], '');
    }

    /** @return list<string> blocked jids */
    public function fetchBlocklist(): array
    {
        $result = $this->query(new BinaryNode('iq', [
            'xmlns' => 'blocklist',
            'to' => 's.whatsapp.net',
            'type' => 'get',
        ]));
        $listNode = GenericUtils::getBinaryNodeChild($result, 'list');
        return array_map(
            fn(BinaryNode $n) => $n->attrs['jid'] ?? '',
            GenericUtils::getBinaryNodeChildren($listNode, 'item')
        );
    }

    /** updateBlockStatus(jid, 'block'|'unblock') */
    public function updateBlockStatus(string $jid, string $action): void
    {
        $normalized = Jid::normalizedUser($jid);
        $pnJid = null;
        if (Jid::isLidUser($normalized) || Jid::isHostedLidUser($normalized)) {
            $lid = $normalized;
            if ($action === 'block') {
                $pn = $this->signalRepository->lidMapping->getPNForLID($normalized);
                if (!$pn) {
                    throw new \RuntimeException("Unable to resolve PN JID for LID: $jid");
                }
                $pnJid = Jid::normalizedUser($pn);
            }
        } elseif (Jid::isPnUser($normalized) || Jid::isHostedPnUser($normalized)) {
            $mapped = $this->signalRepository->lidMapping->getLIDForPN($normalized);
            if (!$mapped) {
                throw new \RuntimeException("Unable to resolve LID for PN JID: $jid");
            }
            $lid = $mapped;
            if ($action === 'block') {
                $pnJid = $normalized;
            }
        } else {
            throw new \InvalidArgumentException("Invalid jid: $jid");
        }
        $attrs = ['action' => $action, 'jid' => $lid];
        if ($action === 'block') {
            if (!$pnJid) {
                throw new \RuntimeException("pn_jid required for block: $jid");
            }
            $attrs['pn_jid'] = $pnJid;
        }
        $this->query(new BinaryNode('iq', [
            'xmlns' => 'blocklist',
            'to' => 's.whatsapp.net',
            'type' => 'set',
        ], [new BinaryNode('item', $attrs)]));
    }

    /** getBusinessProfile(jid) */
    public function getBusinessProfile(string $jid): ?array
    {
        $results = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'xmlns' => 'w:biz',
            'type' => 'get',
        ], [
            new BinaryNode('business_profile', ['v' => '244'], [new BinaryNode('profile', ['jid' => $jid])]),
        ]));
        $profileNode = GenericUtils::getBinaryNodeChild($results, 'business_profile');
        $profile = GenericUtils::getBinaryNodeChild($profileNode, 'profile');
        if ($profile === null) {
            return null;
        }
        $businessHours = GenericUtils::getBinaryNodeChild($profile, 'business_hours');
        $websiteStr = GenericUtils::getBinaryNodeChildString($profile, 'website');
        return [
            'wid' => $profile->attrs['jid'] ?? null,
            'address' => GenericUtils::getBinaryNodeChildString($profile, 'address'),
            'description' => GenericUtils::getBinaryNodeChildString($profile, 'description') ?? '',
            'website' => $websiteStr ? [$websiteStr] : [],
            'email' => GenericUtils::getBinaryNodeChildString($profile, 'email'),
            'category' => GenericUtils::getBinaryNodeChildString(
                GenericUtils::getBinaryNodeChild($profile, 'categories'),
                'category'
            ),
            'business_hours' => [
                'timezone' => $businessHours?->attrs['timezone'] ?? null,
                'business_config' => array_map(
                    fn(BinaryNode $n) => $n->attrs,
                    GenericUtils::getBinaryNodeChildren($businessHours, 'business_hours_config')
                ),
            ],
        ];
    }

    /** createCallLink('audio'|'video', ?['startTime'=>s]) → token */
    public function createCallLink(string $type, ?array $event = null): ?string
    {
        $result = $this->query(new BinaryNode('call', [
            'id' => $this->generateMessageTag(),
            'to' => '@call',
        ], [
            new BinaryNode('link_create', ['media' => $type],
                $event ? [new BinaryNode('event', ['start_time' => (string)$event['startTime']])] : null),
        ]));
        return GenericUtils::getBinaryNodeChild($result, 'link_create')?->attrs['token'] ?? null;
    }

    /** presenceSubscribe(jid) */
    public function presenceSubscribe(string $toJid): void
    {
        $normalized = Jid::normalizedUser($toJid);
        $content = null;
        if (Jid::isPnUser($normalized) || Jid::isLidUser($normalized)) {
            $tokenNode = $this->buildTcTokenNode($normalized);
            $content = $tokenNode !== null ? [$tokenNode] : null;
        }
        $this->sendNode(new BinaryNode('presence', [
            'to' => $toJid,
            'id' => $this->generateMessageTag(),
            'type' => 'subscribe',
        ], $content));
    }

    /** Fetch AB props; updates serverProps + lastPropHash. @return array<string,string> */
    public function fetchProps(): array
    {
        $attrs = ['protocol' => '1'];
        if (!empty($this->creds()->lastPropHash)) {
            $attrs['hash'] = $this->creds()->lastPropHash;
        }
        $resultNode = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'xmlns' => 'abt',
            'type' => 'get',
        ], [new BinaryNode('props', $attrs)]));

        $propsNode = GenericUtils::getBinaryNodeChild($resultNode, 'props');
        $props = [];
        if ($propsNode !== null) {
            if (!empty($propsNode->attrs['hash'])) {
                $this->creds()->lastPropHash = $propsNode->attrs['hash'];
                $this->ev->emit('creds.update', ['lastPropHash' => $propsNode->attrs['hash']]);
                $this->authState->saveCreds();
            }
            $props = self::reduceBinaryNodeToDictionary($propsNode, 'prop');
        }
        $privacyTokenProp = $props['10518'] ?? $props['privacy_token_sending_on_all_1_on_1_messages'] ?? null;
        if ($privacyTokenProp !== null) {
            $this->serverProps['privacyTokenOn1to1'] = $privacyTokenProp === 'true' || $privacyTokenProp === '1';
        }
        $profilePicProp = $props['9666'] ?? $props['profile_scraping_privacy_token_in_photo_iq'] ?? null;
        if ($profilePicProp !== null) {
            $this->serverProps['profilePicPrivacyToken'] = $profilePicProp === 'true' || $profilePicProp === '1';
        }
        $lidIssueProp = $props['14303'] ?? $props['lid_trusted_token_issue_to_lid'] ?? null;
        if ($lidIssueProp !== null) {
            $this->serverProps['lidTrustedTokenIssueToLid'] = $lidIssueProp === 'true' || $lidIssueProp === '1';
        }
        $this->logger->debug(['serverProps' => $this->serverProps], 'fetched props');
        return $props;
    }

    // ---- app-state-backed helpers (delegate to AppStateTrait::chatModify)

    public function updateDisableLinkPreviewsPrivacy(bool $isPreviewsDisabled): void
    {
        $this->chatModify(['disableLinkPreviews' => ['isPreviewsDisabled' => $isPreviewsDisabled]], '');
    }

    /** @param list<array{id:string,fromMe?:bool}> $messages */
    /** archiveChat(jid, archive=true) — convenience wrapper over chatModify. */
    public function archiveChat(string $jid, bool $archive = true): void
    {
        $this->chatModify(['archive' => $archive], $jid);
    }

    public function star(string $jid, array $messages, bool $star): void
    {
        $this->chatModify(['star' => ['messages' => $messages, 'star' => $star]], $jid);
    }

    public function addOrEditContact(string $jid, array $contact): void
    {
        $this->chatModify(['contact' => $contact], $jid);
    }

    public function removeContact(string $jid): void
    {
        $this->chatModify(['contact' => null], $jid);
    }

    public function addLabel(string $jid, array $labels): void
    {
        $this->chatModify(['addLabel' => $labels], $jid);
    }

    public function addChatLabel(string $jid, string $labelId): void
    {
        $this->chatModify(['addChatLabel' => ['labelId' => $labelId]], $jid);
    }

    public function removeChatLabel(string $jid, string $labelId): void
    {
        $this->chatModify(['removeChatLabel' => ['labelId' => $labelId]], $jid);
    }

    public function addMessageLabel(string $jid, string $messageId, string $labelId): void
    {
        $this->chatModify(['addMessageLabel' => ['messageId' => $messageId, 'labelId' => $labelId]], $jid);
    }

    public function removeMessageLabel(string $jid, string $messageId, string $labelId): void
    {
        $this->chatModify(['removeMessageLabel' => ['messageId' => $messageId, 'labelId' => $labelId]], $jid);
    }

    public function addOrEditQuickReply(array $quickReply): void
    {
        $this->chatModify(['quickReply' => $quickReply], '');
    }

    public function removeQuickReply(string $timestamp): void
    {
        $this->chatModify(['quickReply' => ['timestamp' => $timestamp, 'deleted' => true]], '');
    }

    /** generic send receipt (messages-send sendReceipt/sendReceipts). */
    public function sendReceipt(string $jid, ?string $participant, array $messageIds, string $type): void
    {
        if ($messageIds === []) {
            throw new ConnectionException('missing ids in receipt', 400);
        }
        $attrs = ['id' => $messageIds[0], 'to' => $jid, 'type' => $type];
        if ($participant) {
            $attrs['participant'] = $participant;
        }
        $remaining = array_slice($messageIds, 1);
        $content = null;
        if ($remaining !== []) {
            $content = [new BinaryNode('list', [], array_map(
                fn($id) => new BinaryNode('item', ['id' => $id]),
                $remaining
            ))];
        }
        $this->sendNode(new BinaryNode('receipt', $attrs, $content));
    }

    /** @param list<array{remoteJid:string,id:string,participant?:string}> $keys */
    public function sendReceipts(array $keys, string $type): void
    {
        // group by (remoteJid, participant)
        $groups = [];
        foreach ($keys as $key) {
            $remoteJid = $key['remoteJid'];
            $participant = Jid::isGroup($remoteJid) ? ($key['participant'] ?? null) : null;
            $groups[$remoteJid . '|' . ($participant ?? '')] ??= ['jid' => $remoteJid, 'participant' => $participant, 'ids' => []];
            $groups[$remoteJid . '|' . ($participant ?? '')]['ids'][] = $key['id'];
        }
        foreach ($groups as $g) {
            $this->sendReceipt($g['jid'], $g['participant'], $g['ids'], $type);
        }
    }

    /** Reject a VoIP call (messages-recv rejectCall). */
    public function rejectCall(string $callId, string $callFrom): void
    {
        $this->sendNode(new BinaryNode('call', [
            'from' => $this->creds()->me['id'] ?? '',
            'to' => $callFrom,
            'id' => $this->generateMessageTag(),
        ], [
            new BinaryNode('reject', [
                'call-id' => $callId,
                'call-creator' => $callFrom,
                'count' => '0',
            ]),
        ]));
    }
}
