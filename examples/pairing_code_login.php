<?php
/**
 * Pairing Code එකෙන් Login වීම (QR scan කරන්න බැරි අයට)
 *
 * Run කරන්න:
 *   php examples/pairing_code_login.php 94771234567
 *   (ඔයාගේ WhatsApp අංකය, රටේ කෝඩ් එක සමඟ, + ලකුණ නැතුව)
 *
 * ඊට පස්සේ දුරකථනයේ: WhatsApp > Settings > Linked devices > Link a device
 *   > "Link with phone number instead" > මෙතන පේන කෝඩ් එක ගහන්න.
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\DisconnectReason;
use BaileysPHP\Utils\Logger;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

$phone = $argv[1] ?? null;
if (!$phone) {
    echo "ඔයාගේ WhatsApp අංකය ගහන්න (උදා: 94771234567): ";
    $phone = trim((string)fgets(STDIN));
}
$phone = preg_replace('/\D/', '', (string)$phone);
if ($phone === '') {
    fwrite(STDERR, "අංකය අවශ්‍යයි!\n");
    exit(1);
}

function connectToWhatsApp(string $phone): void
{
    $auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
    $auth->saveCreds();

    $sock = makeWASocket([
        'auth' => $auth->state,
        'logger' => new Logger('info'),
        'browser' => ['Baileys-PHP', 'Chrome', '1.0.0'],
        // Pairing code එක ඉල්ලන්නේ මේ option එකෙන් (server එක ready වුණාම automatically)
        'pairingPhoneNumber' => $phone,
        // කැමති නම් තමන්ගේම කෝඩ් එකකුත් දෙන්න පුළුවන් (අකුරු 8ක් වෙන්න ඕනේ):
        // 'customPairingCode' => 'PHPBOT12',
    ]);

    $lastStatus = null;

    $sock->ev->on('connection.update', function (array $update) use (&$lastStatus) {
        if (!empty($update['pairingCode'])) {
            $code = $update['pairingCode'];
            $pretty = substr($code, 0, 4) . '-' . substr($code, 4);
            echo "\n📲 ඔයාගේ Pairing Code එක:  $pretty\n";
            echo "දුරකථනයේ WhatsApp > Linked devices > Link with phone number instead ගිහිල්ලා මේක ගහන්න.\n\n";
        }
        if (($update['connection'] ?? null) === 'open') {
            echo "\n✅ සාර්ථකව Login වුණා!\n";
        }
        if (($update['connection'] ?? null) === 'close') {
            $err = $update['lastDisconnect']['error'] ?? null;
            $lastStatus = $err ? (int)$err->getCode() : null;
            echo "\n❌ Connection එක වැහුණා: " . ($err?->getMessage() ?? 'unknown') . " (code=$lastStatus)\n";
        }
    });

    $sock->ev->on('creds.update', fn($update) => $auth->saveCreds());

    $sock->ev->on('messages.upsert', function (array $upsert) {
        foreach ($upsert['messages'] as $msg) {
            $text = $msg['message']['conversation'] ?? $msg['message']['extendedTextMessage']['text'] ?? null;
            if ($text !== null) {
                $name = $msg['pushName'] ?? ($msg['key']['remoteJid'] ?? '?');
                echo "\n💬 [$name] $text\n";
            }
        }
    });

    $sock->run();

    if ($lastStatus !== DisconnectReason::loggedOut) {
        echo "තත්පර 2කින් නැවත connect වෙනවා...\n";
        sleep(2);
        connectToWhatsApp($phone);
    } else {
        echo "Logged out. නැවත හදන්න නම් baileys_php_auth folder එක මකන්න.\n";
    }
}

connectToWhatsApp($phone);
