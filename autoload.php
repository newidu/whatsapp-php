<?php
declare(strict_types=1);
/**
 * Zero-dependency autoloader for running WITHOUT Composer
 * (handy on XAMPP: just `require __DIR__ . '/baileys-php/autoload.php';`)
 * If you use Composer, this file is not needed (PSR-4 in composer.json).
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'BaileysPHP\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $rel;
    if (is_file($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/src/Utils/Crypto.php';   // namespaced crypto helper functions
require_once __DIR__ . '/src/functions.php';      // makeWASocket(), useMultiFileAuthState()
