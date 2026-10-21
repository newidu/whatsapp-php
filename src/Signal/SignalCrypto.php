<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

/** Port of libsignal src/crypto.js */
final class SignalCrypto
{
    public static function encrypt(string $key, string $data, string $iv): string
    {
        $r = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($r === false) {
            throw new \RuntimeException('Signal AES-CBC encrypt failed');
        }
        return $r;
    }

    public static function decrypt(string $key, string $data, string $iv): string
    {
        $r = openssl_decrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($r === false) {
            throw new \RuntimeException('Signal AES-CBC decrypt failed');
        }
        return $r;
    }

    public static function calculateMAC(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    /**
     * Specific RFC 5869 implementation returning up to the first 3 chunks.
     * @return list<string> each 32 bytes
     */
    public static function deriveSecrets(string $input, string $salt, string $info, int $chunks = 3): array
    {
        if (strlen($salt) !== 32) {
            throw new \RuntimeException('Got salt of incorrect length');
        }
        if ($chunks < 1 || $chunks > 3) {
            throw new \RuntimeException('chunks must be 1..3');
        }
        $prk = self::calculateMAC($salt, $input);
        $signed = [self::calculateMAC($prk, $info . chr(1))];
        if ($chunks > 1) {
            $signed[] = self::calculateMAC($prk, $signed[count($signed) - 1] . $info . chr(2));
        }
        if ($chunks > 2) {
            $signed[] = self::calculateMAC($prk, $signed[count($signed) - 1] . $info . chr(3));
        }
        return $signed;
    }

    public static function verifyMAC(string $data, string $key, string $mac, int $length): void
    {
        $calculated = substr(self::calculateMAC($key, $data), 0, $length);
        if (strlen($mac) !== $length || strlen($calculated) !== $length) {
            throw new \RuntimeException('Bad MAC length');
        }
        if (!hash_equals($calculated, $mac)) {
            throw new \RuntimeException('Bad MAC');
        }
    }
}
