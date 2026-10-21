<?php
/**
 * Baileys-PHP offline test suite (no network needed).
 * Run:  php tests/run_tests.php
 *
 * Covers: XEdDSA Curve25519 (byte-identical vs libsignal vectors), WABinary
 * encode (byte-identical vs upstream Baileys), protobuf, Signal Double-Ratchet
 * end-to-end conversation, and the QR generator (identical matrix to Nayuki).
 */
require __DIR__ . '/../autoload.php';

use BaileysPHP\Auth\AuthState;
use BaileysPHP\Auth\Creds;
use BaileysPHP\Auth\InMemorySignalKeyStore;
use BaileysPHP\Proto\WAProto;
use BaileysPHP\Signal\SignalRepository;
use BaileysPHP\Utils\Curve;
use BaileysPHP\Utils\Logger;
use BaileysPHP\Utils\QrCode;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\Encoder;
use function BaileysPHP\Utils\generateSignalPubKey;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name\n";
    }
}

echo "== Curve25519 / XEdDSA (libsignal vectors) ==\n";
$vectors = [
    [
        'priv' => '103ba46eaca1f3f2fff9a6ab7fd7935b239b338c7c14d08e5db0215c00c18048',
        'pub' => 'a1ecf04146c520338306404f1a5b84a2a8f920151fbf88bd07284632acb7bf7c',
        'msg' => '68656c6c6f206261696c6579732070687020746573742030',
        'sig' => '2e78723b7c8e771c53377745ae92beed1cb850bd3fbb51326fbbaea839eab46ad1cb877a57dcc1b9c2404f450956d9d882a8fb06cfa76232f97d6b71ef6c2600',
    ],
];
foreach ($vectors as $i => $v) {
    $pub = Curve::derivePublicKey(hex2bin($v['priv']));
    check("derive pubkey #$i", bin2hex($pub) === $v['pub']);
    $sig = Curve::sign(hex2bin($v['priv']), hex2bin($v['msg']));
    check("sign byte-identical #$i", bin2hex($sig) === $v['sig']);
    check("verify #$i", Curve::verify(generateSignalPubKey($pub), hex2bin($v['msg']), hex2bin($v['sig'])));
}
$a = Curve::generateKeyPair();
$b = Curve::generateKeyPair();
check('DH symmetric', Curve::sharedKey($a->private, generateSignalPubKey($b->public)) === Curve::sharedKey($b->private, generateSignalPubKey($a->public)));

echo "== WABinary (upstream byte vectors) ==\n";
$nodes = [
    new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'get', 'xmlns' => 'w:p', 'id' => 'TAG123'], [new BinaryNode('ping')]),
    new BinaryNode('message', ['to' => '94771234567@s.whatsapp.net', 'type' => 'text', 'id' => '3EB0ABC'], [
        new BinaryNode('enc', ['v' => '2', 'type' => 'pkmsg'], new Bytes('hello ciphertext bytes')),
    ]),
    new BinaryNode('presence', ['name' => 'Madushka Test']),
    new BinaryNode('iq', ['to' => '94771234567:5@s.whatsapp.net', 'type' => 'set'], [
        new BinaryNode('key', [], [
            new BinaryNode('id', [], new Bytes("\0\0\x07")),
            new BinaryNode('value', [], new Bytes(str_repeat("\x09", 32))),
        ]),
    ]),
];
$expectedHex = [
    '00f80a1911030429165708fc06544147313233f801f80156',
    '00f8081311faff8694771234567f03043808fb843eb0abcff801f8061d51450453fc1668656c6c6f2063697068657274657874206279746573',
    '00f8031f89fc0d4d61647573686b612054657374',
    '00f8061911f70005ff8694771234567f045af801f8029ef802f80208fc03000007f8026cfc200909090909090909090909090909090909090909090909090909090909090909',
];
foreach ($nodes as $i => $n) {
    check("encode #$i byte-identical", bin2hex(Encoder::encodeBinaryNode($n)) === $expectedHex[$i]);
}

echo "== Protobuf (WAProto vectors) ==\n";
$hs = WAProto::encodeHandshakeClientHello(['ephemeral' => str_repeat("\x07", 32)]);
check('HandshakeMessage.clientHello', bin2hex($hs) === '12220a200707070707070707070707070707070707070707070707070707070707070707');
$msg = WAProto::encodeMessage(['conversation' => 'Hello ආයුබෝවන්']);
check('Message.conversation', bin2hex($msg) === '0a1e48656c6c6f20e0b686e0b6bae0b794e0b6b6e0b79de0b780e0b6b1e0b78a');
$msg2 = WAProto::encodeMessage(['extendedTextMessage' => ['text' => 'extended hello']]);
check('Message.extendedTextMessage', bin2hex($msg2) === '32100a0e657874656e6465642068656c6c6f');
$dsm = WAProto::encodeMessage(['deviceSentMessage' => ['destinationJid' => '9477@s.whatsapp.net', 'message' => ['conversation' => 'self note']]]);
check('Message.deviceSentMessage', bin2hex($dsm) === 'fa01220a133934373740732e77686174736170702e6e6574120b0a0973656c66206e6f7465');
check('decode roundtrip', (WAProto::decodeMessage($msg)['conversation'] ?? '') === 'Hello ආයුබෝවන්');

echo "== Signal Double-Ratchet (Alice <-> Bob in PHP) ==\n";
function party(): array
{
    $creds = Creds::init();
    $state = new AuthState($creds, new InMemorySignalKeyStore());
    $repo = new SignalRepository($state, Logger::silent());
    $pre = Curve::generateKeyPair();
    $state->keys->set(['pre-key' => [1 => $pre]]);
    return [$creds, $state, $repo, $pre];
}
[$aCreds, , $aRepo] = party();
[$bCreds, , $bRepo, $bPre] = party();
$aJid = '94770000001@s.whatsapp.net';
$bJid = '94770000002@s.whatsapp.net';
$aRepo->injectE2ESession(['jid' => $bJid, 'session' => [
    'registrationId' => $bCreds->registrationId,
    'identityKey' => generateSignalPubKey($bCreds->signedIdentityKey->public),
    'signedPreKey' => ['keyId' => $bCreds->signedPreKey->keyId, 'publicKey' => generateSignalPubKey($bCreds->signedPreKey->keyPair->public), 'signature' => $bCreds->signedPreKey->signature],
    'preKey' => ['keyId' => 1, 'publicKey' => generateSignalPubKey($bPre->public)],
]]);
$convo = [
    [$aRepo, $bJid, $bRepo, $aJid, 'Hello Bob, first pkmsg!'],
    [$aRepo, $bJid, $bRepo, $aJid, 'Second message same chain'],
    [$bRepo, $aJid, $aRepo, $bJid, 'Reply from Bob (ratchet step)'],
    [$bRepo, $aJid, $aRepo, $bJid, 'Bob second'],
    [$aRepo, $bJid, $bRepo, $aJid, 'Alice again after step'],
    [$aRepo, $bJid, $bRepo, $aJid, 'ආයුබෝවන් Sinhala text test 123'],
];
foreach ($convo as $i => [$from, $toJid, $to, $fromJid, $text]) {
    $enc = $from->encryptMessage(['jid' => $toJid, 'data' => $text]);
    $dec = $to->decryptMessage(['jid' => $fromJid, 'type' => $enc['type'], 'ciphertext' => $enc['ciphertext']]);
    check("ratchet message #$i ({$enc['type']})", $dec === $text);
}
// out-of-order delivery within one chain
$e = [];
foreach (['ooo-1', 'ooo-2', 'ooo-3'] as $t) {
    $e[$t] = $aRepo->encryptMessage(['jid' => $bJid, 'data' => $t]);
}
$oooOk = true;
foreach (['ooo-3', 'ooo-1', 'ooo-2'] as $t) {
    $d = $bRepo->decryptMessage(['jid' => $aJid, 'type' => $e[$t]['type'], 'ciphertext' => $e[$t]['ciphertext']]);
    $oooOk = $oooOk && $d === $t;
}
check('out-of-order messages', $oooOk);

echo "== QR generator (Nayuki-identical matrix) ==\n";
$qr = QrCode::encodeText('HELLO WORLD');
$rows = [];
for ($y = 0; $y < $qr->getSize(); $y++) {
    $r = '';
    for ($x = 0; $x < $qr->getSize(); $x++) {
        $r .= $qr->getModule($x, $y) ? '1' : '0';
    }
    $rows[] = $r;
}
check('HELLO WORLD matrix hash', hash('sha256', implode("\n", $rows)) === '37f15f65c379cd70a87010f7f75eaebaddaf3c0809c0f5ba46e8ef5fa3d5c047');

echo "== Group sender keys (PHP roundtrip) ==\n";
try {
    $mkState = function () {
        $creds = \BaileysPHP\Auth\Creds::init();
        return new \BaileysPHP\Auth\AuthState($creds, new \BaileysPHP\Auth\InMemorySignalKeyStore());
    };
    $lg = new \BaileysPHP\Utils\Logger('error');
    $aState = $mkState();
    $bState = $mkState();
    $aRepo2 = new \BaileysPHP\Signal\SignalRepository($aState, $lg, fn(array $j) => []);
    $bRepo2 = new \BaileysPHP\Signal\SignalRepository($bState, $lg, fn(array $j) => []);
    $groupJid = '1234567890-1600000000@g.us';
    $aJid2 = '94711111111:1@s.whatsapp.net';
    $gEncRes = $aRepo2->encryptGroupMessage(['group' => $groupJid, 'meId' => $aJid2, 'data' => 'group hello']);
    $bRepo2->processSenderKeyDistributionMessage([
        'item' => ['groupId' => $groupJid, 'axolotlSenderKeyDistributionMessage' => $gEncRes['senderKeyDistributionMessage']],
        'authorJid' => $aJid2,
    ]);
    $gDec = $bRepo2->decryptGroupMessage(['group' => $groupJid, 'authorJid' => $aJid2, 'msg' => $gEncRes['ciphertext']]);
    check('sender-key encrypt/decrypt', $gDec === 'group hello');
} catch (\Throwable $e) {
    check('sender-key roundtrip (' . $e->getMessage() . ')', false);
}

echo "== App state (syncd crypto roundtrip) ==\n";
try {
    $keyData = hash('sha256', 'app-state-test-key', true);
    $keyIdB64 = base64_encode("\x01\x02\x03\x04");
    $key = ['keyData' => $keyData];
    $getKey = fn($id) => $key;
    $patchCreate = \BaileysPHP\Utils\AppState::chatModificationToAppPatch(['pushNameSetting' => 'Test Name'], '');
    $r = \BaileysPHP\Utils\AppState::encodeSyncdPatch(
        $patchCreate, $keyIdB64, \BaileysPHP\Utils\AppState::newLTHashState(), $getKey
    );
    $r['patch']['version'] = ['version' => $r['state']['version']];
    $out = \BaileysPHP\Utils\AppState::decodePatches(
        'critical_block', [$r['patch']], \BaileysPHP\Utils\AppState::newLTHashState(),
        $getKey, fn($b) => [], null, null, true
    );
    $mut = array_values($out['mutationMap'])[0] ?? null;
    check('syncd patch encode/decode', ($mut['syncAction']['value']['pushNameSetting']['name'] ?? null) === 'Test Name');
    check('LT-hash consistent', ($out['state']['hash'] ?? null) === $r['state']['hash']);
} catch (\Throwable $e) {
    check('app-state roundtrip (' . $e->getMessage() . ')', false);
}

echo "== Media keys + encryption ==\n";
try {
    $mediaKey = hash('sha256', 'mk', true);
    $plain = str_repeat('media-bytes-', 100);
    $enc = \BaileysPHP\Utils\Media::encryptMedia($plain, 'image', $mediaKey);
    $dec = \BaileysPHP\Utils\Media::decryptMedia($enc['ciphertext'], $mediaKey, 'image');
    check('media AES-CBC roundtrip', $dec === $plain);
    check('media sha256s present', strlen($enc['fileSha256']) === 32 && strlen($enc['fileEncSha256']) === 32);
} catch (\Throwable $e) {
    check('media roundtrip (' . $e->getMessage() . ')', false);
}

echo "== Reporting token ==\n";
try {
    $secret = hash('sha256', 'test-secret', true);
    $msg = ['extendedTextMessage' => ['text' => 'Hello'], 'messageContextInfo' => ['messageSecret' => $secret]];
    $node = \BaileysPHP\Utils\Reporting::getMessageReportingToken(
        \BaileysPHP\Proto\WAProto::encodeMessage($msg), $msg,
        ['id' => '3EB0ABC', 'fromMe' => true, 'remoteJid' => '94770000000@s.whatsapp.net']
    );
    check('reporting token vector', $node !== null && base64_encode($node->content[0]->contentString()) === 'L8ml2QLlFMt3NA0Kap2UHg==');
} catch (\Throwable $e) {
    check('reporting token (' . $e->getMessage() . ')', false);
}

echo "== Generic protobuf roundtrip (HistorySync) ==\n";
try {
    $hist = ['syncType' => 2, 'conversations' => [['id' => '94770000000@s.whatsapp.net', 'name' => 'A']]];
    $bytes = \BaileysPHP\Proto\WAProto::encodeProto('HistorySync', $hist);
    $back = \BaileysPHP\Proto\WAProto::decodeProto('HistorySync', $bytes);
    check('HistorySync roundtrip', ($back['conversations'][0]['name'] ?? null) === 'A' && (int)($back['syncType'] ?? 0) === 2);
} catch (\Throwable $e) {
    check('proto roundtrip (' . $e->getMessage() . ')', false);
}

echo "== Socket API surface ==\n";
try {
    $sock = \BaileysPHP\makeWASocket(['auth' => \BaileysPHP\useMultiFileAuthState(sys_get_temp_dir() . '/bphp_apitest')]);
    $need = ['sendMessage','groupMetadata','groupCreate','chatModify','newsletterCreate','communityCreate','getCatalog','productCreate','resyncAppState','fetchPrivacySettings','downloadMediaMessage','rotateSignedPreKey','executeWMexQuery','updateBussinesProfile'];
    $ok = true;
    foreach ($need as $m) { $ok = $ok && method_exists($sock, $m); }
    check('public API present (' . count(get_class_methods($sock)) . ' methods)', $ok);
} catch (\Throwable $e) {
    check('API surface (' . $e->getMessage() . ')', false);
}

echo "== MessageRetryManager ==\n";
try {
    $mrm = new \BaileysPHP\Utils\MessageRetryManager(new \BaileysPHP\Utils\Logger('error'), 3);
    $mrm->addRecentMessage('94770000000@s.whatsapp.net', 'ID1', ['conversation' => 'hi']);
    $got = $mrm->getRecentMessage('94770000000@s.whatsapp.net', 'ID1');
    check('recent message cache', ($got['message']['conversation'] ?? null) === 'hi');
    check('retry count increments', $mrm->incrementRetryCount('ID1') === 1 && $mrm->incrementRetryCount('ID1') === 2);
    check('max retries gate', !$mrm->hasExceededMaxRetries('ID1') && $mrm->incrementRetryCount('ID1') === 3 && $mrm->hasExceededMaxRetries('ID1'));
    $mrm->markRetrySuccess('ID1');
    check('success clears counter+message', $mrm->getRetryCount('ID1') === 0 && $mrm->getRecentMessage('94770000000@s.whatsapp.net', 'ID1') === null);
    $r1 = $mrm->shouldRecreateSession('a@s.whatsapp.net', false);
    $r2 = $mrm->shouldRecreateSession('b@s.whatsapp.net', true);
    $r3 = $mrm->shouldRecreateSession('b@s.whatsapp.net', true);
    $r4 = $mrm->shouldRecreateSession('b@s.whatsapp.net', true, \BaileysPHP\Utils\MessageRetryManager::RETRY_BAD_MAC);
    check('session recreation logic', $r1['recreate'] && $r2['recreate'] && !$r3['recreate'] && $r4['recreate']);
    check('parse error codes', $mrm->parseRetryErrorCode('7') === 7 && $mrm->parseRetryErrorCode('') === null && $mrm->isMacError(4) && !$mrm->isMacError(1));
    $mrm->saveBaseKey('addr', 'ID9', 'basekey-bytes');
    check('base key compare', $mrm->hasSameBaseKey('addr', 'ID9', 'basekey-bytes') && !$mrm->hasSameBaseKey('addr', 'ID9', 'other'));
    $mrm->deleteBaseKey('addr', 'ID9');
    check('base key delete', !$mrm->hasSameBaseKey('addr', 'ID9', 'basekey-bytes'));
    $stats = $mrm->getStatistics();
    check('statistics tracked', $stats['totalRetries'] === 3 && $stats['sessionRecreations'] === 3);
} catch (\Throwable $e) {
    check('MessageRetryManager (' . $e->getMessage() . ')', false);
}

echo "== Session info (baseKey / registrationId) ==\n";
try {
    // $aRepo/$bRepo sessions were established in the ratchet section above
    $info = $bRepo->getSessionInfo($aJid);
    check('getSessionInfo present', $info !== null && strlen($info['baseKey']) >= 32 && is_int($info['registrationId']));
    check('registrationId matches sender', ($info['registrationId'] ?? -1) === $aCreds->registrationId);
    check('validateSession true', ($bRepo->validateSession($aJid)['exists'] ?? false) === true);
} catch (\Throwable $e) {
    check('session info (' . $e->getMessage() . ')', false);
}

echo "== CacheableSignalKeyStore + InMemoryStore ==\n";
try {
    $inner = new \BaileysPHP\Auth\InMemorySignalKeyStore();
    $cacheStore = \BaileysPHP\makeCacheableSignalKeyStore($inner);
    $cacheStore->set(['session' => ['9477.0:0' => 'abc-bytes']]);
    check('cacheable store set/get', ($cacheStore->get('session', ['9477.0:0'])['9477.0:0'] ?? null) === 'abc-bytes');
    $cacheStore->set(['session' => ['9477.0:0' => null]]);
    check('cacheable store delete', $cacheStore->get('session', ['9477.0:0']) === []);

    $store = \BaileysPHP\makeInMemoryStore(['maxMessagesPerChat' => 10]);
    $ev2 = new \BaileysPHP\Event\EventEmitter();
    $store->bind($ev2);
    $ev2->emit('messages.upsert', ['type' => 'notify', 'messages' => [[
        'key' => ['remoteJid' => '94770000000@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG42'],
        'message' => ['conversation' => 'hello'],
        'messageTimestamp' => 1700000000,
        'messageContextInfo' => ['messageSecret' => random_bytes(32)],
    ]]]);
    check('store captures message + secret', ($store->getMessage('94770000000@s.whatsapp.net', 'MSG42')['conversation'] ?? null) === 'hello');
    $ev2->emit('chats.upsert', [['id' => '94770000000@s.whatsapp.net', 'name' => 'Test']]);
    $ev2->emit('contacts.upsert', [['id' => '94770000000@s.whatsapp.net', 'name' => 'Test User']]);
    check('store chats/contacts', ($store->getChat('94770000000@s.whatsapp.net')['name'] ?? null) === 'Test'
        && ($store->getContact('94770000000@s.whatsapp.net')['name'] ?? null) === 'Test User');
    $tmp = sys_get_temp_dir() . '/bphp_store_test.json';
    $store->writeToFile($tmp);
    $store2 = \BaileysPHP\makeInMemoryStore();
    $store2->readFromFile($tmp);
    $orig = $store->loadMessage('94770000000@s.whatsapp.net', 'MSG42');
    $back = $store2->loadMessage('94770000000@s.whatsapp.net', 'MSG42');
    check('store file roundtrip (binary-safe)',
        ($back['message']['conversation'] ?? null) === 'hello'
        && ($back['messageContextInfo']['messageSecret'] ?? null) === ($orig['messageContextInfo']['messageSecret'] ?? null));
    $ev2->emit('messages.delete', ['keys' => [['remoteJid' => '94770000000@s.whatsapp.net', 'id' => 'MSG42']]]);
    check('store delete', $store->getMessage('94770000000@s.whatsapp.net', 'MSG42') === null);
} catch (\Throwable $e) {
    check('store (' . $e->getMessage() . ')', false);
}

echo "== Regression: AuthState::saveCreds + LID self-detection (v2.1 hotfixes) ==\n";
try {
    $dir = sys_get_temp_dir() . '/bphp_savecreds';
    @mkdir($dir, 0777, true);
    $authX = \BaileysPHP\useMultiFileAuthState($dir);
    $authX->state->creds->accountSyncCounter = 4242;
    $authX->state->saveCreds(); // used to fatal: method did not exist on AuthState
    $saved = json_decode(file_get_contents($dir . '/creds.json'), true);
    check('state->saveCreds() persists via MultiFileAuthState', ($saved['accountSyncCounter'] ?? null) === 4242);
} catch (\Throwable $e) {
    check('saveCreds regression (' . $e->getMessage() . ')', false);
}

try {
    $credsX = \BaileysPHP\Auth\Creds::init();
    $credsX->me = ['id' => '94770000000@s.whatsapp.net', 'lid' => '123456789012345@lid', 'name' => 'Me'];
    $stateX = new \BaileysPHP\Auth\AuthState($credsX, new \BaileysPHP\Auth\InMemorySignalKeyStore());
    $sockX = \BaileysPHP\makeWASocket(['auth' => $stateX, 'logger' => new \BaileysPHP\Utils\Logger('error')]);
    $captured = [];
    $sockX->ev->on('messages.upsert', function (array $e) use (&$captured) {
        foreach ($e['messages'] ?? [] as $m) { $captured[] = $m; }
    });
    $handler = new \ReflectionMethod($sockX, 'handleMessageStanza');
    $protoMsg = ['protocolMessage' => [
        'type' => \BaileysPHP\Proto\WAProto::enumValue('Message.ProtocolMessage.Type', 'HISTORY_SYNC_NOTIFICATION'),
        'historySyncNotification' => ['syncType' => 0],
    ]];
    $mkStanza = fn(string $from, string $id) => new \BaileysPHP\WABinary\BinaryNode('message',
        ['from' => $from, 'id' => $id, 't' => (string)time(), 'type' => 'text'],
        [new \BaileysPHP\WABinary\BinaryNode('plaintext', [], new \BaileysPHP\WABinary\Bytes(
            \BaileysPHP\Proto\WAProto::encodeMessage($protoMsg)
        ))]);
    // own other device, LID-addressed → must be fromMe (history sync must not be dropped)
    $handler->invoke($sockX, $mkStanza('123456789012345:5@lid', 'SELF_LID_1'));
    // stranger LID → must NOT be fromMe
    $handler->invoke($sockX, $mkStanza('999888777666555:1@lid', 'STRANGER_1'));
    $byId = [];
    foreach ($captured as $m) { $byId[$m['key']['id'] ?? ''] = $m; }
    check('LID self stanza detected as fromMe', ($byId['SELF_LID_1']['key']['fromMe'] ?? null) === true);
    check('stranger LID stanza not fromMe', ($byId['STRANGER_1']['key']['fromMe'] ?? null) === false);
} catch (\Throwable $e) {
    check('LID self-detection regression (' . $e->getMessage() . ')', false);
}


// ==================== 16. Full 1:1 relay simulation (receive LID -> reply -> phone decrypts) ====================
echo "\n-- 1:1 relay simulation --\n";
try {
    $TB_PN = '94770000001'; $TB_LID = '111222333444555'; $TB_DEV = 12;
    $TU_PN = '94770000002'; $TU_LID = '248597723508975';
    $mkAuth = function (string $pn, string $lid, int $dev, string $name) {
        $st = new \BaileysPHP\Auth\AuthState(\BaileysPHP\Auth\Creds::init(), new \BaileysPHP\Auth\InMemorySignalKeyStore());
        $st->creds->me = ['id' => $pn . ($dev ? ':' . $dev : '') . '@s.whatsapp.net', 'lid' => $lid . ($dev ? ':' . $dev : '') . '@lid', 'name' => $name];
        return $st;
    };
    $botState = $mkAuth($TB_PN, $TB_LID, $TB_DEV, 'Bot');
    $usrState = $mkAuth($TU_PN, $TU_LID, 0, 'User');
    $usrRepo = new \BaileysPHP\Signal\SignalRepository($usrState, new \BaileysPHP\Utils\Logger('error'), fn(array $j) => []);
    $usrRepo->lidMapping->storeLIDPNMappings([
        ['lid' => $TB_LID . '@lid', 'pn' => $TB_PN . '@s.whatsapp.net'],
        ['lid' => $TU_LID . '@lid', 'pn' => $TU_PN . '@s.whatsapp.net'],
    ]);
    $credMap = [$TB_PN => $botState->creds, $TB_LID => $botState->creds, $TU_PN => $usrState->creds, $TU_LID => $usrState->creds];
    $stateMap = [$TB_PN => $botState, $TB_LID => $botState, $TU_PN => $usrState, $TU_LID => $usrState];
    $devMap = [$TB_PN => [0, $TB_DEV], $TB_LID => [0, $TB_DEV], $TU_PN => [0], $TU_LID => [0]];
    $pkSeq = [];
    $freshPk = function (string $user) use (&$pkSeq, $stateMap) {
        $id = ($pkSeq[$user] = ($pkSeq[$user] ?? 200) + 1);
        $pk = \BaileysPHP\Utils\Curve::generateKeyPair();
        $stateMap[$user]->keys->set(['pre-key' => [(string)$id => $pk]]);
        return \BaileysPHP\Utils\SignalUtils::xmppPreKey($pk, $id);
    };
    $botSock = new class(['auth' => $botState, 'logger' => new \BaileysPHP\Utils\Logger('error')]) extends \BaileysPHP\Socket\WASocket {
        public array $sentNodes = [];
        public array $credMap = [], $devMap = [], $lidMap = [];
        public ?\Closure $freshPk = null;
        public function sendNode(\BaileysPHP\WABinary\BinaryNode $frame): void { $this->sentNodes[] = $frame; }
        public function query(\BaileysPHP\WABinary\BinaryNode $node, ?int $timeoutMs = null): \BaileysPHP\WABinary\BinaryNode
        {
            if (($node->attrs['xmlns'] ?? '') === 'encrypt') {
                $keyNode = \BaileysPHP\WABinary\GenericUtils::getBinaryNodeChild($node, 'key');
                $users = [];
                foreach (\BaileysPHP\WABinary\GenericUtils::getBinaryNodeChildren($keyNode, 'user') as $u) {
                    $jid = $u->attrs['jid'] ?? '';
                    $user = \BaileysPHP\WABinary\Jid::decode($jid)['user'] ?? '';
                    $creds = $this->credMap[$user] ?? null;
                    if (!$creds) continue;
                    $users[] = new \BaileysPHP\WABinary\BinaryNode('user', ['jid' => $jid], [
                        new \BaileysPHP\WABinary\BinaryNode('identity', [], new \BaileysPHP\WABinary\Bytes($creds->signedIdentityKey->public)),
                        \BaileysPHP\Utils\SignalUtils::xmppSignedPreKey($creds->signedPreKey),
                        ($this->freshPk)($user),
                        new \BaileysPHP\WABinary\BinaryNode('registration', [], new \BaileysPHP\WABinary\Bytes(\BaileysPHP\Utils\Generics::encodeBigEndian($creds->registrationId, 4))),
                        new \BaileysPHP\WABinary\BinaryNode('type', [], new \BaileysPHP\WABinary\Bytes("\x05")),
                    ]);
                }
                return new \BaileysPHP\WABinary\BinaryNode('iq', ['type' => 'result'], [new \BaileysPHP\WABinary\BinaryNode('list', [], $users)]);
            }
            return new \BaileysPHP\WABinary\BinaryNode('iq', ['type' => 'result'], []);
        }
        public function executeUSyncQuery(\BaileysPHP\WAUSync\USyncQuery $query): ?array
        {
            $list = [];
            foreach ($query->users as $u) {
                $jid = $u['id'] ?? '';
                $user = \BaileysPHP\WABinary\Jid::decode($jid)['user'] ?? '';
                $list[] = ['id' => $jid, 'devices' => [
                    'deviceList' => array_map(fn($d) => ['id' => $d, 'keyIndex' => 1, 'isHosted' => false], $this->devMap[$user] ?? [0]),
                    'keyIndex' => null,
                ], 'lid' => $this->lidMap[$user] ?? null];
            }
            return ['list' => $list, 'sideList' => []];
        }
    };
    $botSock->credMap = $credMap;
    $botSock->devMap = $devMap;
    $botSock->freshPk = $freshPk;
    $botSock->lidMap = [$TB_PN => $TB_LID . '@lid', $TU_PN => $TU_LID . '@lid', $TB_LID => $TB_LID . '@lid', $TU_LID => $TU_LID . '@lid'];

    // user sends "hi bot" to the bot (pkmsg, LID-addressed)
    $botPre = \BaileysPHP\Utils\Curve::generateKeyPair();
    $botState->keys->set(['pre-key' => ['55' => $botPre]]);
    $usrRepo->injectE2ESession(['jid' => $TB_LID . ':' . $TB_DEV . '@lid', 'session' => [
        'registrationId' => $botState->creds->registrationId,
        'identityKey' => \BaileysPHP\Utils\generateSignalPubKey($botState->creds->signedIdentityKey->public),
        'signedPreKey' => ['keyId' => $botState->creds->signedPreKey->keyId, 'publicKey' => \BaileysPHP\Utils\generateSignalPubKey($botState->creds->signedPreKey->keyPair->public), 'signature' => $botState->creds->signedPreKey->signature],
        'preKey' => ['keyId' => 55, 'publicKey' => \BaileysPHP\Utils\generateSignalPubKey($botPre->public)],
    ]]);
    $encIn = $usrRepo->encryptMessage(['jid' => $TB_LID . ':' . $TB_DEV . '@lid', 'data' => \BaileysPHP\Utils\Generics::encodeWAMessage(['conversation' => 'hi bot'])]);
    $got = [];
    $botSock->ev->on('messages.upsert', function (array $e) use (&$got) {
        foreach ($e['messages'] ?? [] as $m) { $got[] = $m; }
    });
    $handler = new \ReflectionMethod($botSock, 'handleMessageStanza');
    $handler->invoke($botSock, new \BaileysPHP\WABinary\BinaryNode('message',
        ['from' => $TU_LID . '@lid', 'id' => 'SIM_IN_1', 't' => (string)time(), 'type' => 'text', 'sender_pn' => $TU_PN . '@s.whatsapp.net'],
        [new \BaileysPHP\WABinary\BinaryNode('enc', ['v' => '2', 'type' => $encIn['type']], new \BaileysPHP\WABinary\Bytes($encIn['ciphertext']))]));
    check('relay sim: incoming LID message decrypted', ($got[0]['message']['conversation'] ?? null) === 'hi bot' && ($got[0]['key']['remoteJid'] ?? '') === $TU_LID . '@lid');

    // bot replies; phone must decrypt (regression: "replies don't arrive")
    $botSock->sentNodes = [];
    $botSock->sendMessage($got[0]['key']['remoteJid'], ['text' => 'hi back!']);
    $reply = null;
    foreach ($botSock->sentNodes as $n) { if ($n->tag === 'message') { $reply = $n; } }
    $okAddr = $reply !== null && ($reply->attrs['to'] ?? '') === $TU_LID . '@lid';
    $decText = null;
    if ($reply !== null) {
        $parts = \BaileysPHP\WABinary\GenericUtils::getBinaryNodeChild($reply, 'participants');
        foreach (\BaileysPHP\WABinary\GenericUtils::getBinaryNodeChildren($parts, 'to') as $to) {
            if ((\BaileysPHP\WABinary\Jid::decode($to->attrs['jid'] ?? '')['user'] ?? '') !== $TU_LID) continue;
            $enc = \BaileysPHP\WABinary\GenericUtils::getBinaryNodeChild($to, 'enc');
            $plain = $usrRepo->decryptMessage(['jid' => $TB_LID . ':' . $TB_DEV . '@lid', 'type' => $enc->attrs['type'] ?? 'msg', 'ciphertext' => $enc->contentString() ?? '']);
            $m = \BaileysPHP\Proto\WAProto::decodeMessage(\BaileysPHP\Utils\Generics::unpadRandomMax16($plain));
            $decText = $m['extendedTextMessage']['text'] ?? $m['conversation'] ?? null;
        }
    }
    check('relay sim: reply stanza addressed to sender LID', $okAddr);
    check('relay sim: phone decrypts the reply', $decText === 'hi back!');
} catch (\Throwable $e) {
    check('1:1 relay simulation (' . $e->getMessage() . ')', false);
}

echo "\n==============================\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
