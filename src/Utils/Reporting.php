<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;

/**
 * Reporting tokens — port of Baileys src/Utils/reporting-utils.ts.
 * Builds the <reporting><reporting_token/></reporting> stanza child.
 */
final class Reporting
{
    private const FIELDS = [
        ['f' => 1],
        ['f' => 3, 's' => [['f' => 2], ['f' => 3], ['f' => 8], ['f' => 11], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 25]]],
        ['f' => 4, 's' => [['f' => 1], ['f' => 16], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 5, 's' => [['f' => 3], ['f' => 4], ['f' => 5], ['f' => 16], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 6, 's' => [['f' => 1], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 30]]],
        ['f' => 7, 's' => [['f' => 2], ['f' => 7], ['f' => 10], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 20]]],
        ['f' => 8, 's' => [['f' => 2], ['f' => 7], ['f' => 9], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 21]]],
        ['f' => 9, 's' => [['f' => 2], ['f' => 6], ['f' => 7], ['f' => 13], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 20]]],
        ['f' => 12, 's' => [['f' => 1], ['f' => 2], ['f' => 14, 'm' => true], ['f' => 15]]],
        ['f' => 18, 's' => [['f' => 6], ['f' => 16], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 26, 's' => [['f' => 4], ['f' => 5], ['f' => 8], ['f' => 13], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 28, 's' => [['f' => 1], ['f' => 2], ['f' => 4], ['f' => 5], ['f' => 6], ['f' => 7, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 37, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 49, 's' => [['f' => 2], ['f' => 3, 's' => [['f' => 1], ['f' => 2]]], ['f' => 5, 's' => [['f' => 21], ['f' => 22]]], ['f' => 8, 's' => [['f' => 1], ['f' => 2]]]]],
        ['f' => 53, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 55, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 58, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 59, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 60, 's' => [['f' => 2], ['f' => 3, 's' => [['f' => 1], ['f' => 2]]], ['f' => 5, 's' => [['f' => 21], ['f' => 22]]], ['f' => 8, 's' => [['f' => 1], ['f' => 2]]]]],
        ['f' => 64, 's' => [['f' => 2], ['f' => 3, 's' => [['f' => 1], ['f' => 2]]], ['f' => 5, 's' => [['f' => 21], ['f' => 22]]], ['f' => 8, 's' => [['f' => 1], ['f' => 2]]]]],
        ['f' => 66, 's' => [['f' => 2], ['f' => 6], ['f' => 7], ['f' => 13], ['f' => 17, 's' => [['f' => 21], ['f' => 22]]], ['f' => 20]]],
        ['f' => 74, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 87, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 88, 's' => [['f' => 1], ['f' => 2, 's' => [['f' => 1]]], ['f' => 3, 's' => [['f' => 21], ['f' => 22]]]]],
        ['f' => 92, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 93, 's' => [['f' => 1, 'm' => true]]],
        ['f' => 94, 's' => [['f' => 1, 'm' => true]]],
    ];

    private static ?array $compiled = null;

    private static function compile(array $fields): array
    {
        $map = [];
        foreach ($fields as $f) {
            $map[$f['f']] = [
                'm' => $f['m'] ?? false,
                'children' => isset($f['s']) ? self::compile($f['s']) : null,
            ];
        }
        return $map;
    }

    private static function compiledFields(): array
    {
        return self::$compiled ??= self::compile(self::FIELDS);
    }

    public static function shouldIncludeReportingToken(array $message): bool
    {
        return !isset($message['reactionMessage'])
            && !isset($message['encReactionMessage'])
            && !isset($message['encEventResponseMessage'])
            && !isset($message['pollUpdateMessage']);
    }

    private static function generateMsgSecretKey(string $modificationType, string $origMsgId, string $origMsgSender, string $modificationSender, string $origMsgSecret): string
    {
        $info = $origMsgId . $origMsgSender . $modificationSender . $modificationType;
        return hkdf($origMsgSecret, 32, '', $info);
    }

    /** @return array{value:int,bytes:int,ok:bool} */
    private static function decodeVarint(string $data, int $offset): array
    {
        $value = 0;
        $bytes = 0;
        $shift = 0;
        $len = strlen($data);
        while ($offset + $bytes < $len) {
            $current = ord($data[$offset + $bytes]);
            $value |= ($current & 0x7F) << $shift;
            $bytes++;
            if (($current & 0x80) === 0) {
                return ['value' => $value, 'bytes' => $bytes, 'ok' => true];
            }
            $shift += 7;
            if ($shift > 35) {
                return ['value' => 0, 'bytes' => 0, 'ok' => false];
            }
        }
        return ['value' => 0, 'bytes' => 0, 'ok' => false];
    }

    private static function encodeVarint(int $value): string
    {
        $out = '';
        $remaining = $value;
        while ($remaining > 0x7F) {
            $out .= chr(($remaining & 0x7F) | 0x80);
            $remaining >>= 7;
        }
        $out .= chr($remaining);
        return $out;
    }

    private static function extractReportingTokenContent(string $data, array $cfg): ?string
    {
        $out = [];
        $i = 0;
        $len = strlen($data);
        while ($i < $len) {
            $tag = self::decodeVarint($data, $i);
            if (!$tag['ok']) {
                return null;
            }
            $fieldNum = $tag['value'] >> 3;
            $wireType = $tag['value'] & 0x7;
            $fieldStart = $i;
            $i += $tag['bytes'];
            $fieldCfg = $cfg[$fieldNum] ?? null;

            if ($wireType === 0) { // varint
                $v = self::decodeVarint($data, $i);
                if (!$v['ok']) {
                    return null;
                }
                $end = $i + $v['bytes'];
                if ($end > $len) {
                    return null;
                }
                if ($fieldCfg !== null) {
                    $out[] = ['num' => $fieldNum, 'bytes' => substr($data, $fieldStart, $end - $fieldStart)];
                }
                $i = $end;
                continue;
            }
            if ($wireType === 1) { // fixed64
                $end = $i + 8;
                if ($end > $len) {
                    return null;
                }
                if ($fieldCfg !== null) {
                    $out[] = ['num' => $fieldNum, 'bytes' => substr($data, $fieldStart, $end - $fieldStart)];
                }
                $i = $end;
                continue;
            }
            if ($wireType === 5) { // fixed32
                $end = $i + 4;
                if ($end > $len) {
                    return null;
                }
                if ($fieldCfg !== null) {
                    $out[] = ['num' => $fieldNum, 'bytes' => substr($data, $fieldStart, $end - $fieldStart)];
                }
                $i = $end;
                continue;
            }
            if ($wireType === 2) { // length-delimited
                $lv = self::decodeVarint($data, $i);
                if (!$lv['ok']) {
                    return null;
                }
                $valStart = $i + $lv['bytes'];
                $valEnd = $valStart + $lv['value'];
                if ($valEnd > $len) {
                    return null;
                }
                if ($fieldCfg === null) {
                    $i = $valEnd;
                    continue;
                }
                if (!empty($fieldCfg['m']) || $fieldCfg['children'] !== null) {
                    $sub = self::extractReportingTokenContent(
                        substr($data, $valStart, $lv['value']),
                        $fieldCfg['children'] ?? []
                    );
                    if ($sub === null) {
                        return null;
                    }
                    if ($sub !== '') {
                        $out[] = [
                            'num' => $fieldNum,
                            'bytes' => self::encodeVarint($tag['value']) . self::encodeVarint(strlen($sub)) . $sub,
                        ];
                    }
                    $i = $valEnd;
                    continue;
                }
                $out[] = ['num' => $fieldNum, 'bytes' => substr($data, $fieldStart, $valEnd - $fieldStart)];
                $i = $valEnd;
                continue;
            }
            return null;
        }
        if ($out === []) {
            return '';
        }
        usort($out, fn($a, $b) => $a['num'] <=> $b['num']);
        return implode('', array_column($out, 'bytes'));
    }

    /**
     * getMessageReportingToken(encodedProto, message, key) → <reporting> node|null
     * @param array $key WAMessageKey of the outgoing message (fromMe true)
     */
    public static function getMessageReportingToken(string $msgProtobuf, array $message, array $key): ?BinaryNode
    {
        $msgSecret = $message['messageContextInfo']['messageSecret'] ?? null;
        if (!is_string($msgSecret) || empty($key['id'])) {
            return null;
        }
        $from = !empty($key['fromMe']) ? ($key['remoteJid'] ?? '') : ($key['participant'] ?? $key['remoteJid'] ?? '');
        $to = !empty($key['fromMe']) ? ($key['participant'] ?? $key['remoteJid'] ?? '') : ($key['remoteJid'] ?? '');
        $secret = self::generateMsgSecretKey('Report Token', (string)$key['id'], (string)$from, (string)$to, $msgSecret);
        $content = self::extractReportingTokenContent($msgProtobuf, self::compiledFields());
        if ($content === null || $content === '') {
            return null;
        }
        $token = substr(hash_hmac('sha256', $content, $secret, true), 0, 16);
        return new BinaryNode('reporting', [], [
            new BinaryNode('reporting_token', ['v' => '2'], new Bytes($token)),
        ]);
    }
}
