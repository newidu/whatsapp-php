<?php
/**
 * Full features reference — Baileys-PHP v2 එකේ තියෙන හැම feature එකම පෙන්වන "cheat sheet" එකක්.
 * මේ file එක run කරන්නම ඕනේ නැහැ — code එක කියවලා අවශ්‍ය function එක copy කරගන්න.
 *
 * Run කරන්න: php examples/full_features.php 94771234567
 * (සමහර functions demo එකට comment කරලා තියෙන්නේ — අවශ්‍ය ඒවා uncomment කරන්න.)
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\Utils\Logger;
use function BaileysPHP\makeWASocket;
use function BaileysPHP\useMultiFileAuthState;

$phone = $argv[1] ?? '94771234567';
$auth = useMultiFileAuthState(__DIR__ . '/baileys_php_auth');
$auth->saveCreds();

$store = \BaileysPHP\makeInMemoryStore();
$store->readFromFile(__DIR__ . '/baileys_store.json');
$sock = makeWASocket([
    'auth' => $auth,
    'logger' => new Logger('warn'),
    'markOnlineOnConnect' => true,
    'syncFullHistory' => true,          // පැරණි chats/contacts history sync එකෙන් ලැබෙයි
    // store එක bind කරලා getMessage එකට දෙන්න — poll votes/reactions/retries ඒ හින්දා වැඩ
    'getMessage' => fn(array $key) => $store->getMessage($key['remoteJid'] ?? '', $key['id'] ?? ''),
]);
$store->bind($sock->ev);
$sock->ev->on('creds.update', fn($u) => $auth->saveCreds());
$sock->connect();
$sock->waitForConnectionUpdate(fn($u) => ($u['connection'] ?? null) === 'open', 60000);

$jid = $sock->onWhatsApp([$phone])[0]['jid'];

// ================= TEXT / BASIC =================
$sock->sendMessage($jid, ['text' => 'Hello! 👋 Baileys-PHP v2']);
$sock->sendMessage($jid, ['text' => 'Site එක බලන්න: https://github.com/php/php-src']); // link preview auto-fetch

// quote (reply) කරන්න: quoted option එක යවන message එක එළියෙන් දාන්න
$sent = $sock->sendMessage($jid, ['text' => 'මේක reply එකක්'], ['quoted' => null]); // quoted එකට real message එකක් දාන්න

// edit / delete / react
// $sock->sendMessage($jid, ['text' => 'edited!', 'edit' => $sent['key']]);
// $sock->sendMessage($jid, ['react' => ['text' => '👍', 'key' => $sent['key']]]);
// $sock->sendMessage($jid, ['delete' => $sent['key']]);

// forward
// $sock->sendMessage($jid, ['forward' => $someMessage]);

// pin (duration: 86400=1d, 604800=7d, 2592000=30d)
// $sock->sendMessage($jid, ['pin' => ['key' => $sent['key'], 'type' => 1, 'time' => 86400]]);

// ================= MEDIA =================
// $sock->sendMessage($jid, ['image' => ['url' => 'https://example.com/x.jpg'], 'caption' => 'photo']);
// $sock->sendMessage($jid, ['video' => ['stream' => file_get_contents('v.mp4')], 'caption' => 'video', 'gifPlayback' => false]);
// $sock->sendMessage($jid, ['audio' => ['stream' => file_get_contents('a.mp3')], 'mimetype' => 'audio/mpeg']);
// $sock->sendMessage($jid, ['audio' => ['stream' => file_get_contents('v.ogg')], 'ptt' => true]); // voice note
// $sock->sendMessage($jid, ['document' => ['stream' => file_get_contents('f.pdf')], 'fileName' => 'f.pdf', 'mimetype' => 'application/pdf']);
// $sock->sendMessage($jid, ['sticker' => ['stream' => file_get_contents('s.webp')]]);
// $sock->sendMessage($jid, ['documentWithCaption' => ...]); // raw proto ඕනේ නම් ඕනෑම *Message field එකක් කෙලින්ම දාන්න පුළුවන්

// media download (incoming message එකකින්)
// $bytes = $sock->downloadMediaMessage($incomingMessage);
// $path  = $sock->downloadAndSaveMediaMessage($incomingMessage, 'downloaded_file');

// album (images කිහිපයක් එකම album එකක)
// $sock->sendMessage($jid, ['album' => [ ['image'=>...], ['image'=>...] ], 'text' => 'album caption']);

// location / live location
$sock->sendMessage($jid, ['location' => ['degreesLatitude' => 6.9271, 'degreesLongitude' => 79.8612, 'name' => 'World Trade Center']]);

// contact card
$sock->sendMessage($jid, ['contacts' => ['displayName' => 'My Contact', 'contacts' => [['displayName' => 'A', 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:A\nTEL;waid=94770000000:+94 77 000 0000\nEND:VCARD"]]]]);

// poll / event
$sock->sendMessage($jid, ['poll' => ['name' => 'අද සැලසුම?', 'values' => ['Trip 🚗', 'Home 🏠'], 'selectableCount' => 1]]);
// $sock->sendMessage($groupJid, ['event' => ['name' => 'Meetup', 'description' => '...', 'startDate' => time()+86400, 'endDate' => time()+90000, 'location' => ['degreesLatitude'=>6.9,'degreesLongitude'=>79.8,'name'=>'Colombo']]]);

// mentions
// $sock->sendMessage($groupJid, ['text' => "hi @$otherUser", 'mentions' => [$otherJid]]);

// status (story) — statusAudience config එකෙන් කාටද කියලා තීරණය වෙනවා
// $sock->sendMessage('status@broadcast', ['text' => 'මගේ status එක 😎', 'backgroundColor' => '#25D366', 'textArgb' => 4294967295, 'font' => 1]);

// disappearing messages (0=off, 86400=24h, 604800=7d, 7776000=90d)
// $sock->sendMessage($jid, ['disappearingMessagesInChat' => 86400]);

// ================= PRESENCE / READ =================
$sock->presenceSubscribe($jid);
$sock->sendPresenceUpdate('composing', $jid);   // "typing..."
$sock->sendPresenceUpdate('recording', $jid);   // "recording audio..."
$sock->sendPresenceUpdate('available');         // online
// $sock->readMessages([$messageKey1, $messageKey2]);  // blue tick

// ================= PROFILE =================
// $sock->updateProfileName('My New Name');
// $sock->updateProfileStatus('🌴 ');
// $sock->updateProfilePicture($sock->user['id'], ['stream' => file_get_contents('me.jpg')]);
// $sock->removeProfilePicture($sock->user['id']);
echo "My PP: " . ($sock->profilePictureUrl($sock->user['id']) ?? '—') . "\n";

// ================= PRIVACY =================
$privacy = $sock->fetchPrivacySettings();
echo "Privacy: " . json_encode($privacy) . "\n";
// $sock->updateLastSeenPrivacy('contacts');       // all|contacts|contact_blacklist|none
// $sock->updateOnlinePrivacy('match_last_seen');  // all|match_last_seen
// $sock->updateProfilePicturePrivacy('contacts');
// $sock->updateStatusPrivacy('contacts');
// $sock->updateReadReceiptsPrivacy('all');        // all|none
// $sock->updateGroupsAddPrivacy('my_contacts');   // all|contacts|contact_blacklist
// $sock->updateDefaultDisappearingMode(86400);

// block / unblock
$blocks = $sock->fetchBlocklist();
echo "Blocked: " . count($blocks) . "\n";
// $sock->updateBlockStatus($jid, 'block');

// ================= CHATS (app-state) =================
// $sock->archiveChat($jid, true);
// $sock->chatModify(['pin' => true], $jid);
// $sock->chatModify(['mute' => 86400], $jid);       // seconds; null = unmute
// $sock->chatModify(['markRead' => false], $jid);  // unread විදිහට mark
// $sock->chatModify(['clear' => 'messages'], $jid);
// $sock->chatModify(['delete' => true], $jid);

// ================= GROUPS =================
// $g = $sock->groupCreate('New Group', [$jid]);
// $sock->groupParticipantsUpdate($g['id'], [$jid], 'promote');   // add|remove|promote|demote
// $sock->groupUpdateSubject($g['id'], 'අලුත් නම');
// $sock->groupUpdateDescription($g['id'], 'Group එක ගැන...');
// $sock->groupSettingUpdate($g['id'], 'locked');                 // announcement|not_announcement|locked|unlocked
// $sock->groupToggleEphemeral($g['id'], 604800);
// $sock->groupJoinApprovalMode($g['id'], true);
// $sock->groupMemberAddMode($g['id'], 'admin_add');
// $pending = $sock->groupRequestParticipantsList($g['id']);
// $sock->groupRequestParticipantsUpdate($g['id'], [$jid], 'approve');
// $info = $sock->groupGetInviteInfo('inviteCode');
// $sock->groupAcceptInvite('inviteCode');
// $all = $sock->groupFetchAllParticipating();
// $sock->updateMemberLabel($g['id'], 'කොළඹ කණ්ඩායම');  // own member tag (meta member labels)

// ================= COMMUNITIES =================
// $c = $sock->communityCreate('My Community');
// $sock->communityCreateGroup($c['id'], 'Sub Group', [$jid]);
// $sock->communityLinkGroup($c['id'], $g['id']);
// $meta = $sock->communityMetadata($c['id']);

// ================= NEWSLETTERS (Channels) =================
// $nl = $sock->newsletterCreate('My Channel', 'Description එක');
// $sock->newsletterFollow($nlJid);
// $sock->newsletterUpdateName($nlJid, 'New Channel Name');
// $sock->newsletterUpdatePicture($nlJid, ['stream' => file_get_contents('ch.jpg')]);
// $subs = $sock->newsletterSubscribers($nlJid);
// $sock->newsletterReactMessage($nlJid, 'serverMsgId', '🔥');
// $msgs = $sock->newsletterFetchMessages($nlJid, 20);

// ================= BUSINESS =================
try {
    $biz = $sock->getBusinessProfile($jid);
    echo "Business: " . json_encode($biz) . "\n";
} catch (Throwable $e) { /* not a business account */ }
// $catalog = $sock->getCatalog($bizJid, 10);
// $collections = $sock->getCollections($bizJid);
// $sock->productCreate(['title'=>'My Product','price'=>1500,'currency'=>'LKR','description'=>'...','url'=>'https://...','retailerId'=>'p1']);
// $sock->updateBussinesProfile(['description' => 'Our shop', 'email' => 'shop@example.com', 'websites' => ['https://shop.example.com']]);
// $sock->updateCoverPhoto(['stream' => file_get_contents('cover.jpg')]);

// ================= LABELS / QUICK REPLIES / CONTACTS (app-state) =================
// $sock->addLabel(['id' => '1', 'name' => 'VIP', 'color' => 1]);
// $sock->addChatLabel($jid, '1');
// $sock->addOrEditContact($jid, ['firstName' => 'Wimukthi', 'lastName' => 'Sahith']);
// $sock->addOrEditQuickReply(['id'=>'1','shortcut'=>'thanks','message'=>'Thank you! 🙏']);

// ================= MISC =================
// $caps = $sock->fetchNewChatMessageCap();       // new-chat message limits
// $lock = $sock->fetchAccountReachoutTimelock(); // account restriction check
// $dur  = $sock->fetchDisappearingDuration($jid);
// $sock->fetchProps(); $props = $sock->serverProps();
// $link = $sock->createCallLink('audio');        // audio|video call link
// $sock->rotateSignedPreKey();                   // security: signed pre-key rotation
// $sock->resyncAppState(['regular','critical_block'], true); // manual app-state resync

echo "Done. listening... (Ctrl+C)\n";
$sock->run();
