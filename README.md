# WhatsApp PHP Web Bot

A pure-PHP WhatsApp Web bot with QR login, an echo-bot example, and a simple web launcher.

> This project is based on the included Baileys-PHP implementation. It does not use Node.js or npm.

## Features

- Pure PHP WhatsApp Web connection
- QR-code login and pairing-code login
- Echo bot that replies to incoming text messages
- `ping` → `pong` response
- Simple web launcher with live status and QR display
- Stop button for the background bot process
- No automatic timeout in the web launcher; it runs until stopped or terminated by the host

## Requirements

- PHP 8.2 or newer
- PHP extensions: `sodium`, `openssl`, `gmp`, `mbstring`, `zlib`, and `json`
- `shell_exec()` enabled for the web launcher
- `nohup` available on the server
- A hosting environment that allows long-running PHP processes
- Outbound WebSocket/TLS access to WhatsApp Web

Shared hosting may block WebSockets or terminate background processes. A VPS or persistent server is recommended for production use.

## Web installation

1. Download or clone this repository.
2. Upload the files to your web root.
3. Open `index.php` in a browser.
4. The launcher starts `examples/echo_bot.php` in the background.
5. If the account is not linked, scan the QR code shown on the page using WhatsApp → Linked devices.
6. The QR file is generated at `examples/qr.svg`.
7. Use the **Stop bot** button to stop the bot.

The page refreshes every five seconds and shows the current process status and recent log output.

## CLI installation

For a persistent server, CLI mode is recommended:

```bash
php examples/echo_bot.php
```

To create a QR login from the terminal:

```bash
php examples/qr_login.php
```

To use a pairing code:

```bash
php examples/pairing_code_login.php 94771234567
```

## Bot behavior

The default echo bot:

- Ignores messages sent by itself
- Processes incoming text messages
- Marks messages as read
- Shows a composing/presence status while replying
- Replies `pong` when the message is `ping`
- Replies `You said: ...` for other text messages

## Security

Never commit or publish WhatsApp authentication files. These files contain private account session keys:

- `creds.json`
- `session-*.json`
- `identity-key-*.json`
- `pre-key-*.json`
- `device-list-*.json`
- `tctoken-*.json`
- `examples/qr.svg`
- `examples/qr.png`
- logs and message stores

Each deployment must use its own private authentication directory and its own QR login. Do not share a linked session or QR code.

The `.gitignore` file is configured to exclude these files.

## Testing

Run the offline test suite:

```bash
php tests/run_tests.php
```

The package test suite covers cryptography, protocol encoding, QR generation, message handling, stores, and socket APIs.

## License

MIT License. See [LICENSE](LICENSE).
