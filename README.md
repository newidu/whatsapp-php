# Baileys-PHP — Pure PHP WhatsApp Web API (v2.3)

**Node.js Baileys එකේ සියලුම ප්‍රධාන features, 100% Pure PHP වලින්.** Node නැත, npm නැත, වෙන පැකේජයක් නැත — PHP extension වලින් විතරමයි.

WhatsApp Web multi-device protocol එක මුළුමනින්ම PHP වලින් re-implement කරලා තියෙනවා:
Noise XX handshake → QR / Pairing-Code login → Signal (Double-Ratchet + Sender Keys) E2EE → WABinary → Protobuf.

## අවශ්‍ය දේවල් (Requirements)

- **PHP 8.2+** (Windows නම් XAMPP 8.2+ හරි)
- Extensions: `sodium`, `openssl`, `gmp`, `mbstring`, `zlib`, `json` (බොහෝ විට by default තියෙනවා)
- optional: `gd` (profile picture thumbnails සඳහා; නැතිවුනත් දුවනවා)

> ⚠️ **Long-running PHP CLI** එකකින් දුවන්න ඕනේ (bot style). `php examples/echo_bot.php` වගේ. Web request (browser refresh) එකකින් බැහැ — connection එක තබාගන්න ඕනේ.

## ඉක්මන් පටන් ගැනීම (Quick start)

```bash
# 1) QR එකෙන් login වෙන්න (terminal එකේ QR එක පෙන්වනවා)
php examples/qr_login.php

# හරි නම් pairing code එකෙන් (QR scan කරන්න බැරි නම්)
php examples/pairing_code_login.php 94771234567

# 2) message එකක් යවන්න
php examples/send_message.php 94771234567 "Hello from Pure PHP! 👋"

# 3) echo bot එක දුවවන්න
php examples/echo_bot.php
```

Composer ඕනේ නැහැ — `require 'autoload.php';` විතරමයි. Composer තිබේ නම් `composer install` එකෙනුත් autoload වෙනවා.

## සරලම code එක

```php
<?php
require __DIR__ . '/autoload.php';

use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

$auth = useMultiFileAuthState(__DIR__ . '/auth_info');
$sock = makeWASocket(['auth' => $auth]);

$sock->ev->on('creds.update', fn() => $auth->saveCreds()); // අනිවාර්යයි!

$sock->ev->on('connection.update', function (array $u) {
    if (($u['connection'] ?? null) === 'open') echo "✅ Online!\n";
});

$sock->ev->on('messages.upsert', function (array $e) use ($sock) {
    foreach ($e['messages'] as $msg) {
        $text = $msg['message']['conversation'] ?? null;
        if ($text === 'ping') {
            $sock->sendMessage($msg['key']['remoteJid'], ['text' => 'pong 🏓']);
        }
    }
});

$sock->run(); // event loop (Ctrl+C වලින් නවත්වන්න)
```

## v2 Features (Node.js Baileys parity)

### 📩 Messages — `sendMessage($jid, $content, $options)`
| Content key | Description |
|---|---|
| `text` (+ `linkPreview` auto) | text message, link previews (thumbnail fetch) |
| `image`, `video`, `audio`, `document`, `sticker` | media upload (`['url'=>…]` / `['stream'=>bytes]` / path) |
| `audio` + `ptt => true` | voice note (+ waveform) |
| `album` | multiple images in one album |
| `react`, `delete`, `edit`, `forward`, `pin` | reactions / revoke / edit / pin |
| `poll` (V1/V2/V3) | polls — votes decrypt වෙනවා (`messages.update`) |
| `event` | group events — responses decrypt වෙනවා |
| `location`, `contacts`, `groupInvite`, `product` | විශේෂ message වර්ග |
| `mentions`, `quoted` (options) | @mentions, reply/quote |
| `disappearingMessagesInChat` | disappearing timer වෙනස් කරන්න |
| ඕනෑම proto field එකක් (e.g. `extendedTextMessage`) | raw pass-through |

Status (story): `$sock->sendMessage('status@broadcast', …)` — text status (backgroundColor/font), media status.

### 👥 Groups & Communities
`groupCreate`, `groupMetadata`, `groupParticipantsUpdate` (add/remove/promote/demote), `groupUpdateSubject`, `groupUpdateDescription`, `groupInviteCode`, `groupRevokeInvite`, `groupAcceptInvite`, `groupGetInviteInfo`, `groupRevokeInviteV4`/`groupAcceptInviteV4`, `groupSettingUpdate`, `groupToggleEphemeral`, `groupMemberAddMode`, `groupJoinApprovalMode`, `groupRequestParticipantsList/Update`, `groupFetchAllParticipating`, `updateMemberLabel` — සහ `community*` සියල්ල (create, link/unlink groups, sub-groups…).

### 📢 Newsletters (Channels)
`newsletterCreate/Update/UpdateName/UpdateDescription/UpdatePicture/RemovePicture`, `newsletterFollow/Unfollow/Mute/Unmute`, `newsletterMetadata`, `newsletterSubscribers`, `newsletterAdminCount`, `newsletterChangeOwner`, `newsletterDemote`, `newsletterDelete`, `newsletterReactMessage`, `newsletterFetchMessages`, `subscribeNewsletterUpdates` + MEX queries (`executeWMexQuery`).

### 🏪 Business
`getBusinessProfile`, `updateBussinesProfile`, `updateCoverPhoto/removeCoverPhoto`, `getCatalog`, `getCollections`, `getOrderDetails`, `productCreate/Update/Delete` (catalog images auto-upload).

### 💬 Chats / Contacts / App-State
Archive, pin, mute, mark unread, clear, delete chats (`chatModify`, `archiveChat`), push name (`updateProfileName`), contact address book (`addOrEditContact/removeContact`), star messages, labels (`addLabel/addChatLabel/addMessageLabel`), quick replies, blocklist (`updateBlockStatus/fetchBlocklist`) — මේවා real **syncd app-state patches** (LT-Hash) හරහා phone එකත් sync වෙනවා. Login වුනාම app-state auto-resync වෙනවා; `resyncAppState()` manual ද පුළුවන්.

### 🔒 Privacy
`fetchPrivacySettings` + හැම setter එකම: `updateLastSeenPrivacy`, `updateOnlinePrivacy`, `updateProfilePicturePrivacy`, `updateStatusPrivacy`, `updateReadReceiptsPrivacy`, `updateGroupsAddPrivacy`, `updateCallPrivacy`, `updateMessagesPrivacy`, `updateDefaultDisappearingMode`. **Trusted-contact (privacy) tokens** auto: server එකෙන් එන token save වෙනවා, 1:1 messages වලට attach වෙනවා, අපේ token එක contacts වලට issue වෙනවා.

### 👤 Profile & Presence
`updateProfilePicture/removeProfilePicture/profilePictureUrl`, `updateProfileStatus`, `fetchStatus`, `fetchDisappearingDuration`, `onWhatsApp`, `sendPresenceUpdate` (composing/recording/available…), `presenceSubscribe`, `readMessages`, `sendReceipt(s)`.

### 🔄 Receive pipeline (auto)
- Message decrypt (1:1 + group sender-keys), decrypt fail වුනොත් **retry request** auto යවනවා — **MessageRetryManager** එකත් එක්ක: උඹලා යවපු අලුත් messages 512ක් cache වෙලා retry receipt එද්දී `getMessage` නැතුවත් resend වෙනවා, session එක ඕනෑ නම් auto-recreate වෙනවා (MAC errors වලදී වහාම), base-key collision + registration-id mismatch detect කරලා stale session මකනවා, phone එකෙන් resend ඉල්ලන එක delayed schedule වෙනවා
- History sync download + process (`messaging-history.set`), push names → contacts
- Protocol messages: revoke/edit → `messages.update`, ephemeral setting, app-state key share, phone-number share, LID migration, member label changes (`group.member-tag.update`)
- Poll votes / event responses decrypt (config `getMessage` ඕනේ), reactions → `messages.reaction`
- Receipts → `message-receipt.update` (+ incoming retry receipts වලට **re-send**), presence → `presence.update`, calls → `call` (+ `rejectCall`), group notifications → `groups.upsert/update` + `group-participants.update`, dirty-bits auto-clean
- Reporting tokens (WA Web style) හැම message එකකටම

### 🗄 In-Memory Store + Cacheable Key Store
`makeInMemoryStore()` — chats/contacts/messages/group-metadata සියල්ල auto update වෙන store එකක් (`$store->bind($sock->ev)`), `$store->getMessage($jid, $id)` (config `getMessage` එකට කෙලින්ම දෙන්න — එතකොට polls votes, reactions, retries සියල්ල full වැඩ), `writeToFile()/readFromFile()` (binary-safe JSON), `$store->fetchGroupMetadata($jid, $sock)`. `makeCacheableSignalKeyStore($keys)` — file key store එකට 5-min TTL cache එකක් (වේගවත් කරන්න).

### 🛠 Utility / Protocol
`downloadMediaMessage/downloadAndSaveMediaMessage/downloadContentFromMessage`, `updateMediaMessage` (re-upload), `Media::getFile`, `fetchMessageHistory`, `requestPlaceholderResend`, `sendPeerDataOperationMessage`, `fetchProps`/`serverProps`, `fetchAccountReachoutTimelock`, `fetchNewChatMessageCap`, `createCallLink`, `rotateSignedPreKey`, `digestKeyBundle`, `uploadPreKeys`, `executeUSyncQuery`, raw `query()`/`sendNode()`, `getLIDForPN/getPNForLID`.

### 📡 Events
`connection.update`, `creds.update`, `messages.upsert`, `messages.update`, `messages.delete`, `messages.reaction`, `message-receipt.update`, `messaging-history.set`, `chats.upsert/update/delete`, `contacts.upsert/update`, `groups.upsert/update`, `group-participants.update`, `group.member-tag.update`, `presence.update`, `call`, `blocklist.set/update`, `labels.edit`, `labels.association`, `newsletter.update`.

## Examples

| File | Description |
|---|---|
| `examples/qr_login.php` | QR code එකෙන් login |
| `examples/pairing_code_login.php` | Pairing code එකෙන් login |
| `examples/send_message.php` | message එකක් යවලා exit |
| `examples/echo_bot.php` | reply කරන bot එකක් |
| `examples/media_and_groups.php` | photo යවන්න, group හදන්න, poll |
| `examples/full_features.php` | **සියලු features — cheat sheet එක** |

## Tests

```bash
php tests/run_tests.php   # 50 checks, offline
```

Crypto/protocol layers **Node.js libsignal + @whiskeysockets/baileys එකත් එක්ක byte-for-byte සලාකලා** verify කරලා තියෙනවා:
XEdDSA signatures, WABinary encoding, Protobuf, Signal double-ratchet (දෙපැත්තම), group sender-keys, media keys (Node එකෙන් PHP ciphertext decrypt කරනවා), app-state patches (Node `decodePatches` එකෙන් validate, hash match), QR matrices (Nayuki-identical).

## Config options (makeWASocket)

```php
$sock = makeWASocket([
    'auth' => $auth,                      // අනිවාර්යයි (AuthState | MultiFileAuthState)
    'logger' => new Logger('info'),       // error|warn|info|debug|trace
    'browser' => ['Ubuntu', 'Chrome', '20.0.04'],
    'syncFullHistory' => true,            // history sync එක ඉල්ලන්න
    'markOnlineOnConnect' => true,
    'fireInitQueries' => true,            // login පසු privacy/groups/props fetch
    'emitOwnEvents' => true,              // අපේම app-state patches events වලින් කියන්න
    'generateHighQualityLinkPreview' => false,
    'shouldIgnoreJid' => fn($jid) => false,
    'shouldSyncHistoryMessage' => fn($m) => true,
    'getMessage' => fn(array $key) => $store->getMessage($key['remoteJid'], $key['id']),
    'maxMsgRetryCount' => 5,            // එක message එකකට උපරිම retries
    'retryRequestDelayMs' => 250,       // retry receipts තොරන්න
    'enableAutoSessionRecreation' => true, // decrypt fail වෙන session auto අලුත් කරන්න
    'enableRecentMessageCache' => true,    // යවපු messages retry සඳහා cache කරන්න
    'printQRInTerminal' => false,
]);
```

## සටහන් (Notes)

- මේක unofficial library එකක්. WhatsApp එකේ ToS එකට විරුද්ධව පාවිච්චි කරන්න එපා; automation අනිසි ලෙස පාවිච්චි කලොත් number එක **ban වෙන්න පුළුවන්**. Test number එකකින් පටන් ගන්න.
- `auth_info` folder එකේ credentials තියෙනවා — ඒක කාටවත් දෙන්න/share කරන්න එපා, git එකට commit කරන්න එපා.
- PHP blocking architecture එකක් නිසා heavy වැඩ (ලොකු downloads) කරනකොට messages ටිකක් පරක්කු වෙන්න පුළුවන්.
- Production use එකේදී `getMessage` එකට real message store එකක් (DB) connect කරන්න — එතකොට polls votes, retries සම්පූර්ණයෙන්ම වැඩ කරනවා.

## ව්‍යුහය (Structure)

```
src/
  Auth/        creds + multi-file auth state (Node Baileys-compatible JSON)
  Event/       event emitter
  Proto/       generic protobuf codec + WAProto schema (498 messages)
  Signal/      libsignal (sessions, pre-keys, sender keys, LID mapping)
  Store/       in-memory chats/contacts/messages store
  Socket/      WASocket + feature traits (messages, groups, communities,
               business, newsletter, chats, app-state, receive pipeline)
  Utils/       crypto, noise, QR, media, link preview, app-state crypto
  WABinary/    binary node encode/decode, JIDs, token dictionary
  WAUSync/     USync queries (devices, status, username, lid...)
```

Built by reverse-engineering the WhatsApp Web protocol as documented by the open-source
[WhiskeySockets/Baileys](https://github.com/WhiskeySockets/Baileys) project (MIT). QR encoder based on Project Nayuki (MIT).
