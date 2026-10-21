<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\ConnectionException;
use BaileysPHP\Proto\WAProto;
use BaileysPHP\Utils\Media;
use BaileysPHP\Utils\Messages;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Media upload/download + media-retry (Baileys messages-media.ts + parts of messages-send.ts)
 */
trait MediaTrait
{
    /** @var null|array{hosts:list<array{hostname:string,maxContentLengthBytes:int}>,auth:string,ttl:int,fetchTime:int} */
    private ?array $mediaConnCache = null;
    private string $mediaHost = Media::DEF_MEDIA_HOST;

    /** @var array<string,array> pending media-update results keyed "remoteJid|id" */
    private array $pendingMediaUpdates = [];

    public function refreshMediaConn(bool $force = false): array
    {
        if (!$force && $this->mediaConnCache !== null
            && time() - $this->mediaConnCache['fetchTime'] < $this->mediaConnCache['ttl']) {
            return $this->mediaConnCache;
        }
        $result = $this->query(new BinaryNode('iq', [
            'type' => 'set',
            'xmlns' => 'w:m',
            'to' => 's.whatsapp.net',
        ], [new BinaryNode('media_conn')]));
        $node = GenericUtils::getBinaryNodeChild($result, 'media_conn');
        if ($node === null) {
            throw new ConnectionException('failed to fetch media connection info', 500);
        }
        $hosts = [];
        foreach (GenericUtils::getBinaryNodeChildren($node, 'host') as $host) {
            $hosts[] = [
                'hostname' => $host->attrs['hostname'] ?? '',
                'maxContentLengthBytes' => (int)($host->attrs['maxContentLengthBytes'] ?? 0),
            ];
        }
        $this->mediaConnCache = [
            'hosts' => $hosts,
            'auth' => $node->attrs['auth'] ?? '',
            'ttl' => (int)($node->attrs['ttl'] ?? 0),
            'fetchTime' => time(),
        ];
        if ($hosts !== []) {
            $this->mediaHost = $hosts[0]['hostname'];
        }
        $this->logger->debug('fetched media conn');
        return $this->mediaConnCache;
    }

    /**
     * Upload already-encrypted media bytes.
     * @return array{mediaUrl:string,directPath:string,meta_hmac:?string,fbid:?int,ts:?int}
     */
    public function waUploadToServer(string $encBytes, string $mediaType, string $fileEncSha256B64): array
    {
        $path = Media::PATH_MAP[$mediaType] ?? null;
        if ($path === null || $path === '') {
            throw new \InvalidArgumentException("no upload path for media type $mediaType");
        }
        $uploadInfo = $this->refreshMediaConn(false);
        $token = Media::encodeBase64EncodedStringForUpload($fileEncSha256B64);
        $lastError = null;
        foreach ($uploadInfo['hosts'] as $host) {
            $hostname = $host['hostname'];
            $auth = rawurlencode($uploadInfo['auth']);
            $url = "https://$hostname$path/$token?auth=$auth&token=$token";
            try {
                $res = Media::httpPostOctet($url, $encBytes);
                $json = json_decode($res['body'], true);
                if (is_array($json) && (!empty($json['url']) || !empty($json['direct_path']))) {
                    return [
                        'mediaUrl' => $json['url'] ?? '',
                        'directPath' => $json['direct_path'] ?? '',
                        'meta_hmac' => $json['meta_hmac'] ?? null,
                        'fbid' => $json['fbid'] ?? null,
                        'ts' => $json['ts'] ?? null,
                    ];
                }
                $lastError = new \RuntimeException('upload failed: ' . substr($res['body'], 0, 200));
                $uploadInfo = $this->refreshMediaConn(true);
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->warn(['err' => $e->getMessage()], "Error in uploading to $hostname");
            }
        }
        throw new \RuntimeException('Media upload failed on all hosts' . ($lastError ? ': ' . $lastError->getMessage() : ''));
    }

    /**
     * Encrypt + upload media, returning proto fields for the media message node.
     * @return array<string,mixed>
     */
    public function uploadMedia(mixed $data, string $mediaType, ?string $mediaKey = null): array
    {
        $bytes = Media::resolveMediaInput($data);
        $enc = Media::encryptMedia($bytes, $mediaType, $mediaKey);
        $urls = $this->waUploadToServer($enc['ciphertext'], $mediaType, base64_encode($enc['fileEncSha256']));
        return [
            'mediaKey' => $enc['mediaKey'],
            'fileSha256' => $enc['fileSha256'],
            'fileEncSha256' => $enc['fileEncSha256'],
            'fileLength' => $enc['fileLength'],
            'url' => $urls['mediaUrl'] ?: null,
            'directPath' => $urls['directPath'] ?: null,
            'mediaKeyTimestamp' => time(),
        ];
    }

    /**
     * downloadContentFromMessage(downloadable, mediaType) → decrypted bytes.
     * @param array{mediaKey:string,directPath?:string,url?:string} $downloadable
     */
    public function downloadContentFromMessage(array $downloadable, string $mediaType): string
    {
        // candidate URLs: directPath against the media-conn host, then the
        // default media host (some blobs, e.g. app-state snapshots, are only
        // served from mmg.whatsapp.net and answer 403 on edge hosts), then url
        $urls = [];
        if (!empty($downloadable['directPath'])) {
            $hosts = array_unique(array_filter([$this->mediaHost, Media::DEF_MEDIA_HOST]));
            foreach ($hosts as $host) {
                $urls[] = Media::getUrlFromDirectPath($downloadable['directPath'], $host);
            }
        } elseif (!empty($downloadable['url'])) {
            $urls[] = $downloadable['url'];
        }
        if ($urls === []) {
            throw new \RuntimeException('No valid media URL or directPath present in message');
        }
        $res = null;
        foreach ($urls as $i => $candidate) {
            $res = Media::httpGet($candidate);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                break;
            }
            if (isset($urls[$i + 1])) {
                $this->logger->debug(['url' => $candidate, 'status' => $res['status']], 'media download failed, trying next host');
            }
        }
        if ($res === null || $res['status'] < 200 || $res['status'] >= 300) {
            $code = $res['status'] ?? 500;
            throw new ConnectionException("failed to download media (HTTP $code)", $code ?: 500);
        }
        return Media::decryptMedia($res['body'], (string)$downloadable['mediaKey'], $mediaType);
    }

    /**
     * downloadMediaMessage(waMessage, options?) → decrypted media bytes
     * options: reuploadRequest (bool, default true) — ask the phone to re-upload on 404/410
     * @param array<string,mixed> $message WAMessage
     */
    public function downloadMediaMessage(array $message, array $options = []): string
    {
        $part = Messages::getMediaPart($message);
        if ($part === null) {
            throw new \InvalidArgumentException('message has no downloadable media content');
        }
        $mediaType = Media::mediaTypeForContent($part['type']);
        try {
            return $this->downloadContentFromMessage($part['msg'], $mediaType);
        } catch (ConnectionException $e) {
            $status = $e->getCode();
            if (($options['reuploadRequest'] ?? true) && in_array($status, [404, 410], true)) {
                $this->logger->info(['key' => $message['key'] ?? null], 'media expired, requesting re-upload');
                $updated = $this->updateMediaMessage($message);
                $newPart = Messages::getMediaPart($updated);
                if ($newPart !== null) {
                    return $this->downloadContentFromMessage($newPart['msg'], Media::mediaTypeForContent($newPart['type']));
                }
            }
            throw $e;
        }
    }

    /**
     * Ask the primary phone to re-upload expired media; returns the message with refreshed directPath/url.
     * (Baileys: updateMediaMessage — sends server-error receipt and waits for mediaretry notification.)
     * @param array<string,mixed> $message WAMessage
     * @return array<string,mixed>
     */
    /**
     * downloadAndSaveMediaMessage(message, filename, attachExtension) — Utils/messages-media.ts.
     * Returns the path written.
     */
    public function downloadAndSaveMediaMessage(array $message, string $filename, bool $attachExtension = true): string
    {
        $bytes = $this->downloadMediaMessage($message);
        if ($bytes === null) {
            throw new \RuntimeException('downloadAndSaveMediaMessage: no downloadable media in message');
        }
        $path = $filename;
        if ($attachExtension && pathinfo($path, PATHINFO_EXTENSION) === '') {
            $content = \BaileysPHP\Utils\Messages::normalizeMessageContent($message['message'] ?? []);
            $first = $content ? reset($content) : null;
            $mime = is_array($first) ? (string)($first['mimetype'] ?? '') : '';
            $ext = match (true) {
                str_starts_with($mime, 'image/jpeg') => 'jpg',
                str_starts_with($mime, 'image/') => substr(explode(';', substr($mime, 6))[0], 0, 10),
                str_starts_with($mime, 'video/') => 'mp4',
                str_starts_with($mime, 'audio/ogg') => 'ogg',
                str_starts_with($mime, 'audio/') => 'mp3',
                default => 'bin',
            };
            $path .= '.' . ($ext !== '' ? $ext : 'bin');
        }
        file_put_contents($path, $bytes);
        return $path;
    }

    public function updateMediaMessage(array $message, float $timeout = 60): array
    {
        $part = Messages::getMediaPart($message);
        if ($part === null || empty($part['msg']['mediaKey'])) {
            throw new \InvalidArgumentException('message has no mediaKey to request re-upload');
        }
        $key = $message['key'];
        $mediaKey = (string)$part['msg']['mediaKey'];
        $cacheKey = ($key['remoteJid'] ?? '') . '|' . ($key['id'] ?? '');

        $receipt = $this->encryptMediaRetryRequest($key, $mediaKey);
        $this->sendNode($receipt);

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (isset($this->pendingMediaUpdates[$cacheKey])) {
                $event = $this->pendingMediaUpdates[$cacheKey];
                unset($this->pendingMediaUpdates[$cacheKey]);
                if (!empty($event['error'])) {
                    throw $event['error'];
                }
                $retry = $this->decryptMediaRetryData($event['media'], $mediaKey, (string)$key['id']);
                $result = $retry['result'] ?? null;
                if ($result === WAProto::enumValue('MediaRetryNotification.ResultType', 'SUCCESS')
                    || $result === 'SUCCESS') {
                    $updated = $message;
                    $type = Messages::getContentType(Messages::normalizeMessageContent($updated['message'] ?? null));
                    if ($type !== null) {
                        if (!empty($retry['directPath'])) {
                            $updated['message'] = $this->withRefreshedMedia($updated['message'], $type, $retry['directPath']);
                        }
                    }
                    return $updated;
                }
                $codeMap = [
                    WAProto::enumValue('MediaRetryNotification.ResultType', 'DECRYPTION_ERROR') => 412,
                    WAProto::enumValue('MediaRetryNotification.ResultType', 'NOT_FOUND') => 404,
                    WAProto::enumValue('MediaRetryNotification.ResultType', 'GENERAL_ERROR') => 418,
                ];
                throw new ConnectionException('media re-upload failed', $codeMap[$result] ?? 500);
            }
            $this->pump(0.5);
        }
        throw new \RuntimeException('timed out waiting for media re-upload');
    }

    /** Rewrite directPath/url inside (possibly wrapped) message content. */
    private function withRefreshedMedia(array $content, string $contentType, string $directPath): array
    {
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
            if (isset($content[$w]['message'])) {
                $content[$w]['message'] = $this->withRefreshedMedia($content[$w]['message'], $contentType, $directPath);
                return $content;
            }
        }
        if (isset($content[$contentType])) {
            $content[$contentType]['directPath'] = $directPath;
            $content[$contentType]['url'] = 'https://' . Media::DEF_MEDIA_HOST . $directPath;
        }
        return $content;
    }

    /** Port of encryptMediaRetryRequest(). */
    public function encryptMediaRetryRequest(array $key, string $mediaKey): BinaryNode
    {
        $meId = $this->creds()->me['id'] ?? '';
        $recpBuffer = WAProto::encodeProto('ServerErrorReceipt', ['stanzaId' => $key['id'] ?? '']);
        $iv = random_bytes(12);
        $retryKey = Media::mediaRetryKey($mediaKey);
        $ciphertext = \BaileysPHP\Utils\aesEncryptGCM($recpBuffer, $retryKey, $iv, (string)($key['id'] ?? ''));
        $rmrAttrs = [
            'jid' => $key['remoteJid'] ?? '',
            'from_me' => !empty($key['fromMe']) ? 'true' : 'false',
        ];
        if (!empty($key['participant'])) {
            $rmrAttrs['participant'] = $key['participant'];
        }
        return new BinaryNode('receipt', [
            'id' => $key['id'] ?? '',
            'to' => Jid::normalizedUser($meId),
            'type' => 'server-error',
        ], [
            new BinaryNode('encrypt', [], [
                new BinaryNode('enc_p', [], new Bytes($ciphertext)),
                new BinaryNode('enc_iv', [], new Bytes($iv)),
            ]),
            new BinaryNode('rmr', $rmrAttrs),
        ]);
    }

    /** Port of decodeMediaRetryNode(); also caches results for updateMediaMessage(). */
    private function decodeMediaRetryNode(BinaryNode $node): array
    {
        $rmrNode = GenericUtils::getBinaryNodeChild($node, 'rmr');
        $event = [
            'key' => [
                'id' => $node->attrs['id'] ?? null,
                'remoteJid' => $rmrNode?->attrs['jid'] ?? null,
                'fromMe' => ($rmrNode?->attrs['from_me'] ?? '') === 'true',
                'participant' => $rmrNode?->attrs['participant'] ?? null,
            ],
        ];
        $errorNode = GenericUtils::getBinaryNodeChild($node, 'error');
        if ($errorNode !== null) {
            $code = (int)($errorNode->attrs['code'] ?? 500);
            $map = [
                WAProto::enumValue('MediaRetryNotification.ResultType', 'SUCCESS') => 200,
                WAProto::enumValue('MediaRetryNotification.ResultType', 'DECRYPTION_ERROR') => 412,
                WAProto::enumValue('MediaRetryNotification.ResultType', 'NOT_FOUND') => 404,
                WAProto::enumValue('MediaRetryNotification.ResultType', 'GENERAL_ERROR') => 418,
            ];
            $event['error'] = new ConnectionException("Failed to re-upload media ($code)", $map[$code] ?? 500);
        } else {
            $encryptNode = GenericUtils::getBinaryNodeChild($node, 'encrypt');
            $ciphertext = GenericUtils::getBinaryNodeChild($encryptNode, 'enc_p')?->contentString();
            $iv = GenericUtils::getBinaryNodeChild($encryptNode, 'enc_iv')?->contentString();
            if ($ciphertext !== null && $iv !== null) {
                $event['media'] = ['ciphertext' => $ciphertext, 'iv' => $iv];
            } else {
                $event['error'] = new ConnectionException('Failed to re-upload media (missing ciphertext)', 404);
            }
        }
        $cacheKey = ($event['key']['remoteJid'] ?? '') . '|' . ($event['key']['id'] ?? '');
        $this->pendingMediaUpdates[$cacheKey] = $event;
        return $event;
    }

    /** Port of decryptMediaRetryData(). */
    public function decryptMediaRetryData(array $media, string $mediaKey, string $msgId): array
    {
        $retryKey = Media::mediaRetryKey($mediaKey);
        $plaintext = \BaileysPHP\Utils\aesDecryptGCM($media['ciphertext'], $retryKey, $media['iv'], $msgId);
        return WAProto::decodeProto('MediaRetryNotification', $plaintext);
    }
}
