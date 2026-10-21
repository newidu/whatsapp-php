<?php
/**
 * එක Text Message එකක් යවලා ඉවර වෙන උදාහරණය
 * (මීට කලින් qr_login.php හෝ pairing_code_login.php එකෙන් login වෙලා තියෙන්න ඕනේ)
 *
 * Run කරන්න:
 *   php examples/send_message.php 94771234567 "හායි! Baileys-PHP එකෙන්"
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\Utils\Logger;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

$number = preg_replace('/\D/', '', $argv[1] ?? '');
$text = $argv[2] ?? 'හායි! මේ Baileys-PHP (Pure PHP) එකෙන් යවන message එකක් 🤖';
if ($number === '') {
    fwrite(STDERR, "Usage: php send_message.php <94771234567> [\"message\"]\n");
    exit(1);
}

$auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
if (empty($auth->state->creds->me)) {
    fwrite(STDERR, "තවම Login වෙලා නෑ. කලින් qr_login.php run කරලා login වෙන්න.\n");
    exit(1);
}

$sock = makeWASocket([
    'auth' => $auth->state,
    'logger' => new Logger('info'),
]);
$sock->ev->on('creds.update', fn($u) => $auth->saveCreds());

$opened = false;
$closed = false;
$sock->ev->on('connection.update', function (array $update) use (&$opened, &$closed) {
    if (($update['connection'] ?? null) === 'open') {
        $opened = true;
    }
    if (($update['connection'] ?? null) === 'close') {
        $closed = true;
        $err = $update['lastDisconnect']['error'] ?? null;
        echo "Connection වැහුණා: " . ($err?->getMessage() ?? '') . "\n";
    }
});
$sock->ev->on('messages.update', function (array $updates) {
    foreach ($updates as $u) {
        $statusNames = [0 => 'ERROR', 1 => 'PENDING', 2 => 'SERVER_ACK (ගියා)', 3 => 'DELIVERED (ලැබුණා)', 4 => 'READ (කියෙව්වා ✔️✔️)', 5 => 'PLAYED'];
        $s = $u['update']['status'] ?? null;
        if ($s !== null) {
            echo "📬 Status: " . ($statusNames[$s] ?? $s) . "\n";
        }
    }
});

$sock->connect();

// 'open' වෙනකම් loop එක දුවවනවා (තත්පර 60ක් උපරිම)
$deadline = microtime(true) + 60;
while (!$opened && !$closed && microtime(true) < $deadline) {
    $sock->runFor(0.5);
}
if (!$opened) {
    fwrite(STDERR, "Connect වෙන්න බැරි වුණා.\n");
    exit(1);
}

$jid = $number . '@s.whatsapp.net';

// අංකය WhatsApp එකේ තියෙනවාද කියලා බලනවා
$check = $sock->onWhatsApp($number);
if ($check !== [] && empty($check[0]['exists'])) {
    fwrite(STDERR, "මේ අංකය WhatsApp එකේ නැහැ: $number\n");
    $sock->end();
    exit(1);
}

$sent = $sock->sendMessage($jid, ['text' => $text]);
echo "✅ Message එක යැව්වා! ID: {$sent['key']['id']}\n";

// Delivery receipt එනකම් ටිකක් ඉන්නවා
$sock->runFor(8);
$sock->end();
echo "ඉවරයි.\n";
