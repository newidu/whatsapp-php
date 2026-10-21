<?php
declare(strict_types=1);

namespace BaileysPHP\Store;

use BaileysPHP\Event\EventEmitter;
use BaileysPHP\Socket\WASocket;
use BaileysPHP\Utils\Logger;

/**
 * In-memory store for chats / contacts / messages / group metadata,
 * modelled on Baileys' classic makeInMemoryStore (removed from upstream v7,
 * but every bot wants it — especially for the `getMessage` config callback
 * that powers poll-vote decryption and retry resends).
 *
 * Usage:
 *   $store = \BaileysPHP\makeInMemoryStore();
 *   $store->readFromFile(__DIR__ . '/store.json');            // optional
 *   $sock = makeWASocket([
 *       'auth' => $auth,
 *       'getMessage' => fn(array $key) => $store->getMessage($key['remoteJid'], $key['id']),
 *   ]);
 *   $store->bind($sock->ev);
 *   // periodically: $store->writeToFile(__DIR__ . '/store.json');
 */
final class InMemoryStore
{
    /** @var array<string,array> chat jid => chat */
    public array $chats = [];
    /** @var array<string,array> contact jid => contact */
    public array $contacts = [];
    /** @var array<string,array<string,array>> chat jid => (message id => WAMessage) */
    public array $messages = [];
    /** @var array<string,array> group jid => metadata */
    public array $groupMetadata = [];
    /** @var array{connection:string} */
    public array $state = ['connection' => 'close'];

    private ?Logger $logger;
    private int $maxMessagesPerChat;

    public function __construct(array $options = [])
    {
        $lg = $options['logger'] ?? null;
        $this->logger = $lg instanceof Logger ? $lg : null;
        $this->maxMessagesPerChat = (int)($options['maxMessagesPerChat'] ?? 0); // 0 = unlimited
    }

    public function bind(EventEmitter $ev): void
    {
        $ev->on('connection.update', function (array $update) {
            if (isset($update['connection'])) {
                $this->state['connection'] = (string)$update['connection'];
            }
        });

        $ev->on('chats.set', function (array $e) {
            $this->chats = [];
            foreach ($e['chats'] ?? [] as $chat) {
                if (!empty($chat['id'])) {
                    $this->chats[$chat['id']] = $chat;
                }
            }
        });
        $ev->on('chats.upsert', function (array $chats) {
            foreach ($chats as $chat) {
                if (!empty($chat['id'])) {
                    $this->chats[$chat['id']] = array_merge($this->chats[$chat['id']] ?? [], $chat);
                }
            }
        });
        $ev->on('chats.update', function (array $updates) {
            foreach ($updates as $update) {
                $id = $update['id'] ?? null;
                if ($id) {
                    $this->chats[$id] = array_merge($this->chats[$id] ?? ['id' => $id], $update);
                }
            }
        });
        $ev->on('chats.delete', function (array $jids) {
            foreach ($jids as $jid) {
                unset($this->chats[$jid]);
            }
        });

        $upsertContacts = function (array $contacts) {
            foreach ($contacts as $c) {
                if (!empty($c['id'])) {
                    $this->contacts[$c['id']] = array_merge($this->contacts[$c['id']] ?? [], $c);
                }
            }
        };
        $ev->on('contacts.upsert', $upsertContacts);
        $ev->on('contacts.update', $upsertContacts);
        $ev->on('contacts.set', function (array $e) use ($upsertContacts) {
            $this->contacts = [];
            $upsertContacts($e['contacts'] ?? []);
        });

        $ev->on('messaging-history.set', function (array $e) {
            foreach ($e['chats'] ?? [] as $chat) {
                if (!empty($chat['id']) && !isset($this->chats[$chat['id']])) {
                    $this->chats[$chat['id']] = $chat;
                }
            }
            foreach ($e['contacts'] ?? [] as $c) {
                if (!empty($c['id']) && !isset($this->contacts[$c['id']])) {
                    $this->contacts[$c['id']] = $c;
                }
            }
            foreach ($e['messages'] ?? [] as $m) {
                $this->upsertMessage($m);
            }
        });

        $ev->on('messages.upsert', function (array $e) {
            $isNotify = ($e['type'] ?? '') === 'notify';
            foreach ($e['messages'] ?? [] as $m) {
                $this->upsertMessage($m);
                $jid = $m['key']['remoteJid'] ?? null;
                if ($jid && isset($this->chats[$jid])) {
                    $this->chats[$jid]['lastMessage'] = $m;
                    $this->chats[$jid]['conversationTimestamp'] = $m['messageTimestamp'] ?? time();
                    if ($isNotify && empty($m['key']['fromMe'])) {
                        $this->chats[$jid]['unreadCount'] = ($this->chats[$jid]['unreadCount'] ?? 0) + 1;
                    }
                }
            }
        });
        $ev->on('messages.update', function (array $updates) {
            foreach ($updates as $u) {
                $key = $u['key'] ?? null;
                $jid = $key['remoteJid'] ?? null;
                $id = $key['id'] ?? null;
                if ($jid && $id && isset($this->messages[$jid][$id])) {
                    $this->messages[$jid][$id] = array_merge($this->messages[$jid][$id], $u['update'] ?? []);
                }
            }
        });
        $ev->on('messages.delete', function (array $e) {
            if (!empty($e['all']) && !empty($e['jid'])) {
                $this->messages[$e['jid']] = [];
                return;
            }
            foreach ($e['keys'] ?? [] as $key) {
                if (!empty($key['remoteJid']) && !empty($key['id'])) {
                    unset($this->messages[$key['remoteJid']][$key['id']]);
                }
            }
        });

        $ev->on('groups.upsert', function (array $groups) {
            foreach ($groups as $g) {
                if (!empty($g['id'])) {
                    $this->groupMetadata[$g['id']] = array_merge($this->groupMetadata[$g['id']] ?? [], $g);
                }
            }
        });
        $ev->on('groups.update', function (array $updates) {
            foreach ($updates as $g) {
                if (!empty($g['id']) && isset($this->groupMetadata[$g['id']])) {
                    $this->groupMetadata[$g['id']] = array_merge($this->groupMetadata[$g['id']], $g);
                }
            }
        });
        $ev->on('group-participants.update', function (array $e) {
            // participants changed → cached metadata is stale; drop it (fetch again on demand)
            if (!empty($e['id'])) {
                unset($this->groupMetadata[$e['id']]);
            }
        });
    }

    private function upsertMessage(array $msg): void
    {
        $jid = $msg['key']['remoteJid'] ?? null;
        $id = $msg['key']['id'] ?? null;
        if (!$jid || !$id) {
            return;
        }
        $existing = $this->messages[$jid][$id] ?? null;
        $this->messages[$jid][$id] = $existing ? array_merge($existing, $msg) : $msg;
        if ($this->maxMessagesPerChat > 0 && count($this->messages[$jid]) > $this->maxMessagesPerChat) {
            $this->messages[$jid] = array_slice($this->messages[$jid], -$this->maxMessagesPerChat, null, true);
        }
    }

    /** The proto Message of a stored message (for config getMessage). */
    public function getMessage(string $jid, string $id): ?array
    {
        return $this->messages[$jid][$id]['message'] ?? null;
    }

    /** The full stored WAMessage. */
    public function loadMessage(string $jid, string $id): ?array
    {
        return $this->messages[$jid][$id] ?? null;
    }

    public function getChat(string $jid): ?array
    {
        return $this->chats[$jid] ?? null;
    }

    public function getContact(string $jid): ?array
    {
        return $this->contacts[$jid] ?? null;
    }

    /** @return list<array> messages of a chat, oldest → newest */
    public function loadMessages(string $jid): array
    {
        return array_values($this->messages[$jid] ?? []);
    }

    /** Group metadata from cache, fetching (and caching) when missing. */
    public function fetchGroupMetadata(string $jid, WASocket $sock): ?array
    {
        if (!isset($this->groupMetadata[$jid])) {
            try {
                $meta = $sock->groupMetadata($jid);
                if (is_array($meta)) {
                    $this->groupMetadata[$jid] = $meta;
                }
            } catch (\Throwable $e) {
                $this->logger?->warn(['jid' => $jid, 'err' => $e->getMessage()], 'failed to fetch group metadata');
            }
        }
        return $this->groupMetadata[$jid] ?? null;
    }

    // ------------------------------------------------------------ persistence
    public function writeToFile(string $path): void
    {
        $json = json_encode($this->encode([
            'chats' => $this->chats,
            'contacts' => $this->contacts,
            'messages' => $this->messages,
            'groupMetadata' => $this->groupMetadata,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('failed to encode store: ' . json_last_error_msg());
        }
        $tmp = $path . '.tmp' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    public function readFromFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger?->warn(['path' => $path], 'store file is not valid JSON, ignoring');
            return;
        }
        $decoded = $this->decode($data);
        $this->chats = $decoded['chats'] ?? [];
        $this->contacts = $decoded['contacts'] ?? [];
        $this->messages = $decoded['messages'] ?? [];
        $this->groupMetadata = $decoded['groupMetadata'] ?? [];
    }

    // binary-safe JSON transform (Baileys BufferJSON shape)
    private function encode(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : ['type' => 'Buffer', 'data' => base64_encode($value)];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->encode($v);
            }
            return $out;
        }
        return $value;
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            if (($value['type'] ?? null) === 'Buffer' && is_string($value['data'] ?? null) && count($value) === 2) {
                return base64_decode($value['data'], true) ?: '';
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->decode($v);
            }
            return $out;
        }
        return $value;
    }
}
