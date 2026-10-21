<?php
/**
 * Simple WhatsApp bot web launcher.
 * Upload this file to the package root, next to the examples/ folder.
 */

declare(strict_types=1);

$bot = __DIR__ . '/examples/echo_bot.php';
$log = __DIR__ . '/bot-web.log';
$pidFile = __DIR__ . '/bot.pid';
$qrFile = __DIR__ . '/examples/qr.svg';
$timeoutSeconds = 0; // no automatic timeout; stop with the Stop bot button

function readPid(string $pidFile): ?int
{
    if (!is_file($pidFile)) {
        return null;
    }
    $pid = (int)trim((string)file_get_contents($pidFile));
    return $pid > 0 ? $pid : null;
}

function isRunning(?int $pid): bool
{
    if (!$pid || !is_dir('/proc/' . $pid)) {
        return false;
    }
    return true;
}

function startBot(string $bot, string $log, string $pidFile): string
{
    if (!is_file($bot)) {
        return 'Bot file not found: examples/echo_bot.php';
    }

    $oldPid = readPid($pidFile);
    if (isRunning($oldPid)) {
        return 'already-running';
    }

    @unlink($pidFile);
    $php = PHP_BINARY ?: 'php';
    $command = 'nohup '
        . escapeshellarg($php) . ' '
        . escapeshellarg($bot)
        . ' >> ' . escapeshellarg($log)
        . ' 2>&1 < /dev/null & echo $!';

    $pid = trim((string)shell_exec($command));
    if (ctype_digit($pid) && (int)$pid > 0) {
        file_put_contents($pidFile, $pid . PHP_EOL, LOCK_EX);
        return 'started';
    }
    return 'Could not start bot. Check whether shell_exec() is enabled.';
}

function stopBot(string $pidFile): string
{
    $pid = readPid($pidFile);
    if (!$pid) {
        return 'not-running';
    }
    if (isRunning($pid)) {
        @shell_exec('kill ' . (int)$pid . ' 2>/dev/null');
    }
    @unlink($pidFile);
    return 'stopped';
}

if (isset($_GET['stop'])) {
    $message = stopBot($pidFile);
} else {
    $message = startBot($bot, $log, $pidFile);
}

$pid = readPid($pidFile);
$running = isRunning($pid);
$qrExists = is_file($qrFile);
$logTail = '';
if (is_file($log)) {
    $lines = @file($log, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $logTail = implode("\n", array_slice($lines, -12));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="5">
    <title>WhatsApp Bot</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 32px auto; padding: 0 16px; color: #222; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 18px; margin: 14px 0; }
        .ok { color: #087f23; font-weight: bold; }
        .warn { color: #a15c00; font-weight: bold; }
        img { max-width: 320px; border: 1px solid #ddd; padding: 8px; background: white; }
        pre { background: #111; color: #eee; padding: 12px; overflow: auto; white-space: pre-wrap; }
        a.button { display: inline-block; padding: 9px 14px; border-radius: 6px; background: #d33; color: white; text-decoration: none; }
    </style>
</head>
<body>
    <h1>WhatsApp Bot</h1>
    <div class="card">
        <p>Status:
            <?php if ($running): ?>
                <span class="ok">RUNNING</span>
            <?php else: ?>
                <span class="warn">STOPPED</span>
            <?php endif; ?>
        </p>
        <p>Timeout: <strong>none; runs until Stop bot is clicked</strong></p>
        <p>Controller message: <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($running): ?>
            <p>PID: <?= (int)$pid ?></p>
            <p><a class="button" href="?stop=1">Stop bot</a></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>QR login</h2>
        <?php if ($qrExists): ?>
            <img src="examples/qr.svg?t=<?= (int)filemtime($qrFile) ?>" alt="WhatsApp QR code">
            <p>QR URL/path: <code>examples/qr.svg</code></p>
            <p>Direct URL: <a href="examples/qr.svg" target="_blank">open QR.svg</a></p>
            <p>Scan this QR from WhatsApp &rarr; Linked devices.</p>
        <?php else: ?>
            <p>QR has not been generated yet. Keep this page open; it refreshes every 5 seconds.</p>
            <p>When generated, the file will be: <code>examples/qr.svg</code></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Recent log</h2>
        <pre><?= htmlspecialchars($logTail ?: 'No log output yet.', ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</body>
</html>
