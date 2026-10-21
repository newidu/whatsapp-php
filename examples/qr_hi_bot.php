<?php
/**
 * QR-login Hi Bot for Baileys-PHP v2.1
 *
 * Run:
 *   php examples/qr_hi_bot.php
 *
 * Scan the QR from WhatsApp > Settings > Linked devices > Link a device.
 */
declare(strict_types=1);

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

    $store = makeInMemoryStore();
    $storeFile = __DIR__ . '/baileys_store.json';
    $store->readFromFile($storeFile);

    $sock = makeWASocket([
        'auth' => $auth->state,
        'getMessage' => fn(array $key) => $store->getMessage($key['remoteJid'] ?? '', $key['id'] ?? ''),
        'logger' => new Logger('warn'),
        'browser' => ['Simple QR Hi Bot', 'Chrome', '1.0.0'],
        'markOnlineOnConnect' => true,
    ]);
    $store->bind($sock->ev);

    $lastStatus = null;

    $sock->ev->on('connection.update', function (array $update) use (&$lastStatus): void {
        if (!empty($update['qr'])) {
            $qr = QrCode::encodeText((string) $update['qr']);
            echo "\nScan this QR with WhatsApp > Settings > Linked devices > Link a device\n\n";
            echo $qr->toTerminalString();
            $qr->saveSvg(__DIR__ . '/qr.svg');
            echo "\nQR image saved to: " . __DIR__ . "/qr.svg\n\n";
        }
        if (($update['connection'] ?? null) === 'open') {
            echo "Bot online. Send hi to the linked WhatsApp number.\n";
        }
        if (($update['connection'] ?? null) === 'close') {
            $err = $update['lastDisconnect']['error'] ?? null;
            $lastStatus = $err ? (int) $err->getCode() : null;
            echo "Connection closed: " . ($err?->getMessage() ?? 'unknown') . " (code={$lastStatus})\n";
        }
    });

    $sock->ev->on('creds.update', function () use ($auth, $store, $storeFile): void {
        $auth->saveCreds();
        $store->writeToFile($storeFile);
    });

    $sock->ev->on('messages.upsert', function (array $upsert) use ($sock): void {
        if (($upsert['type'] ?? '') !== 'notify') {
            return;
        }
        foreach ($upsert['messages'] as $msg) {
            if (!empty($msg['key']['fromMe'])) {
                continue;
            }
            $text = $msg['message']['conversation']
                ?? $msg['message']['extendedTextMessage']['text']
                ?? null;
            if ($text === null || strtolower(trim($text)) !== 'hi') {
                continue;
            }
            $jid = $msg['key']['remoteJid'] ?? null;
            if (!$jid) {
                continue;
            }
            $fallbackJid = str_ends_with($jid, '@lid') ? $sock->getPNForLID($jid) : null;
            echo "Received hi from {$jid}; replying to the original chat\n";
            try {
                $sock->readMessages([$msg['key']]);
                $sock->sendPresenceUpdate('composing', $jid);
                $sock->sendMessage($jid, ['text' => 'hi']);
                $sock->sendPresenceUpdate('paused', $jid);
                echo "Reply relay accepted for {$jid}.\n";
            } catch (Throwable $e) {
                echo "Original chat reply failed: {$e->getMessage()}\n";
                if ($fallbackJid !== null && $fallbackJid !== $jid) {
                    try {
                        $sock->sendPresenceUpdate('composing', $fallbackJid);
                        $sock->sendMessage($fallbackJid, ['text' => 'hi']);
                        $sock->sendPresenceUpdate('paused', $fallbackJid);
                        echo "Fallback reply relay accepted for {$fallbackJid}.\n";
                    } catch (Throwable $fallbackError) {
                        echo "Fallback reply failed: {$fallbackError->getMessage()}\n";
                    }
                }
            }
        }
    });

    $sock->run();

    if ($lastStatus !== DisconnectReason::loggedOut) {
        sleep(2);
        startBot();
    } else {
        echo "Logged out. Delete examples\\baileys_php_auth and run again to pair.\n";
    }
}

startBot();
