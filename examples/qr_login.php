<?php
/**
 * QR කෝඩ් එකෙන් Login වීම + ලැබෙන Text Messages console එකේ පෙන්වීම
 *
 * Run කරන්න (Terminal/CMD එකේ):
 *   php examples/qr_login.php
 *
 * XAMPP (Windows) නම්:
 *   C:\xampp\php\php.exe examples\qr_login.php
 *
 * QR එක scan කරන්න: WhatsApp > Settings > Linked devices > Link a device
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\DisconnectReason;
use BaileysPHP\Utils\Logger;
use BaileysPHP\Utils\QrCode;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

function connectToWhatsApp(): void
{
    $auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
    $auth->saveCreds(); // මුල් වතාවේ creds.json එක හදන්න

    $sock = makeWASocket([
        'auth' => $auth->state,
        'logger' => new Logger('info'),
        'browser' => ['Baileys-PHP', 'Chrome', '1.0.0'],
        'markOnlineOnConnect' => true,
    ]);

    $lastStatus = null;

    $sock->ev->on('connection.update', function (array $update) use ($sock, &$lastStatus) {
        if (!empty($update['qr'])) {
            echo "\nමෙම QR කෝඩ් එක WhatsApp එකෙන් Scan කරන්න:\n\n";
            $qr = QrCode::encodeText($update['qr']);
            echo $qr->toTerminalString();
            // Terminal එකේ පේන්නේ නැත්නම් මේ file එක browser එකෙන් open කරලා scan කරන්න:
            $qr->saveSvg(__DIR__ . '/qr.svg');
            echo "\nQR රූපය: " . __DIR__ . DIRECTORY_SEPARATOR . "qr.svg (browser එකෙන් open කරන්න)\n";
            if (function_exists('imagecreatetruecolor')) {
                $qr->savePng(__DIR__ . '/qr.png');
                echo "හෝ: " . __DIR__ . DIRECTORY_SEPARATOR . "qr.png\n";
            }
        }
        if (($update['connection'] ?? null) === 'open') {
            echo "\n✅ සාර්ථකව Login වුණා!\n";
        }
        if (($update['connection'] ?? null) === 'close') {
            $err = $update['lastDisconnect']['error'] ?? null;
            $lastStatus = $err ? (int)$err->getCode() : null;
            echo "\n❌ Connection එක වැහුණා. හේතුව: " . ($err?->getMessage() ?? 'unknown') . " (code=$lastStatus)\n";
        }
    });

    $sock->ev->on('creds.update', fn($update) => $auth->saveCreds());

    $sock->ev->on('messages.upsert', function (array $upsert) {
        foreach ($upsert['messages'] as $msg) {
            $text = $msg['message']['conversation']
                ?? $msg['message']['extendedTextMessage']['text']
                ?? null;
            $from = $msg['key']['remoteJid'] ?? '?';
            $name = $msg['pushName'] ?? $from;
            if ($text !== null) {
                echo "\n💬 [$name] $text\n";
            } elseif (isset($msg['messageStubType'])) {
                echo "\n⚠️ Decrypt කරන්න බැරි වුණ message එකක්: " . ($msg['messageStubParameters'][0] ?? '') . "\n";
            }
        }
    });

    // PHP වල මේ call එක අවශ්‍යයි — connection එක පවත්වාගෙන යන්නේ මේකෙන් (Node වල event loop එක වගේ)
    $sock->run();

    // Logout වෙලා නැත්නම් විනාඩියකින් නැවත connect වෙනවා
    if ($lastStatus !== DisconnectReason::loggedOut) {
        echo "තත්පර 2කින් නැවත connect වෙනවා...\n";
        sleep(2);
        connectToWhatsApp();
    } else {
        echo "Logged out. නැවත login වෙන්න නම් baileys_php_auth folder එක මකලා ආයේ run කරන්න.\n";
    }
}

connectToWhatsApp();
