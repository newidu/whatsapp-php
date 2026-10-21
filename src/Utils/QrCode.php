<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Pure-PHP QR Code generator (byte mode, versions 1–40) for displaying the
 * WhatsApp pairing QR in the terminal / as SVG / PNG — no extensions needed.
 *
 * This is a port of Project Nayuki's QR Code generator algorithm
 * (https://www.nayuki.io/page/qr-code-generator-library), MIT License:
 * Copyright (c) Project Nayuki. Permission is hereby granted, free of charge,
 * to any person obtaining a copy of this software and associated documentation
 * files, to deal in the Software without restriction, including the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, subject to including this notice in copies/substantial portions.
 */
final class QrCode
{
    public const ECC_LOW = 0;
    public const ECC_MEDIUM = 1;
    public const ECC_QUARTILE = 2;
    public const ECC_HIGH = 3;

    /** formatbits per ECC ordinal (L,M,Q,H) */
    private const ECC_FORMAT_BITS = [1, 0, 3, 2];

    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private int $size;
    private int $mask;
    /** @var list<list<bool>> */
    private array $modules;
    /** @var list<list<bool>> */
    private array $isFunction;

    /** Encode raw bytes/text (byte mode). Automatically picks smallest version. */
    public static function encodeText(string $data, int $ecl = self::ECC_LOW): self
    {
        $len = strlen($data);
        $version = null;
        $dataUsedBits = null;
        for ($v = 1; $v <= 40; $v++) {
            $ccbits = [8, 16, 16][intdiv($v + 7, 17)];
            $used = $len >= (1 << $ccbits) ? null : 4 + $ccbits + $len * 8;
            $cap = self::getNumDataCodewords($v, $ecl) * 8;
            if ($used !== null && $used <= $cap) {
                $version = $v;
                $dataUsedBits = $used;
                break;
            }
            if ($v >= 40) {
                throw new \RuntimeException('QR data too long');
            }
        }
        // Boost ECC level while data still fits
        foreach ([self::ECC_MEDIUM, self::ECC_QUARTILE, self::ECC_HIGH] as $newEcl) {
            if ($dataUsedBits <= self::getNumDataCodewords($version, $newEcl) * 8) {
                $ecl = $newEcl;
            }
        }
        // Build bit buffer: mode(0100) + char count + bytes
        $bb = [];
        self::appendBits($bb, 0x4, 4);
        self::appendBits($bb, $len, [8, 16, 16][intdiv($version + 7, 17)]);
        for ($i = 0; $i < $len; $i++) {
            self::appendBits($bb, ord($data[$i]), 8);
        }
        $capBits = self::getNumDataCodewords($version, $ecl) * 8;
        self::appendBits($bb, 0, min(4, $capBits - count($bb)));
        self::appendBits($bb, 0, (8 - count($bb) % 8) % 8);
        for ($pad = 0xEC; count($bb) < $capBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bb, $pad, 8);
        }
        $dataCodewords = array_fill(0, intdiv(count($bb), 8), 0);
        foreach ($bb as $i => $bit) {
            $dataCodewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }
        return new self($version, $ecl, $dataCodewords, -1);
    }

    /** @param list<int> $bb */
    private static function appendBits(array &$bb, int $val, int $n): void
    {
        for ($i = $n - 1; $i >= 0; $i--) {
            $bb[] = ($val >> $i) & 1;
        }
    }

    /** @param list<int> $datacodewords */
    private function __construct(private int $version, private int $ecl, array $datacodewords, int $msk)
    {
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->drawFunctionPatterns();
        $all = $this->addEccAndInterleave($datacodewords);
        $this->drawCodewords($all);
        if ($msk === -1) {
            $minPenalty = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $this->applyMask($i);
                $this->drawFormatBits($i);
                $penalty = $this->getPenaltyScore();
                if ($penalty < $minPenalty) {
                    $msk = $i;
                    $minPenalty = $penalty;
                }
                $this->applyMask($i);
            }
        }
        $this->mask = $msk;
        $this->applyMask($msk);
        $this->drawFormatBits($msk);
        $this->isFunction = [];
    }

    public function getVersion(): int { return $this->version; }
    public function getSize(): int { return $this->size; }
    public function getMask(): int { return $this->mask; }
    public function getErrorCorrectionLevel(): int { return $this->ecl; }

    public function getModule(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[$y][$x];
    }

    // ---- drawing ----
    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);
        $align = $this->getAlignmentPatternPositions();
        $num = count($align);
        $skips = [[0, 0], [0, $num - 1], [$num - 1, 0]];
        for ($i = 0; $i < $num; $i++) {
            for ($j = 0; $j < $num; $j++) {
                if (!in_array([$i, $j], $skips, true)) {
                    $this->drawAlignmentPattern($align[$i], $align[$j]);
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::ECC_FORMAT_BITS[$this->ecl] << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        for ($i = 0; $i < 6; $i++) {
            $this->setFunctionModule(8, $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::getBit($bits, 6));
        $this->setFunctionModule(8, 8, self::getBit($bits, 7));
        $this->setFunctionModule(7, 8, self::getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = $this->version << 12 | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = self::getBit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }

    private function drawFinderPattern(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunctionModule($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignmentPattern(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function setFunctionModule(int $x, int $y, bool $isDark): void
    {
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecl][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecl][$this->version];
        $rawCodewords = intdiv(self::getNumRawDataModules($this->version), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);
        $blocks = [];
        $rsdiv = self::reedSolomonComputeDivisor($blockEccLen);
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $blockEccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::reedSolomonComputeRemainder($dat, $rsdiv);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $blk) {
                if ($i !== $shortLen - $blockEccLen || $j >= $numShort) {
                    $result[] = $blk[$i];
                }
            }
        }
        return $result;
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            $r = $right;
            if ($r <= 6) {
                $r -= 1;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $r - $j;
                    $upward = (($r + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < count($data) * 8) {
                        $this->modules[$y][$x] = self::getBit($data[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2,
                    1 => $y % 2,
                    2 => $x % 3,
                    3 => ($x + $y) % 3,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2,
                    5 => $x * $y % 2 + $x * $y % 3,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2,
                };
                $flip = $invert === 0 && !$this->isFunction[$y][$x];
                $this->modules[$y][$x] = $flip ? !$this->modules[$y][$x] : $this->modules[$y][$x];
            }
        }
    }

    private function getPenaltyScore(): int
    {
        $result = 0;
        $size = $this->size;
        // rows
        for ($y = 0; $y < $size; $y++) {
            $runColor = false;
            $runX = 0;
            $hist = [0, 0, 0, 0, 0, 0, 0];
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runX++;
                    if ($runX === 5) $result += 3;
                    elseif ($runX > 5) $result += 1;
                } else {
                    $this->finderPenaltyAddHistory($runX, $hist);
                    if (!$runColor) $result += $this->finderPenaltyCountPatterns($hist) * 40;
                    $runColor = $this->modules[$y][$x];
                    $runX = 1;
                }
            }
            $result += $this->finderPenaltyTerminate($runColor, $runX, $hist) * 40;
        }
        // columns
        for ($x = 0; $x < $size; $x++) {
            $runColor = false;
            $runY = 0;
            $hist = [0, 0, 0, 0, 0, 0, 0];
            for ($y = 0; $y < $size; $y++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runY++;
                    if ($runY === 5) $result += 3;
                    elseif ($runY > 5) $result += 1;
                } else {
                    $this->finderPenaltyAddHistory($runY, $hist);
                    if (!$runColor) $result += $this->finderPenaltyCountPatterns($hist) * 40;
                    $runColor = $this->modules[$y][$x];
                    $runY = 1;
                }
            }
            $result += $this->finderPenaltyTerminate($runColor, $runY, $hist) * 40;
        }
        // 2x2 blocks
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($this->modules[$y][$x] === $this->modules[$y][$x + 1]
                    && $this->modules[$y][$x] === $this->modules[$y + 1][$x]
                    && $this->modules[$y][$x] === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }
        // balance
        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $cell) {
                if ($cell) $dark++;
            }
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $result += $k * 10;
        return $result;
    }

    private function finderPenaltyCountPatterns(array $hist): int
    {
        $n = $hist[1];
        $core = $n > 0 && $hist[2] === $n && $hist[3] === $n * 3 && $hist[4] === $n && $hist[5] === $n;
        return ($core && $hist[0] >= $n * 4 && $hist[6] >= $n ? 1 : 0)
            + ($core && $hist[6] >= $n * 4 && $hist[0] >= $n ? 1 : 0);
    }

    private function finderPenaltyTerminate(bool $runColor, int $runLen, array &$hist): int
    {
        if ($runColor) {
            $this->finderPenaltyAddHistory($runLen, $hist);
            $runLen = 0;
        }
        $runLen += $this->size;
        $this->finderPenaltyAddHistory($runLen, $hist);
        return $this->finderPenaltyCountPatterns($hist);
    }

    private function finderPenaltyAddHistory(int $runLen, array &$hist): void
    {
        if ($hist[0] === 0) {
            $runLen += $this->size;
        }
        array_unshift($hist, $runLen);
        $hist = array_slice($hist, 0, 7);
    }

    /** @return list<int> */
    private function getAlignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [];
        for ($i = 0; $i < $numAlign - 1; $i++) {
            $result[] = $this->size - 7 - $i * $step;
        }
        $result[] = 6;
        return array_reverse($result);
    }

    private static function getNumRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function getNumDataCodewords(int $ver, int $ecl): int
    {
        return intdiv(self::getNumRawDataModules($ver), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecl][$ver] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$ver];
    }

    /** @return list<int> */
    private static function reedSolomonComputeDivisor(int $degree): array
    {
        $result = array_merge(array_fill(0, $degree - 1, 0), [1]);
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::reedSolomonMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::reedSolomonMultiply($root, 0x02);
        }
        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private static function reedSolomonComputeRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::reedSolomonMultiply($coef, $factor);
            }
        }
        return $result;
    }

    private static function reedSolomonMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function getBit(int $x, int $i): bool
    {
        return (($x >> $i) & 1) !== 0;
    }

    // ---- rendering ----

    /** ANSI-colour terminal rendering (true black-on-white, scannable from screen). */
    public function toTerminalString(int $border = 2): string
    {
        $out = '';
        for ($y = -$border; $y < $this->size + $border; $y++) {
            for ($x = -$border; $x < $this->size + $border; $x++) {
                $out .= $this->getModule($x, $y) ? "\e[40m  " : "\e[47m  ";
            }
            $out .= "\e[0m\n";
        }
        return $out;
    }

    /** Plain (no ANSI) rendering using blocks — dark text on light terminals works best. */
    public function toBlockString(int $border = 2): string
    {
        $out = '';
        for ($y = -$border; $y < $this->size + $border; $y++) {
            for ($x = -$border; $x < $this->size + $border; $x++) {
                $out .= $this->getModule($x, $y) ? '██' : '  ';
            }
            $out .= "\n";
        }
        return $out;
    }

    public function toSvgString(int $border = 4, string $dark = '#000000', string $light = '#FFFFFF'): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n"
            . "<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.1\" viewBox=\"0 0 $dim $dim\" stroke=\"none\">"
            . "<rect width=\"$dim\" height=\"$dim\" fill=\"$light\"/>"
            . "<path d=\"$path\" fill=\"$dark\"/></svg>\n";
    }

    /** Write an SVG file you can open in any browser to scan. */
    public function saveSvg(string $path, int $border = 4): void
    {
        file_put_contents($path, $this->toSvgString($border));
    }

    /** Write a PNG file (requires the GD extension, bundled with XAMPP). */
    public function savePng(string $path, int $scale = 8, int $border = 4): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension not available — use saveSvg() instead');
        }
        $dim = ($this->size + $border * 2) * $scale;
        $img = imagecreatetruecolor($dim, $dim);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    imagefilledrectangle(
                        $img,
                        ($x + $border) * $scale,
                        ($y + $border) * $scale,
                        ($x + $border + 1) * $scale - 1,
                        ($y + $border + 1) * $scale - 1,
                        $black
                    );
                }
            }
        }
        imagepng($img, $path);
        imagedestroy($img);
    }
}
