<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Port of Baileys src/Utils/message-retry-manager.ts.
 *
 * Tracks recently sent messages (so retry receipts can be served without a
 * getMessage callback), per-message retry counters, session recreation
 * history and pending "ask the phone to resend" (placeholder) requests.
 *
 * Timers are delegated to the socket's scheduler (single-threaded event loop).
 */
final class MessageRetryManager
{
    /** Retry reason codes matching WhatsApp Web's Signal error codes. */
    public const RETRY_UNKNOWN = 0;
    public const RETRY_NO_SESSION = 1;
    public const RETRY_INVALID_KEY = 2;
    public const RETRY_INVALID_KEY_ID = 3;
    public const RETRY_INVALID_MESSAGE = 4; // MAC verification failed
    public const RETRY_INVALID_SIGNATURE = 5;
    public const RETRY_FUTURE_MESSAGE = 6;
    public const RETRY_BAD_MAC = 7;         // explicit MAC failure
    public const RETRY_INVALID_SESSION = 8;
    public const RETRY_INVALID_MSG_KEY = 9;
    public const RETRY_BAD_BROADCAST_EPHEMERAL = 10;
    public const RETRY_UNKNOWN_COMPANION_NO_PREKEY = 11;
    public const RETRY_ADV_FAILURE = 12;
    public const RETRY_STATUS_REVOKE_DELAY = 13;

    /** Error codes that indicate a MAC failure → immediate session recreation */
    private const MAC_ERROR_CODES = [self::RETRY_INVALID_MESSAGE, self::RETRY_BAD_MAC];

    private const RECENT_MESSAGES_SIZE = 512;
    private const RECENT_TTL = 300;          // 5 minutes
    private const RETRY_COUNTER_TTL = 900;   // 15 minutes
    private const RECREATE_SESSION_TIMEOUT = 3600; // 1 hour
    private const BASE_KEY_TTL = 900;
    private const BASE_KEY_MAX = 1024;
    public const PHONE_REQUEST_DELAY_MS = 3000;

    /** @var array<string,array{message:array,timestamp:float}> "to\0id" => entry */
    private array $recentMessages = [];
    /** @var array<string,string> id => recentMessages key */
    private array $messageKeyIndex = [];
    /** @var array<string,float> jid => last recreation time */
    private array $sessionRecreateHistory = [];
    /** @var array<string,array{count:int,timestamp:float}> */
    private array $retryCounters = [];
    /** @var array<string,array{key:string,timestamp:float}> "addr:msgId" => entry */
    private array $baseKeys = [];
    /** @var array<string,true> */
    private array $pendingPhoneRequests = [];

    /** @var array{totalRetries:int,successfulRetries:int,failedRetries:int,mediaRetries:int,sessionRecreations:int,phoneRequests:int} */
    private array $statistics = [
        'totalRetries' => 0,
        'successfulRetries' => 0,
        'failedRetries' => 0,
        'mediaRetries' => 0,
        'sessionRecreations' => 0,
        'phoneRequests' => 0,
    ];

    /** @var null|callable(string,int,callable):void (timerId, delayMs, callback) */
    private $scheduler = null;
    /** @var null|callable(string):void (timerId) */
    private $timerCanceller = null;

    public function __construct(
        private Logger $logger,
        private int $maxMsgRetryCount = 5,
    ) {}

    /** Wire the socket's timer functions (for scheduled phone requests). */
    public function setTimerHandlers(callable $scheduler, callable $canceller): void
    {
        $this->scheduler = $scheduler;
        $this->timerCanceller = $canceller;
    }

    // ------------------------------------------------------------ recent msgs
    public function addRecentMessage(string $to, string $id, array $message): void
    {
        $this->pruneRecent();
        $key = $to . "\0" . $id;
        $this->recentMessages[$key] = ['message' => $message, 'timestamp' => microtime(true)];
        $this->messageKeyIndex[$id] = $key;
        while (count($this->recentMessages) > self::RECENT_MESSAGES_SIZE) {
            $first = array_key_first($this->recentMessages);
            $oldId = substr($first, strrpos($first, "\0") + 1);
            unset($this->recentMessages[$first], $this->messageKeyIndex[$oldId]);
        }
        $this->logger->debug("Added message to retry cache: $to/$id");
    }

    /** @return null|array{message:array,timestamp:float} */
    public function getRecentMessage(string $to, string $id): ?array
    {
        $key = $to . "\0" . $id;
        $entry = $this->recentMessages[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if (microtime(true) - $entry['timestamp'] > self::RECENT_TTL) {
            unset($this->recentMessages[$key], $this->messageKeyIndex[$id]);
            return null;
        }
        return $entry;
    }

    private function removeRecentMessage(string $messageId): void
    {
        $key = $this->messageKeyIndex[$messageId] ?? null;
        if ($key !== null) {
            unset($this->recentMessages[$key], $this->messageKeyIndex[$messageId]);
        }
    }

    private function pruneRecent(): void
    {
        $cutoff = microtime(true) - self::RECENT_TTL;
        foreach ($this->recentMessages as $k => $v) {
            if ($v['timestamp'] < $cutoff) {
                $id = substr($k, strrpos($k, "\0") + 1);
                unset($this->recentMessages[$k], $this->messageKeyIndex[$id]);
            }
        }
    }

    // ---------------------------------------------------------- retry counts
    public function incrementRetryCount(string $messageId): int
    {
        $now = microtime(true);
        $cur = $this->retryCounters[$messageId] ?? null;
        $count = ($cur !== null && $now - $cur['timestamp'] <= self::RETRY_COUNTER_TTL) ? $cur['count'] + 1 : 1;
        $this->retryCounters[$messageId] = ['count' => $count, 'timestamp' => $now];
        $this->statistics['totalRetries']++;
        return $count;
    }

    public function getRetryCount(string $messageId): int
    {
        $cur = $this->retryCounters[$messageId] ?? null;
        if ($cur === null || microtime(true) - $cur['timestamp'] > self::RETRY_COUNTER_TTL) {
            return 0;
        }
        return $cur['count'];
    }

    public function hasExceededMaxRetries(string $messageId): bool
    {
        return $this->getRetryCount($messageId) >= $this->maxMsgRetryCount;
    }

    public function markRetrySuccess(string $messageId): void
    {
        $this->statistics['successfulRetries']++;
        unset($this->retryCounters[$messageId]);
        $this->cancelPendingPhoneRequest($messageId);
        $this->removeRecentMessage($messageId);
    }

    public function markRetryFailed(string $messageId): void
    {
        $this->statistics['failedRetries']++;
        unset($this->retryCounters[$messageId]);
        $this->cancelPendingPhoneRequest($messageId);
        $this->removeRecentMessage($messageId);
    }

    // --------------------------------------------------- session recreation
    /**
     * @return array{reason:string,recreate:bool}
     */
    public function shouldRecreateSession(string $jid, bool $hasSession, ?int $errorCode = null): array
    {
        if (!$hasSession) {
            $this->sessionRecreateHistory[$jid] = microtime(true);
            $this->statistics['sessionRecreations']++;
            return ['reason' => "we don't have a Signal session with them", 'recreate' => true];
        }
        if ($errorCode !== null && in_array($errorCode, self::MAC_ERROR_CODES, true)) {
            $this->sessionRecreateHistory[$jid] = microtime(true);
            $this->statistics['sessionRecreations']++;
            $this->logger->warn(['jid' => $jid, 'errorCode' => $errorCode], 'MAC error detected, forcing immediate session recreation');
            return ['reason' => "MAC error (code $errorCode), immediate session recreation", 'recreate' => true];
        }
        $now = microtime(true);
        $prev = $this->sessionRecreateHistory[$jid] ?? null;
        if ($prev === null || $now - $prev > self::RECREATE_SESSION_TIMEOUT) {
            $this->sessionRecreateHistory[$jid] = $now;
            $this->statistics['sessionRecreations']++;
            return ['reason' => 'retry count > 1 and over an hour since last recreation', 'recreate' => true];
        }
        return ['reason' => '', 'recreate' => false];
    }

    public function parseRetryErrorCode(?string $errorAttr): ?int
    {
        if ($errorAttr === null || $errorAttr === '') {
            return null;
        }
        $code = (int)$errorAttr;
        if ($code >= self::RETRY_UNKNOWN && $code <= self::RETRY_STATUS_REVOKE_DELAY) {
            return $code;
        }
        return self::RETRY_UNKNOWN;
    }

    public function isMacError(?int $errorCode): bool
    {
        return $errorCode !== null && in_array($errorCode, self::MAC_ERROR_CODES, true);
    }

    // -------------------------------------------------------- phone requests
    public function schedulePhoneRequest(string $messageId, callable $callback, int $delayMs = self::PHONE_REQUEST_DELAY_MS): void
    {
        $this->cancelPendingPhoneRequest($messageId);
        if ($this->scheduler === null) {
            // no timer wired (e.g. unit tests) — run immediately
            $this->statistics['phoneRequests']++;
            $callback();
            return;
        }
        $this->pendingPhoneRequests[$messageId] = true;
        ($this->scheduler)('phone-req:' . $messageId, $delayMs, function () use ($messageId, $callback) {
            unset($this->pendingPhoneRequests[$messageId]);
            $this->statistics['phoneRequests']++;
            $callback();
        });
        $this->logger->debug("Scheduled phone request for message $messageId with {$delayMs}ms delay");
    }

    public function cancelPendingPhoneRequest(string $messageId): void
    {
        if (isset($this->pendingPhoneRequests[$messageId])) {
            unset($this->pendingPhoneRequests[$messageId]);
            if ($this->timerCanceller !== null) {
                ($this->timerCanceller)('phone-req:' . $messageId);
            }
            $this->logger->debug("Cancelled pending phone request for message $messageId");
        }
    }

    // -------------------------------------------------------------- base keys
    public function saveBaseKey(string $addr, string $msgId, string $baseKey): void
    {
        if (count($this->baseKeys) >= self::BASE_KEY_MAX) {
            array_shift($this->baseKeys);
        }
        $this->baseKeys["$addr:$msgId"] = ['key' => $baseKey, 'timestamp' => microtime(true)];
    }

    public function hasSameBaseKey(string $addr, string $msgId, string $baseKey): bool
    {
        $stored = $this->baseKeys["$addr:$msgId"] ?? null;
        if ($stored === null || microtime(true) - $stored['timestamp'] > self::BASE_KEY_TTL) {
            return false;
        }
        return hash_equals($stored['key'], $baseKey);
    }

    public function deleteBaseKey(string $addr, string $msgId): void
    {
        unset($this->baseKeys["$addr:$msgId"]);
    }

    // --------------------------------------------------------------- misc
    public function recordMediaRetry(): void
    {
        $this->statistics['mediaRetries']++;
    }

    /** @return array{totalRetries:int,successfulRetries:int,failedRetries:int,mediaRetries:int,sessionRecreations:int,phoneRequests:int} */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    public function clear(): void
    {
        $this->recentMessages = [];
        $this->messageKeyIndex = [];
        $this->sessionRecreateHistory = [];
        $this->retryCounters = [];
        $this->baseKeys = [];
        foreach (array_keys($this->pendingPhoneRequests) as $id) {
            $this->cancelPendingPhoneRequest($id);
        }
        $this->statistics = [
            'totalRetries' => 0, 'successfulRetries' => 0, 'failedRetries' => 0,
            'mediaRetries' => 0, 'sessionRecreations' => 0, 'phoneRequests' => 0,
        ];
    }
}
