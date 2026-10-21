<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\ConnectionException;
use BaileysPHP\Utils\Generics;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Port of Baileys src/Socket/groups.ts
 * (used by WASocket; methods become part of the socket class)
 */
trait GroupsTrait
{
    /** @var array<string,array<string,mixed>> group metadata cache */
    private array $cachedGroupMetadataMap = [];

    private function groupQuery(string $jid, string $type, array $content): BinaryNode
    {
        return $this->query(new BinaryNode('iq', [
            'type' => $type,
            'xmlns' => 'w:g2',
            'to' => $jid,
        ], $content));
    }

    /** Cached metadata if available (Baileys: cachedGroupMetadata config/fn). */
    public function cachedGroupMetadata(string $jid): ?array
    {
        return $this->cachedGroupMetadataMap[$jid] ?? null;
    }

    /** groupMetadata(jid) — fetch + cache. */
    public function groupMetadata(string $jid): array
    {
        $result = $this->groupQuery($jid, 'get', [new BinaryNode('query', ['request' => 'interactive'])]);
        $meta = $this->extractGroupMetadata($result);
        $this->cachedGroupMetadataMap[$jid] = $meta;
        return $meta;
    }

    /** @return array<string,array<string,mixed>> id => GroupMetadata */
    public function groupFetchAllParticipating(): array
    {
        $result = $this->query(new BinaryNode('iq', [
            'to' => '@g.us',
            'xmlns' => 'w:g2',
            'type' => 'get',
        ], [
            new BinaryNode('participating', [], [
                new BinaryNode('participants'),
                new BinaryNode('description'),
            ]),
        ]));
        $data = [];
        $groupsChild = GenericUtils::getBinaryNodeChild($result, 'groups');
        if ($groupsChild !== null) {
            foreach (GenericUtils::getBinaryNodeChildren($groupsChild, 'group') as $groupNode) {
                $meta = $this->extractGroupMetadata(new BinaryNode('result', [], [$groupNode]));
                $data[$meta['id']] = $meta;
                $this->cachedGroupMetadataMap[$meta['id']] = $meta;
            }
        }
        $this->ev->emit('groups.update', array_values($data));
        return $data;
    }

    public function cleanDirtyBits(string $type, int|string|null $fromTimestamp = null): void
    {
        $this->logger->info('clean dirty bits ' . $type);
        $attrs = ['type' => $type];
        if ($fromTimestamp) {
            $attrs['timestamp'] = (string)$fromTimestamp;
        }
        $this->sendNode(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'urn:xmpp:whatsapp:dirty',
            'id' => $this->generateMessageTag(),
        ], [new BinaryNode('clean', $attrs)]));
    }

    /** @param list<string> $participants */
    public function groupCreate(string $subject, array $participants): array
    {
        $key = Generics::generateMessageIDV2();
        $result = $this->groupQuery('@g.us', 'set', [
            new BinaryNode('create', ['subject' => $subject, 'key' => $key],
                array_map(fn($jid) => new BinaryNode('participant', ['jid' => $jid]), $participants)),
        ]);
        return $this->extractGroupMetadata($result);
    }

    public function groupLeave(string $id): void
    {
        $this->groupQuery('@g.us', 'set', [
            new BinaryNode('leave', [], [new BinaryNode('group', ['id' => $id])]),
        ]);
        unset($this->cachedGroupMetadataMap[$id]);
    }

    public function groupUpdateSubject(string $jid, string $subject): void
    {
        $this->groupQuery($jid, 'set', [new BinaryNode('subject', [], new Bytes($subject))]);
    }

    /** @return list<array<string,string>> attrs of each membership_approval_request */
    public function groupRequestParticipantsList(string $jid): array
    {
        $result = $this->groupQuery($jid, 'get', [new BinaryNode('membership_approval_requests')]);
        $node = GenericUtils::getBinaryNodeChild($result, 'membership_approval_requests');
        return array_map(
            fn(BinaryNode $v) => $v->attrs,
            GenericUtils::getBinaryNodeChildren($node, 'membership_approval_request')
        );
    }

    /**
     * @param list<string> $participants
     * @return list<array{status:string,jid:?string}>
     */
    public function groupRequestParticipantsUpdate(string $jid, array $participants, string $action): array
    {
        $result = $this->groupQuery($jid, 'set', [
            new BinaryNode('membership_requests_action', [], [
                new BinaryNode($action, [], array_map(
                    fn($p) => new BinaryNode('participant', ['jid' => $p]),
                    $participants
                )),
            ]),
        ]);
        $node = GenericUtils::getBinaryNodeChild($result, 'membership_requests_action');
        $nodeAction = GenericUtils::getBinaryNodeChild($node, $action);
        return array_map(fn(BinaryNode $p) => [
            'status' => $p->attrs['error'] ?? '200',
            'jid' => $p->attrs['jid'] ?? null,
        ], GenericUtils::getBinaryNodeChildren($nodeAction, 'participant'));
    }

    /**
     * @param list<string> $participants
     * @param string $action 'add'|'remove'|'promote'|'demote'|'modify'
     * @return list<array{status:string,jid:?string,content:BinaryNode}>
     */
    public function groupParticipantsUpdate(string $jid, array $participants, string $action): array
    {
        $result = $this->groupQuery($jid, 'set', [
            new BinaryNode($action, [], array_map(
                fn($p) => new BinaryNode('participant', ['jid' => $p]),
                $participants
            )),
        ]);
        $node = GenericUtils::getBinaryNodeChild($result, $action);
        return array_map(fn(BinaryNode $p) => [
            'status' => $p->attrs['error'] ?? '200',
            'jid' => $p->attrs['jid'] ?? null,
            'content' => $p,
        ], GenericUtils::getBinaryNodeChildren($node, 'participant'));
    }

    public function groupUpdateDescription(string $jid, ?string $description = null): void
    {
        $metadata = $this->groupMetadata($jid);
        $prev = $metadata['descId'] ?? null;
        $attrs = $description ? ['id' => Generics::generateMessageIDV2()] : ['delete' => 'true'];
        if ($prev) {
            $attrs['prev'] = $prev;
        }
        $this->groupQuery($jid, 'set', [
            new BinaryNode('description', $attrs, $description ? [new BinaryNode('body', [], new Bytes($description))] : null),
        ]);
    }

    public function groupInviteCode(string $jid): ?string
    {
        $result = $this->groupQuery($jid, 'get', [new BinaryNode('invite')]);
        return GenericUtils::getBinaryNodeChild($result, 'invite')?->attrs['code'] ?? null;
    }

    public function groupRevokeInvite(string $jid): ?string
    {
        $result = $this->groupQuery($jid, 'set', [new BinaryNode('invite')]);
        return GenericUtils::getBinaryNodeChild($result, 'invite')?->attrs['code'] ?? null;
    }

    public function groupAcceptInvite(string $code): ?string
    {
        $results = $this->groupQuery('@g.us', 'set', [new BinaryNode('invite', ['code' => $code])]);
        return GenericUtils::getBinaryNodeChild($results, 'group')?->attrs['jid'] ?? null;
    }

    public function groupRevokeInviteV4(string $groupJid, string $invitedJid): bool
    {
        $result = $this->groupQuery($groupJid, 'set', [
            new BinaryNode('revoke', [], [new BinaryNode('participant', ['jid' => $invitedJid])]),
        ]);
        return $result !== null;
    }

    /**
     * Accept a GroupInviteMessage.
     * @param string|array{remoteJid:string,id?:string} $key invite message key (or inviter jid)
     * @param array{groupJid:string,inviteCode:string,inviteExpiration:int} $inviteMessage
     */
    public function groupAcceptInviteV4(string|array $key, array $inviteMessage): ?string
    {
        if (is_string($key)) {
            $key = ['remoteJid' => $key];
        }
        $results = $this->groupQuery($inviteMessage['groupJid'], 'set', [
            new BinaryNode('accept', [
                'code' => $inviteMessage['inviteCode'],
                'expiration' => (string)($inviteMessage['inviteExpiration'] ?? 0),
                'admin' => $key['remoteJid'],
            ]),
        ]);
        if (!empty($key['id'])) {
            $expired = $inviteMessage;
            $expired['inviteExpiration'] = 0;
            $expired['inviteCode'] = '';
            $this->ev->emit('messages.update', [[
                'key' => $key,
                'update' => ['message' => ['groupInviteMessage' => $expired]],
            ]]);
        }
        $this->ev->emit('messages.upsert', [
            'messages' => [[
                'key' => [
                    'remoteJid' => $inviteMessage['groupJid'],
                    'id' => Generics::generateMessageIDV2($this->user['id'] ?? null),
                    'fromMe' => false,
                    'participant' => $key['remoteJid'],
                ],
                'messageStubType' => 20, // GROUP_PARTICIPANT_ADD
                'messageStubParameters' => [json_encode($this->creds()->me)],
                'participant' => $key['remoteJid'],
                'messageTimestamp' => time(),
            ]],
            'type' => 'notify',
        ]);
        return $results->attrs['from'] ?? null;
    }

    public function groupGetInviteInfo(string $code): array
    {
        $results = $this->groupQuery('@g.us', 'get', [new BinaryNode('invite', ['code' => $code])]);
        return $this->extractGroupMetadata($results);
    }

    public function groupToggleEphemeral(string $jid, int $ephemeralExpiration): void
    {
        $content = $ephemeralExpiration
            ? new BinaryNode('ephemeral', ['expiration' => (string)$ephemeralExpiration])
            : new BinaryNode('not_ephemeral');
        $this->groupQuery($jid, 'set', [$content]);
    }

    /** @param string $setting 'announcement'|'not_announcement'|'locked'|'unlocked' */
    public function groupSettingUpdate(string $jid, string $setting): void
    {
        $this->groupQuery($jid, 'set', [new BinaryNode($setting)]);
    }

    /** @param string $mode 'admin_add'|'all_member_add' */
    public function groupMemberAddMode(string $jid, string $mode): void
    {
        $this->groupQuery($jid, 'set', [new BinaryNode('member_add_mode', [], $mode)]);
    }

    /** @param string $mode 'on'|'off' */
    public function groupJoinApprovalMode(string $jid, string $mode): void
    {
        $this->groupQuery($jid, 'set', [
            new BinaryNode('membership_approval_mode', [], [new BinaryNode('group_join', ['state' => $mode])]),
        ]);
    }

    /** Port of extractGroupMetadata() from groups.ts */
    public function extractGroupMetadata(BinaryNode $result): array
    {
        $group = GenericUtils::getBinaryNodeChild($result, 'group');
        if ($group === null) {
            $errorNode = GenericUtils::getBinaryNodeChild($result, 'error');
            if ($errorNode !== null) {
                throw new ConnectionException(
                    $errorNode->attrs['text'] ?? 'group metadata query failed',
                    (int)($errorNode->attrs['code'] ?? 500)
                );
            }
            throw new \RuntimeException('Invalid group metadata response: missing <group> node');
        }
        if (empty($group->attrs['id'])) {
            throw new \RuntimeException('Invalid group metadata response: missing group id');
        }

        $descChild = GenericUtils::getBinaryNodeChild($group, 'description');
        $desc = $descId = $descOwner = $descOwnerPn = $descOwnerUsername = $descTime = null;
        if ($descChild !== null) {
            $desc = GenericUtils::getBinaryNodeChildString($descChild, 'body');
            $descOwner = !empty($descChild->attrs['participant']) ? Jid::normalizedUser($descChild->attrs['participant']) : null;
            $descOwnerPn = !empty($descChild->attrs['participant_pn']) ? Jid::normalizedUser($descChild->attrs['participant_pn']) : null;
            $descOwnerUsername = $descChild->attrs['participant_username'] ?? null;
            $descTime = isset($descChild->attrs['t']) ? (int)$descChild->attrs['t'] : null;
            $descId = $descChild->attrs['id'] ?? null;
        }

        $groupId = str_contains($group->attrs['id'], '@')
            ? $group->attrs['id']
            : Jid::encode($group->attrs['id'], 'g.us');
        $eph = GenericUtils::getBinaryNodeChild($group, 'ephemeral')?->attrs['expiration'] ?? null;
        $memberAddMode = GenericUtils::getBinaryNodeChildString($group, 'member_add_mode') === 'all_member_add';
        $participantNodes = GenericUtils::getBinaryNodeChildren($group, 'participant');

        return [
            'id' => $groupId,
            'notify' => $group->attrs['notify'] ?? null,
            'addressingMode' => ($group->attrs['addressing_mode'] ?? 'lid') === 'lid' ? 'lid' : 'pn',
            'subject' => $group->attrs['subject'] ?? '',
            'subjectOwner' => $group->attrs['s_o'] ?? null,
            'subjectOwnerPn' => $group->attrs['s_o_pn'] ?? null,
            'subjectOwnerUsername' => $group->attrs['s_o_username'] ?? null,
            'subjectTime' => isset($group->attrs['s_t']) ? (int)$group->attrs['s_t'] : null,
            'size' => isset($group->attrs['size']) ? (int)$group->attrs['size'] : count($participantNodes),
            'creation' => isset($group->attrs['creation']) ? (int)$group->attrs['creation'] : null,
            'owner' => !empty($group->attrs['creator']) ? Jid::normalizedUser($group->attrs['creator']) : null,
            'ownerPn' => !empty($group->attrs['creator_pn']) ? Jid::normalizedUser($group->attrs['creator_pn']) : null,
            'ownerUsername' => $group->attrs['creator_username'] ?? null,
            'owner_country_code' => $group->attrs['creator_country_code'] ?? null,
            'desc' => $desc,
            'descId' => $descId,
            'descOwner' => $descOwner,
            'descOwnerPn' => $descOwnerPn,
            'descOwnerUsername' => $descOwnerUsername,
            'descTime' => $descTime,
            'linkedParent' => GenericUtils::getBinaryNodeChild($group, 'linked_parent')?->attrs['jid'] ?? null,
            'restrict' => GenericUtils::getBinaryNodeChild($group, 'locked') !== null,
            'announce' => GenericUtils::getBinaryNodeChild($group, 'announcement') !== null,
            'isCommunity' => GenericUtils::getBinaryNodeChild($group, 'parent') !== null,
            'isCommunityAnnounce' => GenericUtils::getBinaryNodeChild($group, 'default_sub_group') !== null,
            'joinApprovalMode' => GenericUtils::getBinaryNodeChild($group, 'membership_approval_mode') !== null,
            'memberAddMode' => $memberAddMode,
            'participants' => array_map(function (BinaryNode $n) {
                $a = $n->attrs;
                return [
                    'id' => $a['jid'] ?? '',
                    'phoneNumber' => (Jid::isLidUser($a['jid'] ?? '') && Jid::isPnUser($a['phone_number'] ?? '')) ? $a['phone_number'] : null,
                    'lid' => (Jid::isPnUser($a['jid'] ?? '') && Jid::isLidUser($a['lid'] ?? '')) ? $a['lid'] : null,
                    'username' => $a['participant_username'] ?? $a['username'] ?? null,
                    'admin' => $a['type'] ?? null,
                ];
            }, $participantNodes),
            'ephemeralDuration' => $eph !== null ? (int)$eph : null,
        ];
    }
}
