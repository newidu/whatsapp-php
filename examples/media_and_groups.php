<?php
/**
 * Media + Groups demo — v2 features
 *
 * Run කරන්න (මුලින් qr_login.php එකෙන් login වෙලා ඉන්න):
 *   php examples/media_and_groups.php 94771234567
 *
 * කරන දේ:
 *  1) number එක WhatsApp එකේද බලනවා (onWhatsApp)
 *  2) profile picture URL එක ගන්නවා
 *  3) image එකක් caption එකත් එක්ක යවනවා (media upload)
 *  4) reaction එකක් දානවා
 *  5) group එකක් හදලා invite link එක ගන්නවා
 *  6) poll එකක් යවනවා
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\Utils\Logger;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

$phone = $argv[1] ?? null;
if (!$phone) {
    echo "Usage: php examples/media_and_groups.php 94771234567\n";
    exit(1);
}

$auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
$auth->saveCreds();

$sock = makeWASocket([
    'auth' => $auth,
    'logger' => new Logger('warn'),
]);
$sock->ev->on('creds.update', fn($u) => $auth->saveCreds());

$sock->connect();
$ok = $sock->waitForConnectionUpdate(fn($u) => ($u['connection'] ?? null) === 'open', 60000);
if (!$ok) {
    echo "Login වුනේ නැහැ. මුලින් qr_login.php run කරන්න.\n";
    exit(1);
}
echo "✅ Connected!\n";

// 1) number එක WhatsApp තිබේද?
$res = $sock->onWhatsApp([$phone]);
if (empty($res) || empty($res[0]['exists'])) {
    echo "$phone WhatsApp එකේ නැහැ.\n";
    $sock->end();
    exit(1);
}
$jid = $res[0]['jid'];
echo "JID: $jid\n";

// 2) profile picture
$pp = $sock->profilePictureUrl($jid, 'image');
echo "Profile picture: " . ($pp ?? '(නැහැ)') . "\n";

// about/status
try {
    $st = $sock->fetchStatus($jid);
    echo "Status: " . ($st['status'] ?? '(නැහැ)') . "\n";
} catch (Throwable $e) {
    echo "Status ගන්න බැරිවුනා: {$e->getMessage()}\n";
}

// 3) image එකක් යවන්න (test image එකක් හදනවා)
$imgPath = __DIR__ . '/demo.jpg';
if (!is_file($imgPath)) {
    if (extension_loaded('gd')) {
        $im = imagecreatetruecolor(320, 200);
        $bg = imagecolorallocate($im, 34, 139, 34);
        imagefill($im, 0, 0, $bg);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, 60, 90, 'Baileys Pure PHP v2', $white);
        imagejpeg($im, $imgPath, 90);
    } else {
        echo "demo.jpg එකක් " . __DIR__ . " එකට දාන්න (GD නැහැ).\n";
    }
}

$sock->sendPresenceUpdate('composing', $jid);
usleep(800000);
$sock->sendPresenceUpdate('paused', $jid);

$sent = null;
if (is_file($imgPath)) {
    $sent = $sock->sendMessage($jid, [
        'image' => ['stream' => file_get_contents($imgPath)],
        'caption' => 'Baileys Pure PHP v2 එකෙන් යවපු photo එකක්! 📸',
    ]);
    echo "📤 Image sent: {$sent['key']['id']}\n";

    // 4) ඒ message එකටම reaction එකක්
    $sock->sendMessage($jid, ['react' => ['text' => '🔥', 'key' => $sent['key']]]);
    echo "❤️ Reaction sent\n";
}

// 5) group හදන්න
try {
    $group = $sock->groupCreate('Baileys-PHP Test Group', [$jid]);
    $gJid = $group['id'];
    echo "👥 Group: $gJid\n";
    $code = $sock->groupInviteCode($gJid);
    echo "Invite: https://chat.whatsapp.com/$code\n";

    $sock->groupUpdateSubject($gJid, 'Baileys-PHP 🇱🇰 Test');
    $meta = $sock->groupMetadata($gJid);
    echo "Group name: {$meta['subject']} | participants: " . count($meta['participants']) . "\n";

    // 6) poll එකක්
    $sock->sendMessage($gJid, [
        'poll' => [
            'name' => 'PHP ද Node ද හොඳ?',
            'values' => ['PHP 💜', 'Node 💚', 'දෙකම'],
            'selectableCount' => 1,
        ],
    ]);
    echo "📊 Poll sent\n";
} catch (Throwable $e) {
    echo "Group error: {$e->getMessage()}\n";
}

echo "👍 Done. messages බලන්න ටිකක් ඉන්නවා (Ctrl+C නවත්වන්න)...\n";
$sock->ev->on('messages.upsert', function (array $e) {
    foreach ($e['messages'] ?? [] as $m) {
        $text = $m['message']['conversation']
            ?? $m['message']['extendedTextMessage']['text']
            ?? '(media/other)';
        echo "📩 " . ($m['key']['remoteJidAlt'] ?? $m['key']['remoteJid']) . ": $text\n";
    }
});
$sock->run();
