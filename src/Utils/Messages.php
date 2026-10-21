<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Message-content helpers — port of the relevant helpers from
 * Baileys src/Utils/messages.ts (normalizeMessageContent, getContentType,
 * extractMessageContent) plus PHP conveniences.
 */
final class Messages
{
    /** Wrapper keys that simply wrap an inner Message under 'message'. */
    private const WRAPPERS = [
        'ephemeralMessage',
        'viewOnceMessage',
        'documentWithCaptionMessage',
        'viewOnceMessageV2',
        'viewOnceMessageV2Extension',
        'editedMessage',
        'associatedChildMessage',
        'groupStatusMessage',
        'groupStatusMessageV2',
    ];

    /** Normalizes ephemeral / view-once / edited wrappers to regular content. */
    public static function normalizeMessageContent(?array $content): ?array
    {
        if ($content === null) {
            return null;
        }
        for ($i = 0; $i < 5; $i++) {
            $inner = null;
            foreach (self::WRAPPERS as $w) {
                if (isset($content[$w]['message']) && is_array($content[$w]['message'])) {
                    $inner = $content[$w]['message'];
                    break;
                }
            }
            if ($inner === null) {
                break;
            }
            $content = $inner;
        }
        return $content;
    }

    /** The "type" key of a message (e.g. 'conversation', 'imageMessage'). */
    public static function getContentType(?array $content): ?string
    {
        if ($content === null) {
            return null;
        }
        foreach (array_keys($content) as $k) {
            $k = (string)$k;
            if (($k === 'conversation' || str_ends_with($k, 'Message')) && $k !== 'senderKeyDistributionMessage') {
                return $k;
            }
        }
        return null;
    }

    /** Extract the true message content (unwrap + template/buttons flattening). */
    public static function extractMessageContent(?array $content): ?array
    {
        $extractFromTemplate = function (array $msg): array {
            foreach (['imageMessage', 'documentMessage', 'videoMessage', 'locationMessage'] as $k) {
                if (isset($msg[$k])) {
                    return [$k => $msg[$k]];
                }
            }
            return ['conversation' => $msg['contentText'] ?? $msg['hydratedContentText'] ?? ''];
        };

        $content = self::normalizeMessageContent($content);
        if ($content === null) {
            return null;
        }
        if (isset($content['buttonsMessage'])) {
            return $extractFromTemplate($content['buttonsMessage']);
        }
        foreach (['hydratedFourRowTemplate', 'hydratedTemplate', 'fourRowTemplate'] as $k) {
            if (isset($content['templateMessage'][$k])) {
                return $extractFromTemplate($content['templateMessage'][$k]);
            }
        }
        return $content;
    }

    /** Best-effort plain text / caption of a message (for logging & simple bots). */
    public static function extractText(?array $message): ?string
    {
        $m = self::extractMessageContent($message);
        if ($m === null) {
            return null;
        }
        if (isset($m['conversation'])) {
            return (string)$m['conversation'];
        }
        if (isset($m['extendedTextMessage']['text'])) {
            return (string)$m['extendedTextMessage']['text'];
        }
        foreach (['imageMessage', 'videoMessage', 'documentMessage'] as $k) {
            if (isset($m[$k]['caption'])) {
                return (string)$m[$k]['caption'];
            }
        }
        if (isset($m['buttonsResponseMessage']['selectedDisplayText'])) {
            return (string)$m['buttonsResponseMessage']['selectedDisplayText'];
        }
        if (isset($m['listResponseMessage']['title'])) {
            return (string)$m['listResponseMessage']['title'];
        }
        if (isset($m['templateButtonReplyMessage']['selectedDisplayText'])) {
            return (string)$m['templateButtonReplyMessage']['selectedDisplayText'];
        }
        if (isset($m['interactiveResponseMessage'])) {
            $params = $m['interactiveResponseMessage']['nativeFlowResponseMessage']['paramsJson'] ?? null;
            if (is_string($params)) {
                $j = json_decode($params, true);
                return $j['id'] ?? null;
            }
        }
        return null;
    }

    /** Is this (normalized) message content downloadable media? Returns the content key or null. */
    public static function mediaContentType(?array $message): ?string
    {
        $m = self::normalizeMessageContent($message);
        if ($m === null) {
            return null;
        }
        foreach (['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage', 'ptvMessage'] as $k) {
            if (isset($m[$k])) {
                return $k;
            }
        }
        return null;
    }

    /**
     * The downloadable media part of a WAMessage array (any wrapper).
     * @return null|array{type:string,msg:array}
     */
    public static function getMediaPart(array $waMessage): ?array
    {
        $m = self::normalizeMessageContent($waMessage['message'] ?? null);
        if ($m === null) {
            return null;
        }
        $type = self::mediaContentType($m);
        if ($type === null && isset($m['documentWithCaptionMessage'])) {
            $m = self::normalizeMessageContent($m);
            $type = self::mediaContentType($m);
        }
        return $type === null ? null : ['type' => $type, 'msg' => $m[$type]];
    }

    /** Extract mentioned jids from any message content ContextInfo. @return list<string> */
    public static function mentionedJids(?array $message): array
    {
        $m = self::normalizeMessageContent($message);
        if ($m === null) {
            return [];
        }
        $type = self::getContentType($m);
        $ctx = $type ? ($m[$type]['contextInfo'] ?? null) : null;
        return is_array($ctx['mentionedJid'] ?? null) ? array_values($ctx['mentionedJid']) : [];
    }
}
