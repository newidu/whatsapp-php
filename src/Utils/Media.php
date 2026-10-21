<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Media crypto + HTTP helpers — port of Baileys src/Utils/messages-media.ts
 * (buffer-based; large files are loaded into memory — fine for typical bot use).
 */
final class Media
{
    public const HKDF_MAPPING = [
        'audio' => 'Audio',
        'document' => 'Document',
        'gif' => 'Video',
        'image' => 'Image',
        'ppic' => '',
        'product' => 'Image',
        'ptt' => 'Audio',
        'sticker' => 'Image',
        'video' => 'Video',
        'thumbnail-document' => 'Document Thumbnail',
        'thumbnail-image' => 'Image Thumbnail',
        'thumbnail-video' => 'Video Thumbnail',
        'thumbnail-link' => 'Link Thumbnail',
        'md-msg-hist' => 'History',
        'md-app-state' => 'App State',
        'product-catalog-image' => '',
        'payment-bg-image' => 'Payment Background',
        'ptv' => 'Video',
        'biz-cover-photo' => 'Image',
    ];

    public const PATH_MAP = [
        'image' => '/mms/image',
        'video' => '/mms/video',
        'document' => '/mms/document',
        'audio' => '/mms/audio',
        'sticker' => '/mms/image',
        'thumbnail-link' => '/mms/image',
        'product-catalog-image' => '/product/image',
        'md-app-state' => '',
        'md-msg-hist' => '/mms/md-app-state',
        'biz-cover-photo' => '/pps/biz-cover-photo',
    ];

    public const DEF_MEDIA_HOST = 'mmg.whatsapp.net';
    public const DEFAULT_ORIGIN = 'https://web.whatsapp.com';

    /** proto message content type => media (HKDF/path) type */
    private const CONTENT_TO_MEDIA = [
        'imageMessage' => 'image',
        'videoMessage' => 'video',
        'ptvMessage' => 'video',
        'audioMessage' => 'audio',
        'documentMessage' => 'document',
        'stickerMessage' => 'sticker',
    ];

    public static function mediaTypeForContent(?string $contentType): ?string
    {
        return $contentType ? (self::CONTENT_TO_MEDIA[$contentType] ?? null) : null;
    }

    public static function hkdfInfoKey(string $type): string
    {
        if (!array_key_exists($type, self::HKDF_MAPPING)) {
            throw new \InvalidArgumentException("unknown media type: $type");
        }
        return 'WhatsApp ' . self::HKDF_MAPPING[$type] . ' Keys';
    }

    /** @return array{iv:string,cipherKey:string,macKey:string} */
    public static function getMediaKeys(string $mediaKey, string $mediaType): array
    {
        if ($mediaKey === '') {
            throw new \RuntimeException('Cannot derive from empty media key');
        }
        $expanded = hkdf($mediaKey, 112, '', self::hkdfInfoKey($mediaType));
        return [
            'iv' => substr($expanded, 0, 16),
            'cipherKey' => substr($expanded, 16, 32),
            'macKey' => substr($expanded, 48, 32),
        ];
    }

    /**
     * Encrypt media for upload.
     * @return array{mediaKey:string,ciphertext:string,fileSha256:string,fileEncSha256:string,fileLength:int}
     */
    public static function encryptMedia(string $plaintext, string $mediaType, ?string $mediaKey = null): array
    {
        $mediaKey ??= random_bytes(32);
        $keys = self::getMediaKeys($mediaKey, $mediaType);
        $enc = openssl_encrypt($plaintext, 'aes-256-cbc', $keys['cipherKey'], OPENSSL_RAW_DATA, $keys['iv']);
        if ($enc === false) {
            throw new \RuntimeException('media encryption failed');
        }
        $mac = substr(hash_hmac('sha256', $keys['iv'] . $enc, $keys['macKey'], true), 0, 10);
        $full = $enc . $mac;
        return [
            'mediaKey' => $mediaKey,
            'ciphertext' => $full,
            'fileSha256' => hash('sha256', $plaintext, true),
            'fileEncSha256' => hash('sha256', $full, true),
            'fileLength' => strlen($plaintext),
        ];
    }

    /** Decrypt downloaded media (ciphertext incl. trailing 10-byte MAC), verifying the MAC. */
    public static function decryptMedia(string $ciphertextWithMac, string $mediaKey, string $mediaType): string
    {
        $keys = self::getMediaKeys($mediaKey, $mediaType);
        if (strlen($ciphertextWithMac) < 10) {
            throw new \RuntimeException('media ciphertext too short');
        }
        $mac = substr($ciphertextWithMac, -10);
        $enc = substr($ciphertextWithMac, 0, -10);
        $expected = substr(hash_hmac('sha256', $keys['iv'] . $enc, $keys['macKey'], true), 0, 10);
        if (!hash_equals($expected, $mac)) {
            throw new \RuntimeException('media MAC verification failed');
        }
        $plain = openssl_decrypt($enc, 'aes-256-cbc', $keys['cipherKey'], OPENSSL_RAW_DATA, $keys['iv']);
        if ($plain === false) {
            throw new \RuntimeException('media decryption failed');
        }
        return $plain;
    }

    public static function encodeBase64EncodedStringForUpload(string $b64): string
    {
        return rawurlencode(rtrim(strtr($b64, '+/', '-_'), '='));
    }

    public static function getUrlFromDirectPath(string $directPath, string $host = self::DEF_MEDIA_HOST): string
    {
        return "https://$host$directPath";
    }

    /**
     * Normalise a media input to raw bytes.
     * Accepts: binary string (default), ['file' => path], ['url' => https://…],
     * ['stream' => resource], 'data:...;base64,xxx', plain file path with isPath option.
     */
    public static function resolveMediaInput(mixed $input): string
    {
        if (is_resource($input)) {
            $data = stream_get_contents($input);
            if ($data === false) {
                throw new \RuntimeException('failed reading media stream');
            }
            return $data;
        }
        if (is_array($input)) {
            if (isset($input['stream'])) {
                return self::resolveMediaInput($input['stream']);
            }
            if (isset($input['file'])) {
                $data = @file_get_contents((string)$input['file']);
                if ($data === false) {
                    throw new \RuntimeException('cannot read media file: ' . $input['file']);
                }
                return $data;
            }
            if (isset($input['url'])) {
                $res = self::httpGet((string)$input['url']);
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new \RuntimeException('failed fetching media URL (HTTP ' . $res['status'] . ')');
                }
                return $res['body'];
            }
            throw new \InvalidArgumentException('media array input must have file/url/stream key');
        }
        if (is_string($input)) {
            if (preg_match('#^data:[^;]*;base64,(.+)$#s', $input, $m)) {
                return base64_decode($m[1]) ?: '';
            }
            return $input;
        }
        throw new \InvalidArgumentException('unsupported media input type');
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public static function httpGet(string $url, bool $throwOnError = false): array
    {
        return self::http('GET', $url, null, ['Origin' => self::DEFAULT_ORIGIN], $throwOnError);
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public static function httpPostOctet(string $url, string $body, array $extraHeaders = []): array
    {
        return self::http('POST', $url, $body, array_merge([
            'Content-Type' => 'application/octet-stream',
            'Origin' => self::DEFAULT_ORIGIN,
        ], $extraHeaders), false);
    }

    private static function http(string $method, string $url, ?string $body, array $headers, bool $throwOnError): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $hdrs = [];
            foreach ($headers as $k => $v) {
                $hdrs[] = "$k: $v";
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_HEADER => true,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException("HTTP $method $url failed: $err");
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $rawHeaders = substr($resp, 0, $headerLen);
            $out = substr($resp, $headerLen) ?: '';
            $parsed = [];
            foreach (preg_split("/\r\n/", trim($rawHeaders)) ?: [] as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $parsed[strtolower(trim($k))] = trim($v);
                }
            }
            if ($throwOnError && ($status < 200 || $status >= 300)) {
                throw new \RuntimeException("HTTP $status for $url");
            }
            return ['status' => $status, 'headers' => $parsed, 'body' => $out];
        }
        // stream fallback
        $headerStr = '';
        foreach ($headers as $k => $v) {
            $headerStr .= "$k: $v\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headerStr,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 300,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        $parsed = [];
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                $status = (int)$m[1];
                $parsed = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }
        if ($out === false && $status === 0) {
            throw new \RuntimeException("HTTP $method $url failed");
        }
        if ($throwOnError && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException("HTTP $status for $url");
        }
        return ['status' => $status, 'headers' => $parsed, 'body' => $out ?: ''];
    }

    /** Detect mimetype from bytes (finfo), fallback application/octet-stream. */
    public static function detectMime(string $bytes): string
    {
        if ($bytes === '') {
            return 'application/octet-stream';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        return $mime ?: 'application/octet-stream';
    }

    /**
     * JPEG thumbnail (default width 32) + original dimensions, via GD (like Baileys extractImageThumb).
     * @return null|array{buffer:string,width:int,height:int}
     */
    public static function extractImageThumb(string $imageBytes, int $width = 32): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($imageBytes);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $nh = max(1, (int)round($h * $width / max(1, $w)));
        $thumb = imagecreatetruecolor($width, $nh);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $width, $nh, $w, $h);
        ob_start();
        imagejpeg($thumb, null, 50);
        $buffer = (string)ob_get_clean();
        imagedestroy($thumb);
        imagedestroy($img);
        return ['buffer' => $buffer, 'width' => $w, 'height' => $h];
    }

    /** Profile-picture crop (640×640 JPEG) like Baileys generateProfilePicture. */
    public static function generateProfilePicture(string $imageBytes, int $size = 640): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD extension required for profile picture processing (or pass a ready 640x640 JPEG)');
        }
        $img = @imagecreatefromstring($imageBytes);
        if ($img === false) {
            throw new \InvalidArgumentException('invalid image data');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);
        $outImg = imagecreatetruecolor($size, $size);
        imagecopyresampled($outImg, $img, 0, 0, $x, $y, $size, $size, $side, $side);
        ob_start();
        imagejpeg($outImg, null, 50);
        $bytes = (string)ob_get_clean();
        imagedestroy($outImg);
        imagedestroy($img);
        return $bytes;
    }

    /** Audio duration in seconds via ffprobe if available, else null. */
    public static function getAudioDuration(string $audioBytes): ?float
    {
        $ffprobe = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'waaudio');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $audioBytes);
        $cmd = escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($tmp) . ' 2>/dev/null';
        $out = trim((string)@shell_exec($cmd));
        @unlink($tmp);
        return is_numeric($out) ? (float)$out : null;
    }

    /** 64-byte voice-note waveform via ffmpeg PCM RMS (fallback: zeros). */
    public static function generateWaveform(string $audioBytes): string
    {
        $ffmpeg = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '') {
            return str_repeat("\x00", 64);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wawave');
        if ($tmp === false) {
            return str_repeat("\x00", 64);
        }
        file_put_contents($tmp, $audioBytes);
        $cmd = escapeshellcmd($ffmpeg) . ' -v error -i ' . escapeshellarg($tmp) . ' -ac 1 -ar 8000 -f s16le - 2>/dev/null';
        $pcm = (string)@shell_exec($cmd);
        @unlink($tmp);
        $samples = intdiv(strlen($pcm), 2);
        if ($samples < 64) {
            return str_repeat("\x00", 64);
        }
        $per = intdiv($samples, 64);
        $values = [];
        $max = 1.0;
        for ($i = 0; $i < 64; $i++) {
            $sum = 0.0;
            for ($j = 0; $j < $per; $j++) {
                $off = ($i * $per + $j) * 2;
                $v = unpack('v', $pcm, $off)[1];
                if ($v >= 32768) {
                    $v -= 65536;
                }
                $sum += $v * $v;
            }
            $rms = sqrt($sum / $per);
            $values[] = $rms;
            $max = max($max, $rms);
        }
        $out = '';
        foreach ($values as $rms) {
            $out .= chr((int)round($rms / $max * 100));
        }
        return $out;
    }

    /**
     * Insert sticker EXIF (pack name/publisher) into a WebP image
     * (subset of Baileys Utils/sticker.ts writeExifImg).
     * @param array{packname?:string,author?:string,packId?:string,categories?:list<string>} $opts
     */
    public static function writeStickerExif(string $webp, array $opts = []): string
    {
        if (!str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
            return $webp; // not webp — return unchanged
        }
        $json = json_encode([
            'sticker-pack-id' => $opts['packId'] ?? 'com.baileys-php.stickers',
            'sticker-pack-name' => $opts['packname'] ?? '',
            'sticker-pack-publisher' => $opts['author'] ?? '',
            'emojis' => $opts['categories'] ?? [''],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // TIFF header (little-endian) + one IFD entry (tag 0x5741) pointing to the JSON
        $exifLen = 14 + strlen($json); // 8 header + 2 count + 12 entry + 4 next = 26... build precisely below
        $tiff = "II" . pack('v', 42) . pack('V', 8); // header, IFD at offset 8
        $dataOffset = 8 + 2 + 12 + 4; // = 26
        $entry = pack('v', 0x5741) . pack('v', 7) . pack('V', strlen($json)) . pack('V', $dataOffset);
        $exif = $tiff . pack('v', 1) . $entry . pack('V', 0) . $json;
        $exifLen = strlen($exif);
        $pad = $exifLen % 2 === 1 ? "\x00" : '';
        $chunk = 'EXIF' . pack('V', $exifLen) . $exif . $pad;
        $newSize = strlen($webp) - 8 + strlen($chunk);
        return 'RIFF' . pack('V', $newSize) . substr($webp, 8) . $chunk;
    }

    /** Extension (without dot) for a mimetype. */
    public static function extensionForMime(?string $mimetype): string
    {
        if (!$mimetype) {
            return 'bin';
        }
        $part = explode('/', explode(';', $mimetype)[0])[1] ?? 'bin';
        return $part === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/i', '', $part);
    }

    public static function mediaRetryKey(string $mediaKey): string
    {
        return hkdf($mediaKey, 32, '', 'WhatsApp Media Retry Notification');
    }
    /**
     * getFile(pathOrUrl) — port of Utils/generics.ts getFile():
     * reads a local path or http(s) URL and returns {data, mime, ext}.
     * @return array{data:string, mime:string, ext:string}
     */
    public static function getFile(string $pathOrUrl): array
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0'], 'ssl' => ['verify_peer_name' => true]]);
            $data = file_get_contents($pathOrUrl, false, $ctx);
            if ($data === false) {
                throw new \RuntimeException("getFile: failed to download $pathOrUrl");
            }
        } else {
            $data = is_file($pathOrUrl) ? file_get_contents($pathOrUrl) : false;
            if ($data === false) {
                throw new \RuntimeException("getFile: cannot read $pathOrUrl");
            }
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi !== false) {
                $detected = finfo_buffer($fi, $data);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
                finfo_close($fi);
            }
        }
        $ext = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'application/pdf' => 'pdf',
            default => pathinfo($pathOrUrl, PATHINFO_EXTENSION) ?: 'bin',
        };
        return ['data' => $data, 'mime' => $mime, 'ext' => $ext];
    }
}
