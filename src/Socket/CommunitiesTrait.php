<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Utils\Generics;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Communities — port of Baileys src/Socket/communities.ts
 */
trait CommunitiesTrait
{
    private function communityQuery(string $jid, string $type, array $content): BinaryNode
    {
        return $this->query(new BinaryNode('iq', [
            'type' => $type,
            'xmlns' => 'w:g2',
            'to' => $jid,
        ], $content));
    }

    public function communityMetadata(string $jid): array
    {
        $result = $this->communityQuery($jid, 'get', [new BinaryNode('query', ['request' => 'interactive'])]);
        return $this->extractCommunityMetadata($result);
    }

    /** @return array<string,array> */
    public function communityFetchAllParticipating(): array
    {
        $result = $this->query(new BinaryNode('iq', [
            'to' => '@g.us',
            'xmlns' => 'w:g2',
            'type' => 'get',
        ], [
            new BinaryNode('participating', [], [new BinaryNode('participants'), new BinaryNode('description')]),
        ]));
        $data = [];
        $communitiesChild = GenericUtils::getBinaryNodeChild($result, 'communities');
        foreach (GenericUtils::getBinaryNodeChildren($communitiesChild, 'community') as $communityNode) {
            $meta = $this->extractCommunityMetadata(new BinaryNode('result', [], [$communityNode]));
            $data[$meta['id']] = $meta;
        }
        $this->ev->emit('groups.update', array_values($data));
        return $data;
    }

    private function parseCommunityGroupResult(BinaryNode $node): ?array
    {
        $groupNode = GenericUtils::getBinaryNodeChild($node, 'group');
        if ($groupNode === null || empty($groupNode->attrs['id'])) {
            return null;
        }
        try {
            return $this->groupMetadata($groupNode->attrs['id'] . '@g.us');
        } catch (\Throwable) {
            return null;
        }
    }

    public function communityCreate(string $subject, string $body = ''): ?array
    {
        $descriptionId = substr(Generics::generateMessageID(), 0, 12);
        $result = $this->communityQuery('@g.us', 'set', [
            new BinaryNode('create', ['subject' => $subject], [
                new BinaryNode('description', ['id' => $descriptionId], [
                    new BinaryNode('body', [], new Bytes($body)),
                ]),
                new BinaryNode('parent', ['default_membership_approval_mode' => 'request_required']),
                new BinaryNode('allow_non_admin_sub_group_creation'),
                new BinaryNode('create_general_chat'),
            ]),
        ]);
        return $this->parseCommunityGroupResult($result);
    }

    /** @param list<string> $participants */
    public function communityCreateGroup(string $subject, array $participants, string $parentCommunityJid): ?array
    {
        $result = $this->communityQuery('@g.us', 'set', [
            new BinaryNode('create', ['subject' => $subject, 'key' => Generics::generateMessageIDV2()], array_merge(
                array_map(fn($jid) => new BinaryNode('participant', ['jid' => $jid]), $participants),
                [new BinaryNode('linked_parent', ['jid' => $parentCommunityJid])]
            )),
        ]);
        return $this->parseCommunityGroupResult($result);
    }

    public function communityLeave(string $id): void
    {
        $this->communityQuery('@g.us', 'set', [
            new BinaryNode('leave', [], [new BinaryNode('community', ['id' => $id])]),
        ]);
    }

    public function communityUpdateSubject(string $jid, string $subject): void
    {
        $this->communityQuery($jid, 'set', [new BinaryNode('subject', [], new Bytes($subject))]);
    }

    public function communityLinkGroup(string $groupJid, string $parentCommunityJid): void
    {
        $this->communityQuery($parentCommunityJid, 'set', [
            new BinaryNode('links', [], [
                new BinaryNode('link', ['link_type' => 'sub_group'], [new BinaryNode('group', ['jid' => $groupJid])]),
            ]),
        ]);
    }

    public function communityUnlinkGroup(string $groupJid, string $parentCommunityJid): void
    {
        $this->communityQuery($parentCommunityJid, 'set', [
            new BinaryNode('unlink', ['unlink_type' => 'sub_group'], [new BinaryNode('group', ['jid' => $groupJid])]),
        ]);
    }

    /** @return array{communityJid:string,isCommunity:bool,linkedGroups:list<array>} */
    public function communityFetchLinkedGroups(string $jid): array
    {
        $metadata = $this->groupMetadata($jid);
        $communityJid = $jid;
        $isCommunity = false;
        if (!empty($metadata['linkedParent'])) {
            $communityJid = $metadata['linkedParent'];
        } else {
            $isCommunity = true;
        }
        $result = $this->communityQuery($communityJid, 'get', [new BinaryNode('sub_groups')]);
        $linked = [];
        $subGroupsNode = GenericUtils::getBinaryNodeChild($result, 'sub_groups');
        foreach (GenericUtils::getBinaryNodeChildren($subGroupsNode, 'group') as $groupNode) {
            $a = $groupNode->attrs;
            $linked[] = [
                'id' => !empty($a['id']) ? Jid::encode($a['id'], 'g.us') : null,
                'subject' => $a['subject'] ?? '',
                'creation' => isset($a['creation']) ? (int)$a['creation'] : null,
                'owner' => !empty($a['creator']) ? Jid::normalizedUser($a['creator']) : null,
                'size' => isset($a['size']) ? (int)$a['size'] : null,
            ];
        }
        return ['communityJid' => $communityJid, 'isCommunity' => $isCommunity, 'linkedGroups' => $linked];
    }

    /** @return list<array<string,string>> */
    public function communityRequestParticipantsList(string $jid): array
    {
        $result = $this->communityQuery($jid, 'get', [new BinaryNode('membership_approval_requests')]);
        $node = GenericUtils::getBinaryNodeChild($result, 'membership_approval_requests');
        return array_map(fn(BinaryNode $v) => $v->attrs, GenericUtils::getBinaryNodeChildren($node, 'membership_approval_request'));
    }

    /**
     * @param list<string> $participants
     * @return list<array{status:string,jid:?string}>
     */
    public function communityRequestParticipantsUpdate(string $jid, array $participants, string $action): array
    {
        $result = $this->communityQuery($jid, 'set', [
            new BinaryNode('membership_requests_action', [], [
                new BinaryNode($action, [], array_map(fn($p) => new BinaryNode('participant', ['jid' => $p]), $participants)),
            ]),
        ]);
        $nodeAction = GenericUtils::getBinaryNodeChild(GenericUtils::getBinaryNodeChild($result, 'membership_requests_action'), $action);
        return array_map(fn(BinaryNode $p) => [
            'status' => $p->attrs['error'] ?? '200',
            'jid' => $p->attrs['jid'] ?? null,
        ], GenericUtils::getBinaryNodeChildren($nodeAction, 'participant'));
    }

    /**
     * @param list<string> $participants
     * @param string $action 'add'|'remove'|'promote'|'demote'
     * @return list<array{status:string,jid:?string,content:BinaryNode}>
     */
    public function communityParticipantsUpdate(string $jid, array $participants, string $action): array
    {
        $result = $this->communityQuery($jid, 'set', [
            new BinaryNode($action, $action === 'remove' ? ['linked_groups' => 'true'] : [],
                array_map(fn($p) => new BinaryNode('participant', ['jid' => $p]), $participants)),
        ]);
        $node = GenericUtils::getBinaryNodeChild($result, $action);
        return array_map(fn(BinaryNode $p) => [
            'status' => $p->attrs['error'] ?? '200',
            'jid' => $p->attrs['jid'] ?? null,
            'content' => $p,
        ], GenericUtils::getBinaryNodeChildren($node, 'participant'));
    }

    public function communityUpdateDescription(string $jid, ?string $description = null): void
    {
        $metadata = $this->communityMetadata($jid);
        $prev = $metadata['descId'] ?? null;
        $attrs = $description ? ['id' => Generics::generateMessageID()] : ['delete' => 'true'];
        if ($prev) {
            $attrs['prev'] = $prev;
        }
        $this->communityQuery($jid, 'set', [
            new BinaryNode('description', $attrs, $description ? [new BinaryNode('body', [], new Bytes($description))] : null),
        ]);
    }

    public function communityInviteCode(string $jid): ?string
    {
        $result = $this->communityQuery($jid, 'get', [new BinaryNode('invite')]);
        return GenericUtils::getBinaryNodeChild($result, 'invite')?->attrs['code'] ?? null;
    }

    public function communityRevokeInvite(string $jid): ?string
    {
        $result = $this->communityQuery($jid, 'set', [new BinaryNode('invite')]);
        return GenericUtils::getBinaryNodeChild($result, 'invite')?->attrs['code'] ?? null;
    }

    public function communityAcceptInvite(string $code): ?string
    {
        $results = $this->communityQuery('@g.us', 'set', [new BinaryNode('invite', ['code' => $code])]);
        return GenericUtils::getBinaryNodeChild($results, 'community')?->attrs['jid'] ?? null;
    }

    public function communityRevokeInviteV4(string $communityJid, string $invitedJid): bool
    {
        $result = $this->communityQuery($communityJid, 'set', [
            new BinaryNode('revoke', [], [new BinaryNode('participant', ['jid' => $invitedJid])]),
        ]);
        return $result !== null;
    }

    /**
     * @param string|array $key invite message key or inviter jid
     * @param array{groupJid:string,inviteCode:string,inviteExpiration:int} $inviteMessage
     */
    public function communityAcceptInviteV4(string|array $key, array $inviteMessage): ?string
    {
        if (is_string($key)) {
            $key = ['remoteJid' => $key];
        }
        $results = $this->communityQuery($inviteMessage['groupJid'], 'set', [
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

    public function communityGetInviteInfo(string $code): array
    {
        $results = $this->communityQuery('@g.us', 'get', [new BinaryNode('invite', ['code' => $code])]);
        return $this->extractCommunityMetadata($results);
    }

    public function communityToggleEphemeral(string $jid, int $ephemeralExpiration): void
    {
        $content = $ephemeralExpiration
            ? new BinaryNode('ephemeral', ['expiration' => (string)$ephemeralExpiration])
            : new BinaryNode('not_ephemeral');
        $this->communityQuery($jid, 'set', [$content]);
    }

    public function communitySettingUpdate(string $jid, string $setting): void
    {
        $this->communityQuery($jid, 'set', [new BinaryNode($setting)]);
    }

    public function communityMemberAddMode(string $jid, string $mode): void
    {
        $this->communityQuery($jid, 'set', [new BinaryNode('member_add_mode', [], $mode)]);
    }

    public function communityJoinApprovalMode(string $jid, string $mode): void
    {
        $this->communityQuery($jid, 'set', [
            new BinaryNode('membership_approval_mode', [], [new BinaryNode('community_join', ['state' => $mode])]),
        ]);
    }

    /** Port of extractCommunityMetadata(). */
    public function extractCommunityMetadata(BinaryNode $result): array
    {
        $community = GenericUtils::getBinaryNodeChild($result, 'community');
        if ($community === null) {
            throw new \RuntimeException('Invalid community metadata response');
        }
        $descChild = GenericUtils::getBinaryNodeChild($community, 'description');
        $desc = $descId = null;
        if ($descChild !== null) {
            $desc = GenericUtils::getBinaryNodeChildString($descChild, 'body');
            $descId = $descChild->attrs['id'] ?? null;
        }
        $a = $community->attrs;
        $participantNodes = GenericUtils::getBinaryNodeChildren($community, 'participant');
        return [
            'id' => str_contains($a['id'] ?? '', '@') ? $a['id'] : Jid::encode($a['id'] ?? '', 'g.us'),
            'subject' => $a['subject'] ?? '',
            'subjectOwner' => $a['s_o'] ?? null,
            'subjectTime' => (int)($a['s_t'] ?? 0),
            'size' => count($participantNodes),
            'creation' => (int)($a['creation'] ?? 0),
            'owner' => !empty($a['creator']) ? Jid::normalizedUser($a['creator']) : null,
            'desc' => $desc,
            'descId' => $descId,
            'linkedParent' => GenericUtils::getBinaryNodeChild($community, 'linked_parent')?->attrs['jid'] ?? null,
            'restrict' => GenericUtils::getBinaryNodeChild($community, 'locked') !== null,
            'announce' => GenericUtils::getBinaryNodeChild($community, 'announcement') !== null,
            'isCommunity' => GenericUtils::getBinaryNodeChild($community, 'parent') !== null,
            'isCommunityAnnounce' => GenericUtils::getBinaryNodeChild($community, 'default_sub_community') !== null,
            'joinApprovalMode' => GenericUtils::getBinaryNodeChild($community, 'membership_approval_mode') !== null,
            'memberAddMode' => GenericUtils::getBinaryNodeChildString($community, 'member_add_mode') === 'all_member_add',
            'participants' => array_map(fn(BinaryNode $n) => [
                'id' => $n->attrs['jid'] ?? '',
                'admin' => $n->attrs['type'] ?? null,
            ], $participantNodes),
            'ephemeralDuration' => ($e = GenericUtils::getBinaryNodeChild($community, 'ephemeral')?->attrs['expiration'] ?? null) !== null ? (int)$e : null,
            'addressingMode' => GenericUtils::getBinaryNodeChildString($community, 'addressing_mode'),
        ];
    }
}
