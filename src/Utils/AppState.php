<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Proto\WAProto;

/** Thrown when an app-state sync key needed to decode a patch/snapshot is missing (Baileys: error.data.isMissingKey). */
final class MissingAppStateKeyException extends \RuntimeException {}

/**
 * App-state sync crypto — port of Baileys src/Utils/chat-utils.ts + lt-hash.ts.
 * LTHashState is an array: ['version'=>int, 'hash'=>string(128), 'indexValueMap'=>[base64indexMac => ['valueMac'=>string]]]
 */
final class AppState
{
    public const ALL_PATCH_NAMES = ['critical_block', 'critical_unblock_low', 'regular_high', 'regular_low', 'regular'];

    public static function newLTHashState(): array
    {
        return ['version' => 0, 'hash' => str_repeat("\0", 128), 'indexValueMap' => []];
    }

    public static function ensureVersion(array $state): array
    {
        if (!isset($state['version']) || !is_numeric($state['version'])) {
            $state['version'] = 0;
        }
        $state['hash'] ??= str_repeat("\0", 128);
        $state['indexValueMap'] ??= [];
        return $state;
    }

    /** mutationKeys(): HKDF-expand key data to the 5 app-state keys. */
    public static function mutationKeys(string $keyData): array
    {
        $expanded = hkdf($keyData, 160, '', 'WhatsApp Mutation Keys');
        return [
            'indexKey' => substr($expanded, 0, 32),
            'valueEncryptionKey' => substr($expanded, 32, 32),
            'valueMacKey' => substr($expanded, 64, 32),
            'snapshotMacKey' => substr($expanded, 96, 32),
            'patchMacKey' => substr($expanded, 128, 32),
        ];
    }

    /** LT-Hash add/subtract of one 32-byte value-mac into a 128-byte state. */
    private static function ltPointwise(string $base, string $valueMac, int $sign): string
    {
        $derived = hkdf($valueMac, 128, '', 'WhatsApp Patch Integrity');
        $out = '';
        for ($off = 0; $off < 128; $off += 2) {
            $a = unpack('v', $base, $off)[1];
            $b = unpack('v', $derived, $off)[1];
            $out .= pack('v', ($a + $sign * $b) & 0xFFFF);
        }
        return $out;
    }

    public static function ltSubtractThenAdd(string $base, array $subtract, array $add): string
    {
        foreach ($subtract as $m) {
            $base = self::ltPointwise($base, $m, -1);
        }
        foreach ($add as $m) {
            $base = self::ltPointwise($base, $m, +1);
        }
        return $base;
    }

    /** Mutate an LTHashState with a list of ['indexMac','valueMac','operation'] entries; returns new state. */
    public static function ltMix(array $state, array $macs): array
    {
        $indexValueMap = $state['indexValueMap'] ?? [];
        $adds = [];
        $subs = [];
        $remove = WAProto::enumValue('SyncdMutation.SyncdOperation', 'REMOVE');
        foreach ($macs as $mac) {
            $indexB64 = base64_encode($mac['indexMac']);
            $prev = $indexValueMap[$indexB64] ?? null;
            if ((int)$mac['operation'] === $remove) {
                if ($prev === null) {
                    continue; // WA Web logs & skips
                }
                unset($indexValueMap[$indexB64]);
            } else {
                $adds[] = $mac['valueMac'];
                $indexValueMap[$indexB64] = ['valueMac' => $mac['valueMac']];
            }
            if ($prev !== null) {
                $subs[] = $prev['valueMac'];
            }
        }
        $state['hash'] = self::ltSubtractThenAdd($state['hash'], $subs, $adds);
        $state['indexValueMap'] = $indexValueMap;
        return $state;
    }

    private static function to64BitNetworkOrder(int $e): string
    {
        return pack('N2', 0, $e);
    }

    /** generateMac() from chat-utils (HMAC-SHA512, first 32 bytes). */
    public static function generateMac(int $operation, string $data, string $keyIdBytes, string $key): string
    {
        $set = WAProto::enumValue('SyncdMutation.SyncdOperation', 'SET');
        $opByte = chr($operation === $set ? 0x01 : 0x02);
        $keyData = $opByte . $keyIdBytes;
        $last = str_repeat("\0", 7) . chr(strlen($keyData));
        return substr(hash_hmac('sha512', $keyData . $data . $last, $key, true), 0, 32);
    }

    public static function generateSnapshotMac(string $lthash, int $version, string $name, string $key): string
    {
        return hash_hmac('sha256', $lthash . self::to64BitNetworkOrder($version) . $name, $key, true);
    }

    /** @param list<string> $valueMacs */
    public static function generatePatchMac(string $snapshotMac, array $valueMacs, int $version, string $type, string $key): string
    {
        $total = $snapshotMac . implode('', $valueMacs) . self::to64BitNetworkOrder($version) . $type;
        return hash_hmac('sha256', $total, $key, true);
    }

    /** AES-256-CBC encrypt with random IV prefixed (crypto.ts aesEncrypt). */
    public static function aesEncrypt(string $data, string $key): string
    {
        $iv = random_bytes(16);
        $enc = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) {
            throw new \RuntimeException('app state encryption failed');
        }
        return $iv . $enc;
    }

    /** AES-256-CBC decrypt with prefixed IV (crypto.ts aesDecrypt). */
    public static function aesDecrypt(string $data, string $key): string
    {
        $iv = substr($data, 0, 16);
        $enc = substr($data, 16);
        $dec = openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) {
            throw new \RuntimeException('app state decryption failed');
        }
        return $dec;
    }

    /**
     * encodeSyncdPatch — build a single-mutation SyncdPatch.
     * @param array{type:string,index:array,syncAction:array,apiVersion:int,operation:int} $patchCreate
     * @param callable(string):?array $getAppStateSyncKey keyId base64 → AppStateSyncKeyData array
     * @return array{patch:array,state:array}
     */
    public static function encodeSyncdPatch(array $patchCreate, string $myAppStateKeyId, array $state, callable $getAppStateSyncKey): array
    {
        $key = $myAppStateKeyId !== '' ? $getAppStateSyncKey($myAppStateKeyId) : null;
        if (!$key || empty($key['keyData'])) {
            throw new MissingAppStateKeyException("myAppStateKey (\"$myAppStateKeyId\") not present");
        }
        $encKeyId = base64_decode($myAppStateKeyId);
        $state = self::ensureVersion($state);
        $indexBuffer = json_encode(array_values($patchCreate['index'])) ?: '[]';
        $encoded = WAProto::encodeProto('SyncActionData', [
            'index' => $indexBuffer,
            'value' => $patchCreate['syncAction'],
            'padding' => '',
            'version' => $patchCreate['apiVersion'],
        ]);
        $keys = self::mutationKeys($key['keyData']);
        $encValue = self::aesEncrypt($encoded, $keys['valueEncryptionKey']);
        $valueMac = self::generateMac((int)$patchCreate['operation'], $encValue, $encKeyId, $keys['valueMacKey']);
        $indexMac = hash_hmac('sha256', $indexBuffer, $keys['indexKey'], true);

        $state = self::ltMix($state, [[
            'indexMac' => $indexMac, 'valueMac' => $valueMac, 'operation' => (int)$patchCreate['operation'],
        ]]);
        $state['version'] += 1;
        $snapshotMac = self::generateSnapshotMac($state['hash'], (int)$state['version'], $patchCreate['type'], $keys['snapshotMacKey']);
        $patch = [
            'patchMac' => self::generatePatchMac($snapshotMac, [$valueMac], (int)$state['version'], $patchCreate['type'], $keys['patchMacKey']),
            'snapshotMac' => $snapshotMac,
            'keyId' => ['id' => $encKeyId],
            'mutations' => [[
                'operation' => (int)$patchCreate['operation'],
                'record' => [
                    'index' => ['blob' => $indexMac],
                    'value' => ['blob' => $encValue . $valueMac],
                    'keyId' => ['id' => $encKeyId],
                ],
            ]],
        ];
        $state['indexValueMap'][base64_encode($indexMac)] = ['valueMac' => $valueMac];
        return ['patch' => $patch, 'state' => $state];
    }

    /**
     * decodeSyncdMutations — decode a list of SyncdMutation/SyncdRecord arrays.
     * @param callable(array):void $onMutation receives ['syncAction'=>SyncActionData array,'index'=>array]
     * @param callable(string):?array $getAppStateSyncKey
     * @return array new state (hash/indexValueMap updated)
     */
    public static function decodeSyncdMutations(array $mutations, array $initialState, callable $getAppStateSyncKey, callable $onMutation, bool $validateMacs): array
    {
        $state = self::ensureVersion($initialState);
        $macs = [];
        $derivedCache = [];
        $set = WAProto::enumValue('SyncdMutation.SyncdOperation', 'SET');

        $getKey = function (string $keyIdBytes) use (&$derivedCache, $getAppStateSyncKey): array {
            $b64 = base64_encode($keyIdBytes);
            if (!isset($derivedCache[$b64])) {
                $keyEnc = $getAppStateSyncKey($b64);
                if (!$keyEnc || empty($keyEnc['keyData'])) {
                    throw new MissingAppStateKeyException("failed to find key \"$b64\" to decode mutation");
                }
                $derivedCache[$b64] = self::mutationKeys($keyEnc['keyData']);
            }
            return $derivedCache[$b64];
        };

        foreach ($mutations as $mutation) {
            $operation = isset($mutation['operation']) ? (int)$mutation['operation'] : $set;
            $record = isset($mutation['record']) && is_array($mutation['record']) ? $mutation['record'] : $mutation;
            $keyIdBytes = (string)($record['keyId']['id'] ?? '');
            try {
                $key = $getKey($keyIdBytes);
            } catch (MissingAppStateKeyException $e) {
                throw $e;
            } catch (\Throwable) {
                continue;
            }
            $content = (string)($record['value']['blob'] ?? '');
            if (strlen($content) < 32) {
                continue;
            }
            $encContent = substr($content, 0, -32);
            $valueMac = substr($content, -32);
            if ($validateMacs) {
                $hmac = self::generateMac($operation, $encContent, $keyIdBytes, $key['valueMacKey']);
                if (!hash_equals($hmac, $valueMac)) {
                    continue;
                }
            }
            try {
                $result = self::aesDecrypt($encContent, $key['valueEncryptionKey']);
            } catch (\Throwable) {
                continue;
            }
            $syncAction = WAProto::decodeProto('SyncActionData', $result);
            if ($validateMacs) {
                $hmac = hash_hmac('sha256', (string)($syncAction['index'] ?? ''), $key['indexKey'], true);
                if (!hash_equals($hmac, (string)($record['index']['blob'] ?? ''))) {
                    throw new \RuntimeException('HMAC index verification failed');
                }
            }
            $index = json_decode((string)($syncAction['index'] ?? '[]'), true) ?: [];
            $onMutation(['syncAction' => $syncAction, 'index' => $index]);
            $macs[] = [
                'indexMac' => (string)($record['index']['blob'] ?? ''),
                'valueMac' => $valueMac,
                'operation' => $operation,
            ];
        }
        return self::ltMix($state, $macs);
    }

    /** decodeSyncdPatch */
    public static function decodeSyncdPatch(array $msg, string $name, array $initialState, callable $getAppStateSyncKey, callable $onMutation, bool $validateMacs): array
    {
        if ($validateMacs) {
            $b64 = base64_encode((string)($msg['keyId']['id'] ?? ''));
            $mainKeyObj = $getAppStateSyncKey($b64);
            if (!$mainKeyObj || empty($mainKeyObj['keyData'])) {
                throw new MissingAppStateKeyException("failed to find key \"$b64\" to decode patch");
            }
            $mainKey = self::mutationKeys($mainKeyObj['keyData']);
            $mutationMacs = array_map(
                fn($m) => substr((string)($m['record']['value']['blob'] ?? ''), -32),
                $msg['mutations'] ?? []
            );
            $version = isset($msg['version']['version']) ? (int)$msg['version']['version'] : 0;
            $patchMac = self::generatePatchMac((string)($msg['snapshotMac'] ?? ''), $mutationMacs, $version, $name, $mainKey['patchMacKey']);
            if (!hash_equals($patchMac, (string)($msg['patchMac'] ?? ''))) {
                throw new \RuntimeException('Invalid patch mac');
            }
        }
        return self::decodeSyncdMutations($msg['mutations'] ?? [], $initialState, $getAppStateSyncKey, $onMutation, $validateMacs);
    }

    /**
     * decodeSyncdSnapshot. Returns ['state'=>..., 'mutationMap'=>[indexString => mutation]].
     */
    public static function decodeSyncdSnapshot(string $name, array $snapshot, callable $getAppStateSyncKey, ?int $minimumVersionNumber, bool $validateMacs, ?Logger $logger = null): array
    {
        $newState = self::newLTHashState();
        $newState['version'] = isset($snapshot['version']['version']) ? (int)$snapshot['version']['version'] : 0;
        $mutationMap = [];
        $required = $minimumVersionNumber === null || $newState['version'] > $minimumVersionNumber;
        $newState = self::decodeSyncdMutations(
            $snapshot['records'] ?? [],
            $newState,
            $getAppStateSyncKey,
            function (array $mutation) use (&$mutationMap, $required) {
                if ($required) {
                    $mutationMap[(string)($mutation['syncAction']['index'] ?? '')] = $mutation;
                }
            },
            $validateMacs
        );
        $newState['version'] = isset($snapshot['version']['version']) ? (int)$snapshot['version']['version'] : (int)$newState['version'];

        if ($validateMacs) {
            $b64 = base64_encode((string)($snapshot['keyId']['id'] ?? ''));
            $keyEnc = $getAppStateSyncKey($b64);
            if (!$keyEnc || empty($keyEnc['keyData'])) {
                throw new MissingAppStateKeyException("failed to find key \"$b64\" to decode snapshot");
            }
            $keys = self::mutationKeys($keyEnc['keyData']);
            $computed = self::generateSnapshotMac($newState['hash'], (int)$newState['version'], $name, $keys['snapshotMacKey']);
            if (!hash_equals($computed, (string)($snapshot['mac'] ?? ''))) {
                $logger?->warn(['name' => $name, 'version' => $newState['version']], 'LTHash verification failed on snapshot, continuing with partial state');
            }
        }
        return ['state' => $newState, 'mutationMap' => $mutationMap];
    }

    /**
     * decodePatches. $downloadExternalMutations(array $blobRef): list-of-mutations (from SyncdMutations proto).
     * @return array{state:array,mutationMap:array}
     */
    public static function decodePatches(string $name, array $syncds, array $initial, callable $getAppStateSyncKey, callable $downloadExternalMutations, ?int $minimumVersionNumber, ?Logger $logger = null, bool $validateMacs = true): array
    {
        $newState = self::ensureVersion($initial);
        $mutationMap = [];
        foreach ($syncds as $syncd) {
            if (!empty($syncd['externalMutations'])) {
                $logger?->debug(['name' => $name], 'downloading external patch');
                $extra = $downloadExternalMutations($syncd['externalMutations']);
                $syncd['mutations'] = array_merge($syncd['mutations'] ?? [], $extra);
            }
            $patchVersion = isset($syncd['version']['version']) ? (int)$syncd['version']['version'] : 0;
            $newState['version'] = $patchVersion;
            $shouldMutate = $minimumVersionNumber === null || $patchVersion > $minimumVersionNumber;
            try {
                $newState = self::decodeSyncdPatch(
                    $syncd,
                    $name,
                    $newState,
                    $getAppStateSyncKey,
                    function (array $mutation) use (&$mutationMap, $shouldMutate) {
                        if ($shouldMutate) {
                            $mutationMap[(string)($mutation['syncAction']['index'] ?? '')] = $mutation;
                        }
                    },
                    $validateMacs
                );
                $newState['version'] = $patchVersion;
            } catch (MissingAppStateKeyException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $logger?->warn(['name' => $name, 'version' => $patchVersion, 'err' => $e->getMessage()], 'failed to decode patch, skipping');
                continue;
            }
            if ($validateMacs) {
                $b64 = base64_encode((string)($syncd['keyId']['id'] ?? ''));
                $keyEnc = $getAppStateSyncKey($b64);
                if (!$keyEnc || empty($keyEnc['keyData'])) {
                    throw new MissingAppStateKeyException("failed to find key \"$b64\" to decode mutation");
                }
                $keys = self::mutationKeys($keyEnc['keyData']);
                $computed = self::generateSnapshotMac($newState['hash'], (int)$newState['version'], $name, $keys['snapshotMacKey']);
                if (!hash_equals($computed, (string)($syncd['snapshotMac'] ?? ''))) {
                    $logger?->warn(['name' => $name, 'version' => $newState['version']], 'LTHash verification failed, skipping remaining patches');
                    break;
                }
            }
        }
        return ['state' => $newState, 'mutationMap' => $mutationMap];
    }

    /** chatModificationToAppPatch — ChatModification array → WAPatchCreate array. */
    public static function chatModificationToAppPatch(array $mod, string $jid): array
    {
        $set = WAProto::enumValue('SyncdMutation.SyncdOperation', 'SET');
        $remove = WAProto::enumValue('SyncdMutation.SyncdOperation', 'REMOVE');

        $messageRange = function ($lastMessages) {
            if (is_array($lastMessages) && isset($lastMessages[0])) {
                $messages = [];
                foreach ($lastMessages as $m) {
                    if (empty($m['key']['id']) || empty($m['key']['remoteJid'])) {
                        throw new \InvalidArgumentException('Incomplete key in lastMessages');
                    }
                    if (empty($m['messageTimestamp'])) {
                        throw new \InvalidArgumentException('Missing timestamp in last message list');
                    }
                    $messages[] = $m;
                }
                $last = end($messages);
                return [
                    'lastMessageTimestamp' => $last['messageTimestamp'] ?? null,
                    'messages' => $messages,
                ];
            }
            return $lastMessages; // already a range array
        };

        if (array_key_exists('mute', $mod)) {
            $patch = [
                'syncAction' => ['muteAction' => ['muted' => !!$mod['mute'], 'muteEndTimestamp' => $mod['mute'] ?: null]],
                'index' => ['mute', $jid], 'type' => 'regular_high', 'apiVersion' => 2, 'operation' => $set,
            ];
        } elseif (array_key_exists('archive', $mod)) {
            $patch = [
                'syncAction' => ['archiveChatAction' => ['archived' => !!$mod['archive'], 'messageRange' => $messageRange($mod['lastMessages'] ?? [])]],
                'index' => ['archive', $jid], 'type' => 'regular_low', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('markRead', $mod)) {
            $patch = [
                'syncAction' => ['markChatAsReadAction' => ['read' => !!$mod['markRead'], 'messageRange' => $messageRange($mod['lastMessages'] ?? [])]],
                'index' => ['markChatAsRead', $jid], 'type' => 'regular_low', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('deleteForMe', $mod)) {
            $d = $mod['deleteForMe'];
            $patch = [
                'syncAction' => ['deleteMessageForMeAction' => ['deleteMedia' => $d['deleteMedia'] ?? null, 'messageTimestamp' => $d['timestamp'] ?? null]],
                'index' => ['deleteMessageForMe', $jid, $d['key']['id'], !empty($d['key']['fromMe']) ? '1' : '0', '0'],
                'type' => 'regular_high', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('clear', $mod)) {
            $patch = [
                'syncAction' => ['clearChatAction' => ['messageRange' => $messageRange($mod['lastMessages'] ?? [])]],
                'index' => ['clearChat', $jid, '1', '0'], 'type' => 'regular_high', 'apiVersion' => 6, 'operation' => $set,
            ];
        } elseif (array_key_exists('pin', $mod)) {
            $patch = [
                'syncAction' => ['pinAction' => ['pinned' => !!$mod['pin']]],
                'index' => ['pin_v1', $jid], 'type' => 'regular_low', 'apiVersion' => 5, 'operation' => $set,
            ];
        } elseif (array_key_exists('contact', $mod)) {
            $patch = [
                'syncAction' => ['contactAction' => $mod['contact'] ?? []],
                'index' => ['contact', $jid], 'type' => 'critical_unblock_low', 'apiVersion' => 2,
                'operation' => $mod['contact'] ? $set : $remove,
            ];
        } elseif (array_key_exists('disableLinkPreviews', $mod)) {
            $patch = [
                'syncAction' => ['privacySettingDisableLinkPreviewsAction' => $mod['disableLinkPreviews'] ?? []],
                'index' => ['setting_disableLinkPreviews'], 'type' => 'regular', 'apiVersion' => 8, 'operation' => $set,
            ];
        } elseif (array_key_exists('star', $mod)) {
            $key = $mod['star']['messages'][0] ?? [];
            $patch = [
                'syncAction' => ['starAction' => ['starred' => !!$mod['star']['star']]],
                'index' => ['star', $jid, $key['id'], !empty($key['fromMe']) ? '1' : '0', '0'],
                'type' => 'regular_low', 'apiVersion' => 2, 'operation' => $set,
            ];
        } elseif (array_key_exists('delete', $mod)) {
            $patch = [
                'syncAction' => ['deleteChatAction' => ['messageRange' => $messageRange($mod['lastMessages'] ?? [])]],
                'index' => ['deleteChat', $jid, '1'], 'type' => 'regular_high', 'apiVersion' => 6, 'operation' => $set,
            ];
        } elseif (array_key_exists('pushNameSetting', $mod)) {
            $patch = [
                'syncAction' => ['pushNameSetting' => ['name' => $mod['pushNameSetting']]],
                'index' => ['setting_pushName'], 'type' => 'critical_block', 'apiVersion' => 1, 'operation' => $set,
            ];
        } elseif (array_key_exists('quickReply', $mod)) {
            $q = $mod['quickReply'];
            $patch = [
                'syncAction' => ['quickReplyAction' => [
                    'count' => 0,
                    'deleted' => $q['deleted'] ?? false,
                    'keywords' => [],
                    'message' => $q['message'] ?? '',
                    'shortcut' => $q['shortcut'] ?? '',
                ]],
                'index' => ['quick_reply', (string)($q['timestamp'] ?? time())],
                'type' => 'regular', 'apiVersion' => 2, 'operation' => $set,
            ];
        } elseif (array_key_exists('addLabel', $mod)) {
            $l = $mod['addLabel'];
            $patch = [
                'syncAction' => ['labelEditAction' => [
                    'name' => $l['name'] ?? null, 'color' => $l['color'] ?? null,
                    'predefinedId' => $l['predefinedId'] ?? null, 'deleted' => $l['deleted'] ?? null,
                ]],
                'index' => ['label_edit', $l['id']], 'type' => 'regular', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('addChatLabel', $mod)) {
            $patch = [
                'syncAction' => ['labelAssociationAction' => ['labeled' => true]],
                'index' => ['label_jid', $mod['addChatLabel']['labelId'], $jid],
                'type' => 'regular', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('removeChatLabel', $mod)) {
            $patch = [
                'syncAction' => ['labelAssociationAction' => ['labeled' => false]],
                'index' => ['label_jid', $mod['removeChatLabel']['labelId'], $jid],
                'type' => 'regular', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('addMessageLabel', $mod)) {
            $patch = [
                'syncAction' => ['labelAssociationAction' => ['labeled' => true]],
                'index' => ['label_message', $mod['addMessageLabel']['labelId'], $jid, $mod['addMessageLabel']['messageId'], '0', '0'],
                'type' => 'regular', 'apiVersion' => 3, 'operation' => $set,
            ];
        } elseif (array_key_exists('removeMessageLabel', $mod)) {
            $patch = [
                'syncAction' => ['labelAssociationAction' => ['labeled' => false]],
                'index' => ['label_message', $mod['removeMessageLabel']['labelId'], $jid, $mod['removeMessageLabel']['messageId'], '0', '0'],
                'type' => 'regular', 'apiVersion' => 3, 'operation' => $set,
            ];
        } else {
            throw new \InvalidArgumentException('chat modification not supported: ' . implode(',', array_keys($mod)));
        }
        $patch['syncAction']['timestamp'] = (int)(microtime(true) * 1000);
        return $patch;
    }
}
