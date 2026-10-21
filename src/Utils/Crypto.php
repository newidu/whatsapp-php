<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Auth\KeyPair;
use BaileysPHP\Defaults;

/**
 * Port of Baileys: src/Utils/crypto.ts
 *  + the `Curve` object (libsignal curve) implemented over libsodium / GMP.
 *
 * Verified cross-implementation properties (see tests):
 *  - generateKeyPair/sharedKey : sodium X25519 == Node/OpenSSL X25519
 *  - sign()  : XEdDSA as in curve25519-js `curve25519_sign` (crypto_sign_direct)
 *  - verify(): sign-bit from sig[63], Montgomery->Edwards conversion, sodium verify
 */
/** Prefix 0x05 to a 32-byte pubkey if not present (generateSignalPubKey). */
function generateSignalPubKey(string $pubKey): string
{
    return strlen($pubKey) === 33 ? $pubKey : Defaults::KEY_BUNDLE_TYPE . $pubKey;
}

/** signedKeyPair(identityKeyPair, keyId) from crypto.ts */
function signedKeyPair(KeyPair $identityKeyPair, int $keyId): \BaileysPHP\Auth\SignedKeyPair
{
    $preKey = Curve::generateKeyPair();
    $pubKey = generateSignalPubKey($preKey->public);
    $signature = Curve::sign($identityKeyPair->private, $pubKey);
    return new \BaileysPHP\Auth\SignedKeyPair($preKey, $signature, $keyId);
}

/** AES-256-GCM encrypt; auth tag suffixed to ciphertext. */
function aesEncryptGCM(string $plaintext, string $key, string $iv, string $additionalData): string
{
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $additionalData, 16);
    if ($ct === false) {
        throw new \RuntimeException('aesEncryptGCM failed');
    }
    return $ct . $tag;
}

/** AES-256-GCM decrypt; auth tag suffixed to ciphertext. */
function aesDecryptGCM(string $ciphertext, string $key, string $iv, string $additionalData): string
{
    $tagLen = 16;
    $enc = substr($ciphertext, 0, strlen($ciphertext) - $tagLen);
    $tag = substr($ciphertext, strlen($ciphertext) - $tagLen);
    $pt = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $additionalData);
    if ($pt === false) {
        throw new \RuntimeException('aesDecryptGCM failed (auth tag / key mismatch)');
    }
    return $pt;
}

function aesEncryptCTR(string $plaintext, string $key, string $iv): string
{
    $r = openssl_encrypt($plaintext, 'aes-256-ctr', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($r === false) {
        throw new \RuntimeException('aesEncryptCTR failed');
    }
    return $r;
}

function aesDecryptCTR(string $ciphertext, string $key, string $iv): string
{
    $r = openssl_decrypt($ciphertext, 'aes-256-ctr', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($r === false) {
        throw new \RuntimeException('aesDecryptCTR failed');
    }
    return $r;
}

/** AES-256-CBC decrypt where IV is prefixed to buffer. */
function aesDecrypt(string $buffer, string $key): string
{
    return aesDecryptWithIV(substr($buffer, 16), $key, substr($buffer, 0, 16));
}

function aesDecryptWithIV(string $buffer, string $key, string $iv): string
{
    $r = openssl_decrypt($buffer, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($r === false) {
        throw new \RuntimeException('aesDecryptWithIV failed');
    }
    return $r;
}

/** AES-256-CBC encrypt; random IV prefixed to buffer. */
function aesEncrypt(string $buffer, string $key): string
{
    $iv = random_bytes(16);
    return $iv . aesEncryptWithIV($buffer, $key, $iv);
}

function aesEncryptWithIV(string $buffer, string $key, string $iv): string
{
    $r = openssl_encrypt($buffer, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($r === false) {
        throw new \RuntimeException('aesEncryptWithIV failed');
    }
    return $r;
}

function hmacSign(string $buffer, string $key, string $variant = 'sha256'): string
{
    return hash_hmac($variant, $buffer, $key, true);
}

function sha256(string $buffer): string
{
    return hash('sha256', $buffer, true);
}

function md5bin(string $buffer): string
{
    return hash('md5', $buffer, true);
}

/**
 * Standard HKDF-SHA256 (RFC 5869), matching Baileys hkdf(ikm, length, {salt, info}).
 * Implemented manually because PHP's hash_hkdf() rejects an empty IKM,
 * which the Noise finishInit step needs (HKDF with 0-length input).
 */
function hkdf(string $ikm, int $length, string $salt = '', string $info = ''): string
{
    if ($salt === '') {
        $salt = str_repeat("\0", 32); // HashLen zeros, RFC 5869 §2.2
    }
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $okm = '';
    $t = '';
    for ($i = 1; strlen($okm) < $length; $i++) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $okm .= $t;
    }
    return substr($okm, 0, $length);
}

/** PBKDF2-SHA256, 131072 iterations -> 32 bytes (derivePairingCodeKey). */
function derivePairingCodeKey(string $pairingCode, string $salt): string
{
    return hash_pbkdf2('sha256', $pairingCode, $salt, 2 << 16, 32, true);
}
