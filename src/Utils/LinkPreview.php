<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/**
 * Link-preview extraction — simplified port of Baileys src/Utils/link-preview.ts
 * Fetches the page, parses OpenGraph/Twitter/title meta, and (optionally)
 * uploads a high-quality thumbnail through the provided callback.
 */
final class LinkPreview
{
    /**
     * @param null|callable(string $jpegBytes):?array $uploadThumbnail receives jpeg bytes, returns
     *        ['directPath'=>..,'mediaKey'=>..,'mediaKeyTimestamp'=>..,'fileSha256'=>..,'fileEncSha256'=>..,'width'=>..,'height'=>..]
     * @return null|array<string,mixed>
     */
    public static function getUrlInfo(string $text, ?callable $uploadThumbnail = null, int $thumbnailWidth = 192, ?Logger $logger = null): ?array
    {
        if (!preg_match('#https?://[^\s]+#', $text, $m)) {
            return null;
        }
        $url = rtrim($m[0], '.,;:!?)]');
        try {
            $res = self::fetch($url);
            if ($res === null) {
                return null;
            }
            $html = $res['body'];
            $finalUrl = $res['url'];
            $meta = self::parseMeta($html);
            $title = $meta['og:title'] ?? $meta['twitter:title'] ?? self::parseTitle($html);
            $description = $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null;
            $imageUrl = $meta['og:image'] ?? $meta['twitter:image'] ?? null;
            if ($title === null && $description === null && $imageUrl === null) {
                return null;
            }
            $info = [
                'matched-text' => $url,
                'canonical-url' => $meta['og:url'] ?? $finalUrl,
                'title' => $title,
                'description' => $description,
            ];
            if ($imageUrl) {
                $imageUrl = self::absolutize($imageUrl, $finalUrl);
                $imgRes = self::fetch($imageUrl);
                if ($imgRes !== null && $imgRes['body'] !== '') {
                    $thumb = Media::extractImageThumb($imgRes['body'], 32);
                    if ($thumb !== null) {
                        $info['jpegThumbnail'] = $thumb['buffer'];
                    }
                    if ($uploadThumbnail !== null) {
                        $big = Media::extractImageThumb($imgRes['body'], $thumbnailWidth);
                        if ($big !== null) {
                            $uploaded = $uploadThumbnail($big['buffer']);
                            if (is_array($uploaded)) {
                                $uploaded['width'] = $big['width'];
                                $uploaded['height'] = $big['height'];
                                $info['highQualityThumbnail'] = $uploaded;
                            }
                        }
                    }
                }
            }
            return $info;
        } catch (\Throwable $e) {
            $logger?->warn(['err' => $e->getMessage()], 'link preview generation failed');
            return null;
        }
    }

    private static function fetch(string $url): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'WhatsApp/2.24.0',
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                curl_close($ch);
                return null;
            }
            $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            return ['body' => (string)$body, 'url' => $final];
        }
        $body = @file_get_contents($url);
        return $body === false ? null : ['body' => $body, 'url' => $url];
    }

    /** @return array<string,string> */
    private static function parseMeta(string $html): array
    {
        $out = [];
        if (preg_match_all('#<meta\s[^>]*>#i', substr($html, 0, 512_000), $tags)) {
            foreach ($tags[0] as $tag) {
                $prop = null;
                if (preg_match('#(?:property|name)\s*=\s*["\']([^"\']+)["\']#i', $tag, $m)) {
                    $prop = strtolower($m[1]);
                }
                if ($prop !== null && preg_match('#content\s*=\s*["\']([^"\']*)["\']#i', $tag, $m)) {
                    $out[$prop] ??= html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        return $out;
    }

    private static function parseTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
        }
        return null;
    }

    private static function absolutize(string $maybeRelative, string $base): string
    {
        if (parse_url($maybeRelative, PHP_URL_SCHEME)) {
            return $maybeRelative;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $host = $b['host'] ?? '';
        if (str_starts_with($maybeRelative, '//')) {
            return $scheme . ':' . $maybeRelative;
        }
        if (str_starts_with($maybeRelative, '/')) {
            return "$scheme://$host$maybeRelative";
        }
        $path = substr($b['path'] ?? '/', 0, strrpos($b['path'] ?? '/', '/') + 1);
        return "$scheme://$host$path$maybeRelative";
    }
}
