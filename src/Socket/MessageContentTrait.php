<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Proto\WAProto;
use BaileysPHP\Utils\Generics;
use BaileysPHP\Utils\LinkPreview;
use BaileysPHP\Utils\Media;
use BaileysPHP\Utils\Messages;
use BaileysPHP\WABinary\Jid;

/**
 * Message-content generation — port of Baileys src/Utils/messages.ts
 * (generateWAMessageContent / generateWAMessageFromContent / prepareWAMessageMedia …)
 *
 * Content arrays mirror Baileys' AnyMessageContent:
 *  ['text' => 'hi']
 *  ['image' => $bytes|['url'=>..]|['file'=>..], 'caption' => '...', 'jpegThumbnail' => …]
 *  ['video' => …, 'caption' => …, 'gifPlayback' => true, 'ptv' => true]
 *  ['audio' => …, 'ptt' => true, 'seconds' => n, 'waveform' => …]
 *  ['document' => …, 'fileName' => 'x.pdf', 'mimetype' => …]
 *  ['sticker' => …, 'packname' => …, 'author' => …]
 *  ['location' => ['degreesLatitude' => …, 'degreesLongitude' => …, 'name' => …]]
 *  ['contacts' => ['displayName' => …, 'contacts' => [['displayName'=>…,'vcard'=>…]]]]
 *  ['react' => ['text' => '❤️', 'key' => $msgKey]]
 *  ['poll' => ['name' => …, 'values' => […], 'selectableCount' => 1]]
 *  ['event' => ['name'=>…,'description'=>…,'startDate'=>ts|\DateTimeInterface, …]]
 *  ['forward' => $waMessage, 'force' => true]
 *  ['delete' => $msgKey] / ['edit' => $msgKey, 'text' => 'new'] / ['pin' => $msgKey, 'type' => 1, 'time' => 86400]
 *  ['groupInvite' => ['jid'=>…,'inviteCode'=>…,'inviteExpiration'=>…,'subject'=>…,'text'=>…]]
 *  ['buttonReply' => …, 'type' => 'template'|'plain'] / ['listReply' => …]
 *  ['album' => ['expectedImageCount'=>…,'expectedVideoCount'=>…]]
 *  ['sharePhoneNumber' => true] / ['requestPhoneNumber' => true] / ['limitSharing' => true]
 *  ['disappearingMessagesInChat' => seconds|bool]
 *  …or a raw proto Message array like ['conversation' => 'hi'] / ['interactiveMessage' => […]]
 * Common extras: 'mentions' => [jids], 'mentionAll' => true, 'viewOnce' => true,
 * 'linkPreview' => false|array, 'contextInfo' => […], 'ephemeralExpiration' (option), quoted (option).
 */
trait MessageContentTrait
{
    private const MIMETYPE_MAP = [
        'image' => 'image/jpeg',
        'video' => 'video/mp4',
        'document' => 'application/pdf',
        'audio' => 'audio/ogg; codecs=opus',
        'sticker' => 'image/webp',
        'product-catalog-image' => 'image/jpeg',
    ];

    private const MEDIA_CONTENT_KEYS = ['image', 'video', 'audio', 'document', 'sticker'];

    private static function assertColor(mixed $color): int
    {
        if (is_int($color)) {
            return $color > 0 ? $color : 0xFFFFFFFF + $color + 1;
        }
        $hex = str_replace('#', '', trim((string)$color));
        if (strlen($hex) <= 6) {
            $hex = 'FF' . str_pad($hex, 6, '0', STR_PAD_LEFT);
        }
        return (int)hexdec($hex);
    }

    /**
     * prepareWAMessageMedia — encrypt+upload (or raw-upload for newsletters)
     * and build the {xxxMessage: {...}} proto content.
     */
    public function prepareWAMessageMedia(array $content, array $options = []): array
    {
        $mediaType = null;
        foreach (self::MEDIA_CONTENT_KEYS as $k) {
            if (isset($content[$k])) {
                $mediaType = $k;
            }
        }
        if ($mediaType === null) {
            throw new \InvalidArgumentException('Invalid media type');
        }
        $mediaInput = $content[$mediaType];
        $fields = $content;
        unset($fields[$mediaType], $fields['mentions'], $fields['mentionAll'], $fields['viewOnce'],
            $fields['contextInfo'], $fields['albumParentKey'], $fields['edit'], $fields['packname'], $fields['author']);

        if ($mediaType === 'document' && empty($fields['fileName'])) {
            $fields['fileName'] = is_array($mediaInput) && isset($mediaInput['file'])
                ? basename((string)$mediaInput['file'])
                : 'file';
        }
        $bytes = Media::resolveMediaInput($mediaInput);
        if (empty($fields['mimetype'])) {
            $detected = Media::detectMime($bytes);
            $fields['mimetype'] = $mediaType === 'audio' && !empty($fields['ptt'])
                ? 'audio/ogg; codecs=opus'
                : ($detected !== 'application/octet-stream' ? $detected : self::MIMETYPE_MAP[$mediaType]);
        }
        if ($mediaType === 'sticker' && (!empty($content['packname']) || !empty($content['author']))) {
            $bytes = Media::writeStickerExif($bytes, [
                'packname' => $content['packname'] ?? '',
                'author' => $content['author'] ?? '',
            ]);
        }

        $isNewsletter = !empty($options['jid']) && Jid::isNewsletter((string)$options['jid']);
        if ($isNewsletter) {
            $fileSha256 = hash('sha256', $bytes, true);
            $urls = $this->waUploadToServer($bytes, $options['mediaTypeOverride'] ?? $mediaType, base64_encode($fileSha256));
            $msg = array_merge($fields, [
                'url' => $urls['mediaUrl'] ?: null,
                'directPath' => $urls['directPath'] ?: null,
                'fileSha256' => $fileSha256,
                'fileLength' => strlen($bytes),
            ]);
            if ($mediaType === 'sticker') {
                $msg['stickerSentTs'] = (int)(microtime(true) * 1000);
            }
            return $this->wrapMediaProto($mediaType, $msg, $fields);
        }

        $enc = Media::encryptMedia($bytes, $options['mediaTypeOverride'] ?? $mediaType);
        $urls = $this->waUploadToServer($enc['ciphertext'], $mediaType, base64_encode($enc['fileEncSha256']));

        // extras (thumbnails / duration / waveform)
        try {
            if (($mediaType === 'image' || $mediaType === 'video') && !isset($fields['jpegThumbnail'])) {
                if ($mediaType === 'image') {
                    $thumb = Media::extractImageThumb($bytes, 32);
                    if ($thumb !== null) {
                        $fields['jpegThumbnail'] = $thumb['buffer'];
                        if (empty($fields['width'])) {
                            $fields['width'] = $thumb['width'];
                            $fields['height'] = $thumb['height'];
                        }
                    }
                }
                // video thumbnails require ffmpeg frame extraction; pass 'jpegThumbnail' in content if needed
            }
            if ($mediaType === 'audio' && !isset($fields['seconds'])) {
                $dur = Media::getAudioDuration($bytes);
                if ($dur !== null) {
                    $fields['seconds'] = (int)round($dur);
                }
            }
            if ($mediaType === 'audio' && !empty($fields['ptt']) && !isset($fields['waveform'])) {
                $fields['waveform'] = Media::generateWaveform($bytes);
            }
            if ($mediaType === 'audio' && !empty($fields['ptt']) && !empty($options['backgroundColor'])) {
                $fields['backgroundArgb'] = self::assertColor($options['backgroundColor']);
            }
        } catch (\Throwable $e) {
            $this->logger->warn(['err' => $e->getMessage()], 'failed to obtain media extra info');
        }

        $msg = array_merge($fields, [
            'url' => $urls['mediaUrl'] ?: null,
            'directPath' => $urls['directPath'] ?: null,
            'mediaKey' => $enc['mediaKey'],
            'fileEncSha256' => $enc['fileEncSha256'],
            'fileSha256' => $enc['fileSha256'],
            'fileLength' => $enc['fileLength'],
            'mediaKeyTimestamp' => time(),
        ]);
        return $this->wrapMediaProto($mediaType, $msg, $fields);
    }

    private function wrapMediaProto(string $mediaType, array $msg, array $fields): array
    {
        if (!empty($fields['ptv']) && $mediaType === 'video') {
            unset($msg['ptv']);
            return ['ptvMessage' => $msg];
        }
        return [$mediaType . 'Message' => $msg];
    }

    public static function prepareDisappearingMessageSettingContent(int $ephemeralExpiration = 0): array
    {
        return [
            'ephemeralMessage' => [
                'message' => [
                    'protocolMessage' => [
                        'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'EPHEMERAL_SETTING'),
                        'ephemeralExpiration' => $ephemeralExpiration,
                    ],
                ],
            ],
        ];
    }

    /** Forward-content generation (generateForwardMessageContent). */
    public static function generateForwardMessageContent(array $message, bool $forceForward = false): array
    {
        $content = $message['message'] ?? null;
        if (!$content) {
            throw new \InvalidArgumentException('no content in message');
        }
        $content = Messages::normalizeMessageContent($content);
        // deep copy through proto round-trip (also strips unknown junk)
        $content = WAProto::decodeMessage(WAProto::encodeMessage($content));
        unset($content['_rawFields']);
        $key = Messages::getContentType($content) ?? array_key_first($content);
        $score = is_array($content[$key] ?? null) ? (int)($content[$key]['contextInfo']['forwardingScore'] ?? 0) : 0;
        $score += (!empty($message['key']['fromMe']) && !$forceForward) ? 0 : 1;
        if ($key === 'conversation') {
            $content = ['extendedTextMessage' => ['text' => $content['conversation']]];
            $key = 'extendedTextMessage';
        }
        if (is_array($content[$key] ?? null)) {
            $content[$key]['contextInfo'] = $score > 0
                ? ['forwardingScore' => $score, 'isForwarded' => true]
                : [];
        }
        return $content;
    }

    /**
     * generateWAMessageContent(content, options) → proto Message array.
     * options: jid, backgroundColor, font, generateHighQualityLinkPreview is in config.
     */
    public function generateWAMessageContent(array $content, array $options = []): array
    {
        $m = [];
        $has = fn(string $k): bool => isset($content[$k]);

        if ($has('text')) {
            $ext = ['text' => (string)$content['text']];
            $urlInfo = $content['linkPreview'] ?? null;
            if ($urlInfo === null && ($this->config['generateLinkPreview'] ?? true)) {
                $uploadThumb = null;
                if (!empty($this->config['generateHighQualityLinkPreview'])) {
                    $uploadThumb = function (string $jpeg): ?array {
                        $enc = Media::encryptMedia($jpeg, 'thumbnail-link');
                        $urls = $this->waUploadToServer($enc['ciphertext'], 'thumbnail-link', base64_encode($enc['fileEncSha256']));
                        return [
                            'directPath' => $urls['directPath'],
                            'mediaKey' => $enc['mediaKey'],
                            'mediaKeyTimestamp' => time(),
                            'fileSha256' => $enc['fileSha256'],
                            'fileEncSha256' => $enc['fileEncSha256'],
                        ];
                    };
                }
                $urlInfo = LinkPreview::getUrlInfo(
                    (string)$content['text'],
                    $uploadThumb,
                    (int)($this->config['linkPreviewImageThumbnailWidth'] ?? 192),
                    $this->logger
                );
            }
            if (is_array($urlInfo) && $urlInfo !== []) {
                $ext['matchedText'] = $urlInfo['matched-text'] ?? null;
                $ext['jpegThumbnail'] = $urlInfo['jpegThumbnail'] ?? null;
                $ext['description'] = $urlInfo['description'] ?? null;
                $ext['title'] = $urlInfo['title'] ?? null;
                $ext['previewType'] = 0;
                $img = $urlInfo['highQualityThumbnail'] ?? null;
                if (is_array($img)) {
                    $ext['thumbnailDirectPath'] = $img['directPath'] ?? null;
                    $ext['mediaKey'] = $img['mediaKey'] ?? null;
                    $ext['mediaKeyTimestamp'] = $img['mediaKeyTimestamp'] ?? null;
                    $ext['thumbnailWidth'] = $img['width'] ?? null;
                    $ext['thumbnailHeight'] = $img['height'] ?? null;
                    $ext['thumbnailSha256'] = $img['fileSha256'] ?? null;
                    $ext['thumbnailEncSha256'] = $img['fileEncSha256'] ?? null;
                }
            }
            if (!empty($options['backgroundColor'])) {
                $ext['backgroundArgb'] = self::assertColor($options['backgroundColor']);
            }
            if (isset($options['font'])) {
                $ext['font'] = $options['font'];
            }
            $m['extendedTextMessage'] = array_filter($ext, fn($v) => $v !== null);
        } elseif ($has('contacts')) {
            $contacts = $content['contacts'];
            $list = $contacts['contacts'] ?? [];
            if ($list === []) {
                throw new \InvalidArgumentException('require at least 1 contact');
            }
            if (count($list) === 1) {
                $m['contactMessage'] = $list[0];
            } else {
                $m['contactsArrayMessage'] = $contacts;
            }
        } elseif ($has('location')) {
            $m['locationMessage'] = $content['location'];
        } elseif ($has('liveLocation')) {
            $m['liveLocationMessage'] = $content['liveLocation'];
        } elseif ($has('react')) {
            $react = $content['react'];
            if (empty($react['senderTimestampMs'])) {
                $react['senderTimestampMs'] = (int)(microtime(true) * 1000);
            }
            $m['reactionMessage'] = $react;
        } elseif ($has('delete')) {
            $m['protocolMessage'] = [
                'key' => $content['delete'],
                'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'REVOKE'),
            ];
        } elseif ($has('forward')) {
            $m = self::generateForwardMessageContent($content['forward'], !empty($content['force']));
        } elseif ($has('disappearingMessagesInChat')) {
            $v = $content['disappearingMessagesInChat'];
            $exp = is_bool($v) ? ($v ? 604800 : 0) : (int)$v;
            $m = self::prepareDisappearingMessageSettingContent($exp);
        } elseif ($has('groupInvite')) {
            $g = $content['groupInvite'];
            $invite = [
                'inviteCode' => $g['inviteCode'] ?? null,
                'inviteExpiration' => $g['inviteExpiration'] ?? null,
                'caption' => $g['text'] ?? $g['caption'] ?? null,
                'groupJid' => $g['jid'] ?? $g['groupJid'] ?? null,
                'groupName' => $g['subject'] ?? $g['groupName'] ?? null,
            ];
            if (!empty($g['jpegThumbnail'])) {
                $invite['jpegThumbnail'] = Media::resolveMediaInput($g['jpegThumbnail']);
            } elseif (!empty($invite['groupJid']) && method_exists($this, 'profilePictureUrl')) {
                try {
                    $url = $this->profilePictureUrl($invite['groupJid'], 'preview');
                    if ($url) {
                        $invite['jpegThumbnail'] = Media::resolveMediaInput(['url' => $url]);
                    }
                } catch (\Throwable) {
                }
            }
            $m['groupInviteMessage'] = array_filter($invite, fn($v) => $v !== null);
        } elseif ($has('pin')) {
            $type = (int)($content['type'] ?? 1);
            $m['pinInChatMessage'] = [
                'key' => $content['pin'],
                'type' => $type,
                'senderTimestampMs' => (int)(microtime(true) * 1000),
            ];
            $m['messageContextInfo'] = [
                'messageAddOnDurationInSecs' => $type === 1 ? (int)($content['time'] ?? 86400) : 0,
            ];
        } elseif ($has('buttonReply')) {
            if (($content['type'] ?? null) === 'template') {
                $m['templateButtonReplyMessage'] = [
                    'selectedDisplayText' => $content['buttonReply']['displayText'] ?? null,
                    'selectedId' => $content['buttonReply']['id'] ?? null,
                    'selectedIndex' => $content['buttonReply']['index'] ?? null,
                ];
            } else {
                $m['buttonsResponseMessage'] = [
                    'selectedButtonId' => $content['buttonReply']['id'] ?? null,
                    'selectedDisplayText' => $content['buttonReply']['displayText'] ?? null,
                    'type' => WAProto::enumValue('Message.ButtonsResponseMessage.Type', 'DISPLAY_TEXT'),
                ];
            }
        } elseif (!empty($content['ptv'])) {
            $prepared = $this->prepareWAMessageMedia(['video' => $content['video'] ?? null] + $content, $options);
            $m['ptvMessage'] = $prepared['videoMessage'] ?? $prepared['ptvMessage'];
        } elseif ($has('product')) {
            $prepared = $this->prepareWAMessageMedia(['image' => $content['product']['productImage'] ?? null], $options);
            $product = $content;
            $product['product']['productImage'] = $prepared['imageMessage'];
            $m['productMessage'] = $product;
        } elseif ($has('listReply')) {
            $m['listResponseMessage'] = $content['listReply'];
        } elseif ($has('event')) {
            $e = $content['event'];
            $start = $e['startDate'] ?? null;
            $startTs = $start instanceof \DateTimeInterface ? $start->getTimestamp() : (is_numeric($start) ? (int)$start : strtotime((string)$start));
            $ev = [];
            if (!empty($e['call']) && method_exists($this, 'createCallLink')) {
                $token = $this->createCallLink($e['call'], ['startTime' => $startTs]);
                $ev['joinLink'] = ($e['call'] === 'audio' ? 'https://call.whatsapp.com/voice/' : 'https://call.whatsapp.com/video/') . $token;
            }
            $m['messageContextInfo'] = ['messageSecret' => $e['messageSecret'] ?? random_bytes(32)];
            $ev['name'] = $e['name'] ?? null;
            $ev['description'] = $e['description'] ?? null;
            $ev['startTime'] = $startTs;
            $end = $e['endDate'] ?? null;
            $ev['endTime'] = $end instanceof \DateTimeInterface ? $end->getTimestamp() : (is_numeric($end) ? (int)$end : null);
            $ev['isCanceled'] = $e['isCancelled'] ?? false;
            $ev['extraGuestsAllowed'] = $e['extraGuestsAllowed'] ?? null;
            $ev['isScheduleCall'] = $e['isScheduleCall'] ?? false;
            $ev['location'] = $e['location'] ?? null;
            $m['eventMessage'] = array_filter($ev, fn($v) => $v !== null);
        } elseif ($has('poll')) {
            $poll = $content['poll'];
            $selectable = (int)($poll['selectableCount'] ?? 0);
            $values = $poll['values'] ?? null;
            if (!is_array($values)) {
                throw new \InvalidArgumentException('Invalid poll values');
            }
            if ($selectable < 0 || $selectable > count($values)) {
                throw new \InvalidArgumentException("poll.selectableCount should be >= 0 and <= " . count($values));
            }
            $m['messageContextInfo'] = ['messageSecret' => $poll['messageSecret'] ?? random_bytes(32)];
            $pollMsg = [
                'name' => $poll['name'] ?? '',
                'selectableOptionsCount' => $selectable,
                'options' => array_map(fn($v) => ['optionName' => is_array($v) ? ($v['optionName'] ?? '') : (string)$v], $values),
            ];
            if (!empty($poll['toAnnouncementGroup'])) {
                $m['pollCreationMessageV2'] = $pollMsg;
            } elseif ($selectable === 1) {
                $m['pollCreationMessageV3'] = $pollMsg;
            } else {
                $m['pollCreationMessage'] = $pollMsg;
            }
        } elseif ($has('album')) {
            $m['albumMessage'] = [
                'expectedImageCount' => $content['album']['expectedImageCount'] ?? null,
                'expectedVideoCount' => $content['album']['expectedVideoCount'] ?? null,
            ];
        } elseif ($has('sharePhoneNumber')) {
            $m['protocolMessage'] = ['type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'SHARE_PHONE_NUMBER')];
        } elseif ($has('requestPhoneNumber')) {
            $m['requestPhoneNumberMessage'] = [];
        } elseif ($has('limitSharing')) {
            $m['protocolMessage'] = [
                'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'LIMIT_SHARING'),
                'limitSharing' => [
                    'sharingLimited' => $content['limitSharing'] === true,
                    'trigger' => 1,
                    'limitSharingSettingTimestamp' => (int)(microtime(true) * 1000),
                    'initiatedByMe' => true,
                ],
            ];
        } elseif ($this->isRawProtoMessage($content)) {
            // raw proto Message array (conversation/extendedTextMessage/interactiveMessage/…)
            $m = $content;
            foreach (['mentions', 'mentionAll', 'viewOnce', 'edit', 'contextInfo', 'albumParentKey'] as $extra) {
                unset($m[$extra]);
            }
        } else {
            $m = $this->prepareWAMessageMedia($content, $options);
        }

        if (!empty($content['viewOnce'])) {
            $m = ['viewOnceMessage' => ['message' => $m]];
        }

        if (!empty($content['mentions']) || !empty($content['mentionAll'])) {
            $key = array_key_first($m);
            // unwrap view-once for mention placement like upstream (first key)
            if (is_array($m[$key] ?? null)) {
                $m[$key]['contextInfo'] ??= [];
                if (!empty($content['mentions'])) {
                    $m[$key]['contextInfo']['mentionedJid'] = array_values($content['mentions']);
                }
                if (!empty($content['mentionAll'])) {
                    $m[$key]['contextInfo']['nonJidMentions'] = 1;
                }
            }
        }

        if (isset($content['edit'])) {
            $m = [
                'protocolMessage' => [
                    'key' => $content['edit'],
                    'editedMessage' => $m,
                    'timestampMs' => (int)(microtime(true) * 1000),
                    'type' => WAProto::enumValue('Message.ProtocolMessage.Type', 'MESSAGE_EDIT'),
                ],
            ];
        }

        if (!empty($content['contextInfo']) && is_array($content['contextInfo'])) {
            $key = array_key_first($m);
            if (is_array($m[$key] ?? null)) {
                $m[$key]['contextInfo'] = array_merge($m[$key]['contextInfo'] ?? [], $content['contextInfo']);
            }
        }

        if (!empty($content['albumParentKey'])) {
            $m['messageContextInfo'] = array_merge($m['messageContextInfo'] ?? [], [
                'messageAssociation' => [
                    'associationType' => WAProto::enumValue('MessageAssociation.AssociationType', 'MEDIA_ALBUM'),
                    'parentMessageKey' => $content['albumParentKey'],
                ],
            ]);
        }

        // reporting token message secret
        if (!isset($m['reactionMessage']) && !isset($m['encReactionMessage'])
            && !isset($m['encEventResponseMessage']) && !isset($m['pollUpdateMessage'])) {
            $m['messageContextInfo'] ??= [];
            if (empty($m['messageContextInfo']['messageSecret']) && ($this->config['includeReportingTokenSecret'] ?? true)) {
                $m['messageContextInfo']['messageSecret'] = random_bytes(32);
            }
            if (($m['messageContextInfo'] ?? []) === []) {
                unset($m['messageContextInfo']);
            }
        }
        return $m;
    }

    private function isRawProtoMessage(array $content): bool
    {
        foreach (array_keys($content) as $k) {
            if ($k === 'conversation' || (str_ends_with((string)$k, 'Message') && $k !== 'messageContextInfo')) {
                return true;
            }
        }
        return isset($content['messageContextInfo']);
    }

    /**
     * generateWAMessageFromContent(jid, message, options) → full WAMessage array.
     * options: quoted (WAMessage), ephemeralExpiration, messageId, timestamp, userJid
     */
    public function generateWAMessageFromContent(string $jid, array $message, array $options = []): array
    {
        $meId = $this->creds()->me['id'] ?? null;
        $userJid = $options['userJid'] ?? $meId;
        $timestamp = isset($options['timestamp'])
            ? ($options['timestamp'] instanceof \DateTimeInterface ? $options['timestamp']->getTimestamp() : (int)$options['timestamp'])
            : time();

        $message = $this->applyQuotedAndEphemeral($jid, $message, $options, $userJid);

        return [
            'key' => [
                'remoteJid' => $jid,
                'fromMe' => true,
                'id' => $options['messageId'] ?? Generics::generateMessageIDV2($meId),
            ],
            'message' => $message,
            'messageTimestamp' => $timestamp,
            'participant' => (Jid::isGroup($jid) || Jid::isStatusBroadcast($jid)) ? $userJid : null,
            'status' => 1, // PENDING
        ];
    }

    private function applyQuotedAndEphemeral(string $jid, array $message, array $options, ?string $userJid): array
    {
        $quoted = $options['quoted'] ?? null;
        $ephExp = $options['ephemeralExpiration'] ?? null;
        if (!$quoted && !$ephExp) {
            return $message;
        }
        // work on the normalized (unwrapped) inner message, then re-wrap
        $wrappers = [];
        $inner = $message;
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
            if (isset($inner[$w]['message'])) {
                $wrappers[] = $w;
                $inner = $inner[$w]['message'];
                break;
            }
        }
        $key = Messages::getContentType($inner);
        if ($key === null || !is_array($inner[$key] ?? null)) {
            return $message;
        }
        if (is_string($inner[$key])) {
            return $message;
        }

        if ($quoted && !Jid::isNewsletter($jid)) {
            $participant = !empty($quoted['key']['fromMe'])
                ? $userJid
                : ($quoted['participant'] ?? $quoted['key']['participant'] ?? $quoted['key']['remoteJid']);
            $quotedMsg = Messages::normalizeMessageContent($quoted['message'] ?? null) ?? [];
            $quotedType = Messages::getContentType($quotedMsg);
            if ($quotedType !== null) {
                $stripped = [$quotedType => $quotedMsg[$quotedType]];
                if (is_array($stripped[$quotedType])) {
                    unset($stripped[$quotedType]['contextInfo']);
                }
                $ctx = $inner[$key]['contextInfo'] ?? [];
                $ctx['participant'] = Jid::normalizedUser($participant ?? '');
                $ctx['stanzaId'] = $quoted['key']['id'] ?? null;
                $ctx['quotedMessage'] = $stripped;
                if ($jid !== ($quoted['key']['remoteJid'] ?? null)) {
                    $ctx['remoteJid'] = $quoted['key']['remoteJid'];
                }
                $inner[$key]['contextInfo'] = $ctx;
            }
        }

        if ($ephExp && $key !== 'protocolMessage' && !Jid::isNewsletter($jid)) {
            $inner[$key]['contextInfo'] = array_merge($inner[$key]['contextInfo'] ?? [], [
                'expiration' => (int)$ephExp,
            ]);
        }

        foreach (array_reverse($wrappers) as $w) {
            $inner = [$w => ['message' => $inner]];
        }
        return $inner;
    }

    /** generateWAMessage(jid, content, options) */
    public function generateWAMessage(string $jid, array $content, array $options = []): array
    {
        $options['messageId'] ??= Generics::generateMessageIDV2($this->creds()->me['id'] ?? null);
        $proto = $this->generateWAMessageContent($content, array_merge($options, ['jid' => $jid]));
        return $this->generateWAMessageFromContent($jid, $proto, $options);
    }
}
