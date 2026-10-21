<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Auth\AuthState;
use BaileysPHP\Auth\Creds;
use BaileysPHP\ConnectionException;
use BaileysPHP\Defaults;
use BaileysPHP\DisconnectReason;
use BaileysPHP\Event\EventEmitter;
use BaileysPHP\Proto\WAProto;
use BaileysPHP\Signal\SignalRepository;
use BaileysPHP\Utils\Curve;
use BaileysPHP\Utils\Generics;
use BaileysPHP\Utils\Logger;
use BaileysPHP\Utils\NoiseHandler;
use BaileysPHP\Utils\SignalUtils;
use BaileysPHP\Utils\ValidateConnection;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\Encoder;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;
use BaileysPHP\WAUSync\USyncQuery;
use function BaileysPHP\Utils\aesEncryptCTR;
use function BaileysPHP\Utils\derivePairingCodeKey;
use function BaileysPHP\Utils\generateSignalPubKey;
use function BaileysPHP\Utils\signedKeyPair;

/**
 * makeWASocket() — the PHP port of Baileys' socket (src/Socket/socket.ts +
 * the Basic parts of messages-send.ts / messages-recv.ts).
 *
 * IMPORTANT PHP DIFFERENCE — the event loop:
 * Node Baileys runs on an async event loop. Pure PHP (CLI) has none, so this
 * socket exposes a blocking loop you must call at the end of your script:
 *
 *     $sock = makeWASocket([...]);
 *     $sock->ev->on('connection.update', fn($u) => ...);
 *     $sock->ev->on('messages.upsert', fn($m) => ...);
 *     $sock->run();            // <-- keeps the connection alive (like Node's loop)
 *
 * query()/sendMessage() internally pump the loop while waiting for replies,
 * so calling them from inside event handlers works as in Baileys examples.
 */
class WASocket
{
    use GroupsTrait;
    use MediaTrait;
    use MessageContentTrait;
    use ChatsTrait;
    use TcTokenTrait;
    use AppStateTrait;
    use ReceiveTrait;
    use CommunitiesTrait;
    use BusinessTrait;
    use NewsletterTrait;

    public string $type = 'md';
    public EventEmitter $ev;
    public AuthState $authState;
    public SignalRepository $signalRepository;
    /** @var null|array{id:string,name?:?string,lid?:?string} mirrors creds.me */
    public ?array $user = null;
    public ?string $lastQr = null;

    /** @var array<string,mixed> */
    private array $config;
    private Logger $logger;
    private WebSocketClient $ws;
    private NoiseHandler $noise;
    private \BaileysPHP\Auth\KeyPair $ephemeralKeyPair;

    private string $uqTagId;
    private int $epoch = 1;
    private bool $closed = false;
    private bool $connected = false;
    private float $lastDateRecv = 0.0;
    private float $nextKeepAliveAt = 0.0;
    private int $serverTimeOffsetMs = 0;

    /** @var array<string,BinaryNode> TAG results awaiting query() */
    private array $tagResults = [];
    /** @var list<string> pre-transport raw frames (handshake) */
    private array $rawFrames = [];
    /** @var array<string,list<callable(BinaryNode):void>> CB pattern handlers */
    private array $cbHandlers = [];
    /** @var array<string,array{at:float,cb:callable}> named timers */
    private array $timers = [];
    /** @var list<string> remaining QR refs */
    private array $qrRefs = [];
    private int $qrMs = 60000;
    /** @var array<string,list<array{user:string,device:int,domainType:int,server:string}>> */
    private array $devicesCache = [];
    private ?\Throwable $endError = null;
    private ?\BaileysPHP\Utils\MessageRetryManager $retryManager = null;
    private bool $pairingRequested = false;

    /** @param array<string,mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(Defaults::defaultConfig(), $config);
        // also accept a MultiFileAuthState directly — unwrap to its ->state
        if (($this->config['auth'] ?? null) instanceof \BaileysPHP\Auth\MultiFileAuthState) {
            $this->config['auth'] = $this->config['auth']->state;
        }
        if (!isset($this->config['auth']) || !$this->config['auth'] instanceof AuthState) {
            throw new \InvalidArgumentException("makeWASocket: 'auth' (AuthState) is required — use useMultiFileAuthState()");
        }
        $this->authState = $this->config['auth'];
        $lg = $this->config['logger'] ?? null;
        $this->logger = $lg instanceof Logger ? $lg : new Logger(is_string($lg) ? $lg : 'info');
        $this->ev = new EventEmitter();
        $this->user = $this->creds()->me;
        $this->uqTagId = Generics::generateMdTagPrefix();

        $this->signalRepository = new SignalRepository(
            $this->authState,
            $this->logger,
            fn(array $jids) => $this->pnFromLIDUSync($jids)
        );

        // smart retry handling (recent-message cache, session recreation)
        if ($this->config['enableRecentMessageCache'] ?? true) {
            $this->retryManager = new \BaileysPHP\Utils\MessageRetryManager(
                $this->logger,
                (int)($this->config['maxMsgRetryCount'] ?? 5)
            );
            $this->retryManager->setTimerHandlers(
                fn(string $id, int $delayMs, callable $cb) => $this->schedule($id, $delayMs / 1000, $cb),
                fn(string $id) => $this->cancelTimer($id),
            );
        }

        $this->ephemeralKeyPair = Curve::generateKeyPair();
        $this->noise = new NoiseHandler(
            $this->ephemeralKeyPair,
            Defaults::noiseWAHeader(),
            $this->logger,
            $this->creds()->routingInfo
        );

        $url = (string)$this->config['waWebSocketUrl'];
        if ($this->creds()->routingInfo) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . 'ED=' . rtrim(strtr(base64_encode($this->creds()->routingInfo), '+/', '-_'), '=');
        }
        $this->ws = new WebSocketClient($url, $this->config, $this->logger);
        $this->registerHandlers();
    }

    /** @param array<string,mixed> $config */
    public static function makeWASocket(array $config = []): self
    {
        return new self($config);
    }

    private function creds(): Creds
    {
        return $this->authState->creds;
    }

    // ------------------------------------------------------------------ core io
    public function getLogger(): Logger { return $this->logger; }
    public function isClosed(): bool { return $this->closed; }
    public function isOpen(): bool { return $this->ws->isOpen; }

    public function generateMessageTag(): string
    {
        return $this->uqTagId . $this->epoch++;
    }

    public function sendRawMessage(string $data): void
    {
        if (!$this->ws->isOpen) {
            throw new ConnectionException('Connection Closed', DisconnectReason::connectionClosed);
        }
        $this->ws->send($this->noise->encodeFrame($data));
    }

    public function sendNode(BinaryNode $frame): void
    {
        if ($this->logger->level === 'trace') {
            $this->logger->trace(['xml' => GenericUtils::binaryNodeToString($frame)], 'xml send');
        }
        $this->sendRawMessage(Encoder::encodeBinaryNode($frame));
    }

    private function onCB(string $pattern, callable $handler): void
    {
        $this->cbHandlers[$pattern][] = $handler;
    }

    private function dispatchFrame(string|BinaryNode $frame): void
    {
        $this->lastDateRecv = microtime(true);
        if (is_string($frame)) {
            $this->rawFrames[] = $frame;
            return;
        }
        if ($this->logger->level === 'trace') {
            $this->logger->trace(['xml' => GenericUtils::binaryNodeToString($frame)], 'recv xml');
        }
        $msgId = $frame->attrs['id'] ?? null;
        if ($msgId !== null) {
            $this->tagResults[$msgId] = $frame;
        }
        $l0 = $frame->tag;
        $l1 = $frame->attrs;
        $l2 = (is_array($frame->content) && isset($frame->content[0])) ? $frame->content[0]->tag : '';
        $patterns = [];
        foreach ($l1 as $k => $v) {
            $patterns[] = "CB:$l0,$k:$v,$l2";
            $patterns[] = "CB:$l0,$k:$v";
            $patterns[] = "CB:$l0,$k";
        }
        $patterns[] = "CB:$l0,,$l2";
        $patterns[] = "CB:$l0";
        $any = false;
        foreach (array_unique($patterns) as $p) {
            foreach ($this->cbHandlers[$p] ?? [] as $h) {
                $any = true;
                try {
                    $h($frame);
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage(), 'pattern' => $p], 'error in stanza handler');
                }
            }
        }
        if (!$any && $this->logger->level === 'debug') {
            $this->logger->debug(['unhandled' => true, 'tag' => $l0, 'attrs' => $l1], 'communication recv');
        }
    }

    /** Read from the WebSocket once and dispatch everything complete. */
    public function pump(float $timeoutSec = 0.2): void
    {
        if ($this->ws->isOpen) {
            foreach ($this->ws->poll($timeoutSec) as $payload) {
                try {
                    foreach ($this->noise->decodeFrame($payload) as $frame) {
                        $this->dispatchFrame($frame);
                        if ($this->closed) {
                            return;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage()], 'error decoding frame');
                    $this->end($e);
                    return;
                }
            }
            if ($this->ws->isClosed && !$this->closed && $this->connected) {
                $this->end(new ConnectionException('Connection Terminated', DisconnectReason::connectionClosed));
                return;
            }
        }
        $this->serviceTimers();
    }

    private function schedule(string $id, float $afterSec, callable $cb): void
    {
        $this->timers[$id] = ['at' => microtime(true) + $afterSec, 'cb' => $cb];
    }

    private function cancelTimer(string $id): void
    {
        unset($this->timers[$id]);
    }

    private function serviceTimers(): void
    {
        // keep-alive
        if ($this->connected && $this->noise->isTransport() && $this->nextKeepAliveAt > 0 && microtime(true) >= $this->nextKeepAliveAt) {
            $interval = ((int)$this->config['keepAliveIntervalMs']) / 1000;
            $this->nextKeepAliveAt = microtime(true) + $interval;
            $diff = microtime(true) - ($this->lastDateRecv ?: microtime(true));
            if ($diff > $interval + 5) {
                $this->end(new ConnectionException('Connection was lost', DisconnectReason::connectionLost));
                return;
            }
            if ($this->ws->isOpen) {
                try {
                    $this->query(new BinaryNode('iq', [
                        'id' => $this->generateMessageTag(), 'to' => 's.whatsapp.net', 'type' => 'get', 'xmlns' => 'w:p',
                    ], [new BinaryNode('ping')]), 15000);
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage()], 'error in sending keep alive');
                }
            }
        }
        $now = microtime(true);
        foreach ($this->timers as $id => $t) {
            if ($now >= $t['at']) {
                unset($this->timers[$id]);
                try {
                    ($t['cb'])();
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage(), 'timer' => $id], 'timer error');
                }
            }
        }
    }

    private function waitForRawFrame(int $timeoutMs): string
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (true) {
            if ($this->rawFrames !== []) {
                return array_shift($this->rawFrames);
            }
            if ($this->closed || $this->ws->isClosed) {
                throw new ConnectionException('Connection Closed', DisconnectReason::connectionClosed);
            }
            if (microtime(true) > $deadline) {
                throw new ConnectionException('Timed Out', DisconnectReason::timedOut);
            }
            $this->pump(0.2);
        }
    }

    public function waitForMessage(string $msgId, ?int $timeoutMs = null): ?BinaryNode
    {
        $timeoutMs ??= (int)($this->config['defaultQueryTimeoutMs'] ?? 60000);
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (true) {
            if (isset($this->tagResults[$msgId])) {
                $r = $this->tagResults[$msgId];
                unset($this->tagResults[$msgId]);
                return $r;
            }
            if ($this->closed) {
                throw $this->endError instanceof \Throwable
                    ? new ConnectionException($this->endError->getMessage(), (int)$this->endError->getCode() ?: 428)
                    : new ConnectionException('Connection Closed', DisconnectReason::connectionClosed);
            }
            if (microtime(true) > $deadline) {
                $this->logger->warn(['msgId' => $msgId], 'timed out waiting for message');
                return null;
            }
            $this->pump(0.2);
        }
    }

    /** send a query and wait for its response (auto-generates id). */
    public function query(BinaryNode $node, ?int $timeoutMs = null): BinaryNode
    {
        if (empty($node->attrs['id'])) {
            $node->attrs['id'] = $this->generateMessageTag();
        }
        $msgId = $node->attrs['id'];
        $this->sendNode($node);
        $result = $this->waitForMessage($msgId, $timeoutMs);
        if ($result === null) {
            throw new ConnectionException('Timed Out', DisconnectReason::timedOut);
        }
        GenericUtils::assertNodeErrorFree($result);
        return $result;
    }

    // ------------------------------------------------------------------ lifecycle
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }
        $this->ev->emit('connection.update', ['connection' => 'connecting', 'receivedPendingNotifications' => false, 'qr' => null]);
        $this->ws->connect();
        $this->connected = true;
        $this->lastDateRecv = microtime(true);
        try {
            $this->validateConnection();
        } catch (\Throwable $e) {
            $this->logger->error(['err' => $e->getMessage()], 'error in validating connection');
            $this->end($e);
            throw $e;
        }
        // Optional automatic pairing-code flow (PHP-friendly alternative to calling
        // requestPairingCode() from a 'connecting' handler, which Node examples do async):
        // makeWASocket(['pairingPhoneNumber' => '9477xxxxxxx'])  (+ optional 'customPairingCode')
        // The request itself is sent when the server's pair-device stanza arrives
        // (see registerHandlers) — i.e. as soon as the server is ready to pair.
    }

    /**
     * The main blocking loop — call this at the end of your script.
     * Returns when the connection closes (inspect connection.update events for why).
     */
    public function run(?float $maxSeconds = null): void
    {
        if (!$this->connected) {
            $this->connect();
        }
        $start = microtime(true);
        while (!$this->closed) {
            $this->pump(0.2);
            if ($maxSeconds !== null && (microtime(true) - $start) >= $maxSeconds) {
                return;
            }
        }
    }

    /** Run the loop for a fixed number of seconds (useful for scripts/tests). */
    public function runFor(float $seconds): void
    {
        $this->run($seconds);
    }

    public function end(?\Throwable $error = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->endError = $error;
        $this->logger->info($error ? 'connection errored: ' . $error->getMessage() : 'connection closed');
        $this->cancelTimer('qr');
        try {
            $this->ws->close();
        } catch (\Throwable) {
        }
        $this->ev->emit('connection.update', [
            'connection' => 'close',
            'lastDisconnect' => [
                'error' => $error,
                'date' => new \DateTimeImmutable(),
            ],
        ]);
    }

    public function logout(?string $msg = null): void
    {
        $jid = $this->creds()->me['id'] ?? null;
        if ($jid) {
            try {
                $this->sendNode(new BinaryNode('iq', [
                    'to' => 's.whatsapp.net', 'type' => 'set', 'id' => $this->generateMessageTag(), 'xmlns' => 'md',
                ], [new BinaryNode('remove-companion-device', ['jid' => $jid, 'reason' => 'user_initiated'])]));
            } catch (\Throwable) {
            }
        }
        $this->end(new ConnectionException($msg ?: 'Intentional Logout', DisconnectReason::loggedOut));
    }

    private function validateConnection(): void
    {
        $helloBytes = WAProto::encodeHandshakeClientHello(['ephemeral' => $this->ephemeralKeyPair->public]);
        $this->logger->info('connected to WA');
        $this->sendRawMessage($helloBytes);
        $result = $this->waitForRawFrame((int)$this->config['connectTimeoutMs']);
        $handshake = WAProto::decodeHandshakeMessage($result);
        $this->logger->debug('handshake recv from WA');
        if (empty($handshake['serverHello'])) {
            throw new ConnectionException('No serverHello in handshake', 400);
        }
        $keyEnc = $this->noise->processHandshake($handshake['serverHello'], $this->creds()->noiseKey);

        if (empty($this->creds()->me)) {
            $node = ValidateConnection::generateRegistrationNode($this->creds(), $this->config);
            $this->logger->info('not logged in, attempting registration...');
        } else {
            $node = ValidateConnection::generateLoginNode($this->creds()->me['id'], $this->config);
            $this->logger->info('logging in...');
        }
        $payloadEnc = $this->noise->encrypt(WAProto::encodeClientPayload($node));
        $this->sendRawMessage(WAProto::encodeHandshakeClientFinish($keyEnc, $payloadEnc));
        $this->noise->finishInit();
        $this->nextKeepAliveAt = microtime(true) + ((int)$this->config['keepAliveIntervalMs']) / 1000;
    }

    // ------------------------------------------------------------------ creds helpers
    /** @param array<string,mixed> $update */
    private function applyCredsUpdate(array $update): void
    {
        $this->creds()->applyUpdate($update);
        $this->user = $this->creds()->me;
        $this->ev->emit('creds.update', $update);
    }

    private function updateServerTimeOffset(BinaryNode $node): void
    {
        $t = $node->attrs['t'] ?? null;
        if ($t === null || !is_numeric($t) || (int)$t <= 0) {
            return;
        }
        $this->serverTimeOffsetMs = (int)$t * 1000 - (int)(microtime(true) * 1000);
    }

    private function sendUnifiedSession(): void
    {
        if (!$this->ws->isOpen) {
            return;
        }
        $week = 7 * 24 * 60 * 60 * 1000;
        $id = (string)(((int)(microtime(true) * 1000) + $this->serverTimeOffsetMs + 3 * 24 * 60 * 60 * 1000) % $week);
        try {
            $this->sendNode(new BinaryNode('ib', [], [new BinaryNode('unified_session', ['id' => $id])]));
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ pre-keys
    public function getAvailablePreKeysOnServer(): int
    {
        $result = $this->query(new BinaryNode('iq', [
            'id' => $this->generateMessageTag(), 'xmlns' => 'encrypt', 'type' => 'get', 'to' => 's.whatsapp.net',
        ], [new BinaryNode('count')]));
        $countChild = GenericUtils::getBinaryNodeChild($result, 'count');
        return (int)($countChild?->attrs['value'] ?? 0);
    }

    public function uploadPreKeys(int $count = Defaults::MIN_PREKEY_COUNT): void
    {
        $this->logger->info(['count' => $count], 'uploading pre-keys');
        ['update' => $update, 'node' => $node] = SignalUtils::getNextPreKeysNode($this->authState, $count);
        $this->applyCredsUpdate($update);
        $this->query($node);
        $this->logger->info(['count' => $count], 'uploaded pre-keys successfully');
    }

    /** rotateSignedPreKey — upload a fresh signed pre-key and persist it in creds. */
    public function rotateSignedPreKey(): void
    {
        $creds = $this->creds();
        $newId = ($creds->signedPreKey->keyId ?? 0) + 1;
        $skey = signedKeyPair($creds->signedIdentityKey, $newId);
        $this->query(new BinaryNode('iq', [
            'to' => Jid::S_WHATSAPP_NET, 'type' => 'set', 'xmlns' => 'encrypt',
        ], [new BinaryNode('rotate', [], [SignalUtils::xmppSignedPreKey($skey)])]));
        $creds->signedPreKey = $skey;
        $this->emitter->emit('creds.update', ['signedPreKey' => $skey]);
        $this->authState->saveCreds();
    }

    /**
     * waitForConnectionUpdate — keep processing until the check callback returns true
     * (Baileys: bindWaitForConnectionUpdate). $check receives each connection.update payload.
     */
    public function waitForConnectionUpdate(callable $check, int $timeoutMs = 60000): bool
    {
        $done = false;
        $off = $this->emitter->on('connection.update', function (array $u) use ($check, &$done) {
            if ($check($u)) {
                $done = true;
            }
        });
        try {
            $deadline = microtime(true) + $timeoutMs / 1000;
            while (!$done && $this->state !== 'closed' && microtime(true) < $deadline) {
                $this->processNext(250);
            }
        } finally {
            $this->emitter->off('connection.update', $off);
        }
        return $done;
    }

    public function uploadPreKeysToServerIfRequired(): void
    {
        try {
            $preKeyCount = $this->getAvailablePreKeysOnServer();
            $count = $preKeyCount === 0 ? (int)($this->config['initialPreKeyCount'] ?? Defaults::INITIAL_PREKEY_COUNT) : Defaults::MIN_PREKEY_COUNT;
            $currentPreKeyId = $this->creds()->nextPreKeyId - 1;
            $exists = false;
            if ($currentPreKeyId > 0) {
                $pre = $this->authState->keys->get('pre-key', [(string)$currentPreKeyId]);
                $exists = isset($pre[(string)$currentPreKeyId]);
            }
            $this->logger->info("$preKeyCount pre-keys found on server");
            if ($preKeyCount <= $count || (!$exists && $currentPreKeyId > 0)) {
                $this->uploadPreKeys($count);
            }
        } catch (\Throwable $e) {
            $this->logger->error(['err' => $e->getMessage()], 'Failed to check/upload pre-keys during initialization');
        }
    }

    public function digestKeyBundle(): void
    {
        $res = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net', 'type' => 'get', 'xmlns' => 'encrypt',
        ], [new BinaryNode('digest')]));
        if (GenericUtils::getBinaryNodeChild($res, 'digest') === null) {
            $this->uploadPreKeys();
            throw new \RuntimeException('encrypt/get digest returned no digest node');
        }
    }

    private function sendPassiveIq(string $tag): void
    {
        $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net', 'xmlns' => 'passive', 'type' => 'set',
        ], [new BinaryNode($tag)]));
    }

    // ------------------------------------------------------------------ pairing
    public function requestPairingCode(string $phoneNumber, ?string $customPairingCode = null): string
    {
        $pairingCode = $customPairingCode ?? Generics::bytesToCrockford(random_bytes(5));
        if ($customPairingCode !== null && strlen($customPairingCode) !== 8) {
            throw new \InvalidArgumentException('Custom pairing code must be exactly 8 chars');
        }
        $this->pairingRequested = true;
        $meId = Jid::encode(preg_replace('/\D/', '', $phoneNumber), 's.whatsapp.net');
        $this->applyCredsUpdate(['pairingCode' => $pairingCode, 'me' => ['id' => $meId, 'name' => '~']]);

        $salt = random_bytes(32);
        $iv = random_bytes(16);
        $key = derivePairingCodeKey($pairingCode, $salt);
        $ciphered = aesEncryptCTR($this->creds()->pairingEphemeralKeyPair->public, $key, $iv);
        $pairingKey = $salt . $iv . $ciphered;

        $browser = $this->config['browser'];
        $this->sendNode(new BinaryNode('iq', [
            'to' => 's.whatsapp.net', 'type' => 'set', 'id' => $this->generateMessageTag(), 'xmlns' => 'md',
        ], [
            new BinaryNode('link_code_companion_reg', [
                'jid' => $meId, 'stage' => 'companion_hello', 'should_show_push_notification' => 'true',
            ], [
                new BinaryNode('link_code_pairing_wrapped_companion_ephemeral_pub', [], new Bytes($pairingKey)),
                new BinaryNode('companion_server_auth_key_pub', [], new Bytes($this->creds()->noiseKey->public)),
                new BinaryNode('companion_platform_id', [], Generics::getCompanionPlatformId($browser)),
                new BinaryNode('companion_platform_display', [], $browser[1] . ' (' . $browser[0] . ')'),
                new BinaryNode('link_code_pairing_nonce', [], '0'),
            ]),
        ]));
        return $pairingCode;
    }

    // ------------------------------------------------------------------ stanza handlers
    private function registerHandlers(): void
    {
        // server acknowledgements: surface rejections (e.g. 401/479) instead of failing silently
        $this->onCB('CB:ack', function (BinaryNode $stanza) {
            $err = $stanza->attrs['error'] ?? null;
            if ($err !== null && $err !== '' && $err !== '0') {
                $this->logger->warn([
                    'error' => $err,
                    'class' => $stanza->attrs['class'] ?? null,
                    'id' => $stanza->attrs['id'] ?? null,
                    'to' => $stanza->attrs['to'] ?? ($stanza->attrs['participant'] ?? null),
                ], 'server rejected stanza (ack error)');
            } else {
                $this->logger->debug(['class' => $stanza->attrs['class'] ?? null, 'id' => $stanza->attrs['id'] ?? null], 'ack received');
            }
        });

        // QR generation
        $this->onCB('CB:iq,type:set,pair-device', function (BinaryNode $stanza) {
            $this->sendNode(new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'result', 'id' => $stanza->attrs['id'] ?? '']));
            $pairDevice = GenericUtils::getBinaryNodeChild($stanza, 'pair-device');
            $this->qrRefs = [];
            foreach (GenericUtils::getBinaryNodeChildren($pairDevice, 'ref') as $r) {
                $ref = $r->contentString();
                if ($ref !== null) {
                    $this->qrRefs[] = $ref;
                }
            }
            $this->qrMs = (int)($this->config['qrTimeout'] ?? 60000);
            if (!empty($this->config['pairingPhoneNumber']) && !$this->pairingRequested) {
                try {
                    $code = $this->requestPairingCode((string)$this->config['pairingPhoneNumber'], $this->config['customPairingCode'] ?? null);
                    $this->ev->emit('connection.update', ['pairingCode' => $code]);
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage()], 'failed to request pairing code');
                }
            }
            $this->genPairQR();
        });

        // paired for the first time
        $this->onCB('CB:iq,,pair-success', function (BinaryNode $stanza) {
            $this->logger->debug('pair success recv');
            try {
                $this->updateServerTimeOffset($stanza);
                ['reply' => $reply, 'creds' => $updated] = ValidateConnection::configureSuccessfulPairing($stanza, $this->creds());
                $this->logger->info(['me' => $updated['me'] ?? null], 'pairing configured successfully, expect to restart the connection...');
                $this->applyCredsUpdate($updated);
                $this->ev->emit('connection.update', ['isNewLogin' => true, 'qr' => null]);
                $this->sendNode($reply);
                $this->sendUnifiedSession();
            } catch (\Throwable $e) {
                $this->logger->info(['err' => $e->getMessage()], 'error in pairing');
                $this->end($e);
            }
        });

        // login complete
        $this->onCB('CB:success', function (BinaryNode $node) {
            try {
                $this->updateServerTimeOffset($node);
                $this->uploadPreKeysToServerIfRequired();
                $this->sendPassiveIq('active');
                try {
                    $this->digestKeyBundle();
                } catch (\Throwable $e) {
                    $this->logger->warn(['err' => $e->getMessage()], 'failed to run digest after login');
                }
                try {
                    $this->digestKeyBundle();
                } catch (\Throwable $e) {
                    $this->logger->warn(['err' => $e->getMessage()], 'failed to run digest after login');
                }
            } catch (\Throwable) {
            }
            $this->logger->info('opened connection to WA');
            $this->cancelTimer('qr');
            $me = $this->creds()->me ?? [];
            if (!empty($node->attrs['lid'])) {
                $me['lid'] = $node->attrs['lid'];
            }
            $this->applyCredsUpdate(['me' => $me]);
            $this->ev->emit('connection.update', ['connection' => 'open']);
            $this->sendUnifiedSession();
            if (!empty($node->attrs['lid']) && !empty($this->creds()->me['id'])) {
                try {
                    $pn = $this->creds()->me['id'];
                    $this->signalRepository->lidMapping->storeLIDPNMappings([['lid' => $node->attrs['lid'], 'pn' => $pn]]);
                    $dec = Jid::decode($pn);
                    $this->authState->keys->set(['device-list' => [$dec['user'] => [(string)($dec['device'] ?? 0)]]]);
                } catch (\Throwable $e) {
                    $this->logger->error(['err' => $e->getMessage()], 'Failed to store own LID mapping');
                }
            }
            if (!empty($this->config['markOnlineOnConnect'])) {
                try {
                    $this->sendPresenceUpdate('available');
                } catch (\Throwable) {
                }
            }
            if ($this->config['fireInitQueries'] ?? true) {
                try {
                    $this->executeInitQueries();
                } catch (\Throwable $e) {
                    $this->logger->warn(['err' => $e->getMessage()], 'init queries failed');
                }
            }
            // initial app-state sync (best-effort; retries when the sync key arrives)
            try {
                $this->doInitialAppStateSync();
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage()], 'initial app state sync failed');
            }
        });

        $this->onCB('CB:stream:error', function (BinaryNode $node) {
            $children = GenericUtils::getAllBinaryNodeChildren($node);
            $reason = $children[0]->tag ?? 'unknown';
            $codeMap = ['conflict' => DisconnectReason::connectionReplaced];
            $status = (int)($node->attrs['code'] ?? ($codeMap[$reason] ?? DisconnectReason::badSession));
            if ($status === DisconnectReason::restartRequired) {
                $reason = 'restart required';
            }
            $this->end(new ConnectionException("Stream Errored ($reason)", $status));
        });

        $this->onCB('CB:failure', function (BinaryNode $node) {
            $this->end(new ConnectionException('Connection Failure', (int)($node->attrs['reason'] ?? 500)));
        });

        $this->onCB('CB:xmlstreamend', function () {
            $this->end(new ConnectionException('Connection Terminated by Server', DisconnectReason::connectionClosed));
        });

        $this->onCB('CB:ib,,offline_preview', function () {
            $this->sendNode(new BinaryNode('ib', [], [new BinaryNode('offline_batch', ['count' => '100'])]));
        });

        $this->onCB('CB:ib,,edge_routing', function (BinaryNode $node) {
            $edge = GenericUtils::getBinaryNodeChild($node, 'edge_routing');
            $routing = $edge ? GenericUtils::getBinaryNodeChild($edge, 'routing_info') : null;
            $content = $routing?->contentString();
            if ($content !== null && $content !== '') {
                $this->applyCredsUpdate(['routingInfo' => $content]);
            }
        });

        $this->onCB('CB:ib,,offline', function () {
            $this->ev->emit('connection.update', ['receivedPendingNotifications' => true]);
        });

        // messages / receipts / notifications / presence
        $this->onCB('CB:message', fn(BinaryNode $n) => $this->handleMessageStanza($n));
        $this->onCB('CB:receipt', fn(BinaryNode $n) => $this->handleReceipt($n));
        $this->onCB('CB:notification', fn(BinaryNode $n) => $this->handleNotification($n));
        $this->onCB('CB:presence', fn(BinaryNode $n) => $this->handlePresenceUpdate($n));
        $this->onCB('CB:chatstate', fn(BinaryNode $n) => $this->handlePresenceUpdate($n));
        $this->onCB('CB:call', fn(BinaryNode $n) => $this->handleCall($n));
        $this->onCB('CB:ib,,dirty', fn(BinaryNode $n) => $this->handleDirtyBits($n));
    }

    private function genPairQR(): void
    {
        if (!$this->ws->isOpen) {
            return;
        }
        $ref = array_shift($this->qrRefs);
        if ($ref === null) {
            $this->end(new ConnectionException('QR refs attempts ended', DisconnectReason::timedOut));
            return;
        }
        $qr = Generics::buildPairingQRData(
            $ref,
            base64_encode($this->creds()->noiseKey->public),
            base64_encode($this->creds()->signedIdentityKey->public),
            $this->creds()->advSecretKey,
            $this->config['browser']
        );
        $this->lastQr = $qr;
        if (!empty($this->config['printQRInTerminal'])) {
            echo \BaileysPHP\Utils\QrCode::encodeText($qr)->toTerminalString() . "\n";
        }
        $this->ev->emit('connection.update', ['qr' => $qr]);
        $this->schedule('qr', $this->qrMs / 1000, fn() => $this->genPairQR());
        // upstream: first QR lives (qrTimeout||60s), subsequent ones (qrTimeout||20s)
        $this->qrMs = (int)($this->config['qrTimeout'] ?? 20000) ?: 20000;
    }

    /** Init queries on connection open (chats.ts executeInitQueries). */
    private function executeInitQueries(): void
    {
        try {
            $this->fetchProps();
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'fetchProps failed');
        }
        try {
            $blocklist = $this->fetchBlocklist();
            $this->ev->emit('blocklist.set', ['blocklist' => $blocklist]);
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'fetchBlocklist failed');
        }
        try {
            $this->fetchPrivacySettings();
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'fetchPrivacySettings failed');
        }
    }

    /** One-time-per-connection full app-state sync. */
    private bool $appStateInitialSyncDone = false;

    private function doInitialAppStateSync(): void
    {
        if ($this->appStateInitialSyncDone) {
            return;
        }
        $this->blockedAppStateCollections = [];
        $this->resyncAppState(\BaileysPHP\Utils\AppState::ALL_PATCH_NAMES, true);
        $this->appStateInitialSyncDone = true;
        $counter = ($this->creds()->accountSyncCounter ?? 0) + 1;
        $this->creds()->accountSyncCounter = $counter;
        $this->ev->emit('creds.update', ['accountSyncCounter' => $counter]);
        $this->authState->saveCreds();
    }

    private function buildAckStanza(BinaryNode $node, ?int $errorCode = null): BinaryNode
    {
        $attrs = ['id' => $node->attrs['id'] ?? '', 'to' => $node->attrs['from'] ?? '', 'class' => $node->tag];
        if ($errorCode) {
            $attrs['error'] = (string)$errorCode;
        }
        if (isset($node->attrs['participant'])) $attrs['participant'] = $node->attrs['participant'];
        if (isset($node->attrs['recipient'])) $attrs['recipient'] = $node->attrs['recipient'];
        if (isset($node->attrs['type'])) $attrs['type'] = $node->attrs['type'];
        if ($node->tag === 'message' && !empty($this->creds()->me['id'])) {
            $attrs['from'] = $this->creds()->me['id'];
        }
        return new BinaryNode('ack', $attrs);
    }

    // ------------------------------------------------------------------ receive
    private function handleMessageStanza(BinaryNode $stanza): void
    {
        $messages = [];
        $decryptFailed = false;
        $fromMeFailGuard = false;
        try {
            $from = $stanza->attrs['from'] ?? '';
            $participant = $stanza->attrs['participant'] ?? null;
            $recipient = $stanza->attrs['recipient'] ?? null;
            $msgId = $stanza->attrs['id'] ?? '';
            $meId = $this->creds()->me['id'] ?? '';
            $meLid = $this->creds()->me['lid'] ?? '';
            // self-check must cover BOTH our phone-number JID and our LID,
            // otherwise own messages arriving LID-addressed (incl. history-sync
            // and app-state key-share from the phone) look like they are not ours
            $isMe = fn(?string $j) => $j !== null
                && (Jid::areSameUser($j, $meId) || ($meLid !== '' && Jid::areSameUser($j, $meLid)));

            $fromMe = false;
            $chatId = $from;
            $author = $from;
            if (Jid::isPnUser($from) || Jid::isLidUser($from) || Jid::isHostedPnUser($from) || Jid::isHostedLidUser($from)) {
                if ($recipient) {
                    $fromMe = $isMe($from);
                    $chatId = $recipient;
                } else {
                    $fromMe = $isMe($from);
                    $chatId = $from;
                }
                $author = $from;
            } elseif (Jid::isGroup($from) || Jid::isBroadcast($from)) {
                $fromMe = $isMe($participant);
                $author = $participant ?? $from;
                $chatId = $from;
            }

            // LID/PN alternates from the envelope (helps later replies)
            $senderAlt = $stanza->attrs['sender_lid'] ?? $stanza->attrs['participant_lid'] ?? $stanza->attrs['sender_pn'] ?? $stanza->attrs['participant_pn'] ?? null;
            if ($senderAlt && Jid::isPnUser($author) && Jid::isLidUser($senderAlt)) {
                $this->signalRepository->lidMapping->storeLIDPNMappings([['lid' => $senderAlt, 'pn' => $author]]);
                $this->signalRepository->migrateSession($author, $senderAlt);
            } elseif ($senderAlt && Jid::isLidUser($author) && Jid::isPnUser($senderAlt)) {
                $this->signalRepository->lidMapping->storeLIDPNMappings([['lid' => $author, 'pn' => $senderAlt]]);
            }

            $fromMeFailGuard = !empty($fromMe);
            $encs = GenericUtils::getBinaryNodeChildren($stanza, 'enc');
            $plaintextNode = GenericUtils::getBinaryNodeChild($stanza, 'plaintext');
            if ($plaintextNode !== null) {
                $raw = $plaintextNode->contentString() ?? '';
                $encs = []; // newsletter plaintext handled below as a pseudo-enc
                $decoded = WAProto::decodeMessage($raw);
                $messages[] = $this->buildWAMessage($stanza, $chatId, $author, $fromMe, $msgId, $decoded);
            }
            // Decrypt 1:1 encs first (they may carry SKDM), then the group skmsg
            usort($encs, fn($x, $y) => (($x->attrs['type'] ?? 'msg') === 'skmsg' ? 1 : 0) <=> (($y->attrs['type'] ?? 'msg') === 'skmsg' ? 1 : 0));
            foreach ($encs as $enc) {
                $type = $enc->attrs['type'] ?? 'msg';
                $ciphertext = $enc->contentString() ?? '';
                try {
                    if ($type === 'skmsg') {
                        // sender = chatId for group/status (decode-wa-message semantics)
                        $plain = $this->signalRepository->decryptGroupMessage([
                            'group' => $chatId,
                            'authorJid' => $author,
                            'msg' => $ciphertext,
                        ]);
                    } else {
                        $plain = $this->signalRepository->decryptMessage(['jid' => $this->getDecryptionJid($author), 'type' => $type, 'ciphertext' => $ciphertext]);
                    }
                    $unpadded = Generics::unpadRandomMax16($plain);
                    $decoded = WAProto::decodeMessage($unpadded);
                    $decoded = $decoded['deviceSentMessage']['message'] ?? $decoded;
                    if (isset($decoded['senderKeyDistributionMessage']) && is_array($decoded['senderKeyDistributionMessage'])) {
                        try {
                            $this->signalRepository->processSenderKeyDistributionMessage([
                                'item' => $decoded['senderKeyDistributionMessage'],
                                'authorJid' => $author,
                            ]);
                        } catch (\Throwable $e) {
                            $this->logger->error(['err' => $e->getMessage()], 'failed to process sender key distribution message');
                        }
                        unset($decoded['senderKeyDistributionMessage'], $decoded['_rawFields']);
                        if ($decoded === [] && $type !== 'skmsg') {
                            continue; // pure SKDM fan-out node, not a user message
                        }
                    }
                    $messages[] = $this->buildWAMessage(
                        $stanza, $chatId, $author, $fromMe, $msgId, $decoded,
                        isset($enc->attrs['count']) ? (int)$enc->attrs['count'] : null
                    );
                } catch (\Throwable $e) {
                    $decryptFailed = true;
                    $this->logger->error(['err' => $e->getMessage(), 'id' => $msgId], 'failed to decrypt message');
                    $stubMsg = $this->buildWAMessage(
                        $stanza, $chatId, $author, $fromMe, $msgId, [],
                        isset($enc->attrs['count']) ? (int)$enc->attrs['count'] : null
                    );
                    unset($stubMsg['message']);
                    $stubMsg['messageStubType'] = 'CIPHERTEXT';
                    $stubMsg['messageStubParameters'] = [$e->getMessage()];
                    $messages[] = $stubMsg;
                }
            }
            // nothing decryptable in the stanza at all (decode-wa-message)
            if ($messages === [] && $encs === [] && $plaintextNode === null) {
                $stubMsg = $this->buildWAMessage($stanza, $chatId, $author, $fromMe, $msgId, []);
                unset($stubMsg['message']);
                if (empty($stubMsg['key']['isViewOnce'])) {
                    $stubMsg['messageStubType'] = 'CIPHERTEXT';
                    $stubMsg['messageStubParameters'] = ['Message absent from node'];
                }
                $messages[] = $stubMsg;
            }
        } catch (\Throwable $e) {
            $decryptFailed = true;
            $this->logger->error(['err' => $e->getMessage()], 'error handling message stanza');
        }
        try {
            $this->sendNode($this->buildAckStanza($stanza, $decryptFailed ? 500 : null));
        } catch (\Throwable) {
        }
        // store any trusted-contact token attached to the stanza
        $tcNode = GenericUtils::getBinaryNodeChild($stanza, 'tctoken');
        if ($tcNode !== null) {
            $senderForToken = $stanza->attrs['participant'] ?? $stanza->attrs['from'] ?? '';
            $tokenBytes = $tcNode->contentString();
            if ($tokenBytes !== null && $tokenBytes !== '' && !$isMe($senderForToken)) {
                $storageJid = $this->resolveTcTokenJid(Jid::normalizedUser($senderForToken));
                $existing = $this->getTcTokenEntry($storageJid) ?? [];
                $existing['token'] = $tokenBytes;
                $existing['timestamp'] = $tcNode->attrs['t'] ?? (string)time();
                $this->authState->keys->set(['tctoken' => [$storageJid => $existing]]);
                $this->ensurePrivacyTokenIssued($senderForToken);
            }
        }
        if ($decryptFailed && !$fromMeFailGuard) {
            try {
                $this->sendRetryRequest($stanza, GenericUtils::getBinaryNodeChild($stanza, 'enc') === null);
                $retryDelayMs = (int)($this->config['retryRequestDelayMs'] ?? 250);
                if ($retryDelayMs > 0) {
                    usleep($retryDelayMs * 1000); // throttle consecutive retry receipts
                }
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage()], 'failed to send retry request');
            }
        }
        if ($messages !== []) {
            $cleaned = [];
            foreach ($messages as $m) {
                $cleaned[] = isset($m['message']) ? $this->cleanMessage($m) : $m;
            }
            $type = isset($stanza->attrs['offline']) ? 'append' : 'notify';
            $this->ev->emit('messages.upsert', ['messages' => $cleaned, 'type' => $type, 'requestId' => null]);
            foreach ($cleaned as $m) {
                try {
                    $this->processMessage($m);
                } catch (\Throwable $e) {
                    $this->logger->warn(['err' => $e->getMessage()], 'processMessage failed');
                }
            }
        }
    }

    /** presence/chatstate → presence.update (chats.ts handlePresenceUpdate). */
    private function handlePresenceUpdate(BinaryNode $node): void
    {
        $jid = $node->attrs['from'] ?? '';
        if ($jid === '') {
            return;
        }
        if (!empty($this->config['shouldIgnoreJid']) && is_callable($this->config['shouldIgnoreJid'])
            && $this->config['shouldIgnoreJid']($jid) && $jid !== 's.whatsapp.net') {
            return;
        }
        $participant = $node->attrs['participant'] ?? $jid;
        $presence = null;
        if ($node->tag === 'presence') {
            $presence = [
                'lastKnownPresence' => ($node->attrs['type'] ?? '') === 'unavailable' ? 'unavailable' : 'available',
                'lastSeen' => (!empty($node->attrs['last']) && $node->attrs['last'] !== 'deny') ? (int)$node->attrs['last'] : null,
                'groupOnlineCount' => isset($node->attrs['count']) ? (int)$node->attrs['count'] : null,
            ];
        } elseif (is_array($node->content) && $node->content !== []) {
            $first = $node->content[0];
            $type = $first->tag;
            if ($type === 'paused') {
                $type = 'available';
            }
            if (($first->attrs['media'] ?? '') === 'audio') {
                $type = 'recording';
            }
            $presence = ['lastKnownPresence' => $type];
        }
        if ($presence !== null) {
            $this->ev->emit('presence.update', ['id' => $jid, 'presences' => [$participant => $presence]]);
        }
    }

    /** call stanza → 'call' event (messages-recv handleCall). */
    private function handleCall(BinaryNode $node): void
    {
        try {
            $children = GenericUtils::getAllBinaryNodeChildren($node);
            $infoChild = $children[0] ?? null;
            if ($infoChild === null) {
                return;
            }
            $status = $infoChild->tag;
            $call = [
                'chatId' => $node->attrs['from'] ?? null,
                'from' => $infoChild->attrs['from'] ?? $infoChild->attrs['call-creator'] ?? null,
                'callerPn' => $infoChild->attrs['caller_pn'] ?? null,
                'id' => $infoChild->attrs['call-id'] ?? null,
                'date' => (int)($node->attrs['t'] ?? time()),
                'offline' => isset($node->attrs['offline']),
                'status' => $status,
            ];
            if ($status === 'offer') {
                $call['isVideo'] = GenericUtils::getBinaryNodeChild($infoChild, 'video') !== null;
                $call['isGroup'] = ($infoChild->attrs['type'] ?? '') === 'group' || isset($infoChild->attrs['group-jid']);
                $call['groupJid'] = $infoChild->attrs['group-jid'] ?? null;
                $this->callOffersCache[$call['id']] = $call;
            } elseif (isset($this->callOffersCache[$call['id']])) {
                $existing = $this->callOffersCache[$call['id']];
                $call['isVideo'] = $existing['isVideo'] ?? null;
                $call['isGroup'] = $existing['isGroup'] ?? null;
                $call['callerPn'] = $call['callerPn'] ?: ($existing['callerPn'] ?? null);
                if (in_array($status, ['reject', 'accept', 'timeout', 'terminate'], true)) {
                    unset($this->callOffersCache[$call['id']]);
                }
            }
            $this->ev->emit('call', [$call]);
        } catch (\Throwable $e) {
            $this->logger->error(['err' => $e->getMessage()], 'error in handling call');
        }
        try {
            $this->sendNode($this->buildAckStanza($node));
        } catch (\Throwable) {
        }
    }

    /** CB:ib,,dirty handling (account_sync + groups). */
    private function handleDirtyBits(BinaryNode $node): void
    {
        $dirty = GenericUtils::getBinaryNodeChild($node, 'dirty');
        if ($dirty === null) {
            return;
        }
        $type = $dirty->attrs['type'] ?? '';
        try {
            if ($type === 'account_sync') {
                if (!empty($dirty->attrs['timestamp'])) {
                    $last = $this->creds()->lastAccountSyncTimestamp;
                    if ($last) {
                        $this->cleanDirtyBits('account_sync', $last);
                    }
                    $this->creds()->lastAccountSyncTimestamp = (int)$dirty->attrs['timestamp'];
                    $this->ev->emit('creds.update', ['lastAccountSyncTimestamp' => (int)$dirty->attrs['timestamp']]);
                    $this->authState->saveCreds();
                }
            } elseif ($type === 'groups') {
                $this->groupFetchAllParticipating();
                $this->cleanDirtyBits('groups');
            } elseif ($type === 'communities') {
                $this->communityFetchAllParticipating();
                $this->cleanDirtyBits('groups');
            }
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'dirty bits handling failed');
        }
    }

    /** @param array<string,mixed> $decoded */
    /** extractAddressingContext — decode-wa-message.ts */
    private function extractAddressingContext(BinaryNode $stanza): array
    {
        $sender = $stanza->attrs['participant'] ?? $stanza->attrs['from'] ?? '';
        $addressingMode = $stanza->attrs['addressing_mode'] ?? (str_ends_with($sender, 'lid') ? 'lid' : 'pn');
        if ($addressingMode === 'lid') {
            $senderAlt = $stanza->attrs['participant_pn'] ?? $stanza->attrs['sender_pn'] ?? $stanza->attrs['peer_recipient_pn'] ?? null;
            $recipientAlt = $stanza->attrs['recipient_pn'] ?? null;
        } else {
            $senderAlt = $stanza->attrs['participant_lid'] ?? $stanza->attrs['sender_lid'] ?? $stanza->attrs['peer_recipient_lid'] ?? null;
            $recipientAlt = $stanza->attrs['recipient_lid'] ?? null;
        }
        return ['addressingMode' => $addressingMode, 'senderAlt' => $senderAlt, 'recipientAlt' => $recipientAlt];
    }

    private function buildWAMessage(BinaryNode $stanza, string $chatId, string $author, bool $fromMe, string $msgId, array $decoded, ?int $retryCount = null): array
    {
        $from = $stanza->attrs['from'] ?? '';
        $isGroupChat = Jid::isGroup($chatId);
        $groupish = $isGroupChat || Jid::isBroadcast($from);
        $ctx = $this->extractAddressingContext($stanza);
        $key = [
            'remoteJid' => $chatId,
            'remoteJidAlt' => !$isGroupChat ? $ctx['senderAlt'] : null,
            'remoteJidUsername' => !$isGroupChat ? ($stanza->attrs['peer_recipient_username'] ?? $stanza->attrs['recipient_username'] ?? null) : null,
            'fromMe' => $fromMe,
            'id' => $msgId,
            'participant' => $groupish ? $author : ($stanza->attrs['participant'] ?? null),
            'participantAlt' => $isGroupChat ? $ctx['senderAlt'] : null,
            'participantUsername' => $stanza->attrs['participant_username'] ?? null,
            'addressingMode' => $ctx['addressingMode'],
        ];
        if (Jid::isNewsletter($from) && isset($stanza->attrs['server_id'])) {
            $key['server_id'] = $stanza->attrs['server_id'];
        }
        $unavailable = GenericUtils::getBinaryNodeChild($stanza, 'unavailable');
        if ($unavailable !== null && ($unavailable->attrs['type'] ?? '') === 'view_once') {
            $key['isViewOnce'] = true;
        }
        $msg = [
            'key' => $key,
            'message' => $decoded,
            'messageTimestamp' => (int)($stanza->attrs['t'] ?? time()),
            'broadcast' => Jid::isBroadcast($from),
        ];
        if (isset($stanza->attrs['category'])) {
            $msg['category'] = $stanza->attrs['category'];
        }
        if ($fromMe) {
            $msg['status'] = 2; // WebMessageInfo.Status.SERVER_ACK
        }
        if ($retryCount !== null) {
            $msg['retryCount'] = $retryCount;
        }
        if (!$fromMe && !empty($stanza->attrs['notify'])) {
            $msg['pushName'] = $stanza->attrs['notify'];
        }
        // verified business name certificate
        $vname = GenericUtils::getBinaryNodeChild($stanza, 'verified_name');
        $certBytes = $vname?->contentString();
        if ($certBytes !== null && $certBytes !== '') {
            try {
                $cert = WAProto::decodeProto('VerifiedNameCertificate', $certBytes);
                $detailsBytes = $cert['details'] ?? null;
                if (is_string($detailsBytes)) {
                    $details = WAProto::decodeProto('VerifiedNameCertificate.Details', $detailsBytes);
                    if (!empty($details['verifiedName'])) {
                        $msg['verifiedBizName'] = $details['verifiedName'];
                    }
                }
            } catch (\Throwable) {
            }
        }
        return $msg;
    }

    private function handleReceipt(BinaryNode $node): void
    {
        $type = $node->attrs['type'] ?? null;
        if (($type ?? '') === 'retry') {
            try {
                $this->sendNode($this->buildAckStanza($node));
            } catch (\Throwable) {
            }
            $this->handleRetryReceipt($node);
            return;
        }
        $status = match ($type) {
            null, '' => 3,          // DELIVERY_ACK
            'read', 'read-self' => 4,
            'played' => 5,
            'sender' => 2,          // SERVER_ACK
            default => 3,
        };
        $ids = [];
        if (!empty($node->attrs['id'])) {
            $ids[] = $node->attrs['id'];
        }
        $list = GenericUtils::getBinaryNodeChild($node, 'list');
        if ($list !== null && is_array($list->content)) {
            foreach ($list->content as $item) {
                if (!empty($item->attrs['id'])) {
                    $ids[] = $item->attrs['id'];
                }
            }
        }
        $remoteJid = $node->attrs['from'] ?? '';
        $participant = $node->attrs['participant'] ?? $remoteJid;
        $updates = [];
        foreach ($ids as $id) {
            $updates[] = [
                'key' => ['remoteJid' => $remoteJid, 'fromMe' => true, 'id' => $id, 'participant' => Jid::isGroup($remoteJid) ? $participant : null],
                'update' => ['status' => $status],
            ];
        }
        if ($updates !== []) {
            $this->ev->emit('messages.update', $updates);
            // message-receipt.update (per-user receipts, mainly for groups)
            $receiptType = match ($status) { 4 => 'read', 5 => 'played', 3 => 'delivered', default => 'delivered' };
            $receiptUpdates = [];
            foreach ($ids as $id) {
                $receiptUpdates[] = [
                    'key' => ['remoteJid' => $remoteJid, 'fromMe' => true, 'id' => $id, 'participant' => Jid::isGroup($remoteJid) ? $this->creds()->me['id'] ?? null : null],
                    'receipt' => ['userJid' => Jid::normalizedUser($participant), $receiptType . 'Timestamp' => (int)($node->attrs['t'] ?? time())],
                ];
            }
            $this->ev->emit('message-receipt.update', $receiptUpdates);
        }
        try {
            $this->sendNode($this->buildAckStanza($node));
        } catch (\Throwable) {
        }
    }

    private function handleNotification(BinaryNode $node): void
    {
        $type = $node->attrs['type'] ?? '';
        try {
            $this->sendNode($this->buildAckStanza($node));
        } catch (\Throwable) {
        }
        try {
            switch ($type) {
                case 'encrypt':
                    $this->uploadPreKeysToServerIfRequired();
                    break;
                case 'w:gp2':
                    $this->handleGroupNotification($node);
                    break;
                case 'devices':
                    $this->handleDevicesNotification($node);
                    break;
                case 'picture':
                    $this->ev->emit('contacts.update', [[
                        'id' => Jid::normalizedUser($node->attrs['from'] ?? ''),
                        'imgUrl' => GenericUtils::getBinaryNodeChild($node, 'delete') !== null ? 'removed' : 'changed',
                    ]]);
                    break;
                case 'mediaretry':
                    $this->ev->emit('messages.media-update', [$this->decodeMediaRetryNode($node)]);
                    break;
                case 'server_sync':
                    $collection = GenericUtils::getBinaryNodeChild($node, 'collection');
                    if ($collection !== null && !empty($collection->attrs['name'])) {
                        $this->resyncAppState([$collection->attrs['name']], false);
                    }
                    break;
                case 'newsletter':
                case 'mex':
                    $this->handleMexNotification($node);
                    break;
                case 'privacy_token':
                    $this->handlePrivacyTokenNotification($node);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage(), 'type' => $type], 'notification handling failed');
        }
        $this->ev->emit('notifications', ['node' => $node, 'type' => $type]);
    }

    /** Devices notifications: refresh/delete sessions for changed device lists. */
    private function handleDevicesNotification(BinaryNode $node): void
    {
        $children = GenericUtils::getAllBinaryNodeChildren($node);
        $child = $children[0] ?? null;
        if ($child === null) {
            return;
        }
        $tag = $child->tag; // add|remove|update
        $devices = GenericUtils::getBinaryNodeChildren($child, 'device');
        $usersSeen = [];
        $removeJids = [];
        foreach ($devices as $d) {
            $jid = $d->attrs['jid'] ?? null;
            if (!$jid) {
                continue;
            }
            $usersSeen[Jid::decode($jid)['user'] ?? ''] = true;
            if ($tag === 'remove') {
                $removeJids[] = $jid;
            }
        }
        if ($removeJids !== []) {
            $this->signalRepository->deleteSession($removeJids);
        }
        foreach (array_keys($usersSeen) as $user) {
            unset($this->devicesCache[$user]);
        }
    }

    /** Group notifications (w:gp2) → stub message upsert + groups events. */
    private function handleGroupNotification(BinaryNode $node): void
    {
        $children = GenericUtils::getAllBinaryNodeChildren($node);
        $child = $children[0] ?? null;
        if ($child === null) {
            return;
        }
        $groupJid = $node->attrs['from'] ?? '';
        $acting = $node->attrs['participant'] ?? null;
        $actingPn = $node->attrs['participant_pn'] ?? null;
        $msg = [
            'key' => [
                'remoteJid' => $groupJid,
                'fromMe' => false,
                'id' => $node->attrs['id'] ?? '',
                'participant' => $acting,
                'participantAlt' => $actingPn,
            ],
            'messageTimestamp' => (int)($node->attrs['t'] ?? time()),
        ];
        $stub = fn(string $name): int => WAProto::enumValue('WebMessageInfo.StubType', $name);
        $participantOf = function (BinaryNode $n): array {
            $a = $n->attrs;
            return [
                'id' => $a['jid'] ?? '',
                'phoneNumber' => (Jid::isLidUser($a['jid'] ?? '') && Jid::isPnUser($a['phone_number'] ?? '')) ? $a['phone_number'] : null,
                'lid' => (Jid::isPnUser($a['jid'] ?? '') && Jid::isLidUser($a['lid'] ?? '')) ? $a['lid'] : null,
                'username' => $a['participant_username'] ?? $a['username'] ?? null,
                'admin' => $a['type'] ?? null,
            ];
        };

        unset($this->cachedGroupMetadataMap[$groupJid]);

        switch ($child->tag) {
            case 'create':
                // child here is the <group> node wrapped by the notification content
                try {
                    $metadata = $this->extractGroupMetadata(new BinaryNode('result', [], [GenericUtils::getBinaryNodeChild($node, 'group') ?? $child]));
                    $msg['messageStubType'] = $stub('GROUP_CREATE');
                    $msg['messageStubParameters'] = [$metadata['subject']];
                    $msg['key']['participant'] = $metadata['owner'];
                    $this->cachedGroupMetadataMap[$metadata['id']] = $metadata;
                    $this->ev->emit('chats.upsert', [[
                        'id' => $metadata['id'], 'name' => $metadata['subject'],
                        'conversationTimestamp' => $metadata['creation'],
                    ]]);
                    $this->ev->emit('groups.upsert', [array_merge($metadata, [
                        'author' => $acting, 'authorPn' => $actingPn,
                    ])]);
                } catch (\Throwable $e) {
                    $this->logger->debug(['err' => $e->getMessage()], 'group create metadata parse failed');
                }
                break;
            case 'ephemeral':
            case 'not_ephemeral':
                $msg['message'] = ['protocolMessage' => [
                    'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'EPHEMERAL_SETTING'),
                    'ephemeralExpiration' => (int)($child->attrs['expiration'] ?? 0),
                ]];
                break;
            case 'modify':
                $participants = array_map($participantOf, GenericUtils::getBinaryNodeChildren($child, 'participant'));
                $msg['messageStubType'] = $stub('GROUP_PARTICIPANT_CHANGE_NUMBER');
                $msg['messageStubParameters'] = array_map(fn($x) => json_encode($x), $participants);
                $this->ev->emit('group-participants.update', [
                    'id' => $groupJid, 'author' => $acting, 'authorPn' => $actingPn,
                    'participants' => $participants, 'action' => 'modify',
                ]);
                break;
            case 'promote':
            case 'demote':
            case 'remove':
            case 'add':
            case 'leave':
                $participants = array_map($participantOf, GenericUtils::getBinaryNodeChildren($child, 'participant'));
                $action = $child->tag === 'leave' ? 'remove' : $child->tag;
                $msg['messageStubType'] = $stub('GROUP_PARTICIPANT_' . strtoupper($child->tag));
                if ($child->tag === 'remove' && count($participants) === 1
                    && ($participants[0]['id'] === $acting || $participants[0]['id'] === $actingPn)) {
                    $msg['messageStubType'] = $stub('GROUP_PARTICIPANT_LEAVE');
                }
                $msg['messageStubParameters'] = array_map(fn($x) => json_encode($x), $participants);
                $this->ev->emit('group-participants.update', [
                    'id' => $groupJid, 'author' => $acting, 'authorPn' => $actingPn,
                    'participants' => $participants, 'action' => $action,
                ]);
                break;
            case 'subject':
                $msg['messageStubType'] = $stub('GROUP_CHANGE_SUBJECT');
                $msg['messageStubParameters'] = [$child->attrs['subject'] ?? ''];
                $this->ev->emit('groups.update', [[
                    'id' => $groupJid, 'subject' => $child->attrs['subject'] ?? null,
                    'author' => $acting, 'authorPn' => $actingPn,
                ]]);
                break;
            case 'description':
                $body = GenericUtils::getBinaryNodeChild($child, 'body')?->contentString();
                $msg['messageStubType'] = $stub('GROUP_CHANGE_DESCRIPTION');
                $msg['messageStubParameters'] = $body ? [$body] : null;
                $this->ev->emit('groups.update', [[
                    'id' => $groupJid, 'desc' => $body, 'author' => $acting, 'authorPn' => $actingPn,
                ]]);
                break;
            case 'announcement':
            case 'not_announcement':
                $msg['messageStubType'] = $stub('GROUP_CHANGE_ANNOUNCE');
                $msg['messageStubParameters'] = [$child->tag === 'announcement' ? 'on' : 'off'];
                $this->ev->emit('groups.update', [[
                    'id' => $groupJid, 'announce' => $child->tag === 'announcement',
                    'author' => $acting, 'authorPn' => $actingPn,
                ]]);
                break;
            case 'locked':
            case 'unlocked':
                $msg['messageStubType'] = $stub('GROUP_CHANGE_RESTRICT');
                $msg['messageStubParameters'] = [$child->tag === 'locked' ? 'on' : 'off'];
                $this->ev->emit('groups.update', [[
                    'id' => $groupJid, 'restrict' => $child->tag === 'locked',
                    'author' => $acting, 'authorPn' => $actingPn,
                ]]);
                break;
            case 'invite':
                $msg['messageStubType'] = $stub('GROUP_CHANGE_INVITE_LINK');
                $msg['messageStubParameters'] = [$child->attrs['code'] ?? ''];
                break;
            case 'member_add_mode':
                $mode = $child->contentString();
                if ($mode) {
                    $msg['messageStubType'] = $stub('GROUP_MEMBER_ADD_MODE');
                    $msg['messageStubParameters'] = [$mode];
                }
                break;
            case 'membership_approval_mode':
                $approval = GenericUtils::getBinaryNodeChild($child, 'group_join');
                if ($approval !== null) {
                    $msg['messageStubType'] = $stub('GROUP_MEMBERSHIP_JOIN_APPROVAL_MODE');
                    $msg['messageStubParameters'] = [$approval->attrs['state'] ?? ''];
                }
                break;
            case 'created_membership_requests':
            case 'revoked_membership_requests':
                $affected = GenericUtils::getBinaryNodeChild($child, 'participant');
                $msg['messageStubType'] = $stub('GROUP_MEMBERSHIP_JOIN_APPROVAL_REQUEST_NON_ADMIN_ADD');
                $msg['messageStubParameters'] = [
                    json_encode([
                        'lid' => $affected?->attrs['jid'] ?? $acting,
                        'pn' => $affected?->attrs['phone_number'] ?? $actingPn,
                    ]),
                    $child->tag === 'created_membership_requests' ? 'created' : 'revoked',
                ];
                $this->ev->emit('group.join-request', [
                    'id' => $groupJid, 'author' => $acting, 'authorPn' => $actingPn,
                    'participant' => $affected?->attrs['jid'] ?? $acting,
                    'action' => $child->tag === 'created_membership_requests' ? 'created' : 'revoked',
                    'method' => $child->attrs['request_method'] ?? null,
                ]);
                break;
        }
        if (isset($msg['messageStubType']) || isset($msg['message'])) {
            $this->ev->emit('messages.upsert', ['messages' => [$msg], 'type' => 'notify']);
        }
    }

    // ------------------------------------------------------------------ USync / devices / sessions
    public function executeUSyncQuery(USyncQuery $query): ?array
    {
        if ($query->protocols === []) {
            throw new \RuntimeException('USyncQuery must have at least one protocol');
        }
        $result = $this->query($query->buildNode($this->generateMessageTag()));
        return $query->parseResult($result);
    }

    /** @param list<string> $jids @return list<array{lid:string,pn:string}> */
    /** Convenience delegates to signalRepository->lidMapping (sock.signalRepository.lidMapping in Baileys) */
    public function getLIDForPN(string $pn): ?string
    {
        return $this->signalRepository->lidMapping->getLIDForPN($pn);
    }

    public function getPNForLID(string $lid): ?string
    {
        return $this->signalRepository->lidMapping->getPNForLID($lid);
    }

    public function pnFromLIDUSync(array $jids): array
    {
        if (!$this->ws->isOpen || !$this->noise->isTransport()) {
            return [];
        }
        $query = (new USyncQuery())->withLIDProtocol()->withContext('background');
        $added = 0;
        foreach ($jids as $jid) {
            if (Jid::isLidUser($jid)) {
                continue;
            }
            $query->withUser(['id' => $jid]);
            $added++;
        }
        if ($added === 0) {
            return [];
        }
        $results = $this->executeUSyncQuery($query);
        $out = [];
        foreach ($results['list'] ?? [] as $item) {
            if (!empty($item['lid'])) {
                $out[] = ['lid' => $item['lid'], 'pn' => $item['id']];
            }
        }
        return $out;
    }

    /** onWhatsApp(...numbers) → [['jid'=>, 'exists'=>bool], ...] */
    public function onWhatsApp(string ...$phoneNumbers): array
    {
        $query = (new USyncQuery())->withContactProtocol();
        foreach ($phoneNumbers as $jid) {
            $phone = '+' . preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
            $query->withUser(['phone' => $phone]);
        }
        $results = $this->executeUSyncQuery($query);
        $out = [];
        foreach ($results['list'] ?? [] as $item) {
            if (array_key_exists('contact', $item)) {
                $out[] = ['jid' => $item['id'], 'exists' => (bool)$item['contact']];
            }
        }
        return $out;
    }

    /**
     * Extract device jids from USync results (Utils/signal.ts extractDeviceJids).
     * @param list<array<string,mixed>> $resultList
     * @return list<array{user:string,device:int,domainType:int,server:string,jid:string}>
     */
    private function extractDeviceJids(array $resultList, bool $excludeZeroDevices): array
    {
        $meId = $this->creds()->me['id'] ?? '';
        $meLid = $this->creds()->me['lid'] ?? '';
        $my = Jid::decode($meId);
        $myUser = $my['user'] ?? '';
        $myDevice = $my['device'] ?? 0;
        $myLidUser = $meLid ? (Jid::decode($meLid)['user'] ?? '') : '';
        $extracted = [];
        foreach ($resultList as $userResult) {
            $decoded = Jid::decode($userResult['id']);
            if (!$decoded) continue;
            $user = $decoded['user'];
            $server = $decoded['server'];
            $domainType = $decoded['domainType'];
            $deviceList = $userResult['devices']['deviceList'] ?? null;
            if (!is_array($deviceList)) continue;
            foreach ($deviceList as $dev) {
                $device = (int)$dev['id'];
                if (
                    (!$excludeZeroDevices || $device !== 0)
                    && (($myUser !== $user && $myLidUser !== $user) || $myDevice !== $device)
                    && ($device === 0 || !empty($dev['keyIndex']) || isset($dev['keyIndex']))
                ) {
                    $dt = $domainType;
                    if (!empty($dev['isHosted'])) {
                        $dt = $dt === Jid::DOMAIN_LID ? Jid::DOMAIN_HOSTED_LID : Jid::DOMAIN_HOSTED;
                    }
                    $srv = Jid::serverFromDomainType($server, $dt);
                    $extracted[] = [
                        'user' => $user, 'device' => $device, 'domainType' => $dt, 'server' => $srv,
                        'jid' => Jid::encode($user, $srv, $device),
                    ];
                }
            }
        }
        return $extracted;
    }

    /**
     * getUSyncDevices (simplified: in-memory cache only)
     * @param list<string> $jids
     * @return list<array{user:string,device:int,domainType:int,server:string,jid:string}>
     */
    public function getUSyncDevices(array $jids, bool $useCache = true, bool $ignoreZeroDevices = false): array
    {
        $results = [];
        $toFetch = [];
        foreach ($jids as $jid) {
            $decoded = Jid::decode($jid);
            $user = $decoded['user'] ?? '';
            if ($decoded !== null && $decoded['device'] !== null && $user !== '') {
                $results[] = [
                    'user' => $user, 'device' => (int)$decoded['device'], 'domainType' => $decoded['domainType'],
                    'server' => $decoded['server'], 'jid' => $jid,
                ];
                continue;
            }
            $normalized = Jid::normalizedUser($jid);
            if ($useCache && isset($this->devicesCache[$user])) {
                $results = array_merge($results, $this->devicesCache[$user]);
                continue;
            }
            $toFetch[] = $normalized;
        }
        if ($toFetch === []) {
            return $results;
        }
        $query = (new USyncQuery())->withContext('message')->withDeviceProtocol()->withLIDProtocol();
        foreach ($toFetch as $jid) {
            $query->withUser(['id' => $jid]);
        }
        $result = $this->executeUSyncQuery($query);
        if ($result !== null) {
            $lidPairs = [];
            foreach ($result['list'] as $item) {
                if (!empty($item['lid'])) {
                    $lidPairs[] = ['lid' => $item['lid'], 'pn' => $item['id']];
                }
            }
            if ($lidPairs !== []) {
                $this->signalRepository->lidMapping->storeLIDPNMappings($lidPairs);
            }
            $extracted = $this->extractDeviceJids($result['list'], $ignoreZeroDevices);
            $byUser = [];
            foreach ($extracted as $d) {
                $byUser[$d['user']][] = $d;
                $results[] = $d;
            }
            foreach ($byUser as $user => $devs) {
                $this->devicesCache[$user] = $devs;
            }
            $deviceUpdates = [];
            foreach ($byUser as $user => $devs) {
                $deviceUpdates[$user] = array_map(fn($d) => (string)$d['device'], $devs);
            }
            if ($deviceUpdates !== []) {
                try {
                    $this->authState->keys->set(['device-list' => $deviceUpdates]);
                } catch (\Throwable) {
                }
            }
        }
        return $results;
    }

    /** parseAndInjectE2ESessions for an encrypt/key query result. */
    private function parseAndInjectE2ESessions(BinaryNode $node): void
    {
        $extractKey = function (?BinaryNode $key, bool $withSignature): ?array {
            if ($key === null) {
                return null;
            }
            $value = GenericUtils::getBinaryNodeChildBuffer($key, 'value');
            if ($value === null) {
                return null;
            }
            $out = [
                'keyId' => GenericUtils::getBinaryNodeChildUInt($key, 'id', 3),
                'publicKey' => generateSignalPubKey($value),
            ];
            if ($withSignature) {
                $out['signature'] = GenericUtils::getBinaryNodeChildBuffer($key, 'signature') ?? '';
            }
            return $out;
        };
        $list = GenericUtils::getBinaryNodeChild($node, 'list');
        $users = GenericUtils::getBinaryNodeChildren($list, 'user');
        foreach ($users as $userNode) {
            GenericUtils::assertNodeErrorFree($userNode);
            $jid = $userNode->attrs['jid'] ?? '';
            $identity = GenericUtils::getBinaryNodeChildBuffer($userNode, 'identity');
            $signedKey = $extractKey(GenericUtils::getBinaryNodeChild($userNode, 'skey'), true);
            $preKey = $extractKey(GenericUtils::getBinaryNodeChild($userNode, 'key'), true);
            if ($jid === '' || $identity === null || $signedKey === null) {
                continue;
            }
            $session = [
                'registrationId' => GenericUtils::getBinaryNodeChildUInt($userNode, 'registration', 4) ?? 0,
                'identityKey' => generateSignalPubKey($identity),
                'signedPreKey' => $signedKey,
            ];
            if ($preKey !== null && ($preKey['keyId'] ?? null) !== null) {
                $session['preKey'] = ['keyId' => $preKey['keyId'], 'publicKey' => $preKey['publicKey']];
            }
            $this->signalRepository->injectE2ESession(['jid' => $jid, 'session' => $session]);
        }
    }

    /** assertSessions(jids, force) */
    public function assertSessions(array $jids, bool $force = false): bool
    {
        $unique = array_values(array_unique($jids));
        $requiring = [];
        foreach ($unique as $jid) {
            if (!$force && $this->signalRepository->validateSession($jid)['exists']) {
                continue;
            }
            $requiring[] = $jid;
        }
        if ($requiring === []) {
            return false;
        }
        // Prefer LID addressing when a mapping is known/fetchable, else keep the PN jid.
        $wireJids = [];
        foreach ($requiring as $jid) {
            if (Jid::isLidUser($jid) || Jid::isHostedLidUser($jid)) {
                $wireJids[] = $jid;
            } elseif (Jid::isPnUser($jid) || Jid::isHostedPnUser($jid)) {
                $lid = $this->signalRepository->lidMapping->getLIDForPN($jid);
                $wireJids[] = $lid ?? $jid;
            } else {
                $wireJids[] = $jid;
            }
        }
        $wireJids = array_values(array_unique($wireJids));
        $this->logger->debug(['jidsRequiringFetch' => $requiring, 'wireJids' => $wireJids], 'fetching sessions');
        $users = [];
        foreach ($wireJids as $jid) {
            $attrs = ['jid' => $jid];
            if ($force) {
                $attrs['reason'] = 'identity';
            }
            $users[] = new BinaryNode('user', $attrs);
        }
        $result = $this->query(new BinaryNode('iq', [
            'xmlns' => 'encrypt', 'type' => 'get', 'to' => 's.whatsapp.net',
        ], [new BinaryNode('key', [], $users)]));
        $this->parseAndInjectE2ESessions($result);
        return true;
    }

    // ------------------------------------------------------------------ send
    /**
     * createParticipantNodes — encrypt a message for each recipient device.
     * @param list<string> $recipientJids
     * @param array<string,mixed> $message
     * @return array{nodes:list<BinaryNode>,shouldIncludeDeviceIdentity:bool}
     */
    private function createParticipantNodes(array $recipientJids, array $message, array $extraAttrs = [], ?array $dsmMessage = null): array
    {
        $nodes = [];
        $shouldInclude = false;
        $meId = $this->creds()->me['id'] ?? '';
        foreach ($recipientJids as $jid) {
            try {
                $msgToEncrypt = $message;
                if ($dsmMessage !== null) {
                    $targetUser = Jid::decode($jid)['user'] ?? '';
                    $ownUser = Jid::decode($meId)['user'] ?? '';
                    if ($targetUser === $ownUser && $jid !== $meId) {
                        $msgToEncrypt = $dsmMessage;
                    }
                }
                $bytes = Generics::encodeWAMessage($msgToEncrypt);
                $enc = $this->signalRepository->encryptMessage(['jid' => $jid, 'data' => $bytes]);
                if ($enc['type'] === 'pkmsg') {
                    $shouldInclude = true;
                }
                $nodes[] = new BinaryNode('to', ['jid' => $jid], [
                    new BinaryNode('enc', array_merge(['v' => '2', 'type' => $enc['type']], $extraAttrs), new Bytes($enc['ciphertext'])),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error(['jid' => $jid, 'err' => $e->getMessage()], 'Failed to encrypt for recipient');
            }
        }
        if ($recipientJids !== [] && $nodes === []) {
            throw new \RuntimeException('All encryptions failed');
        }
        return ['nodes' => $nodes, 'shouldIncludeDeviceIdentity' => $shouldInclude];
    }

    /**
     * relayMessage for 1-to-1 chats (Basic). Returns the message id.
     * @param array<string,mixed> $message proto Message array
     * @param array<string,mixed> $options {messageId?, additionalAttributes?, additionalNodes?}
     */
    public function relayMessage(string $jid, array $message, array $options = []): string
    {
        $meId = $this->creds()->me['id'] ?? null;
        if (!$meId) {
            throw new ConnectionException('Cannot proceed: socket is not authenticated yet', 401);
        }
        $decoded = Jid::decode($jid);
        $server = $decoded['server'] ?? '';
        $meLid = $this->creds()->me['lid'] ?? null;
        $participant = $options['participant'] ?? null; // ['jid'=>..., 'count'=>int] retry resend
        $isRetryResend = !empty($participant['jid']);
        $shouldInclude = $isRetryResend;
        $isGroup = $server === 'g.us';
        $isStatus = $jid === 'status@broadcast';
        $isNewsletter = $server === 'newsletter';
        $isLid = $server === 'lid';
        $msgId = $options['messageId'] ?? Generics::generateMessageIDV2($meId);
        $additionalAttributes = $options['additionalAttributes'] ?? [];
        $destinationJid = $jid;

        $extraAttrs = [];
        $mediaType = self::getMediaType($message);
        if ($mediaType) {
            $extraAttrs['mediatype'] = $mediaType;
        }
        $normalized = \BaileysPHP\Utils\Messages::normalizeMessageContent($message);
        if (isset($normalized['pinInChatMessage']) || isset($normalized['reactionMessage'])) {
            $extraAttrs['decrypt-fail'] = 'hide';
        }

        // ---- newsletters: plaintext
        if ($isNewsletter) {
            $bytes = WAProto::encodeMessage($message);
            $stanza = new BinaryNode('message', array_merge([
                'to' => $jid,
                'id' => $msgId,
                'type' => self::getMessageStanzaType($message),
            ], $additionalAttributes), [new BinaryNode('plaintext', [], new Bytes($bytes))]);
            $this->sendNode($stanza);
            return $msgId;
        }

        $participants = [];
        $content = [];
        $meMsg = [
            'deviceSentMessage' => ['destinationJid' => $destinationJid, 'message' => $message],
        ];
        if (isset($message['messageContextInfo'])) {
            $meMsg['messageContextInfo'] = $message['messageContextInfo'];
        }

        if (($isGroup || $isStatus) && !$isRetryResend) {
            // ---- group / status fan-out with sender keys
            $groupData = null;
            if (!$isStatus) {
                $groupData = ($options['useCachedGroupMetadata'] ?? true) ? $this->cachedGroupMetadata($jid) : null;
                if ($groupData === null || !is_array($groupData['participants'] ?? null)) {
                    $groupData = $this->groupMetadata($jid);
                }
            }
            $participantsList = [];
            foreach (($groupData['participants'] ?? []) as $p) {
                $participantsList[] = $p['id'];
            }
            if (!empty($groupData['ephemeralDuration'])) {
                $additionalAttributes['expiration'] = (string)$groupData['ephemeralDuration'];
            }
            if ($isStatus && !empty($options['statusJidList'])) {
                $participantsList = array_merge($participantsList, $options['statusJidList']);
            }
            if ($isGroup) {
                $additionalAttributes['addressing_mode'] = $groupData['addressingMode'] ?? 'lid';
            }
            $devices = $this->getUSyncDevices(array_values(array_unique($participantsList)), $options['useUserDevicesCache'] ?? true, false);

            $addressingMode = $additionalAttributes['addressing_mode'] ?? $groupData['addressingMode'] ?? 'lid';
            $groupSenderIdentity = ($addressingMode === 'lid' && $meLid) ? $meLid : $meId;

            ['ciphertext' => $ciphertext, 'senderKeyDistributionMessage' => $skdmBytes] =
                $this->signalRepository->encryptGroupMessage([
                    'group' => $destinationJid,
                    'data' => Generics::encodeWAMessage($message),
                    'meId' => $groupSenderIdentity,
                ]);

            $senderKeyMap = [];
            if (!$isStatus) {
                $res = $this->authState->keys->get('sender-key-memory', [$jid]);
                $senderKeyMap = is_array($res[$jid] ?? null) ? $res[$jid] : [];
            }
            $senderKeyRecipients = [];
            foreach ($devices as $device) {
                $deviceJid = $device['jid'];
                if (empty($senderKeyMap[$deviceJid])
                    && !Jid::isHostedLidUser($deviceJid) && !Jid::isHostedPnUser($deviceJid)
                    && ($device['device'] ?? 0) !== 99) {
                    $senderKeyRecipients[] = $deviceJid;
                    $senderKeyMap[$deviceJid] = true;
                }
            }
            if ($senderKeyRecipients !== []) {
                $this->logger->debug(['senderKeyJids' => $senderKeyRecipients], 'sending new sender key');
                $senderKeyMsg = ['senderKeyDistributionMessage' => [
                    'axolotlSenderKeyDistributionMessage' => $skdmBytes,
                    'groupId' => $destinationJid,
                ]];
                $this->assertSessions($senderKeyRecipients);
                $res = $this->createParticipantNodes($senderKeyRecipients, $senderKeyMsg, $extraAttrs);
                $participants = array_merge($participants, $res['nodes']);
                $shouldInclude = $shouldInclude || $res['shouldIncludeDeviceIdentity'];
            }
            $content[] = new BinaryNode('enc', array_merge(['v' => '2', 'type' => 'skmsg'], $extraAttrs), new Bytes($ciphertext));
            $this->authState->keys->set(['sender-key-memory' => [$jid => $senderKeyMap]]);
        } elseif ($isRetryResend) {
            // ---- retry resend to a single participant device
            if (!$isGroup && !$isStatus) {
                $additionalAttributes['device_fanout'] = 'false';
            }
            $messageToSend = $message;
            if ($isGroup || $isStatus) {
                $groupSenderIdentity = null;
                if ($meLid && $this->signalRepository->hasSenderKey(['group' => $destinationJid, 'meId' => $meLid])) {
                    $groupSenderIdentity = $meLid;
                } elseif ($this->signalRepository->hasSenderKey(['group' => $destinationJid, 'meId' => $meId])) {
                    $groupSenderIdentity = $meId;
                }
                if ($groupSenderIdentity) {
                    try {
                        $messageToSend['senderKeyDistributionMessage'] = [
                            'groupId' => $destinationJid,
                            'axolotlSenderKeyDistributionMessage' => $this->signalRepository->getSenderKeyDistributionMessage([
                                'group' => $destinationJid, 'meId' => $groupSenderIdentity,
                            ]),
                        ];
                    } catch (\Throwable $e) {
                        $this->logger->warn(['err' => $e->getMessage()], 'failed to build SKDM for retry, sending without it');
                    }
                }
            }
            $isMeParticipant = Jid::areSameUser($participant['jid'], Jid::isLidUser($participant['jid']) ? $meLid : $meId);
            $encoded = $isMeParticipant
                ? Generics::encodeWAMessage(['deviceSentMessage' => ['destinationJid' => $destinationJid, 'message' => $messageToSend]])
                : Generics::encodeWAMessage($messageToSend);
            $enc = $this->signalRepository->encryptMessage(['jid' => $participant['jid'], 'data' => $encoded]);
            $content[] = new BinaryNode('enc', [
                'v' => '2', 'type' => $enc['type'], 'count' => (string)($participant['count'] ?? 1),
            ], new Bytes($enc['ciphertext']));
        } else {
            // ---- 1:1 fan-out
            $senderIdentity = $isLid && $meLid
                ? Jid::encode(Jid::decode($meLid)['user'], 'lid')
                : Jid::encode(Jid::decode($meId)['user'], 's.whatsapp.net');
            $devices = $this->getUSyncDevices([$senderIdentity, $jid], $options['useUserDevicesCache'] ?? true, false);

            $mePnUser = Jid::decode($meId)['user'] ?? '';
            $meLidUser = $meLid ? (Jid::decode($meLid)['user'] ?? '') : '';
            $meRecipients = [];
            $otherRecipients = [];
            $seen = [];
            foreach ($devices as $d) {
                $dj = $d['jid'];
                if (isset($seen[$dj])) continue;
                $seen[$dj] = true;
                if ($dj === $meId || ($meLid && $dj === $meLid)) {
                    continue;
                }
                if ($d['user'] === $mePnUser || ($meLidUser !== '' && $d['user'] === $meLidUser)) {
                    $meRecipients[] = $dj;
                } else {
                    $otherRecipients[] = $dj;
                }
            }
            $all = array_merge($meRecipients, $otherRecipients);
            if ($all === []) {
                $all = [$jid];
                $otherRecipients = [$jid];
            }
            $this->assertSessions($all);
            ['nodes' => $meNodes, 'shouldIncludeDeviceIdentity' => $s1] =
                $this->createParticipantNodes($meRecipients, $meMsg, $extraAttrs);
            ['nodes' => $otherNodes, 'shouldIncludeDeviceIdentity' => $s2] =
                $this->createParticipantNodes($otherRecipients, $message, $extraAttrs, $meMsg);
            $participants = array_merge($meNodes, $otherNodes);
            $shouldInclude = $shouldInclude || $s1 || $s2;
            if ($all !== []) {
                // NB: upstream computes a participant hash but never attaches it to the
                // stanza; a locally-enumerated device list that differs even slightly
                // from the server's view makes the hash mismatch, which can get the
                // message rejected/dropped by the server. Omit it like upstream.
            }
        }

        if ($participants !== []) {
            if (($additionalAttributes['category'] ?? null) === 'peer') {
                $peerEnc = $participants[0]->content[0] ?? null;
                if ($peerEnc !== null) {
                    $content[] = $peerEnc;
                }
            } else {
                $content[] = new BinaryNode('participants', [], $participants);
            }
        }
        if ($shouldInclude && !empty($this->creds()->account)) {
            $content[] = new BinaryNode('device-identity', [], new Bytes(
                ValidateConnection::encodeSignedDeviceIdentity($this->creds()->account, true)
            ));
        }
        foreach (($options['additionalNodes'] ?? []) as $n) {
            $content[] = $n;
        }

        $stanzaAttrs = array_merge([
            'id' => $msgId,
            'to' => $destinationJid,
            'type' => self::getMessageStanzaType($message),
        ], $additionalAttributes);
        if ($participant) {
            if (Jid::isGroup($destinationJid)) {
                $stanzaAttrs['to'] = $destinationJid;
                $stanzaAttrs['participant'] = $participant['jid'];
            } elseif (Jid::areSameUser($participant['jid'], $meId)) {
                $stanzaAttrs['to'] = $participant['jid'];
                $stanzaAttrs['recipient'] = $destinationJid;
            } else {
                $stanzaAttrs['to'] = $participant['jid'];
            }
        }
        // reporting token (abuse-report integrity), like WA Web
        if (!$isRetryResend
            && !empty($message['messageContextInfo']['messageSecret'])
            && \BaileysPHP\Utils\Reporting::shouldIncludeReportingToken($message)) {
            try {
                $reportingNode = \BaileysPHP\Utils\Reporting::getMessageReportingToken(
                    WAProto::encodeMessage($message),
                    $message,
                    ['id' => $msgId, 'fromMe' => true, 'remoteJid' => $destinationJid, 'participant' => $participant['jid'] ?? null]
                );
                if ($reportingNode !== null) {
                    $content[] = $reportingNode;
                }
            } catch (\Throwable $e) {
                $this->logger->warn(['err' => $e->getMessage()], 'failed to attach reporting token');
            }
        }
        // trusted-contact (privacy) token on 1:1 sends
        $isPeerMessage = ($additionalAttributes['category'] ?? null) === 'peer';
        $is1on1Send = !$isGroup && !$isRetryResend && !$isStatus && !$isPeerMessage;
        if ($is1on1Send && ($this->serverProps['privacyTokenOn1to1'] ?? true)) {
            $tcNode = $this->buildTcTokenNode($destinationJid);
            if ($tcNode !== null) {
                // stanza-level tctoken carries no attrs upstream
                $content[] = new BinaryNode('tctoken', [], $tcNode->content);
            }
        }
        $stanza = new BinaryNode('message', $stanzaAttrs, $content);
        $this->logger->debug(['msgId' => $msgId], 'sending message to ' . count($participants) . ' devices');
        $this->sendNode($stanza);
        if ($is1on1Send && !isset(\BaileysPHP\Utils\Messages::normalizeMessageContent($message)['protocolMessage'])) {
            $this->ensurePrivacyTokenIssued($destinationJid);
        }
        // cache for retry receipts (messages-send)
        if ($this->retryManager !== null && !$isRetryResend) {
            $this->retryManager->addRecentMessage($destinationJid, $msgId, $message);
        }
        return $msgId;
    }

    /** getDecryptionJid: prefer LID addressing for Signal like upstream. */
    private function getDecryptionJid(string $sender): string
    {
        if (Jid::isLidUser($sender) || Jid::isHostedLidUser($sender)) {
            return $sender;
        }
        return $this->signalRepository->lidMapping->getLIDForPN($sender) ?? $sender;
    }

    /** Port of getMessageType() from messages-send.ts ('text'|'media'|'poll'|'reaction'|'event'). */
    public static function getMessageStanzaType(array $message): string
    {
        $n = \BaileysPHP\Utils\Messages::normalizeMessageContent($message);
        if ($n === null) {
            return 'text';
        }
        if (isset($n['reactionMessage']) || isset($n['encReactionMessage'])) {
            return 'reaction';
        }
        if (isset($n['pollCreationMessage']) || isset($n['pollCreationMessageV2'])
            || isset($n['pollCreationMessageV3']) || isset($n['pollUpdateMessage'])) {
            return 'poll';
        }
        if (isset($n['eventMessage'])) {
            return 'event';
        }
        if (self::getMediaType($n)) {
            return 'media';
        }
        return 'text';
    }

    /** Port of getMediaType() from messages-send.ts ('' when not media). */
    public static function getMediaType(array $message): string
    {
        if (isset($message['imageMessage'])) return 'image';
        if (isset($message['videoMessage'])) return !empty($message['videoMessage']['gifPlayback']) ? 'gif' : 'video';
        if (isset($message['audioMessage'])) return !empty($message['audioMessage']['ptt']) ? 'ptt' : 'audio';
        if (isset($message['contactMessage'])) return 'vcard';
        if (isset($message['documentMessage'])) return 'document';
        if (isset($message['contactsArrayMessage'])) return 'contact_array';
        if (isset($message['liveLocationMessage'])) return 'livelocation';
        if (isset($message['stickerMessage'])) return 'sticker';
        if (isset($message['listMessage'])) return 'list';
        if (isset($message['locationMessage'])) return 'location';
        return '';
    }

    /**
     * sendMessage(jid, content, options) — Basic supports text:
     *   $sock->sendMessage('9477xxxxxxx@s.whatsapp.net', ['text' => 'hello'])
     * Also accepts a raw proto Message array, e.g. ['conversation' => 'hello'].
     * @return array<string,mixed> the sent WAMessage (key/message/messageTimestamp/status)
     */
    public function sendMessage(string $jid, array $content, array $options = []): array
    {
        if (!empty($content['disappearingMessagesInChat']) && Jid::isGroup($jid)) {
            $v = $content['disappearingMessagesInChat'];
            $value = is_bool($v) ? ($v ? 604800 : 0) : (int)$v;
            $this->groupToggleEphemeral($jid, $value);
            // upstream returns undefined here; return a minimal marker message
            return [
                'key' => ['remoteJid' => $jid, 'fromMe' => true, 'id' => Generics::generateMessageIDV2($this->creds()->me['id'] ?? null)],
                'messageTimestamp' => time(),
            ];
        }
        $fullMsg = $this->generateWAMessage($jid, $content, array_merge($options, [
            'userJid' => $this->creds()->me['id'] ?? null,
        ]));
        $additionalAttributes = [];
        $additionalNodes = [];
        if (!empty($content['delete'])) {
            $additionalAttributes['edit'] = (Jid::isGroup($content['delete']['remoteJid'] ?? '') && empty($content['delete']['fromMe'])) ? '8' : '7';
        } elseif (!empty($content['edit'])) {
            $additionalAttributes['edit'] = '1';
        } elseif (!empty($content['pin'])) {
            $additionalAttributes['edit'] = '2';
        } elseif (!empty($content['poll'])) {
            $additionalNodes[] = new BinaryNode('meta', ['polltype' => 'creation']);
        } elseif (!empty($content['event'])) {
            $additionalNodes[] = new BinaryNode('meta', ['event_type' => 'creation']);
        }
        $this->relayMessage($jid, $fullMsg['message'], [
            'messageId' => $fullMsg['key']['id'],
            'useCachedGroupMetadata' => $options['useCachedGroupMetadata'] ?? true,
            'additionalAttributes' => $additionalAttributes,
            'statusJidList' => $options['statusJidList'] ?? null,
            'additionalNodes' => $additionalNodes,
        ]);
        if (!empty($this->config['emitOwnEvents'])) {
            $this->ev->emit('messages.upsert', ['messages' => [$fullMsg], 'type' => 'append', 'requestId' => null]);
        }
        return $fullMsg;
    }

    /** sendPresenceUpdate('available'|'unavailable'|'composing'|'recording'|'paused', $toJid?) */
    public function sendPresenceUpdate(string $type, ?string $toJid = null): void
    {
        if ($type === 'available' || $type === 'unavailable') {
            $name = $this->creds()->me['name'] ?? null;
            $attrs = ['type' => $type];
            if ($name) {
                $attrs['name'] = $name;
            }
            $this->sendNode(new BinaryNode('presence', $attrs));
        } else {
            if (!$toJid) {
                throw new \InvalidArgumentException('toJid required for chatstate presence');
            }
            $this->sendNode(new BinaryNode('chatstate', ['to' => $toJid], [
                new BinaryNode($type === 'recording' ? 'recording' : $type, $type === 'recording' ? ['media' => 'audio'] : []),
            ]));
        }
    }

    public function presenceSubscribe(string $toJid): void
    {
        $this->sendNode(new BinaryNode('presence', ['to' => $toJid, 'type' => 'subscribe']));
    }

    /**
     * readMessages(keys) — mark messages as read (Basic: sends 'read' receipts).
     * @param list<array{remoteJid:string,id:string,participant?:?string,fromMe?:bool}> $keys
     */
    public function readMessages(array $keys): void
    {
        $byChat = [];
        foreach ($keys as $k) {
            if (!empty($k['fromMe'])) continue;
            $jid = $k['remoteJid'];
            $byChat[$jid]['participant'] = $k['participant'] ?? null;
            $byChat[$jid]['ids'][] = $k['id'];
        }
        foreach ($byChat as $jid => $info) {
            $this->sendReceipt($jid, $info['participant'], $info['ids'], 'read');
        }
    }

    public function sendReceipt(string $jid, ?string $participant, array $messageIds, string $type): void
    {
        if ($messageIds === []) {
            throw new \InvalidArgumentException('missing ids in receipt');
        }
        $attrs = ['id' => $messageIds[0]];
        if ($type === 'read' || $type === 'read-self') {
            $attrs['t'] = (string)time();
        }
        if ($type === 'sender' && (Jid::isPnUser($jid) || Jid::isLidUser($jid))) {
            $attrs['recipient'] = $jid;
            $attrs['to'] = $participant ?? $jid;
        } else {
            $attrs['to'] = $jid;
            if ($participant) {
                $attrs['participant'] = $participant;
            }
        }
        if ($type !== '') {
            $attrs['type'] = $type;
        }
        $content = null;
        $rest = array_slice($messageIds, 1);
        if ($rest !== []) {
            $content = [new BinaryNode('list', [], array_map(fn($id) => new BinaryNode('item', ['id' => $id]), $rest))];
        }
        $this->sendNode(new BinaryNode('receipt', $attrs, $content));
    }
}
