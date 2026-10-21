<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/** Port of Baileys: src/WABinary/jid-utils.ts */
final class Jid
{
    public const S_WHATSAPP_NET = '@s.whatsapp.net';
    public const OFFICIAL_BIZ_JID = '16505361212@c.us';
    public const SERVER_JID = 'server@c.us';
    public const PSA_WID = '0@c.us';
    public const STORIES_JID = 'status@broadcast';

    public const DOMAIN_WHATSAPP = 0;
    public const DOMAIN_LID = 1;
    public const DOMAIN_HOSTED = 128;
    public const DOMAIN_HOSTED_LID = 129;

    public static function serverFromDomainType(string $initialServer, ?int $domainType): string
    {
        return match ($domainType) {
            self::DOMAIN_LID => 'lid',
            self::DOMAIN_HOSTED => 'hosted',
            self::DOMAIN_HOSTED_LID => 'hosted.lid',
            default => $initialServer,
        };
    }

    public static function encode(string|int|null $user, string $server, ?int $device = null, ?int $agent = null): string
    {
        return ($user ?? '') . ($agent ? '_' . $agent : '') . ($device ? ':' . $device : '') . '@' . $server;
    }

    /**
     * @return null|array{server:string,user:string,domainType:int,device:?int}
     */
    public static function decode(?string $jid): ?array
    {
        if ($jid === null) {
            return null;
        }
        $sepIdx = strpos($jid, '@');
        if ($sepIdx === false || $sepIdx < 0) {
            return null;
        }
        $server = substr($jid, $sepIdx + 1);
        $userCombined = substr($jid, 0, $sepIdx);
        $parts = explode(':', $userCombined, 2);
        $userAgent = $parts[0];
        $device = $parts[1] ?? null;
        $uaParts = explode('_', $userAgent, 2);
        $user = $uaParts[0];
        $agent = $uaParts[1] ?? null;

        $domainType = self::DOMAIN_WHATSAPP;
        if ($server === 'lid') {
            $domainType = self::DOMAIN_LID;
        } elseif ($server === 'hosted') {
            $domainType = self::DOMAIN_HOSTED;
        } elseif ($server === 'hosted.lid') {
            $domainType = self::DOMAIN_HOSTED_LID;
        } elseif ($agent !== null && $agent !== '') {
            $domainType = (int)$agent;
        }

        return [
            'server' => $server,
            'user' => $user,
            'domainType' => $domainType,
            'device' => ($device !== null && $device !== '') ? (int)$device : null,
        ];
    }

    public static function areSameUser(?string $jid1, ?string $jid2): bool
    {
        return self::decode($jid1)['user'] === self::decode($jid2)['user'];
    }

    public static function isPnUser(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@s.whatsapp.net'); }
    public static function isLidUser(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@lid'); }
    public static function isBroadcast(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@broadcast'); }
    public static function isGroup(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@g.us'); }
    public static function isStatusBroadcast(string $jid): bool { return $jid === 'status@broadcast'; }
    public static function isNewsletter(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@newsletter'); }
    public static function isHostedPnUser(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@hosted'); }
    public static function isHostedLidUser(?string $jid): bool { return $jid !== null && str_ends_with($jid, '@hosted.lid'); }

    public static function normalizedUser(?string $jid): string
    {
        $result = self::decode($jid);
        if (!$result) {
            return '';
        }
        return self::encode($result['user'], $result['server'] === 'c.us' ? 's.whatsapp.net' : $result['server']);
    }

    public static function transferDevice(string $fromJid, string $toJid): string
    {
        $from = self::decode($fromJid);
        $deviceId = $from['device'] ?? 0;
        $to = self::decode($toJid);
        return self::encode($to['user'], $to['server'], $deviceId);
    }
}
