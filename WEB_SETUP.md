# Web Setup Guide

## Requirements

- PHP 8.2 or newer
- `sodium`, `openssl`, `gmp`, `mbstring`, `zlib`, and `json`
- `shell_exec()` enabled
- `nohup` available
- Long-running background processes allowed by the host
- Outbound WebSocket/TLS connections to WhatsApp Web allowed

Shared hosting may block WebSockets or terminate background processes. A VPS or persistent server is recommended.

## Install

1. Upload the repository files to the web root.
2. Open `index.php` in a browser.
3. The launcher starts `examples/echo_bot.php` in the background.
4. If the account is not linked, scan the QR code shown on the page.
5. The generated QR file is available at `examples/qr.svg`.
6. Click **Stop bot** to terminate the process.

The launcher has no automatic timeout. It runs until the Stop button is clicked or the hosting provider terminates the process.

## Important security warning

Do not publish or share any WhatsApp authentication files, QR codes, logs, or message stores. These can contain private account credentials or session keys.

Every deployment must create and use its own private authentication directory. The public repository intentionally does not contain login/session data.

## Public repository limitation

A public source repository does not provide one shared WhatsApp account to everyone. Each user must deploy their own copy and link their own WhatsApp account by scanning their own QR code.
