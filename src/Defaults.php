<?php
declare(strict_types=1);

namespace BaileysPHP;

/**
 * Port of Baileys: src/Defaults/index.ts
 */
final class Defaults
{
    /** @var array{0:int,1:int,2:int} WA Web version (same default as upstream Baileys) */
    public const WA_VERSION = [2, 3000, 1043857760];

    public const UNAUTHORIZED_CODES = [401, 403, 419];
    public const DEFAULT_ORIGIN = 'https://web.whatsapp.com';
    public const DEF_CALLBACK_PREFIX = 'CB:';
    public const DEF_TAG_PREFIX = 'TAG:';
    public const PHONE_CONNECTION_CB = 'CB:Pong';

    public const WA_ADV_ACCOUNT_SIG_PREFIX = "\x06\x00";
    public const WA_ADV_DEVICE_SIG_PREFIX = "\x06\x01";
    public const WA_ADV_HOSTED_ACCOUNT_SIG_PREFIX = "\x06\x05";
    public const WA_ADV_HOSTED_DEVICE_SIG_PREFIX = "\x06\x06";

    public const WA_DEFAULT_EPHEMERAL = 7 * 24 * 60 * 60;

    public const NOISE_MODE = "Noise_XX_25519_AESGCM_SHA256\0\0\0\0";
    public const DICT_VERSION = 3;
    public const KEY_BUNDLE_TYPE = "\x05";

    /** "WA" + protocol version 6 + DICT_VERSION */
    public static function noiseWAHeader(): string
    {
        return chr(87) . chr(65) . chr(6) . chr(self::DICT_VERSION);
    }

    public const WA_CERT_SERIAL = 0;
    public const WA_CERT_ISSUER = 'WhatsAppLongTerm1';
    public const WA_CERT_PUBLIC_KEY_HEX = '142375574d0a587166aae71ebe516437c4a28b73e3695c6ce1f7f9545da8ee6b';

    public const MIN_PREKEY_COUNT = 5;
    public const INITIAL_PREKEY_COUNT = 30; // Basic edition: upstream uses 812; 30 is enough to start & faster on local PHP. Raise if you wish.
    public const UPLOAD_TIMEOUT = 30000;

    /** @return array<string,mixed> default socket config (subset used by Basic) */
    public static function defaultConfig(): array
    {
        return [
            'version' => self::WA_VERSION,
            'browser' => \BaileysPHP\Utils\Browsers::macOS('Chrome'),
            'waWebSocketUrl' => 'wss://web.whatsapp.com/ws/chat',
            'connectTimeoutMs' => 20000,
            'keepAliveIntervalMs' => 30000,
            'defaultQueryTimeoutMs' => 60000,
            'emitOwnEvents' => true,
            'maxMsgRetryCount' => 5,
            'retryRequestDelayMs' => 250,
            'enableAutoSessionRecreation' => true,
            'enableRecentMessageCache' => true,
            'markOnlineOnConnect' => true,
            'syncFullHistory' => false, // upstream default; set true to request full history on pairing
            'countryCode' => 'US',
            // 'qrTimeout' intentionally unset: first QR lives 60s, later ones 20s (upstream behavior)
            'printQRInTerminal' => false,
        ];
    }
}
