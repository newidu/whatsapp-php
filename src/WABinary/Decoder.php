<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/** Port of Baileys: src/WABinary/decode.ts */
final class Decoder
{
    private int $index = 0;

    private function __construct(private string $buffer) {}

    public static function decodeBinaryNode(string $buff): BinaryNode
    {
        $decomp = self::decompressingIfRequired($buff);
        $d = new self($decomp);
        return $d->decodeNode();
    }

    public static function decompressingIfRequired(string $buffer): string
    {
        if ($buffer === '') {
            throw new \RuntimeException('empty buffer');
        }
        if (2 & ord($buffer[0])) {
            $out = @zlib_decode(substr($buffer, 1)); // zlib (RFC1950) like Node inflate
            if ($out === false) {
                $out = @gzinflate(substr($buffer, 1));
            }
            if ($out === false) {
                throw new \RuntimeException('failed to inflate binary node');
            }
            return $out;
        }
        return substr($buffer, 1);
    }

    private function checkEOS(int $length): void
    {
        if ($this->index + $length > strlen($this->buffer)) {
            throw new \RuntimeException('end of stream');
        }
    }

    private function nextByte(): int
    {
        $v = ord($this->buffer[$this->index]);
        $this->index++;
        return $v;
    }

    private function readByte(): int
    {
        $this->checkEOS(1);
        return $this->nextByte();
    }

    private function readBytes(int $n): string
    {
        $this->checkEOS($n);
        $v = substr($this->buffer, $this->index, $n);
        $this->index += $n;
        return $v;
    }

    private function readInt(int $n, bool $littleEndian = false): int
    {
        $this->checkEOS($n);
        $val = 0;
        for ($i = 0; $i < $n; $i++) {
            $shift = $littleEndian ? $i : $n - 1 - $i;
            $val |= $this->nextByte() << ($shift * 8);
        }
        return $val;
    }

    private function readInt20(): int
    {
        $this->checkEOS(3);
        return (($this->nextByte() & 15) << 16) + ($this->nextByte() << 8) + $this->nextByte();
    }

    private function unpackHex(int $value): int
    {
        if ($value >= 0 && $value < 16) {
            return $value < 10 ? ord('0') + $value : ord('A') + $value - 10;
        }
        throw new \RuntimeException('invalid hex: ' . $value);
    }

    private function unpackNibble(int $value): int
    {
        if ($value >= 0 && $value <= 9) {
            return ord('0') + $value;
        }
        return match ($value) {
            10 => ord('-'),
            11 => ord('.'),
            15 => 0,
            default => throw new \RuntimeException('invalid nibble: ' . $value),
        };
    }

    private function unpackByte(int $tag, int $value): int
    {
        if ($tag === Constants::TAGS['NIBBLE_8']) {
            return $this->unpackNibble($value);
        }
        if ($tag === Constants::TAGS['HEX_8']) {
            return $this->unpackHex($value);
        }
        throw new \RuntimeException('unknown tag: ' . $tag);
    }

    private function readPacked8(int $tag): string
    {
        $start = $this->readByte();
        $value = '';
        for ($i = 0; $i < ($start & 127); $i++) {
            $cur = $this->readByte();
            $value .= chr($this->unpackByte($tag, ($cur & 0xf0) >> 4));
            $value .= chr($this->unpackByte($tag, $cur & 0x0f));
        }
        if (($start >> 7) !== 0) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function isListTag(int $tag): bool
    {
        return $tag === Constants::TAGS['LIST_EMPTY'] || $tag === Constants::TAGS['LIST_8'] || $tag === Constants::TAGS['LIST_16'];
    }

    private function readListSize(int $tag): int
    {
        return match ($tag) {
            Constants::TAGS['LIST_EMPTY'] => 0,
            Constants::TAGS['LIST_8'] => $this->readByte(),
            Constants::TAGS['LIST_16'] => $this->readInt(2),
            default => throw new \RuntimeException('invalid tag for list size: ' . $tag),
        };
    }

    private function readJidPair(): string
    {
        $i = $this->readString($this->readByte());
        $j = $this->readString($this->readByte());
        if ($j !== '') {
            return $i . '@' . $j;
        }
        throw new \RuntimeException("invalid jid pair: $i, $j");
    }

    private function readAdJid(): string
    {
        $domainType = $this->readByte();
        $device = $this->readByte();
        $user = $this->readString($this->readByte());
        $server = 's.whatsapp.net';
        if ($domainType === Jid::DOMAIN_LID) {
            $server = 'lid';
        } elseif ($domainType === Jid::DOMAIN_HOSTED) {
            $server = 'hosted';
        } elseif ($domainType === Jid::DOMAIN_HOSTED_LID) {
            $server = 'hosted.lid';
        }
        return Jid::encode($user, $server, $device);
    }

    private function readFbJid(): string
    {
        $user = $this->readString($this->readByte());
        $device = $this->readInt(2);
        $server = $this->readString($this->readByte());
        return "$user:$device@$server";
    }

    private function readInteropJid(): string
    {
        $user = $this->readString($this->readByte());
        $device = $this->readInt(2);
        $integrator = $this->readInt(2);
        $server = 'interop';
        $before = $this->index;
        try {
            $server = $this->readString($this->readByte());
        } catch (\Throwable) {
            $this->index = $before;
        }
        return "$integrator-$user:$device@$server";
    }

    private function getTokenDouble(int $index1, int $index2): string
    {
        $dict = Constants::DOUBLE_BYTE_TOKENS[$index1] ?? null;
        if ($dict === null) {
            throw new \RuntimeException("Invalid double token dict ($index1)");
        }
        $value = $dict[$index2] ?? null;
        if ($value === null) {
            throw new \RuntimeException("Invalid double token ($index2)");
        }
        return $value;
    }

    private function readString(int $tag): string
    {
        if ($tag >= 1 && $tag < count(Constants::SINGLE_BYTE_TOKENS)) {
            return Constants::SINGLE_BYTE_TOKENS[$tag] ?? '';
        }
        return match ($tag) {
            Constants::TAGS['DICTIONARY_0'], Constants::TAGS['DICTIONARY_1'], Constants::TAGS['DICTIONARY_2'], Constants::TAGS['DICTIONARY_3']
                => $this->getTokenDouble($tag - Constants::TAGS['DICTIONARY_0'], $this->readByte()),
            Constants::TAGS['LIST_EMPTY'] => '',
            Constants::TAGS['BINARY_8'] => $this->readBytes($this->readByte()),
            Constants::TAGS['BINARY_20'] => $this->readBytes($this->readInt20()),
            Constants::TAGS['BINARY_32'] => $this->readBytes($this->readInt(4)),
            Constants::TAGS['JID_PAIR'] => $this->readJidPair(),
            Constants::TAGS['FB_JID'] => $this->readFbJid(),
            Constants::TAGS['INTEROP_JID'] => $this->readInteropJid(),
            Constants::TAGS['AD_JID'] => $this->readAdJid(),
            Constants::TAGS['HEX_8'], Constants::TAGS['NIBBLE_8'] => $this->readPacked8($tag),
            default => throw new \RuntimeException('invalid string with tag: ' . $tag),
        };
    }

    /** @return list<BinaryNode> */
    private function readList(int $tag): array
    {
        $items = [];
        $size = $this->readListSize($tag);
        for ($i = 0; $i < $size; $i++) {
            $items[] = $this->decodeNode();
        }
        return $items;
    }

    private function decodeNode(): BinaryNode
    {
        $listSize = $this->readListSize($this->readByte());
        $header = $this->readString($this->readByte());
        if (!$listSize || $header === '') {
            throw new \RuntimeException('invalid node');
        }
        $attrs = [];
        $data = null;
        $attrsLen = intdiv($listSize - 1, 2);
        for ($i = 0; $i < $attrsLen; $i++) {
            $key = $this->readString($this->readByte());
            $value = $this->readString($this->readByte());
            $attrs[$key] = $value;
        }
        if ($listSize % 2 === 0) {
            $tag = $this->readByte();
            if ($this->isListTag($tag)) {
                $data = $this->readList($tag);
            } else {
                $data = match ($tag) {
                    Constants::TAGS['BINARY_8'] => new Bytes($this->readBytes($this->readByte())),
                    Constants::TAGS['BINARY_20'] => new Bytes($this->readBytes($this->readInt20())),
                    Constants::TAGS['BINARY_32'] => new Bytes($this->readBytes($this->readInt(4))),
                    default => $this->readString($tag),
                };
            }
        }
        return new BinaryNode($header, $attrs, $data);
    }
}
