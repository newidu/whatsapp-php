<?php
declare(strict_types=1);

namespace BaileysPHP\Proto;

/** Protobuf wire-format reader. See ProtoWriter for overview. */
final class ProtoReader
{
    /** @var array<int, list<array{wire:int,value:mixed}>> */
    public array $fields = [];

    private int $pos = 0;

    private function __construct(private string $buf) {}

    public static function parse(string $buf): self
    {
        $r = new self($buf);
        $len = strlen($buf);
        while ($r->pos < $len) {
            $tag = $r->readVarint();
            $field = (int)gmp_div_q($tag, 8);
            $wire = (int)gmp_mod($tag, 8);
            if ($field === 0) {
                break;
            }
            switch ($wire) {
                case 0:
                    $v = $r->readVarint();
                    $r->fields[$field][] = ['wire' => 0, 'value' => self::gmpToPhpInt($v)];
                    break;
                case 2:
                    $l = (int)self::gmpToPhpInt($r->readVarint());
                    $v = substr($r->buf, $r->pos, $l);
                    $r->pos += $l;
                    $r->fields[$field][] = ['wire' => 2, 'value' => $v];
                    break;
                case 5:
                    $v = substr($r->buf, $r->pos, 4);
                    $r->pos += 4;
                    $r->fields[$field][] = ['wire' => 5, 'value' => strlen($v) === 4 ? unpack('V', $v)[1] : 0];
                    break;
                case 1:
                    $v = substr($r->buf, $r->pos, 8);
                    $r->pos += 8;
                    $r->fields[$field][] = ['wire' => 1, 'value' => $v];
                    break;
                default:
                    throw new \RuntimeException("Unsupported protobuf wire type $wire (field $field)");
            }
        }
        return $r;
    }

    private function readVarint(): \GMP
    {
        $result = gmp_init(0);
        $shift = 0;
        $len = strlen($this->buf);
        while ($this->pos < $len) {
            $b = ord($this->buf[$this->pos++]);
            $result = gmp_or($result, gmp_mul($b & 0x7f, gmp_pow(2, $shift)));
            if (($b & 0x80) === 0) {
                break;
            }
            $shift += 7;
            if ($shift > 70) {
                throw new \RuntimeException('varint too long');
            }
        }
        return $result;
    }

    private static function gmpToPhpInt(\GMP $v): int
    {
        // Values > PHP_INT_MAX wrap (timestamps & phone numbers fit in signed 64)
        if (gmp_cmp($v, gmp_pow(2, 63)) >= 0) {
            $v = gmp_sub($v, gmp_pow(2, 64));
        }
        return (int)gmp_strval($v);
    }

    public function getBytes(int $field): ?string
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @return list<string> */
    public function getAllBytes(int $field): array
    {
        $out = [];
        foreach ($this->fields[$field] ?? [] as $e) {
            if (is_string($e['value'])) {
                $out[] = $e['value'];
            }
        }
        return $out;
    }

    public function getString(int $field): ?string
    {
        return $this->getBytes($field);
    }

    public function getVarint(int $field): ?int
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_int($v) ? $v : null;
    }

    /** @return list<int> */
    public function getAllVarints(int $field): array
    {
        $out = [];
        foreach ($this->fields[$field] ?? [] as $e) {
            if (is_int($e['value'])) {
                $out[] = $e['value'];
            }
        }
        return $out;
    }

    public function getBool(int $field): ?bool
    {
        $v = $this->getVarint($field);
        return $v === null ? null : $v !== 0;
    }

    public function getMessage(int $field): ?self
    {
        $b = $this->getBytes($field);
        return $b === null ? null : self::parse($b);
    }

    public function getFixed32(int $field): ?int
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_string($v) && strlen($v) === 4 ? unpack('V', $v)[1] : (is_int($v) ? $v : null);
    }

    public function getFloat(int $field): ?float
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_string($v) && strlen($v) === 4 ? unpack('g', $v)[1] : null;
    }

    public function getFixed64(int $field): ?int
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_string($v) && strlen($v) === 8 ? unpack('P', $v)[1] : null;
    }

    public function getDouble(int $field): ?float
    {
        $v = $this->fields[$field][0]['value'] ?? null;
        return is_string($v) && strlen($v) === 8 ? unpack('e', $v)[1] : null;
    }

    public function has(int $field): bool
    {
        return isset($this->fields[$field]);
    }
}
