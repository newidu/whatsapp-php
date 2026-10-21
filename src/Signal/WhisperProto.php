<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

use BaileysPHP\Proto\ProtoReader;
use BaileysPHP\Proto\ProtoWriter;

/** WhisperTextProtocol.proto (libsignal) — WhisperMessage & PreKeyWhisperMessage. */
final class WhisperProto
{
    /**
     * @param array{ephemeralKey:string,counter:int,previousCounter:int,ciphertext:string} $m
     */
    public static function encodeWhisperMessage(array $m): string
    {
        return (new ProtoWriter())
            ->bytes(1, $m['ephemeralKey'])
            ->varint(2, $m['counter'])
            ->varint(3, $m['previousCounter'])
            ->bytes(4, $m['ciphertext'])
            ->finish();
    }

    /** @return array{ephemeralKey:?string,counter:int,previousCounter:int,ciphertext:?string} */
    public static function decodeWhisperMessage(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return [
            'ephemeralKey' => $r->getBytes(1),
            'counter' => $r->getVarint(2) ?? 0,
            'previousCounter' => $r->getVarint(3) ?? 0,
            'ciphertext' => $r->getBytes(4),
        ];
    }

    /**
     * @param array{preKeyId?:?int,baseKey:string,identityKey:string,message:string,registrationId:int,signedPreKeyId:int} $m
     */
    public static function encodePreKeyWhisperMessage(array $m): string
    {
        $w = new ProtoWriter();
        if (!empty($m['preKeyId'])) {
            $w->varint(1, $m['preKeyId']);
        }
        return $w
            ->bytes(2, $m['baseKey'])
            ->bytes(3, $m['identityKey'])
            ->bytes(4, $m['message'])
            ->varint(5, $m['registrationId'])
            ->varint(6, $m['signedPreKeyId'])
            ->finish();
    }

    /** @return array{preKeyId:?int,baseKey:?string,identityKey:?string,message:?string,registrationId:?int,signedPreKeyId:?int} */
    public static function decodePreKeyWhisperMessage(string $buf): array
    {
        $r = ProtoReader::parse($buf);
        return [
            'preKeyId' => $r->getVarint(1),
            'baseKey' => $r->getBytes(2),
            'identityKey' => $r->getBytes(3),
            'message' => $r->getBytes(4),
            'registrationId' => $r->getVarint(5),
            'signedPreKeyId' => $r->getVarint(6),
        ];
    }
}
