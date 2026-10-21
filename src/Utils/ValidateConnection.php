<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Auth\Creds;
use BaileysPHP\Defaults;
use BaileysPHP\Proto\WAProto;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/** Port of Baileys: src/Utils/validate-connection.ts */
final class ValidateConnection
{
    private const PLATFORM_WEB = 14;
    private const PLATFORM_ANDROID = 0;
    private const ADV_HOSTED = 1;

    /** @param array<string,mixed> $config */
    private static function getUserAgent(array $config): array
    {
        $browser = $config['browser'];
        return [
            'appVersion' => [
                'primary' => $config['version'][0],
                'secondary' => $config['version'][1],
                'tertiary' => $config['version'][2],
            ],
            'platform' => str_contains(strtolower($browser[1]), 'android') ? self::PLATFORM_ANDROID : self::PLATFORM_WEB,
            'releaseChannel' => 0, // RELEASE
            'osVersion' => '0.1',
            'device' => 'Desktop',
            'osBuildNumber' => '0.1',
            'localeLanguageIso6391' => 'en',
            'mnc' => '000',
            'mcc' => '000',
            'localeCountryIso31661Alpha2' => $config['countryCode'] ?? 'US',
        ];
    }

    /** @param array<string,mixed> $config */
    private static function getWebInfo(array $config): array
    {
        $map = ['Mac OS' => 3 /* DARWIN */, 'Windows' => 5 /* WIN_HYBRID */];
        $webSubPlatform = 0; // WEB_BROWSER
        if (!empty($config['syncFullHistory']) && isset($map[$config['browser'][0]]) && $config['browser'][1] === 'Desktop') {
            $webSubPlatform = $map[$config['browser'][0]];
        }
        return ['webSubPlatform' => $webSubPlatform];
    }

    /** @param array<string,mixed> $config */
    private static function getClientPayload(array $config): array
    {
        $payload = [
            'connectType' => 1,   // WIFI_UNKNOWN
            'connectReason' => 1, // USER_ACTIVATED
            'userAgent' => self::getUserAgent($config),
        ];
        if (!str_contains(strtolower($config['browser'][1]), 'android')) {
            $payload['webInfo'] = self::getWebInfo($config);
        }
        if (!empty($config['pushName'])) {
            $payload['pushName'] = $config['pushName'];
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed> ClientPayload array
     */
    public static function generateLoginNode(string $userJid, array $config): array
    {
        $decoded = Jid::decode($userJid);
        return array_merge(self::getClientPayload($config), [
            'passive' => true,
            'pull' => true,
            'username' => (int)$decoded['user'],
            'device' => $decoded['device'] ?? 0,
            'lidDbMigrated' => false,
        ]);
    }

    private static function getPlatformType(string $platform): int
    {
        $upper = strtoupper($platform);
        if ($upper === 'ANDROID') {
            return 16; // ANDROID_PHONE
        }
        $map = [
            'UNKNOWN' => 0, 'CHROME' => 1, 'FIREFOX' => 2, 'IE' => 3, 'OPERA' => 4, 'SAFARI' => 5,
            'EDGE' => 6, 'DESKTOP' => 7, 'IPAD' => 8, 'ANDROID_TABLET' => 9, 'OHANA' => 10,
            'ALOHA' => 11, 'CATALINA' => 12, 'TCL_TV' => 13, 'IOS_PHONE' => 14, 'IOS_CATALYST' => 15,
            'ANDROID_PHONE' => 16, 'ANDROID_AMBIGUOUS' => 17, 'WEAR_OS' => 18, 'AR_WRIST' => 19,
            'AR_DEVICE' => 20, 'UWP' => 21, 'VR' => 22, 'CLOUD_API' => 23, 'SMARTGLASSES' => 24,
        ];
        return $map[$upper] ?? 1; // CHROME
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed> ClientPayload array
     */
    public static function generateRegistrationNode(Creds $creds, array $config): array
    {
        $appVersionBuf = md5bin(implode('.', $config['version']));
        $companion = [
            'os' => $config['browser'][0],
            'platformType' => self::getPlatformType($config['browser'][1]),
            'requireFullSync' => (bool)($config['syncFullHistory'] ?? false),
            'historySyncConfig' => [
                'storageQuotaMb' => 10240,
                'inlineInitialPayloadInE2EeMsg' => true,
                'supportCallLogHistory' => false,
                'supportBotUserAgentChatHistory' => true,
                'supportCagReactionsAndPolls' => true,
                'supportBizHostedMsg' => true,
                'supportRecentSyncChunkMessageCountTuning' => true,
                'supportHostedGroupMsg' => true,
                'supportFbidBotChatHistory' => true,
                'supportMessageAssociation' => true,
                'supportGroupHistory' => false,
            ],
            'version' => ['primary' => 10, 'secondary' => 15, 'tertiary' => 7],
        ];
        $companionProto = WAProto::encodeDeviceProps($companion);

        return array_merge(self::getClientPayload($config), [
            'passive' => false,
            'pull' => false,
            'devicePairingData' => [
                'buildHash' => $appVersionBuf,
                'deviceProps' => $companionProto,
                'eRegid' => Generics::encodeBigEndian($creds->registrationId),
                'eKeytype' => Defaults::KEY_BUNDLE_TYPE,
                'eIdent' => $creds->signedIdentityKey->public,
                'eSkeyId' => Generics::encodeBigEndian($creds->signedPreKey->keyId, 3),
                'eSkeyVal' => $creds->signedPreKey->keyPair->public,
                'eSkeySig' => $creds->signedPreKey->signature,
            ],
        ]);
    }

    /** encodeSignedDeviceIdentity(account, includeSignatureKey) */
    public static function encodeSignedDeviceIdentity(array $account, bool $includeSignatureKey): string
    {
        if (!$includeSignatureKey || empty($account['accountSignatureKey'])) {
            $account['accountSignatureKey'] = null;
        }
        return WAProto::encodeADVSignedDeviceIdentity($account);
    }

    /**
     * configureSuccessfulPairing(stanza, creds)
     * @return array{creds:array<string,mixed>,reply:BinaryNode}
     */
    public static function configureSuccessfulPairing(BinaryNode $stanza, Creds $creds): array
    {
        $msgId = $stanza->attrs['id'] ?? '';
        $pairSuccess = GenericUtils::getBinaryNodeChild($stanza, 'pair-success');
        $deviceIdentityNode = GenericUtils::getBinaryNodeChild($pairSuccess, 'device-identity');
        $platformNode = GenericUtils::getBinaryNodeChild($pairSuccess, 'platform');
        $deviceNode = GenericUtils::getBinaryNodeChild($pairSuccess, 'device');
        $businessNode = GenericUtils::getBinaryNodeChild($pairSuccess, 'biz');
        if ($deviceIdentityNode === null || $deviceNode === null) {
            throw new \RuntimeException('Missing device-identity or device in pair success node');
        }
        $bizName = $businessNode?->attrs['name'] ?? null;
        $jid = $deviceNode->attrs['jid'] ?? null;
        $lid = $deviceNode->attrs['lid'] ?? null;

        $content = $deviceIdentityNode->contentString() ?? '';
        ['details' => $details, 'hmac' => $hmac, 'accountType' => $accountType] = WAProto::decodeADVSignedDeviceIdentityHMAC($content);

        $hmacPrefix = ($accountType !== null && $accountType === self::ADV_HOSTED) ? Defaults::WA_ADV_HOSTED_ACCOUNT_SIG_PREFIX : '';
        $advSign = hmacSign($hmacPrefix . ($details ?? ''), base64_decode($creds->advSecretKey));
        if (!hash_equals($hmac ?? '', $advSign)) {
            throw new \RuntimeException('Invalid account signature');
        }

        $account = WAProto::decodeADVSignedDeviceIdentity($details ?? '');
        $accountSignatureKey = $account['accountSignatureKey'] ?? '';
        $accountSignature = $account['accountSignature'] ?? '';
        $deviceDetails = $account['details'] ?? '';

        $deviceIdentity = WAProto::decodeADVDeviceIdentity($deviceDetails);
        $accountSignaturePrefix = ($deviceIdentity['deviceType'] ?? null) === self::ADV_HOSTED
            ? Defaults::WA_ADV_HOSTED_ACCOUNT_SIG_PREFIX
            : Defaults::WA_ADV_ACCOUNT_SIG_PREFIX;
        $accountMsg = $accountSignaturePrefix . $deviceDetails . $creds->signedIdentityKey->public;
        if (!Curve::verify($accountSignatureKey, $accountMsg, $accountSignature)) {
            throw new \RuntimeException('Failed to verify account signature');
        }

        $deviceMsg = Defaults::WA_ADV_DEVICE_SIG_PREFIX . $deviceDetails . $creds->signedIdentityKey->public . $accountSignatureKey;
        $account['deviceSignature'] = Curve::sign($creds->signedIdentityKey->private, $deviceMsg);

        $identity = SignalUtils::createSignalIdentity($lid ?? '', $accountSignatureKey);
        $accountEnc = self::encodeSignedDeviceIdentity($account, false);

        $reply = new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'result', 'id' => $msgId], [
            new BinaryNode('pair-device-sign', [], [
                new BinaryNode('device-identity', ['key-index' => (string)($deviceIdentity['keyIndex'] ?? 0)], new Bytes($accountEnc)),
            ]),
        ]);

        $authUpdate = [
            'account' => $account,
            'me' => ['id' => $jid, 'name' => $bizName, 'lid' => $lid],
            'signalIdentities' => [...$creds->signalIdentities, $identity],
            'platform' => $platformNode?->attrs['name'] ?? null,
        ];
        return ['creds' => $authUpdate, 'reply' => $reply];
    }
}
