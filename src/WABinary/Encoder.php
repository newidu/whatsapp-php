<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/** Port of Baileys: src/WABinary/encode.ts (encodeBinaryNode) */
final class Encoder
{
    private string $buffer = "\0"; // nodes start with 0x00 (no compression)

    public static function encodeBinaryNode(BinaryNode $node): string
    {
        $e = new self();
        $e->encodeInner($node);
        return $e->buffer;
    }

    private function pushByte(int $value): void
    {
        $this->buffer .= chr($value & 0xff);
    }

    private function pushBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    private function pushInt(int $value, int $n, bool $littleEndian = false): void
    {
        for ($i = 0; $i < $n; $i++) {
            $shift = $littleEndian ? $i : $n - 1 - $i;
            $this->buffer .= chr(($value >> ($shift * 8)) & 0xff);
        }
    }

    private function pushInt16(int $value): void
    {
        $this->pushBytes(chr(($value >> 8) & 0xff) . chr($value & 0xff));
    }

    private function pushInt20(int $value): void
    {
        $this->pushBytes(chr(($value >> 16) & 0x0f) . chr(($value >> 8) & 0xff) . chr($value & 0xff));
    }

    private function writeByteLength(int $length): void
    {
        if ($length >= 4294967296) {
            throw new \RuntimeException('string too large to encode: ' . $length);
        }
        if ($length >= (1 << 20)) {
            $this->pushByte(Constants::TAGS['BINARY_32']);
            $this->pushInt($length, 4);
        } elseif ($length >= 256) {
            $this->pushByte(Constants::TAGS['BINARY_20']);
            $this->pushInt20($length);
        } else {
            $this->pushByte(Constants::TAGS['BINARY_8']);
            $this->pushByte($length);
        }
    }

    private function writeStringRaw(string $str): void
    {
        $this->writeByteLength(strlen($str));
        $this->pushBytes($str);
    }

    /** @param array{server:string,user:string,domainType:int,device:?int} $jid */
    private function writeJid(array $jid): void
    {
        if ($jid['device'] !== null) {
            $this->pushByte(Constants::TAGS['AD_JID']);
            $this->pushByte($jid['domainType'] ?? 0);
            $this->pushByte($jid['device'] ?? 0);
            $this->writeString($jid['user']);
        } else {
            $this->pushByte(Constants::TAGS['JID_PAIR']);
            if ($jid['user'] !== '') {
                $this->writeString($jid['user']);
            } else {
                $this->pushByte(Constants::TAGS['LIST_EMPTY']);
            }
            $this->writeString($jid['server']);
        }
    }

    private function packNibble(string $char): int
    {
        return match ($char) {
            '-' => 10,
            '.' => 11,
            "\0" => 15,
            default => ($char >= '0' && $char <= '9')
                ? ord($char) - ord('0')
                : throw new \RuntimeException("invalid byte for nibble \"$char\""),
        };
    }

    private function packHex(string $char): int
    {
        if ($char >= '0' && $char <= '9') {
            return ord($char) - ord('0');
        }
        if ($char >= 'A' && $char <= 'F') {
            return 10 + ord($char) - ord('A');
        }
        if ($char >= 'a' && $char <= 'f') {
            return 10 + ord($char) - ord('a');
        }
        if ($char === "\0") {
            return 15;
        }
        throw new \RuntimeException("Invalid hex char \"$char\"");
    }

    private function writePackedBytes(string $str, string $type): void
    {
        if (strlen($str) > Constants::TAGS['PACKED_MAX']) {
            throw new \RuntimeException('Too many bytes to pack');
        }
        $this->pushByte($type === 'nibble' ? Constants::TAGS['NIBBLE_8'] : Constants::TAGS['HEX_8']);
        $rounded = (int)ceil(strlen($str) / 2.0);
        if (strlen($str) % 2 !== 0) {
            $rounded |= 128;
        }
        $this->pushByte($rounded);
        $pack = fn(string $c): int => $type === 'nibble' ? $this->packNibble($c) : $this->packHex($c);
        $half = intdiv(strlen($str), 2);
        for ($i = 0; $i < $half; $i++) {
            $this->pushByte(($pack($str[2 * $i]) << 4) | $pack($str[2 * $i + 1]));
        }
        if (strlen($str) % 2 !== 0) {
            $this->pushByte(($pack($str[strlen($str) - 1]) << 4) | $pack("\0"));
        }
    }

    private function isNibble(?string $str): bool
    {
        if (!$str || strlen($str) > Constants::TAGS['PACKED_MAX']) {
            return false;
        }
        for ($i = 0, $n = strlen($str); $i < $n; $i++) {
            $c = $str[$i];
            if (!(($c >= '0' && $c <= '9') || $c === '-' || $c === '.')) {
                return false;
            }
        }
        return true;
    }

    private function isHex(?string $str): bool
    {
        if (!$str || strlen($str) > Constants::TAGS['PACKED_MAX']) {
            return false;
        }
        for ($i = 0, $n = strlen($str); $i < $n; $i++) {
            $c = $str[$i];
            if (!(($c >= '0' && $c <= '9') || ($c >= 'A' && $c <= 'F'))) {
                return false;
            }
        }
        return true;
    }

    private function writeString(?string $str): void
    {
        if ($str === null) {
            $this->pushByte(Constants::TAGS['LIST_EMPTY']);
            return;
        }
        if ($str === '') {
            $this->writeStringRaw($str);
            return;
        }
        $tokenMap = Constants::tokenMap();
        $token = $tokenMap[$str] ?? null;
        // NOTE: upstream checks `if (tokenIndex)` — index 0 == '' handled above.
        if ($token !== null && ($token['index'] !== 0 || $token['dict'] !== null)) {
            if ($token['dict'] !== null) {
                $this->pushByte(Constants::TAGS['DICTIONARY_0'] + $token['dict']);
            }
            $this->pushByte($token['index']);
        } elseif ($this->isNibble($str)) {
            $this->writePackedBytes($str, 'nibble');
        } elseif ($this->isHex($str)) {
            $this->writePackedBytes($str, 'hex');
        } else {
            $decoded = Jid::decode($str);
            if ($decoded !== null) {
                $this->writeJid($decoded);
            } else {
                $this->writeStringRaw($str);
            }
        }
    }

    private function writeListStart(int $listSize): void
    {
        if ($listSize === 0) {
            $this->pushByte(Constants::TAGS['LIST_EMPTY']);
        } elseif ($listSize < 256) {
            $this->pushBytes(chr(Constants::TAGS['LIST_8']) . chr($listSize));
        } else {
            $this->pushByte(Constants::TAGS['LIST_16']);
            $this->pushInt16($listSize);
        }
    }

    private function encodeInner(BinaryNode $node): void
    {
        if ($node->tag === '') {
            throw new \RuntimeException('Invalid node: tag cannot be undefined');
        }
        $validAttrs = [];
        foreach ($node->attrs as $k => $v) {
            if ($v !== null) {
                $validAttrs[] = $k;
            }
        }
        $hasContent = $node->content !== null;
        $this->writeListStart(2 * count($validAttrs) + 1 + ($hasContent ? 1 : 0));
        $this->writeString($node->tag);
        foreach ($validAttrs as $key) {
            if (is_string($node->attrs[$key])) {
                $this->writeString($key);
                $this->writeString($node->attrs[$key]);
            }
        }
        $content = $node->content;
        if (is_string($content)) {
            $this->writeString($content);
        } elseif ($content instanceof Bytes) {
            $this->writeByteLength(strlen($content->data));
            $this->pushBytes($content->data);
        } elseif (is_array($content)) {
            $valid = array_values(array_filter($content, fn($i) => $i instanceof BinaryNode));
            $this->writeListStart(count($valid));
            foreach ($valid as $item) {
                $this->encodeInner($item);
            }
        } elseif ($content === null) {
            // do nothing
        } else {
            throw new \RuntimeException("invalid children for header \"{$node->tag}\"");
        }
    }
}
