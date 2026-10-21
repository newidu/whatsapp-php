<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Proto\WAProto;
use BaileysPHP\WABinary\Jid;

/** Port of (the Basic-relevant parts of) Baileys: src/Utils/generics.ts + companion-reg-client-utils.ts */
final class Generics
{
    // ---- BufferJSON (Baileys format: {"type":"Buffer","data":"<base64>"})
    /** @return array{type:string,data:string} */
    public static function bufferToJson(string $buf): array
    {
        return ['type' => 'Buffer', 'data' => base64_encode($buf)];
    }

    public static function bufferFromJson(mixed $v): ?string
    {
        if (is_array($v) && ($v['type'] ?? null) === 'Buffer' && is_string($v['data'] ?? null)) {
            // New Baileys writes base64; very old writes a number[]. Support both on read.
            return base64_decode($v['data'], true) ?: '';
        }
        if (is_array($v) && ($v['type'] ?? null) === 'Buffer' && is_array($v['data'] ?? null)) {
            return implode('', array_map('chr', $v['data']));
        }
        if (is_string($v)) {
            return $v;
        }
        return null;
    }

    public static function writeRandomPadMax16(string $msg): string
    {
        $padLength = (ord(random_bytes(1)[0]) & 0x0f) + 1;
        return $msg . str_repeat(chr($padLength), $padLength);
    }

    public static function unpadRandomMax16(string $e): string
    {
        if ($e === '') {
            throw new \RuntimeException('unpadPkcs7 given empty bytes');
        }
        $r = ord($e[strlen($e) - 1]);
        if ($r > strlen($e)) {
            throw new \RuntimeException("unpad given " . strlen($e) . " bytes, but pad is $r");
        }
        return substr($e, 0, strlen($e) - $r);
    }

    /** @param list<string> $participants */
    public static function generateParticipantHashV2(array $participants): string
    {
        sort($participants);
        $hash = base64_encode(sha256(implode('', $participants)));
        return '2:' . substr($hash, 0, 6);
    }

    /** @param array<string,mixed> $message proto Message array */
    public static function encodeWAMessage(array $message): string
    {
        return self::writeRandomPadMax16(WAProto::encodeMessage($message));
    }

    public static function generateRegistrationId(): int
    {
        return unpack('v', random_bytes(2))[1] & 16383;
    }

    public static function readBigEndian(string $data, int $length): int
    {
        $value = 0;
        $data = substr($data, 0, $length);
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($data[$i]);
        }
        // when fewer bytes given than $length, upstream pads left — value already correct
        return $value;
    }

    public static function encodeBigEndian(int $e, int $t = 4): string
    {
        $out = str_repeat("\0", $t);
        $r = $e;
        for ($i = $t - 1; $i >= 0; $i--) {
            $out[$i] = chr(255 & $r);
            $r >>= 8;
        }
        return $out;
    }

    public static function unixTimestampSeconds(?int $ms = null): int
    {
        return intdiv($ms ?? (int)(microtime(true) * 1000), 1000);
    }

    public static function generateMessageIDV2(?string $userId = null): string
    {
        $data = str_repeat("\0", 8 + 20 + 16);
        // 8-byte BE seconds
        $secs = self::encodeBigEndian(self::unixTimestampSeconds(), 8);
        $data = $secs . substr($data, 8);
        if ($userId) {
            $id = Jid::decode($userId);
            if ($id && $id['user'] !== '') {
                $u = $id['user'] . '@c.us';
                $data = substr($data, 0, 8) . $u . substr($data, 8 + strlen($u));
            }
        }
        $data = substr($data, 0, 28) . random_bytes(16);
        return '3EB0' . substr(strtoupper(bin2hex(hash('sha256', $data, true))), 0, 18);
    }

    public static function generateMessageID(): string
    {
        return '3EB0' . strtoupper(bin2hex(random_bytes(18)));
    }

    public static function generateMdTagPrefix(): string
    {
        $b = random_bytes(4);
        return unpack('n', substr($b, 0, 2))[1] . '.' . unpack('n', substr($b, 2, 2))[1] . '-';
    }

    private const CROCKFORD = '123456789ABCDEFGHJKLMNPQRSTVWXYZ';

    public static function bytesToCrockford(string $buffer): string
    {
        $value = 0;
        $bitCount = 0;
        $out = '';
        for ($i = 0, $n = strlen($buffer); $i < $n; $i++) {
            $value = ($value << 8) | (ord($buffer[$i]) & 0xff);
            $bitCount += 8;
            while ($bitCount >= 5) {
                $out .= self::CROCKFORD[(($value >> ($bitCount - 5)) & 31)];
                $bitCount -= 5;
            }
        }
        if ($bitCount > 0) {
            $out .= self::CROCKFORD[(($value << (5 - $bitCount)) & 31)];
        }
        return $out;
    }

    /** getCompanionPlatformId(browser) */
    public static function getCompanionPlatformId(array $browser): string
    {
        [$os, $browserName] = $browser;
        if ($browserName === 'Desktop') {
            return (string)($os === 'Windows' ? 8 : 7); // UWP / ELECTRON
        }
        $map = ['Chrome' => 1, 'Edge' => 2, 'Firefox' => 3, 'IE' => 4, 'Opera' => 5, 'Safari' => 6];
        return (string)($map[$browserName] ?? 9);
    }

    public static function buildPairingQRData(string $ref, string $noiseKeyB64, string $identityKeyB64, string $advB64, array $browser): string
    {
        return 'https://wa.me/settings/linked_devices#'
            . implode(',', [$ref, $noiseKeyB64, $identityKeyB64, $advB64, self::getCompanionPlatformId($browser)]);
    }

    public static function delayMs(int $ms): void
    {
        usleep($ms * 1000);
    }
}
