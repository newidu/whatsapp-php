<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

use BaileysPHP\Utils\Generics;

/**
 * File-backed SignalKeyStore used by useMultiFileAuthState():
 * one JSON file per entry:  {folder}/{type}-{id}.json
 * File-name fixing matches Baileys: '/' -> '__', ':' -> '-'
 */
final class FileSignalKeyStore implements SignalKeyStore
{
    private bool $inTx = false;
    /** @var array<string,mixed> simple read cache (single CLI process) */
    private array $cache = [];

    public function __construct(private string $folder) {}

    private function fixFileName(string $file): string
    {
        return str_replace(['/', ':'], ['__', '-'], $file);
    }

    private function path(string $type, string $id): string
    {
        return $this->folder . DIRECTORY_SEPARATOR . $this->fixFileName("$type-$id.json");
    }

    /** encode a PHP value into JSON-ready structure for its type */
    private function encode(string $type, mixed $value): mixed
    {
        return match ($type) {
            'pre-key' => $value instanceof KeyPair ? [
                'private' => Generics::bufferToJson($value->private),
                'public' => Generics::bufferToJson($value->public),
            ] : $value,
            'session', 'sender-key', 'identity-key' => is_string($value) ? Generics::bufferToJson($value) : $value,
            'tctoken' => is_array($value) ? $this->encodeTctoken($value) : $value,
            'app-state-sync-key' => is_array($value) ? $this->encodeAppStateKey($value) : $value,
            'app-state-sync-version' => is_array($value) ? $this->encodeAppStateVersion($value) : $value,
            default => $value, // device-list (array), lid-mapping (string), sender-key-memory (array of bools)
        };
    }

    private function decode(string $type, mixed $json): mixed
    {
        if ($json === null) {
            return null;
        }
        return match ($type) {
            'pre-key' => new KeyPair(
                Generics::bufferFromJson($json['private'] ?? null) ?? '',
                Generics::bufferFromJson($json['public'] ?? null) ?? ''
            ),
            'session', 'sender-key', 'identity-key' => Generics::bufferFromJson($json),
            'tctoken' => is_array($json) ? $this->decodeTctoken($json) : $json,
            'app-state-sync-key' => is_array($json) ? $this->decodeAppStateKey($json) : $json,
            'app-state-sync-version' => is_array($json) ? $this->decodeAppStateVersion($json) : $json,
            default => $json,
        };
    }

    private function encodeTctoken(array $value): array
    {
        if (isset($value['token']) && is_string($value['token'])) {
            $value['token'] = Generics::bufferToJson($value['token']);
        }
        return $value;
    }

    private function decodeTctoken(array $json): array
    {
        if (isset($json['token'])) {
            $json['token'] = Generics::bufferFromJson($json['token']) ?? '';
        }
        return $json;
    }

    private function encodeAppStateKey(array $value): array
    {
        foreach (['keyData'] as $k) {
            if (isset($value[$k]) && is_string($value[$k])) {
                $value[$k] = Generics::bufferToJson($value[$k]);
            }
        }
        if (isset($value['fingerprint']['rawId'])) {
            // rawId is numeric in proto; keep as-is
        }
        return $value;
    }

    private function decodeAppStateKey(array $json): array
    {
        if (isset($json['keyData'])) {
            $json['keyData'] = Generics::bufferFromJson($json['keyData']) ?? '';
        }
        return $json;
    }

    private function encodeAppStateVersion(array $value): array
    {
        if (isset($value['hash']) && is_string($value['hash'])) {
            $value['hash'] = Generics::bufferToJson($value['hash']);
        }
        if (isset($value['indexValueMap']) && is_array($value['indexValueMap'])) {
            foreach ($value['indexValueMap'] as $k => $entry) {
                if (is_array($entry) && isset($entry['valueMac']) && is_string($entry['valueMac'])) {
                    $value['indexValueMap'][$k]['valueMac'] = Generics::bufferToJson($entry['valueMac']);
                }
            }
        }
        return $value;
    }

    private function decodeAppStateVersion(array $json): array
    {
        if (isset($json['hash'])) {
            $json['hash'] = Generics::bufferFromJson($json['hash']) ?? str_repeat("\0", 128);
        }
        if (isset($json['indexValueMap']) && is_array($json['indexValueMap'])) {
            foreach ($json['indexValueMap'] as $k => $entry) {
                if (is_array($entry) && isset($entry['valueMac'])) {
                    $json['indexValueMap'][$k]['valueMac'] = Generics::bufferFromJson($entry['valueMac']) ?? '';
                }
            }
        }
        return $json;
    }

    public function get(string $type, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $ck = "$type.$id";
            if (array_key_exists($ck, $this->cache)) {
                if ($this->cache[$ck] !== null) {
                    $out[$id] = $this->cache[$ck];
                }
                continue;
            }
            $path = $this->path($type, (string)$id);
            $value = null;
            if (is_file($path)) {
                $raw = file_get_contents($path);
                if ($raw !== false && $raw !== '') {
                    $json = json_decode($raw, true);
                    $value = $this->decode($type, $json);
                }
            }
            $this->cache[$ck] = $value;
            if ($value !== null) {
                $out[$id] = $value;
            }
        }
        return $out;
    }

    public function set(array $data): void
    {
        foreach ($data as $type => $items) {
            foreach ($items as $id => $value) {
                $id = (string)$id;
                $ck = "$type.$id";
                $this->cache[$ck] = $value;
                $path = $this->path($type, $id);
                if ($value === null) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                } else {
                    $json = json_encode($this->encode($type, $value), JSON_UNESCAPED_SLASHES);
                    // atomic-ish write
                    $tmp = $path . '.tmp' . getmypid();
                    file_put_contents($tmp, $json, LOCK_EX);
                    rename($tmp, $path);
                }
            }
        }
    }

    public function clear(): void
    {
        $this->cache = [];
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            if (basename($f) !== 'creds.json') {
                @unlink($f);
            }
        }
    }

    public function isInTransaction(): bool
    {
        return $this->inTx;
    }

    public function transaction(callable $work, string $key): mixed
    {
        if ($this->inTx) {
            return $work();
        }
        $this->inTx = true;
        try {
            return $work();
        } finally {
            $this->inTx = false;
        }
    }
}
