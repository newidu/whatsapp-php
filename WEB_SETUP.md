# WhatsApp PHP Web Bot

This repository contains a simple PHP web launcher for the Baileys-PHP example echo bot.

## Important security note

Never commit WhatsApp authentication files, QR files, logs, or message stores. The first QR login creates session keys under `examples/baileys_php_auth/`. Those files belong only to the account owner and must remain private. The public repository intentionally does not include them.

Each deployment must create its own private auth directory and scan its own QR code. Do not share the QR image or auth directory.

## Requirements

- PHP 8.2+
- `sodium`, `openssl`, `gmp`, `mbstring`, `zlib`, and `json`
- `shell_exec()` enabled
- `nohup` available
- A host that permits long-running PHP CLI processes and outbound WebSocket/TLS connections to WhatsApp Web

Shared hosting may kill background processes or block WebSockets. A VPS or other persistent server is more reliable.

## Web installation

1. Upload the repository files to the web root.
2. Open `/index.php` in a browser.
3. The launcher starts `examples/echo_bot.php` in the background.
4. If the account is not linked, scan the QR shown on the page or open `examples/qr.svg`.
5. The page refreshes every five seconds and shows recent logs.
6. Click **Stop bot** to stop the process.

The public launcher has no automatic timeout. It runs until the Stop button is used or the hosting provider stops the process.

## Sinhala setup

1. Files web root එකට upload කරන්න.
2. `/index.php` open කරන්න.
3. Bot එක background එකේ start වෙයි.
4. QR එක පෙන්වුණොත් WhatsApp → Linked devices වලින් scan කරන්න.
5. QR path එක `examples/qr.svg` වේ.
6. Bot නවත්වන්න **Stop bot** button එක click කරන්න.

මෙම version එකට automatic timeout එකක් නැහැ. Stop button එක click කරනකම් run වේ. Hosting එක background process නවත්වනවා නම් VPS/persistent server එකක් භාවිතා කරන්න.

## CLI installation

For a persistent server, CLI mode is recommended:

```bash
php examples/echo_bot.php
```

## Public repository limitation

Making the source repository public does not make one shared WhatsApp account available to everyone. Each person must deploy their own copy and link their own WhatsApp account. Never publish a linked session, QR code, phone number, or credentials.
