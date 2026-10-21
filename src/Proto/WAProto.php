<?php
declare(strict_types=1);

namespace BaileysPHP\Proto;

/**
 * Hand-written pure-PHP encoders/decoders for the WAProto messages the
 * Basic edition needs. Field numbers are taken 1:1 from WAProto.proto
 * shipped with Baileys. Messages are represented as PHP associative arrays
 * mirroring protobufjs plain objects (camelCase keys).
 */
final class WAProto
{
    // ---------------------------------------------------------------- HandshakeMessage
    /** @param array{ephemeral?:string,static?:string,payload?:string} $clientHello */
    public static function encodeHandshakeClientHello(array $clientHello): string
    {
        $hello = (new ProtoWriter())
            ->bytes(1, $clientHello['ephemeral'] ?? null)
            ->bytes(2, $clientHello['static'] ?? null)
            ->bytes(3, $clientHello['payload'] ?? null)
            ->finish();
        return (new ProtoWriter())->message(2, $hello)->finish();
    }

    public static function encodeHandshakeClientFinish(string $static, string $payload): string
    {
        $finish = (new ProtoWriter())->bytes(1, $static)->bytes(2, $payload)->finish();
        return (new ProtoWriter())->message(4, $finish)->finish();
    }

    /**
     * @return array{clientHello:?array,serverHello:?array{ephemeral:?string,static:?string,payload:?string},clientFinish:?array}
     */
    public static function decodeHandshakeMessage(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        $pick = function (?ProtoReader $m): ?array {
            if ($m === null) {
                return null;
            }
            return [
                'ephemeral' => $m->getBytes(1),
                'static' => $m->getBytes(2),
                'payload' => $m->getBytes(3),
            ];
        };
        $finish = $r->getMessage(4);
        return [
            'clientHello' => $pick($r->getMessage(2)),
            'serverHello' => $pick($r->getMessage(3)),
            'clientFinish' => $finish === null ? null : [
                'static' => $finish->getBytes(1),
                'payload' => $finish->getBytes(2),
            ],
        ];
    }

    // ---------------------------------------------------------------- CertChain
    /**
     * @return array{leaf:?array{details:?string,signature:?string},intermediate:?array{details:?string,signature:?string}}
     */
    public static function decodeCertChain(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        $cert = fn(?ProtoReader $m): ?array => $m === null ? null : [
            'details' => $m->getBytes(1),
            'signature' => $m->getBytes(2),
        ];
        return ['leaf' => $cert($r->getMessage(1)), 'intermediate' => $cert($r->getMessage(2))];
    }

    /** @return array{serial:?int,issuerSerial:?int,key:?string,notBefore:?int,notAfter:?int} */
    public static function decodeCertDetails(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return [
            'serial' => $r->getVarint(1),
            'issuerSerial' => $r->getVarint(2),
            'key' => $r->getBytes(3),
            'notBefore' => $r->getVarint(4),
            'notAfter' => $r->getVarint(5),
        ];
    }

    // ---------------------------------------------------------------- DeviceProps
    /**
     * @param array{
     *   os?:string, version?:array{primary?:int,secondary?:int,tertiary?:int},
     *   platformType?:int, requireFullSync?:bool, historySyncConfig?:array<string,mixed>
     * } $props
     */
    public static function encodeDeviceProps(array $props): string
    {
        $w = new ProtoWriter();
        $w->string(1, $props['os'] ?? null);
        if (isset($props['version'])) {
            $v = (new ProtoWriter())
                ->varint(1, $props['version']['primary'] ?? null)
                ->varint(2, $props['version']['secondary'] ?? null)
                ->varint(3, $props['version']['tertiary'] ?? null)
                ->finish();
            $w->message(2, $v);
        }
        $w->varint(3, $props['platformType'] ?? null);
        // protobufjs omits false/default here in practice; encode only when true-ish set
        if (array_key_exists('requireFullSync', $props)) {
            $w->bool(4, (bool)$props['requireFullSync']);
        }
        if (isset($props['historySyncConfig'])) {
            $h = $props['historySyncConfig'];
            $hw = new ProtoWriter();
            $hw->varint(1, $h['fullSyncDaysLimit'] ?? null);
            $hw->varint(2, $h['fullSyncSizeMbLimit'] ?? null);
            $hw->varint(3, $h['storageQuotaMb'] ?? null);
            // booleans: encode only true values (missing == false on the wire)
            $boolFields = [
                4 => 'inlineInitialPayloadInE2EeMsg', 6 => 'supportCallLogHistory',
                7 => 'supportBotUserAgentChatHistory', 8 => 'supportCagReactionsAndPolls',
                9 => 'supportBizHostedMsg', 10 => 'supportRecentSyncChunkMessageCountTuning',
                11 => 'supportHostedGroupMsg', 12 => 'supportFbidBotChatHistory',
                13 => 'supportAddOnHistorySyncMigration', 14 => 'supportMessageAssociation',
                15 => 'supportGroupHistory', 16 => 'onDemandReady', 17 => 'supportGuestChat',
            ];
            foreach ($boolFields as $num => $name) {
                if (!empty($h[$name])) {
                    $hw->bool($num, true);
                }
            }
            $hw->varint(5, $h['recentSyncDaysLimit'] ?? null);
            $w->message(5, $hw->finish());
        }
        return $w->finish();
    }

    // ---------------------------------------------------------------- ClientPayload
    /**
     * Keys mirror proto.IClientPayload as built by validate-connection.ts.
     * @param array<string,mixed> $p
     */
    public static function encodeClientPayload(array $p): string
    {
        $w = new ProtoWriter();
        if (isset($p['username'])) {
            $w->varint(1, is_int($p['username']) ? $p['username'] : (int)$p['username']);
        }
        if (array_key_exists('passive', $p)) {
            $w->bool(3, (bool)$p['passive']);
        }
        if (isset($p['userAgent'])) {
            $ua = $p['userAgent'];
            $uw = new ProtoWriter();
            $uw->varint(1, $ua['platform'] ?? null);
            if (isset($ua['appVersion'])) {
                $av = (new ProtoWriter())
                    ->varint(1, $ua['appVersion']['primary'] ?? null)
                    ->varint(2, $ua['appVersion']['secondary'] ?? null)
                    ->varint(3, $ua['appVersion']['tertiary'] ?? null)
                    ->finish();
                $uw->message(2, $av);
            }
            $uw->string(3, $ua['mcc'] ?? null);
            $uw->string(4, $ua['mnc'] ?? null);
            $uw->string(5, $ua['osVersion'] ?? null);
            $uw->string(6, $ua['manufacturer'] ?? null);
            $uw->string(7, $ua['device'] ?? null);
            $uw->string(8, $ua['osBuildNumber'] ?? null);
            if (isset($ua['releaseChannel'])) {
                $uw->varint(10, $ua['releaseChannel']);
            }
            $uw->string(11, $ua['localeLanguageIso6391'] ?? null);
            $uw->string(12, $ua['localeCountryIso31661Alpha2'] ?? null);
            $w->message(5, $uw->finish());
        }
        if (isset($p['webInfo'])) {
            $wi = (new ProtoWriter())->varint(4, $p['webInfo']['webSubPlatform'] ?? null)->finish();
            $w->message(6, $wi);
        }
        $w->string(7, $p['pushName'] ?? null);
        if (isset($p['connectType'])) {
            $w->varint(12, $p['connectType']);
        }
        if (isset($p['connectReason'])) {
            $w->varint(13, $p['connectReason']);
        }
        if (isset($p['device'])) {
            $w->varint(18, $p['device']);
        }
        if (isset($p['devicePairingData'])) {
            $d = $p['devicePairingData'];
            $dw = (new ProtoWriter())
                ->bytes(1, $d['eRegid'] ?? null)
                ->bytes(2, $d['eKeytype'] ?? null)
                ->bytes(3, $d['eIdent'] ?? null)
                ->bytes(4, $d['eSkeyId'] ?? null)
                ->bytes(5, $d['eSkeyVal'] ?? null)
                ->bytes(6, $d['eSkeySig'] ?? null)
                ->bytes(7, $d['buildHash'] ?? null)
                ->bytes(8, $d['deviceProps'] ?? null)
                ->finish();
            $w->message(19, $dw);
        }
        if (array_key_exists('pull', $p)) {
            $w->bool(33, (bool)$p['pull']);
        }
        if (array_key_exists('lidDbMigrated', $p)) {
            $w->bool(41, (bool)$p['lidDbMigrated']);
        }
        return $w->finish();
    }

    // ---------------------------------------------------------------- ADV identities
    /** @return array{details:?string,hmac:?string,accountType:?int} */
    public static function decodeADVSignedDeviceIdentityHMAC(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return ['details' => $r->getBytes(1), 'hmac' => $r->getBytes(2), 'accountType' => $r->getVarint(3)];
    }

    /** @return array{details:?string,accountSignatureKey:?string,accountSignature:?string,deviceSignature:?string} */
    public static function decodeADVSignedDeviceIdentity(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return [
            'details' => $r->getBytes(1),
            'accountSignatureKey' => $r->getBytes(2),
            'accountSignature' => $r->getBytes(3),
            'deviceSignature' => $r->getBytes(4),
        ];
    }

    /** @param array{details?:?string,accountSignatureKey?:?string,accountSignature?:?string,deviceSignature?:?string} $a */
    public static function encodeADVSignedDeviceIdentity(array $a): string
    {
        return (new ProtoWriter())
            ->bytes(1, $a['details'] ?? null)
            ->bytes(2, $a['accountSignatureKey'] ?? null)
            ->bytes(3, $a['accountSignature'] ?? null)
            ->bytes(4, $a['deviceSignature'] ?? null)
            ->finish();
    }

    /** @return array{rawId:?int,timestamp:?int,keyIndex:?int,accountType:?int,deviceType:?int} */
    public static function decodeADVDeviceIdentity(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return [
            'rawId' => $r->getVarint(1),
            'timestamp' => $r->getVarint(2),
            'keyIndex' => $r->getVarint(3),
            'accountType' => $r->getVarint(4),
            'deviceType' => $r->getVarint(5),
        ];
    }

    // ---------------------------------------------------------------- Generic schema-driven codec
    /**
     * Encode any WAProto message by name, using WAProtoSchema (generated from
     * upstream WAProto.proto). Values are Baileys-style arrays; enum fields
     * accept ints or enum value names. '_rawFields' from decodeProto() are
     * re-emitted losslessly.
     * @param array<string,mixed> $data
     */
    public static function encodeProto(string $message, array $data): string
    {
        $fields = WAProtoSchema::MESSAGES[$message]
            ?? throw new \InvalidArgumentException("Unknown WAProto message: $message");
        $w = new ProtoWriter();
        foreach ($fields as $no => $def) {
            $name = $def[0];
            if (!array_key_exists($name, $data) || $data[$name] === null) {
                continue;
            }
            self::encodeField($w, (int)$no, $def, $data[$name]);
        }
        if (isset($data['_rawFields']) && is_array($data['_rawFields'])) {
            foreach ($data['_rawFields'] as $field => $entries) {
                foreach ((array)$entries as $entry) {
                    self::writeRawField($w, (int)$field, (int)($entry['wire'] ?? 2), $entry['value'] ?? null);
                }
            }
        }
        return $w->finish();
    }

    /** @param array{0:string,1:string,2:string,3:string,4:bool} $def */
    private static function encodeField(ProtoWriter $w, int $no, array $def, mixed $value): void
    {
        [, $type, $kind, $rule, $packed] = $def;
        if (str_starts_with($kind, 'map:')) {
            [, $keyType, $valueType] = explode(':', $kind);
            foreach ((array)$value as $k => $v) {
                $entry = is_array($v) && array_key_exists('key', $v)
                    ? [$v['key'], $v['value'] ?? null]
                    : [$k, $v];
                $ew = new ProtoWriter();
                self::encodeScalarOrMsg($ew, 1, $keyType, self::kindOf($keyType), $entry[0]);
                if ($entry[1] !== null) {
                    self::encodeScalarOrMsg($ew, 2, $valueType, self::kindOf($valueType), $entry[1]);
                }
                $w->bytes($no, $ew->finish());
            }
            return;
        }
        if ($rule === 'repeated') {
            $values = is_array($value) ? array_values($value) : [$value];
            $packable = $kind !== 'message' && !in_array($type, ['string', 'bytes'], true);
            if ($packed && $packable && $values !== []) {
                $pw = new ProtoWriter();
                foreach ($values as $v) {
                    self::encodePackedScalar($pw, $type, $kind, $v);
                }
                $w->bytes($no, $pw->finish());
                return;
            }
            foreach ($values as $v) {
                self::encodeScalarOrMsg($w, $no, $type, $kind, $v);
            }
            return;
        }
        self::encodeScalarOrMsg($w, $no, $type, $kind, $value);
    }

    private static function kindOf(string $type): string
    {
        static $scalars = ['double' => 1, 'float' => 1, 'int32' => 1, 'int64' => 1, 'uint32' => 1, 'uint64' => 1,
            'sint32' => 1, 'sint64' => 1, 'fixed32' => 1, 'fixed64' => 1, 'sfixed32' => 1, 'sfixed64' => 1,
            'bool' => 1, 'string' => 1, 'bytes' => 1];
        if (isset($scalars[$type])) {
            return 'scalar';
        }
        return isset(WAProtoSchema::ENUMS[$type]) ? 'enum' : 'message';
    }

    private static function encodeScalarOrMsg(ProtoWriter $w, int $no, string $type, string $kind, mixed $value): void
    {
        if ($kind === 'message') {
            if (is_string($value)) {
                $w->bytes($no, $value); // pre-encoded passthrough
                return;
            }
            if (is_array($value) && isset($value['_raw']) && is_string($value['_raw'])) {
                $w->bytes($no, $value['_raw']);
                return;
            }
            $w->bytes($no, self::encodeProto($type, (array)$value));
            return;
        }
        if ($kind === 'enum') {
            $w->varint($no, self::enumToInt($type, $value));
            return;
        }
        switch ($type) {
            case 'string':
            case 'bytes':
                $w->bytes($no, is_string($value) ? $value : (string)$value);
                break;
            case 'bool':
                $w->bool($no, (bool)$value);
                break;
            case 'double':
                $w->double($no, (float)$value);
                break;
            case 'float':
                $w->float($no, (float)$value);
                break;
            case 'fixed32':
            case 'sfixed32':
                $w->fixed32($no, (int)$value);
                break;
            case 'fixed64':
            case 'sfixed64':
                $w->fixed64($no, (int)$value);
                break;
            case 'sint32':
            case 'sint64':
                $w->sint($no, (int)$value);
                break;
            default: // int32,int64,uint32,uint64
                $w->varint($no, self::toVarintArg($value));
        }
    }

    private static function encodePackedScalar(ProtoWriter $w, string $type, string $kind, mixed $value): void
    {
        if ($kind === 'enum') {
            $w->varintValue(self::enumToInt($type, $value));
            return;
        }
        switch ($type) {
            case 'bool':
                $w->varintValue($value ? 1 : 0);
                break;
            case 'double':
                $w->raw(pack('e', (float)$value));
                break;
            case 'float':
                $w->raw(pack('g', (float)$value));
                break;
            case 'fixed32':
            case 'sfixed32':
                $w->raw(pack('V', (int)$value));
                break;
            case 'fixed64':
            case 'sfixed64':
                $w->raw(pack('P', (int)$value));
                break;
            case 'sint32':
            case 'sint64':
                $v = (int)$value;
                $w->varintValue(($v << 1) ^ ($v >> 63));
                break;
            default:
                $w->varintValue(self::toVarintArg($value));
        }
    }

    private static function enumToInt(string $enumType, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $map = WAProtoSchema::ENUMS[$enumType] ?? [];
        if (is_string($value) && isset($map[$value])) {
            return $map[$value];
        }
        throw new \InvalidArgumentException("Invalid enum value for $enumType: " . var_export($value, true));
    }

    /** Numeric enum value by name — like proto.Message.ProtocolMessage.Type.REVOKE in TS. */
    /** Resolve an enum numeric value to its name (or null). */
    public static function enumName(string $enumType, int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && !is_numeric($value)) {
            return $value;
        }
        $map = WAProtoSchema::ENUMS[$enumType] ?? null;
        if ($map === null) {
            return null;
        }
        $flipped = array_flip($map);
        return $flipped[(int)$value] ?? null;
    }

    public static function enumValue(string $enumType, string $name): int
    {
        return WAProtoSchema::ENUMS[$enumType][$name]
            ?? throw new \InvalidArgumentException("Unknown enum $enumType.$name");
    }

    private static function toVarintArg(mixed $value): int|\GMP
    {
        if ($value instanceof \GMP || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $g = gmp_init($value);
            return (gmp_cmp($g, PHP_INT_MAX) <= 0 && gmp_cmp($g, PHP_INT_MIN) >= 0) ? (int)$value : $g;
        }
        return (int)$value;
    }

    private static function writeRawField(ProtoWriter $w, int $no, int $wire, mixed $value): void
    {
        switch ($wire) {
            case 0:
                $w->varint($no, self::toVarintArg($value));
                break;
            case 2:
                $w->bytes($no, is_string($value) ? $value : '');
                break;
            case 5:
                if (is_string($value) && strlen($value) === 4) {
                    $w->varintValue(($no << 3) | 5)->raw($value);
                } elseif (is_int($value)) {
                    $w->fixed32($no, $value);
                }
                break;
            case 1:
                if (is_string($value) && strlen($value) === 8) {
                    $w->varintValue(($no << 3) | 1)->raw($value);
                } elseif (is_int($value)) {
                    $w->fixed64($no, $value);
                }
                break;
        }
    }

    /**
     * Decode any WAProto message by name into a Baileys-like array.
     * Enum fields decode to ints (see enumValue()). Unknown fields are kept
     * under '_rawFields' (fieldNo => list of ['wire'=>,'value'=>]).
     * @return array<string,mixed>
     */
    public static function decodeProto(string $message, string $buf): array
    {
        $schema = WAProtoSchema::MESSAGES[$message]
            ?? throw new \InvalidArgumentException("Unknown WAProto message: $message");
        $r = ProtoReader::parse($buf);
        $out = [];
        $raw = [];
        foreach ($r->fields as $no => $entries) {
            $def = $schema[$no] ?? null;
            if ($def === null) {
                $raw[$no] = $entries;
                continue;
            }
            [$name, $type, $kind, $rule] = $def;
            if (str_starts_with($kind, 'map:')) {
                [, $keyType, $valueType] = explode(':', $kind);
                $map = [];
                foreach ($entries as $e) {
                    if (!is_string($e['value'])) {
                        continue;
                    }
                    $er = ProtoReader::parse($e['value']);
                    $kEntry = $er->fields[1][0] ?? null;
                    $vEntry = $er->fields[2][0] ?? null;
                    $k = $kEntry ? self::convertDecoded($keyType, self::kindOf($keyType), $kEntry) : null;
                    $v = $vEntry ? self::convertDecoded($valueType, self::kindOf($valueType), $vEntry) : null;
                    if (is_int($k) || is_string($k)) {
                        $map[$k] = $v;
                    }
                }
                $out[$name] = $map;
                continue;
            }
            $packable = $kind !== 'message' && !in_array($type, ['string', 'bytes'], true);
            if ($rule === 'repeated') {
                $list = $out[$name] ?? [];
                foreach ($entries as $e) {
                    if ($packable && ($e['wire'] ?? null) === 2 && is_string($e['value'])) {
                        foreach (self::unpackPacked($type, $e['value']) as $pv) {
                            $list[] = $kind === 'enum' ? $pv : self::adjustScalar($type, $pv);
                        }
                    } else {
                        $list[] = self::convertDecoded($type, $kind, $e);
                    }
                }
                $out[$name] = $list;
            } else {
                $e = $entries[count($entries) - 1];
                $out[$name] = self::convertDecoded($type, $kind, $e);
            }
        }
        if ($raw !== []) {
            $out['_rawFields'] = $raw;
        }
        return $out;
    }

    /** @param array{wire:int,value:mixed} $e */
    private static function convertDecoded(string $type, string $kind, array $e): mixed
    {
        $value = $e['value'];
        if ($kind === 'message') {
            return is_string($value) ? self::decodeProto($type, $value) : null;
        }
        if ($kind === 'enum') {
            return is_int($value) ? $value : null;
        }
        switch ($type) {
            case 'string':
            case 'bytes':
                return is_string($value) ? $value : null;
            case 'bool':
                return is_int($value) ? $value !== 0 : null;
            case 'double':
                return is_string($value) && strlen($value) === 8 ? unpack('e', $value)[1] : null;
            case 'float':
                return is_string($value) && strlen($value) === 4 ? unpack('g', $value)[1] : null;
            case 'fixed32':
                return is_string($value) && strlen($value) === 4 ? unpack('V', $value)[1] : (is_int($value) ? $value : null);
            case 'sfixed32':
                if (is_string($value) && strlen($value) === 4) {
                    $u = unpack('V', $value)[1];
                    return $u >= 0x80000000 ? $u - 0x100000000 : $u;
                }
                return is_int($value) ? $value : null;
            case 'fixed64':
            case 'sfixed64':
                return is_string($value) && strlen($value) === 8 ? unpack('P', $value)[1] : (is_int($value) ? $value : null);
            case 'sint32':
            case 'sint64':
            case 'int32':
            case 'uint32':
            case 'int64':
            case 'uint64':
                return is_int($value) ? self::adjustScalar($type, $value) : null;
        }
        return $value;
    }

    private static function adjustScalar(string $type, int $v): int
    {
        switch ($type) {
            case 'sint32':
            case 'sint64':
                return ($v >> 1) ^ (-($v & 1));
            case 'uint32':
                return $v & 0xFFFFFFFF;
            case 'int32':
                return ($v << 32) >> 32;
            default:
                return $v;
        }
    }

    /** @return list<int|float> */
    private static function unpackPacked(string $type, string $bytes): array
    {
        $out = [];
        switch ($type) {
            case 'double':
                for ($i = 0; $i + 8 <= strlen($bytes); $i += 8) {
                    $out[] = unpack('e', substr($bytes, $i, 8))[1];
                }
                return $out;
            case 'float':
                for ($i = 0; $i + 4 <= strlen($bytes); $i += 4) {
                    $out[] = unpack('g', substr($bytes, $i, 4))[1];
                }
                return $out;
            case 'fixed32':
            case 'sfixed32':
                for ($i = 0; $i + 4 <= strlen($bytes); $i += 4) {
                    $u = unpack('V', substr($bytes, $i, 4))[1];
                    $out[] = $type === 'sfixed32' && $u >= 0x80000000 ? $u - 0x100000000 : $u;
                }
                return $out;
            case 'fixed64':
            case 'sfixed64':
                for ($i = 0; $i + 8 <= strlen($bytes); $i += 8) {
                    $out[] = unpack('P', substr($bytes, $i, 8))[1];
                }
                return $out;
            default: // varint family
                $pos = 0;
                $len = strlen($bytes);
                while ($pos < $len) {
                    $result = gmp_init(0);
                    $shift = 0;
                    while ($pos < $len) {
                        $b = ord($bytes[$pos++]);
                        $result = gmp_or($result, gmp_mul($b & 0x7f, gmp_pow(2, $shift)));
                        if (($b & 0x80) === 0) {
                            break;
                        }
                        $shift += 7;
                    }
                    if (gmp_cmp($result, gmp_pow(2, 63)) >= 0) {
                        $result = gmp_sub($result, gmp_pow(2, 64));
                    }
                    $out[] = (int)gmp_strval($result);
                }
                return $out;
        }
    }

    // ---------------------------------------------------------------- Message
    /**
     * Encode a WA Message (proto.Message) from a Baileys-style array.
     * Supports every field in WAProto.proto via the generic codec:
     * conversation, imageMessage, pollCreationMessageV3, protocolMessage, ...
     * @param array<string,mixed> $m
     */
    public static function encodeMessage(array $m): string
    {
        return self::encodeProto('Message', $m);
    }

    /**
     * Decode a WA Message into a Baileys-like array (unknown fields kept
     * under '_rawFields', no data loss).
     * @return array<string,mixed>
     */
    public static function decodeMessage(string $buf): array
    {
        return self::decodeProto('Message', $buf);
    }

    /** Extract plain text from a decoded Message array, like Baileys getContentType helpers do for text. */
    public static function extractText(array $m): ?string
    {
        if (isset($m['conversation'])) {
            return (string)$m['conversation'];
        }
        if (isset($m['extendedTextMessage']['text'])) {
            return (string)$m['extendedTextMessage']['text'];
        }
        if (isset($m['deviceSentMessage']['message']) && is_array($m['deviceSentMessage']['message'])) {
            return self::extractText($m['deviceSentMessage']['message']);
        }
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'] as $wrap) {
            if (isset($m[$wrap]['message']) && is_array($m[$wrap]['message'])) {
                return self::extractText($m[$wrap]['message']);
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- WebMessageInfo
    /** @return array<string,mixed> */
    public static function decodeWebMessageInfo(string $buf): array
    {
        return self::decodeProto('WebMessageInfo', $buf);
    }
}
