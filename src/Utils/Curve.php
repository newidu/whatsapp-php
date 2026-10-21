<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Auth\KeyPair;

/**
 * The `Curve` object from Baileys src/Utils/crypto.ts (libsignal curve),
 * implemented over libsodium (X25519) + pure-PHP GMP XEdDSA. See Crypto.php.
 */
final class Curve
{
    public static function generateKeyPair(): KeyPair
    {
        $private = random_bytes(32);
        $public = sodium_crypto_scalarmult_base($private);
        return new KeyPair($private, $public);
    }

    /** Derive the X25519 public key for a 32-byte private key. */
    public static function derivePublicKey(string $privateKey): string
    {
        return sodium_crypto_scalarmult_base($privateKey);
    }

    /** Accepts a 32-byte pubkey or a 33-byte 0x05-prefixed Signal pubkey. */
    public static function stripPrefix(string $pubKey): string
    {
        if (strlen($pubKey) === 33 && $pubKey[0] === "\x05") {
            return substr($pubKey, 1);
        }
        return $pubKey;
    }

    public static function sharedKey(string $privateKey, string $publicKey): string
    {
        $pub = self::stripPrefix($publicKey);
        if (strlen($pub) !== 32) {
            throw new \InvalidArgumentException('Invalid public key length: ' . strlen($pub));
        }
        return sodium_crypto_scalarmult($privateKey, $pub);
    }

    /**
     * XEdDSA signature (curve25519-js compatible):
     *   a = clamp(sk); A = a*B
     *   r = SHA512(a || M) mod L; R = r*B
     *   h = SHA512(R || A || M) mod L
     *   s = r + h*a (mod L);  sig = R||s with sign-bit of A stored in sig[63] top bit
     */
    public static function sign(string $privateKey, string $message): string
    {
        if (strlen($privateKey) !== 32) {
            throw new \InvalidArgumentException('Invalid private key length');
        }
        $aBytes = Edwards25519::clampScalar($privateKey);
        $a = Edwards25519::littleEndianToGmp($aBytes);
        $A = Edwards25519::scalarbasePack($a);

        $rBytes = Edwards25519::reduceModL(hash('sha512', $aBytes . $message, true));
        $r = Edwards25519::littleEndianToGmp($rBytes);
        $R = Edwards25519::scalarbasePack($r);

        $hBytes = Edwards25519::reduceModL(hash('sha512', $R . $A . $message, true));
        $h = Edwards25519::littleEndianToGmp($hBytes);

        $L = Edwards25519::L();
        $s = gmp_mod(gmp_add($r, gmp_mul($h, $a)), $L);
        $sBytes = Edwards25519::gmpToLittleEndian($s, 32);

        $sig = $R . $sBytes;
        $sig[63] = chr(ord($sig[63]) | (ord($A[31]) & 0x80));
        return $sig;
    }

    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        $pub = self::stripPrefix($publicKey);
        if (strlen($pub) !== 32 || strlen($signature) !== 64) {
            return false;
        }
        $signBit = (ord($signature[63]) & 0x80) ? 1 : 0;
        $sig = $signature;
        $sig[63] = chr(ord($sig[63]) & 0x7f);
        try {
            $edPub = Edwards25519::montgomeryToEdwardsPublic($pub, $signBit);
            return sodium_crypto_sign_verify_detached($sig, $message, $edPub);
        } catch (\Throwable) {
            return false;
        }
    }
}
