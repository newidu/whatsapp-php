<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/** Port of Baileys: src/Utils/browser-utils.ts */
final class Browsers
{
    /** @return array{0:string,1:string,2:string} */
    public static function ubuntu(string $browser): array { return ['Ubuntu', $browser, '22.04.4']; }
    /** @return array{0:string,1:string,2:string} */
    public static function macOS(string $browser): array { return ['Mac OS', $browser, '14.4.1']; }
    /** @return array{0:string,1:string,2:string} */
    public static function baileys(string $browser): array { return ['Baileys', $browser, '6.5.0']; }
    /** @return array{0:string,1:string,2:string} */
    public static function windows(string $browser): array { return ['Windows', $browser, '10.0.22631']; }
    /** @return array{0:string,1:string,2:string} */
    public static function appropriate(string $browser): array
    {
        $os = PHP_OS_FAMILY === 'Darwin' ? 'Mac OS' : (PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Ubuntu');
        return [$os, $browser, php_uname('r')];
    }

    /** DeviceProps.PlatformType numeric id as string (getCompanionPlatformId in newer Baileys). */
    public static function getPlatformId(string $browserName): string
    {
        $map = [
            'CHROME' => 1, 'FIREFOX' => 2, 'IE' => 3, 'OPERA' => 4, 'SAFARI' => 5, 'EDGE' => 6,
            'DESKTOP' => 7, 'IPAD' => 8, 'ANDROID_TABLET' => 9,
        ];
        return (string)($map[strtoupper($browserName)] ?? 1);
    }
}
