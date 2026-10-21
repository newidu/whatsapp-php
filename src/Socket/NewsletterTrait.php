<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\ConnectionException;
use BaileysPHP\Utils\Media;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;

/**
 * Newsletters (channels) via w:mex GraphQL — port of Baileys
 * src/Socket/mex.ts + src/Socket/newsletter.ts.
 */
trait NewsletterTrait
{
    private const MEX_QUERY_IDS = [
        'CREATE' => '8823471724422422',
        'UPDATE_METADATA' => '24250201037901610',
        'METADATA' => '6563316087068696',
        'SUBSCRIBERS' => '9783111038412085',
        'FOLLOW' => '24404358912487870',
        'UNFOLLOW' => '9767147403369991',
        'MUTE' => '29766401636284406',
        'UNMUTE' => '9864994326891137',
        'ADMIN_COUNT' => '7130823597031706',
        'CHANGE_OWNER' => '7341777602580933',
        'DEMOTE' => '6551828931592903',
        'DELETE' => '30062808666639665',
        'REACHOUT_TIMELOCK' => '23983697327930364',
        'MESSAGE_CAPPING_INFO' => '24503548349331633',
    ];

    /** executeWMexQuery(variables, queryId, dataPath) → data at dataPath */
    public function executeWMexQuery(array $variables, string $queryId, string $dataPath): mixed
    {
        $result = $this->query(new BinaryNode('iq', [
            'id' => $this->generateMessageTag(),
            'type' => 'get',
            'to' => 's.whatsapp.net',
            'xmlns' => 'w:mex',
        ], [
            new BinaryNode('query', ['query_id' => $queryId], new Bytes(json_encode(['variables' => $variables]) ?: '{}')),
        ]));
        $child = GenericUtils::getBinaryNodeChild($result, 'result');
        $content = $child?->contentString();
        if ($content !== null && $content !== '') {
            $data = json_decode($content, true);
            if (is_array($data)) {
                if (!empty($data['errors'])) {
                    $messages = array_map(fn($e) => $e['message'] ?? 'Unknown error', $data['errors']);
                    $code = (int)($data['errors'][0]['extensions']['error_code'] ?? 400);
                    throw new ConnectionException('GraphQL server error: ' . implode(', ', $messages), $code);
                }
                $response = $dataPath !== '' ? ($data['data'][$dataPath] ?? null) : ($data['data'] ?? null);
                if ($response !== null) {
                    return $response;
                }
            }
        }
        $action = str_replace('_', ' ', str_starts_with($dataPath, 'xwa2_') ? substr($dataPath, 5) : $dataPath);
        throw new ConnectionException("Failed to $action, unexpected response structure.", 400);
    }

    private static function parseNewsletterCreateResponse(array $response): array
    {
        $thread = $response['thread_metadata'] ?? [];
        $viewer = $response['viewer_metadata'] ?? [];
        return [
            'id' => $response['id'] ?? null,
            'owner' => null,
            'name' => $thread['name']['text'] ?? null,
            'creation_time' => (int)($thread['creation_time'] ?? 0),
            'description' => $thread['description']['text'] ?? null,
            'invite' => $thread['invite'] ?? null,
            'subscribers' => (int)($thread['subscribers_count'] ?? 0),
            'verification' => $thread['verification'] ?? null,
            'picture' => [
                'id' => $thread['picture']['id'] ?? null,
                'directPath' => $thread['picture']['direct_path'] ?? null,
            ],
            'mute_state' => $viewer['mute'] ?? null,
        ];
    }

    private static function parseNewsletterMetadata(mixed $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }
        if (isset($result['id']) && is_string($result['id'])) {
            return $result;
        }
        if (isset($result['result']) && is_array($result['result']) && isset($result['result']['id'])) {
            return $result['result'];
        }
        return null;
    }

    /** newsletterCreate(name, description?) → NewsletterMetadata */
    public function newsletterCreate(string $name, ?string $description = null): array
    {
        $raw = $this->executeWMexQuery([
            'input' => ['name' => $name, 'description' => $description],
        ], self::MEX_QUERY_IDS['CREATE'], 'xwa2_newsletter_create');
        return self::parseNewsletterCreateResponse($raw);
    }

    /** newsletterUpdate(jid, updates) */
    public function newsletterUpdate(string $jid, array $updates): mixed
    {
        return $this->executeWMexQuery([
            'newsletter_id' => $jid,
            'updates' => array_merge($updates, ['settings' => null]),
        ], self::MEX_QUERY_IDS['UPDATE_METADATA'], 'xwa2_newsletter_update');
    }

    public function newsletterUpdateName(string $jid, string $name): mixed
    {
        return $this->newsletterUpdate($jid, ['name' => $name]);
    }

    public function newsletterUpdateDescription(string $jid, string $description): mixed
    {
        return $this->newsletterUpdate($jid, ['description' => $description]);
    }

    public function newsletterUpdatePicture(string $jid, mixed $content): mixed
    {
        $img = Media::generateProfilePicture(Media::resolveMediaInput($content));
        return $this->newsletterUpdate($jid, ['picture' => base64_encode($img)]);
    }

    public function newsletterRemovePicture(string $jid): mixed
    {
        return $this->newsletterUpdate($jid, ['picture' => '']);
    }

    /** @return array{subscribers:int} */
    public function newsletterSubscribers(string $jid): array
    {
        return $this->executeWMexQuery(
            ['newsletter_id' => $jid],
            self::MEX_QUERY_IDS['SUBSCRIBERS'],
            'xwa2_newsletter_subscribers'
        );
    }

    /** newsletterMetadata('invite'|'jid', key) */
    public function newsletterMetadata(string $type, string $key): ?array
    {
        $result = $this->executeWMexQuery([
            'fetch_creation_time' => true,
            'fetch_full_image' => true,
            'fetch_viewer_metadata' => true,
            'input' => ['key' => $key, 'type' => strtoupper($type)],
        ], self::MEX_QUERY_IDS['METADATA'], 'xwa2_newsletter');
        return self::parseNewsletterMetadata($result);
    }

    public function newsletterFollow(string $jid): mixed
    {
        return $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['FOLLOW'], 'xwa2_newsletter_join_v2');
    }

    public function newsletterUnfollow(string $jid): mixed
    {
        return $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['UNFOLLOW'], 'xwa2_newsletter_leave_v2');
    }

    public function newsletterMute(string $jid): mixed
    {
        return $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['MUTE'], 'xwa2_newsletter_mute_v2');
    }

    public function newsletterUnmute(string $jid): mixed
    {
        return $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['UNMUTE'], 'xwa2_newsletter_unmute_v2');
    }

    public function newsletterAdminCount(string $jid): int
    {
        $response = $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['ADMIN_COUNT'], 'xwa2_newsletter_admin');
        return (int)($response['admin_count'] ?? 0);
    }

    public function newsletterChangeOwner(string $jid, string $newOwnerJid): void
    {
        $this->executeWMexQuery(
            ['newsletter_id' => $jid, 'user_id' => $newOwnerJid],
            self::MEX_QUERY_IDS['CHANGE_OWNER'],
            'xwa2_newsletter_change_owner'
        );
    }

    public function newsletterDemote(string $jid, string $userJid): void
    {
        $this->executeWMexQuery(
            ['newsletter_id' => $jid, 'user_id' => $userJid],
            self::MEX_QUERY_IDS['DEMOTE'],
            'xwa2_newsletter_demote'
        );
    }

    public function newsletterDelete(string $jid): void
    {
        $this->executeWMexQuery(['newsletter_id' => $jid], self::MEX_QUERY_IDS['DELETE'], 'xwa2_newsletter_delete_v2');
    }

    /** React to (or un-react from) a newsletter message by server id. */
    public function newsletterReactMessage(string $jid, string $serverId, ?string $reaction = null): void
    {
        $attrs = ['to' => $jid, 'type' => 'reaction', 'server_id' => $serverId, 'id' => $this->generateMessageTag()];
        if ($reaction === null) {
            $attrs['edit'] = '7';
        }
        $this->query(new BinaryNode('message', $attrs, [
            new BinaryNode('reaction', $reaction ? ['code' => $reaction] : []),
        ]));
    }

    /** newsletterFetchMessages(jid, count, since?, after?) — raw result node like Baileys. */
    public function newsletterFetchMessages(string $jid, int $count, ?int $since = null, ?int $after = null): BinaryNode
    {
        $attrs = ['count' => (string)$count];
        if ($since !== null) {
            $attrs['since'] = (string)$since;
        }
        if ($after !== null) {
            $attrs['after'] = (string)$after;
        }
        return $this->query(new BinaryNode('iq', [
            'id' => $this->generateMessageTag(),
            'type' => 'get',
            'xmlns' => 'newsletter',
            'to' => $jid,
        ], [new BinaryNode('message_updates', $attrs)]));
    }

    /** subscribeNewsletterUpdates(jid) → ['duration' => string]|null */
    public function subscribeNewsletterUpdates(string $jid): ?array
    {
        $result = $this->query(new BinaryNode('iq', [
            'id' => $this->generateMessageTag(),
            'type' => 'set',
            'xmlns' => 'newsletter',
            'to' => $jid,
        ], [new BinaryNode('live_updates', [], [])]));
        $node = GenericUtils::getBinaryNodeChild($result, 'live_updates');
        $duration = $node?->attrs['duration'] ?? null;
        return $duration ? ['duration' => $duration] : null;
    }

    // ---------- account limits (Socket/socket.ts) ----------

    public function fetchAccountReachoutTimelock(): array
    {
        $q = $this->executeWMexQuery([], self::MEX_QUERY_IDS['REACHOUT_TIMELOCK'], 'xwa2_fetch_account_reachout_timelock');
        $result = [
            'isActive' => !empty($q['is_active']),
            'timeEnforcementEnds' => !empty($q['time_enforcement_ends']) && $q['time_enforcement_ends'] !== '0'
                ? (int)$q['time_enforcement_ends']
                : null,
            'enforcementType' => $q['enforcement_type'] ?? 'DEFAULT',
        ];
        $this->emitter->emit('connection.update', ['reachoutTimeLock' => $result]);
        return $result;
    }

    public function fetchNewChatMessageCap(): array
    {
        return $this->executeWMexQuery(
            ['input' => ['type' => 'INDIVIDUAL_NEW_CHAT_MSG']],
            self::MEX_QUERY_IDS['MESSAGE_CAPPING_INFO'],
            'xwa2_message_capping_info'
        );
    }

    /**
     * Handle 'mex' / 'newsletter' notifications: emit 'newsletter.update' when parseable.
     * (messages-recv handleMexNotification, simplified.)
     */
    private function handleMexNotification(BinaryNode $node): void
    {
        $updateNode = GenericUtils::getBinaryNodeChild($node, 'update');
        if ($updateNode === null) {
            return;
        }
        $opName = $updateNode->attrs['op_name'] ?? null;
        $content = $updateNode->contentString();
        if ($content === null) {
            return;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !empty($data['errors'])) {
            return;
        }
        $this->ev->emit('newsletter.update', ['opName' => $opName, 'data' => $data['data'] ?? null]);
    }
}
