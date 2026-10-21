<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Tiny logger shaped like Baileys' pino ILogger (level + child()).
 * Levels: trace < debug < info < warn < error < silent
 */
final class Logger
{
    private const LEVELS = ['trace' => 10, 'debug' => 20, 'info' => 30, 'warn' => 40, 'error' => 50, 'silent' => 60];

    public function __construct(
        public string $level = 'info',
        private array $ctx = [],
        private mixed $stream = null,
    ) {
        // STDERR is defined by CLI SAPI, but not by normal web PHP requests.
        // Prefer the server error stream for web SAPI and fall back to output.
        $this->stream ??= defined('STDERR')
            ? STDERR
            : (fopen('php://stderr', 'wb') ?: fopen('php://output', 'wb'));
    }

    public function child(array $ctx): self
    {
        return new self($this->level, array_merge($this->ctx, $ctx), $this->stream);
    }

    private function log(string $lvl, mixed $obj, ?string $msg): void
    {
        if ((self::LEVELS[$lvl] ?? 30) < (self::LEVELS[$this->level] ?? 30)) {
            return;
        }
        $time = date('H:i:s');
        $cls = $this->ctx['class'] ?? 'baileys-php';
        $line = "[$time] " . strtoupper($lvl) . " ($cls): " . ($msg ?? '');
        if ($obj !== null && $obj !== '' && $obj !== []) {
            $s = is_string($obj) ? $obj : json_encode($this->sanitize($obj), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($s !== false && $s !== 'null' && $s !== '""') {
                $line .= ' ' . (strlen($s) > 2000 ? substr($s, 0, 2000) . '…' : $s);
            }
        }
        fwrite($this->stream, rtrim($line) . PHP_EOL);
    }

    /** Replace binary strings with hex summaries so logs stay readable. */
    private function sanitize(mixed $v, int $depth = 0): mixed
    {
        if (is_string($v)) {
            return preg_match('//u', $v) === 1 && !str_contains($v, "\0") ? $v : '<bin ' . strlen($v) . 'B ' . substr(bin2hex($v), 0, 32) . '…>';
        }
        if (is_array($v) && $depth < 6) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = $this->sanitize($x, $depth + 1);
            }
            return $out;
        }
        if (is_object($v)) {
            return $this->sanitize(get_object_vars($v), $depth + 1);
        }
        return $v;
    }

    public function trace(mixed $obj = '', ?string $msg = null): void { $this->log('trace', $obj, $msg ?? (is_string($obj) ? $obj : null)); }
    public function debug(mixed $obj = '', ?string $msg = null): void { $this->log('debug', $obj, $msg ?? (is_string($obj) ? $obj : null)); }
    public function info(mixed $obj = '', ?string $msg = null): void { $this->log('info', $obj, $msg ?? (is_string($obj) ? $obj : null)); }
    public function warn(mixed $obj = '', ?string $msg = null): void { $this->log('warn', $obj, $msg ?? (is_string($obj) ? $obj : null)); }
    public function error(mixed $obj = '', ?string $msg = null): void { $this->log('error', $obj, $msg ?? (is_string($obj) ? $obj : null)); }

    public static function silent(): self
    {
        return new self('silent');
    }
}
