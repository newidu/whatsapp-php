<?php
declare(strict_types=1);

namespace BaileysPHP\Proto;

/**
 * Minimal pure-PHP Protobuf (proto2/proto3 wire format) reader/writer.
 *
 * Enough for every WAProto message Baileys-Basic touches:
 * varint (uint/int/enum/bool), length-delimited (bytes/string/message),
 * 32-bit (sfixed32) and 64-bit wire types. No .proto compiler, no extensions.
 * 64-bit varints are done via GMP so negative int32 / large uint64 stay correct.
 */
final class ProtoWriter
{
    private string $buf = '';

    public function varintValue(int|\GMP $value): self
    {
        $v = $value instanceof \GMP ? $value : gmp_init($value);
        if (gmp_sign($v) < 0) {
            $v = gmp_add($v, gmp_pow(2, 64)); // two's complement 64-bit
        }
        while (true) {
            $byte = (int)gmp_mod($v, 128);
            $v = gmp_div_q($v, 128);
            if (gmp_sign($v) === 0) {
                $this->buf .= chr($byte);
                break;
            }
            $this->buf .= chr($byte | 0x80);
        }
        return $this;
    }

    private function tag(int $field, int $wire): self
    {
        return $this->varintValue(($field << 3) | $wire);
    }

    /** uint32/uint64/int32/int64/enum */
    public function varint(int $field, int|\GMP|null $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 0);
        return $this->varintValue($value);
    }

    public function bool(int $field, ?bool $value): self
    {
        if ($value === null) {
            return $this;
        }
        return $this->varint($field, $value ? 1 : 0);
    }

    public function bytes(int $field, ?string $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 2);
        $this->varintValue(strlen($value));
        $this->buf .= $value;
        return $this;
    }

    public function string(int $field, ?string $value): self
    {
        return $this->bytes($field, $value);
    }

    /** Embedded message, supplied already-encoded. */
    public function message(int $field, ?string $encoded): self
    {
        return $this->bytes($field, $encoded);
    }

    /** sfixed32/fixed32 (wire type 5, little-endian). */
    public function fixed32(int $field, ?int $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 5);
        $this->buf .= pack('V', $value);
        return $this;
    }

    /** float (wire type 5, little-endian IEEE-754). */
    public function float(int $field, float|int|null $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 5);
        $this->buf .= pack('g', (float)$value);
        return $this;
    }

    /** sfixed64/fixed64 (wire type 1, little-endian). */
    public function fixed64(int $field, int|null $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 1);
        $this->buf .= pack('P', $value);
        return $this;
    }

    /** double (wire type 1, little-endian IEEE-754). */
    public function double(int $field, float|int|null $value): self
    {
        if ($value === null) {
            return $this;
        }
        $this->tag($field, 1);
        $this->buf .= pack('e', (float)$value);
        return $this;
    }

    /** sint32/sint64 (zigzag varint). */
    public function sint(int $field, int|null $value): self
    {
        if ($value === null) {
            return $this;
        }
        $zz = ($value << 1) ^ ($value >> 63);
        return $this->varint($field, $zz);
    }

    /** Append raw pre-encoded bytes (packed repeated fields etc.). */
    public function raw(string $bytes): self
    {
        $this->buf .= $bytes;
        return $this;
    }

    public function finish(): string
    {
        return $this->buf;
    }
}

