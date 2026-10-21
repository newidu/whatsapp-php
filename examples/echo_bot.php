<?php
/**
 * Echo Bot — ලැබෙන message එකකට reply එකක් යවන සරලම බොට් එක
 *
 * Run කරන්න:
 *   php examples/echo_bot.php
 *
 * Login වෙලා නැත්නම් QR එකක් පෙන්වනවා. අලුතෙන් එන text message එකකට
 * blue tick (read) දාලා, "ඔයා කිව්වේ: ..." කියලා reply කරනවා.
 * කවුරුහරි "ping" කිව්වොත් "pong" කියනවා.
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\DisconnectReason;
use BaileysPHP\Utils\Logger;
use BaileysPHP\Utils\QrCode;
use function BaileysPHP\makeInMemoryStore;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

function startBot(): void
{
    $auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
    $auth->saveCreds();

    // chats/contacts/messages තබාගන්නා store එක — polls/retries සඳහා getMessage එකත් මේකෙන්
    $store = makeInMemoryStore();
    $store->readFromFile(__DIR__ . '/baileys_store.json'); // පරණ data ඇත්නම් load වෙනවා

    $sock = makeWASocket([
        'auth' => $auth->state,
        'getMessage' => fn(array $key) => $store->getMessage($key['remoteJid'] ?? '', $key['id'] ?? ''),
        'logger' => new Logger('warn'), // බොට් එකට 'warn' ඇති; debug කරන්න ඕනේ නම් 'info' දාන්න
        'browser' => ['Baileys-PHP Bot', 'Chrome', '1.0.0'],
        'markOnlineOnConnect' => true,
    ]);

    $store->bind($sock->ev);

    $lastStatus = null;

    $sock->ev->on('connection.update', function (array $update) use (&$lastStatus) {
        if (!empty($update['qr'])) {
            echo "QR එක scan කරන්න:\n" . QrCode::encodeText($update['qr'])->toTerminalString() . "\n";
            QrCode::encodeText($update['qr'])->saveSvg(__DIR__ . '/qr.svg');
        }
        if (($update['connection'] ?? null) === 'open') {
            echo "✅ බොට් එක online!\n";
        }
        if (($update['connection'] ?? null) === 'close') {
            $err = $update['lastDisconnect']['error'] ?? null;
            $lastStatus = $err ? (int)$err->getCode() : null;
        }
    });

    $sock->ev->on('creds.update', function ($u) use ($auth, $store) {
        $auth->saveCreds();
        $store->writeToFile(__DIR__ . '/baileys_store.json'); // store එකත් save කරන්න
    });

    $sock->ev->on('messages.upsert', function (array $upsert) use ($sock) {
        if (($upsert['type'] ?? '') !== 'notify') {
            return; // පරණ/offline messages වලට reply කරන්නේ නෑ
        }
        foreach ($upsert['messages'] as $msg) {
            if (!empty($msg['key']['fromMe'])) {
                continue; // තමන්ගේම messages වලට reply නෑ
            }
            $text = $msg['message']['conversation']
                ?? $msg['message']['extendedTextMessage']['text']
                ?? null;
            if ($text === null) {
                continue; // text නෙමෙයි නම් (image/sticker වගේ) Basic version එකේ skip කරනවා
            }
            $jid = $msg['key']['remoteJid'];
            echo "💬 $jid: $text\n";

            // blue tick එක දානවා (කියෙව්වා කියලා)
            $sock->readMessages([$msg['key']]);

            // typing... පෙන්වනවා
            $sock->sendPresenceUpdate('composing', $jid);

            $reply = strtolower(trim($text)) === 'ping'
                ? 'pong 🏓'
                : "ඔයා කිව්වේ: $text";
            $sock->sendMessage($jid, ['text' => $reply]);

            $sock->sendPresenceUpdate('paused', $jid);
        }
    });

    $sock->run();

    if ($lastStatus !== DisconnectReason::loggedOut) {
        sleep(2);
        startBot();
    }
}

startBot();
