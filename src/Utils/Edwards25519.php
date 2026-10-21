<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Pure-PHP (GMP) Edwards25519 base-point multiplication.
 *
 * Why this exists:
 *  PHP's libsodium binding can do Ed25519 and X25519 separately, but it has
 *  NO XEdDSA (sign with a Curve25519/X25519 key) and NO curve25519-pk -> ed25519-pk
 *  conversion. Signal/WhatsApp signing is XEdDSA, so the missing pieces are
 *  implemented here in pure PHP:
 *   - scalarbase(): A = a*B on Edwards25519 (extended coordinates, no per-step inversions)
 *   - montgomeryToEdwardsPublic(): u -> (u-1)/(u+1)   (single GMP inversion)
 *
 * Verification then delegates to sodium Ed25519 (fast), signing uses scalarbase().
 * This file was cross-tested against libsignal (curve25519-js) vectors.
 */
final class Edwards25519
{
    private static ?\GMP $p = null;
    private static ?\GMP $d = null;
    private static ?\GMP $l = null;
    private static ?\GMP $bx = null;
    private static ?\GMP $by = null;

    private static function init(): void
    {
        if (self::$p !== null) {
            return;
        }
        self::$p = gmp_sub(gmp_pow(2, 255), 19);
        // d = -121665/121666 mod p
        self::$d = self::mod(gmp_mul(-121665, gmp_invert(gmp_init(121666), self::$p)));
        // group order L = 2^252 + 27742317777372353535851937790883648493
        self::$l = gmp_add(gmp_pow(2, 252), gmp_init('27742317777372353535851937790883648493'));
        self::$bx = gmp_init('15112221349535400772501151409588531511454012693041857206046113283949847762202');
        self::$by = gmp_init('46316835694926478169428394003475163141307993866256225615783033603165251855960');
    }

    public static function L(): \GMP
    {
        self::init();
        return clone self::$l;
    }

    public static function P(): \GMP
    {
        self::init();
        return clone self::$p;
    }

    private static function mod(\GMP|int $x): \GMP
    {
        $r = gmp_mod($x, self::$p);
        return $r;
    }

    /**
     * A point in extended coordinates [X, Y, Z, T]; x = X/Z, y = Y/Z, T = XY/Z.
     * @param array{0:\GMP,1:\GMP,2:\GMP,3:\GMP} $p1
     * @param array{0:\GMP,1:\GMP,2:\GMP,3:\GMP} $p2
     * @return array{0:\GMP,1:\GMP,2:\GMP,3:\GMP}
     */
    private static function pointAdd(array $p1, array $p2): array
    {
        [$x1, $y1, $z1, $t1] = $p1;
        [$x2, $y2, $z2, $t2] = $p2;
        $a = self::mod(gmp_mul(gmp_sub($y1, $x1), gmp_sub($y2, $x2)));
        $b = self::mod(gmp_mul(gmp_add($y1, $x1), gmp_add($y2, $x2)));
        $c = self::mod(gmp_mul(gmp_mul(2, self::$d), gmp_mul($t1, $t2)));
        $dd = self::mod(gmp_mul(2, gmp_mul($z1, $z2)));
        $e = self::mod(gmp_sub($b, $a));
        $f = self::mod(gmp_sub($dd, $c));
        $g = self::mod(gmp_add($dd, $c));
        $h = self::mod(gmp_add($b, $a));
        return [
            self::mod(gmp_mul($e, $f)),
            self::mod(gmp_mul($g, $h)),
            self::mod(gmp_mul($f, $g)),
            self::mod(gmp_mul($e, $h)),
        ];
    }

    /**
     * @param array{0:\GMP,1:\GMP,2:\GMP,3:\GMP} $p1
     * @return array{0:\GMP,1:\GMP,2:\GMP,3:\GMP}
     */
    private static function pointDouble(array $p1): array
    {
        [$x1, $y1, $z1] = $p1;
        $a = self::mod(gmp_mul($x1, $x1));
        $b = self::mod(gmp_mul($y1, $y1));
        $c = self::mod(gmp_mul(2, gmp_mul($z1, $z1)));
        $dd = self::mod(gmp_neg($a)); // a_curve (= -1) * A
        $e = self::mod(gmp_sub(gmp_sub(self::mod(gmp_pow(gmp_add($x1, $y1), 2)), $a), $b));
        $g = self::mod(gmp_add($dd, $b));
        $f = self::mod(gmp_sub($g, $c));
        $h = self::mod(gmp_sub($dd, $b));
        return [
            self::mod(gmp_mul($e, $f)),
            self::mod(gmp_mul($g, $h)),
            self::mod(gmp_mul($f, $g)),
            self::mod(gmp_mul($e, $h)),
        ];
    }

    /** Returns the 32-byte packed encoding of (scalar * B). */
    public static function scalarbasePack(\GMP $scalar): string
    {
        self::init();
        // identity in extended coords: (0, 1, 1, 0); base: (Bx, By, 1, Bx*By)
        $result = [gmp_init(0), gmp_init(1), gmp_init(1), gmp_init(0)];
        $base = [clone self::$bx, clone self::$by, gmp_init(1), self::mod(gmp_mul(self::$bx, self::$by))];

        $k = $scalar;
        // LSB-first double-and-add over all bits of the scalar.
        $bits = gmp_strval($k, 2);
        $n = strlen($bits);
        for ($i = 0; $i < $n; $i++) {
            // bit i (from LSB) == $bits[$n-1-$i]
            if ($bits[$n - 1 - $i] === '1') {
                $result = self::pointAdd($result, $base);
            }
            if ($i !== $n - 1) {
                $base = self::pointDouble($base);
            }
        }

        [$x, $y, $z] = $result;
        $zinv = gmp_invert($z, self::$p);
        $ax = self::mod(gmp_mul($x, $zinv));
        $ay = self::mod(gmp_mul($y, $zinv));

        $yBytes = self::gmpToLittleEndian($ay, 32);
        if (gmp_testbit($ax, 0)) { // sign bit = LSB of x
            $yBytes[31] = chr(ord($yBytes[31]) | 0x80);
        }
        return $yBytes;
    }

    public static function gmpToLittleEndian(\GMP $n, int $len): string
    {
        $bytes = gmp_export($n, 1, GMP_LSW_FIRST);
        return str_pad($bytes, $len, "\0", STR_PAD_RIGHT);
    }

    public static function littleEndianToGmp(string $bytes): \GMP
    {
        if ($bytes === '') {
            return gmp_init(0);
        }
        return gmp_import($bytes, 1, GMP_LSW_FIRST);
    }

    /** Reduce a (usually 64-byte) little-endian integer modulo L; return 32-byte LE. */
    public static function reduceModL(string $bytes): string
    {
        self::init();
        $v = gmp_mod(self::littleEndianToGmp($bytes), self::$l);
        return self::gmpToLittleEndian($v, 32);
    }

    public static function clampScalar(string $sk32): string
    {
        $a = $sk32;
        $a[0] = chr(ord($a[0]) & 248);
        $a[31] = chr((ord($a[31]) & 127) | 64);
        return $a;
    }

    /** Convert an X25519 (Montgomery u) public key to an Ed25519 public key with the given sign bit. */
    public static function montgomeryToEdwardsPublic(string $uBytes32, int $signBit): string
    {
        self::init();
        $u = gmp_mod(self::littleEndianToGmp($uBytes32), self::$p);
        $one = gmp_init(1);
        $y = self::mod(gmp_mul(gmp_sub($u, $one), gmp_invert(gmp_add($u, $one), self::$p)));
        $yBytes = self::gmpToLittleEndian($y, 32);
        $yBytes[31] = chr($signBit ? (ord($yBytes[31]) | 0x80) : (ord($yBytes[31]) & 0x7f));
        return $yBytes;
    }
}
