<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/**
 * Port of libsignal session_record.js (SessionEntry + SessionRecord).
 * Serialization format is the same shape as libsignal's (base64 fields),
 * stored by the PHP key store as a JSON string.
 */

final class SessionRecord
{
    public const VERSION = 'v1';
    private const CLOSED_SESSIONS_MAX = 40;

    /** @var array<string,SessionEntry> base64(baseKey) => entry */
    public array $sessions = [];

    public static function createEntry(): SessionEntry
    {
        return new SessionEntry();
    }

    /** Serialize to the string stored in the 'session' key store. */
    public function serialize(): string
    {
        $sessions = [];
        foreach ($this->sessions as $k => $entry) {
            $sessions[$k] = $entry->serialize();
        }
        return json_encode(['_sessions' => $sessions, 'version' => self::VERSION], JSON_UNESCAPED_SLASHES);
    }

    public static function deserialize(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Corrupt session record');
        }
        $o = new self();
        foreach (($data['_sessions'] ?? []) as $k => $entry) {
            $o->sessions[$k] = SessionEntry::deserialize($entry);
        }
        return $o;
    }

    public function haveOpenSession(): bool
    {
        $open = $this->getOpenSession();
        return $open !== null && is_int($open->registrationId);
    }

    public function getSession(string $key): ?SessionEntry
    {
        $s = $this->sessions[base64_encode($key)] ?? null;
        if ($s !== null && ($s->indexInfo['baseKeyType'] ?? null) === SessionEntry::BASEKEY_OURS) {
            throw new \RuntimeException('Tried to lookup a session using our basekey');
        }
        return $s;
    }

    public function getOpenSession(): ?SessionEntry
    {
        foreach ($this->sessions as $s) {
            if (!$this->isClosed($s)) {
                return $s;
            }
        }
        return null;
    }

    public function setSession(SessionEntry $session): void
    {
        $this->sessions[base64_encode($session->indexInfo['baseKey'])] = $session;
    }

    /** @return list<SessionEntry> most recently used first */
    public function getSessions(): array
    {
        $list = array_values($this->sessions);
        usort($list, fn(SessionEntry $a, SessionEntry $b) => (($b->indexInfo['used'] ?? 0) <=> ($a->indexInfo['used'] ?? 0)));
        return $list;
    }

    public function closeSession(SessionEntry $session): void
    {
        if (!$this->isClosed($session)) {
            $session->indexInfo['closed'] = (int)(microtime(true) * 1000);
        }
    }

    public function isClosed(SessionEntry $session): bool
    {
        return ($session->indexInfo['closed'] ?? -1) !== -1;
    }

    public function removeOldSessions(): void
    {
        while (count($this->sessions) > self::CLOSED_SESSIONS_MAX) {
            $oldestKey = null;
            $oldest = null;
            foreach ($this->sessions as $k => $s) {
                if ($s->indexInfo['closed'] !== -1 && ($oldest === null || $s->indexInfo['closed'] < $oldest->indexInfo['closed'])) {
                    $oldestKey = $k;
                    $oldest = $s;
                }
            }
            if ($oldestKey === null) {
                throw new \RuntimeException('Corrupt sessions object');
            }
            unset($this->sessions[$oldestKey]);
        }
    }
}
