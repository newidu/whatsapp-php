<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Defaults;
use BaileysPHP\Utils\Logger;

/**
 * Pure-PHP WebSocket client (RFC 6455) — replaces Node's `ws` package.
 *
 * Blocking connect (TLS + HTTP Upgrade), then non-blocking poll().
 * Handles: client masking, 7/16/64-bit lengths, fragmentation, ping->pong, close.
 * Works on PHP CLI (Linux/macOS/Windows). Requires only ext-openssl + streams.
 */
final class WebSocketClient
{
    /** @var resource|null */
    private $stream = null;
    private string $recvBuffer = '';
    private string $fragmentBuffer = '';
    private ?int $fragmentOpcode = null;
    public bool $isOpen = false;
    public bool $isClosed = true;
    public ?int $closeCode = null;
    public string $closeReason = '';

    /**
     * @param array<string,mixed> $config socket config (connectTimeoutMs, options.headers, tlsVerify)
     */
    public function __construct(
        private string $url,
        private array $config = [],
        private ?Logger $logger = null,
    ) {
        $this->logger ??= new Logger();
    }

    public function connect(): void
    {
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('Invalid WebSocket URL: ' . $this->url);
        }
        $scheme = $parts['scheme'] ?? 'wss';
        $host = $parts['host'];
        $isTls = $scheme === 'wss' || $scheme === 'https';
        $port = $parts['port'] ?? ($isTls ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $timeout = (int)ceil(($this->config['connectTimeoutMs'] ?? 20000) / 1000);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->config['tlsVerify'] ?? true,
                'verify_peer_name' => $this->config['tlsVerify'] ?? true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
            'socket' => ['tcp_nodelay' => true],
        ]);

        $remote = ($isTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $this->stream = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($this->stream === false) {
            throw new \RuntimeException("WebSocket connect failed to $remote : [$errno] $errstr", 428);
        }
        stream_set_timeout($this->stream, $timeout);

        $key = base64_encode(random_bytes(16));
        $headers = [
            "GET $path HTTP/1.1",
            "Host: $host" . (($isTls && $port !== 443) || (!$isTls && $port !== 80) ? ':' . $port : ''),
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: $key",
            'Sec-WebSocket-Version: 13',
            'Origin: ' . Defaults::DEFAULT_ORIGIN,
        ];
        foreach (($this->config['options']['headers'] ?? []) as $k => $v) {
            $headers[] = "$k: $v";
        }
        $req = implode("\r\n", $headers) . "\r\n\r\n";
        $this->writeAllBlocking($req);

        // Read response headers
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('WebSocket handshake failed: no response from server', 428);
            }
            $response .= $chunk;
            if (strlen($response) > 16384) {
                throw new \RuntimeException('WebSocket handshake failed: headers too large', 428);
            }
        }
        [$head, $rest] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        if (!preg_match('#HTTP/1\.\d 101#', $head)) {
            throw new \RuntimeException("WebSocket handshake rejected: " . strtok($head, "\r\n"), 428);
        }
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $head, $m) || !hash_equals($expected, trim($m[1]))) {
            throw new \RuntimeException('WebSocket handshake failed: bad Sec-WebSocket-Accept', 428);
        }
        $this->recvBuffer = $rest;
        stream_set_blocking($this->stream, false);
        $this->isOpen = true;
        $this->isClosed = false;
        $this->logger->debug('WebSocket open: ' . $this->url);
    }

    private function writeAllBlocking(string $data): void
    {
        $written = 0;
        $len = strlen($data);
        while ($written < $len) {
            $n = fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('WebSocket write failed', 428);
            }
            $written += $n;
        }
    }

    /** Write with select-based waiting (stream is non-blocking after connect). */
    private function writeAll(string $data): void
    {
        $written = 0;
        $len = strlen($data);
        $deadline = microtime(true) + 20;
        while ($written < $len) {
            $n = @fwrite($this->stream, substr($data, $written, 8192));
            if ($n !== false && $n > 0) {
                $written += $n;
                continue;
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('WebSocket write timed out', 408);
            }
            $read = null;
            $write = [$this->stream];
            $except = null;
            @stream_select($read, $write, $except, 1);
        }
    }

    public function send(string $data, int $opcode = 2): bool
    {
        if (!$this->isOpen) {
            return false;
        }
        $this->writeAll($this->encodeFrame($data, $opcode));
        return true;
    }

    private function encodeFrame(string $payload, int $opcode): string
    {
        $len = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127); // 64-bit big-endian length (built manually — pack('J') is machine order)
            for ($i = 7; $i >= 0; $i--) {
                $header .= chr(($len >> ($i * 8)) & 0xff);
            }
        }
        $mask = random_bytes(4);
        $masked = '';
        // XOR in chunks for speed
        $maskRepeated = str_repeat($mask, (int)ceil($len / 4) + 1);
        $masked = $payload ^ substr($maskRepeated, 0, $len);
        return $header . $mask . $masked;
    }

    /**
     * Poll the socket once.
     * @param float $timeoutSec select timeout (0 = return immediately)
     * @return list<string> complete message payloads received (binary/text)
     */
    public function poll(float $timeoutSec = 0.2): array
    {
        if (!$this->isOpen) {
            return [];
        }
        $messages = $this->extractFrames();
        if ($messages !== []) {
            return $messages;
        }
        $read = [$this->stream];
        $write = null;
        $except = null;
        $sec = (int)floor($timeoutSec);
        $usec = (int)(($timeoutSec - $sec) * 1_000_000);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false || $ready === 0) {
            return [];
        }
        $chunk = @fread($this->stream, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) {
            $this->handleRemoteClose();
            return [];
        }
        if ($chunk !== '') {
            $this->recvBuffer .= $chunk;
        }
        return $this->extractFrames();
    }

    /** @return list<string> */
    private function extractFrames(): array
    {
        $messages = [];
        while (strlen($this->recvBuffer) >= 2) {
            $b0 = ord($this->recvBuffer[0]);
            $b1 = ord($this->recvBuffer[1]);
            $fin = (bool)($b0 & 0x80);
            $opcode = $b0 & 0x0f;
            $masked = (bool)($b1 & 0x80);
            $len = $b1 & 0x7f;
            $offset = 2;
            if ($len === 126) {
                if (strlen($this->recvBuffer) < 4) break;
                $len = unpack('n', substr($this->recvBuffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($this->recvBuffer) < 10) break;
                $len = 0;
                for ($i = 0; $i < 8; $i++) {
                    $len = ($len << 8) | ord($this->recvBuffer[2 + $i]);
                }
                $offset = 10;
            }
            $maskKey = '';
            if ($masked) {
                if (strlen($this->recvBuffer) < $offset + 4) break;
                $maskKey = substr($this->recvBuffer, $offset, 4);
                $offset += 4;
            }
            if (strlen($this->recvBuffer) < $offset + $len) break;
            $payload = substr($this->recvBuffer, $offset, $len);
            $this->recvBuffer = (string)substr($this->recvBuffer, $offset + $len);
            if ($masked) {
                $payload = $payload ^ substr(str_repeat($maskKey, (int)ceil($len / 4) + 1), 0, $len);
            }

            switch ($opcode) {
                case 9: // ping -> pong
                    $this->send($payload, 10);
                    break;
                case 10: // pong
                    break;
                case 8: // close
                    if (strlen($payload) >= 2) {
                        $this->closeCode = unpack('n', substr($payload, 0, 2))[1];
                        $this->closeReason = substr($payload, 2);
                    }
                    $this->handleRemoteClose();
                    break;
                case 0: // continuation
                    $this->fragmentBuffer .= $payload;
                    if ($fin) {
                        $messages[] = $this->fragmentBuffer;
                        $this->fragmentBuffer = '';
                        $this->fragmentOpcode = null;
                    }
                    break;
                default: // 1 text, 2 binary
                    if (!$fin) {
                        $this->fragmentBuffer = $payload;
                        $this->fragmentOpcode = $opcode;
                    } else {
                        $messages[] = $payload;
                    }
            }
        }
        return $messages;
    }

    private function handleRemoteClose(): void
    {
        $this->isOpen = false;
        $this->isClosed = true;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    public function close(): void
    {
        if ($this->isOpen && is_resource($this->stream)) {
            try {
                $this->writeAll($this->encodeFrame(pack('n', 1000), 8));
            } catch (\Throwable) {
            }
        }
        $this->handleRemoteClose();
    }
}
