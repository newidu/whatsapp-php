<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Proto\WAProto;
use BaileysPHP\Utils\AppState;
use BaileysPHP\Utils\MissingAppStateKeyException;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * App-state synchronisation orchestration — port of the app-state parts of
 * Baileys src/Socket/chats.ts (resyncAppState, appPatch, chatModify,
 * extractSyncdPatches, processSyncAction emissions).
 */
trait AppStateTrait
{
    /** @var array<string,true> collections parked on missing keys */
    private array $blockedAppStateCollections = [];

    private function macVerificationEnabled(string $kind): bool
    {
        $cfg = $this->config['appStateMacVerification'] ?? null;
        if (is_array($cfg)) {
            return (bool)($cfg[$kind] ?? true);
        }
        return true;
    }

    private function getAppStateSyncKey(string $keyId): ?array
    {
        $res = $this->authState->keys->get('app-state-sync-key', [$keyId]);
        $key = $res[$keyId] ?? null;
        return is_array($key) ? $key : null;
    }

    /** Store an app-state sync key shared by the phone (APP_STATE_SYNC_KEY_SHARE). */
    public function storeAppStateSyncKey(array $keyData): void
    {
        $idRaw = $keyData['keyId']['id'] ?? null;
        if (!is_string($idRaw) || $idRaw === '') {
            return;
        }
        $id = base64_encode($idRaw);
        $this->authState->keys->set(['app-state-sync-key' => [$id => $keyData]]);
        if ($this->blockedAppStateCollections !== []) {
            $cols = array_keys($this->blockedAppStateCollections);
            $this->blockedAppStateCollections = [];
            $this->logger->info(['collections' => $cols], 'app state key arrived, resyncing blocked collections');
            $this->resyncAppState($cols, false);
        }
    }

    private function getAppStateVersion(string $name): array
    {
        $res = $this->authState->keys->get('app-state-sync-version', [$name]);
        $state = $res[$name] ?? null;
        return is_array($state) ? AppState::ensureVersion($state) : AppState::newLTHashState();
    }

    private function setAppStateVersion(string $name, array $state): void
    {
        $this->authState->keys->set(['app-state-sync-version' => [$name => $state]]);
    }

    /** downloadExternalBlob: fetch + decrypt an ExternalBlobReference ('md-app-state'). */
    public function downloadExternalBlob(array $blob): string
    {
        return $this->downloadContentFromMessage($blob, 'md-app-state');
    }

    /** extractSyncdPatches(resultNode) → [name => ['patches'=>[],'hasMorePatches'=>bool,'snapshot'=>?array]] */
    public function extractSyncdPatches(BinaryNode $result): array
    {
        $syncNode = GenericUtils::getBinaryNodeChild($result, 'sync');
        $final = [];
        foreach (GenericUtils::getBinaryNodeChildren($syncNode, 'collection') as $collectionNode) {
            $name = $collectionNode->attrs['name'] ?? '';
            $patchesNode = GenericUtils::getBinaryNodeChild($collectionNode, 'patches');
            $patchNodes = GenericUtils::getBinaryNodeChildren($patchesNode ?? $collectionNode, 'patch');
            $snapshotNode = GenericUtils::getBinaryNodeChild($collectionNode, 'snapshot');
            $hasMore = ($collectionNode->attrs['has_more_patches'] ?? '') === 'true';

            $snapshot = null;
            if ($snapshotNode !== null && $snapshotNode->contentString() !== null && $snapshotNode->contentString() !== '') {
                $blobRef = WAProto::decodeProto('ExternalBlobReference', $snapshotNode->contentString());
                $data = $this->downloadExternalBlob($blobRef);
                $snapshot = WAProto::decodeProto('SyncdSnapshot', $data);
            }
            $syncds = [];
            foreach ($patchNodes as $patchNode) {
                $content = $patchNode->contentString();
                if ($content === null || $content === '') {
                    continue;
                }
                $syncd = WAProto::decodeProto('SyncdPatch', $content);
                if (!isset($syncd['version'])) {
                    $syncd['version'] = ['version' => ((int)($collectionNode->attrs['version'] ?? 0)) + 1];
                }
                $syncds[] = $syncd;
            }
            $final[$name] = ['patches' => $syncds, 'hasMorePatches' => $hasMore, 'snapshot' => $snapshot];
        }
        return $final;
    }

    /**
     * resyncAppState(collections, isInitialSync)
     * @param list<string> $collections
     */
    public function resyncAppState(array $collections, bool $isInitialSync): void
    {
        $keyCache = [];
        $getKey = function (string $keyId) use (&$keyCache): ?array {
            if (!array_key_exists($keyId, $keyCache)) {
                $keyCache[$keyId] = $this->getAppStateSyncKey($keyId);
            }
            return $keyCache[$keyId];
        };
        $downloadExternal = function (array $blob): array {
            $data = $this->downloadExternalBlob($blob);
            $decoded = WAProto::decodeProto('SyncdMutations', $data);
            return $decoded['mutations'] ?? [];
        };

        $initialVersionMap = [];
        $globalMutationMap = [];
        $toHandle = array_values(array_unique($collections));
        $attempts = [];
        $forceSnapshot = [];

        $this->authState->keys->transaction(function () use (
            &$toHandle, &$attempts, &$forceSnapshot, &$initialVersionMap, &$globalMutationMap, $getKey, $downloadExternal
        ) {
            $guard = 0;
            while ($toHandle !== [] && $guard++ < 50) {
                $states = [];
                $nodes = [];
                foreach ($toHandle as $name) {
                    $state = $this->getAppStateVersion($name);
                    if (!isset($initialVersionMap[$name])) {
                        $initialVersionMap[$name] = (int)$state['version'];
                    }
                    $states[$name] = $state;
                    $force = in_array($name, $forceSnapshot, true);
                    $this->logger->info('resyncing ' . $name . ' from v' . $state['version'] . ($force ? ' (forcing snapshot)' : ''));
                    $nodes[] = new BinaryNode('collection', [
                        'name' => $name,
                        'version' => (string)$state['version'],
                        'return_snapshot' => ($force || !$state['version']) ? 'true' : 'false',
                    ]);
                }
                $forceSnapshot = [];

                $result = $this->query(new BinaryNode('iq', [
                    'to' => 's.whatsapp.net',
                    'xmlns' => 'w:sync:app:state',
                    'type' => 'set',
                ], [new BinaryNode('sync', [], $nodes)]));

                $decoded = $this->extractSyncdPatches($result);
                foreach ($decoded as $name => $info) {
                    try {
                        if ($info['snapshot'] !== null) {
                            $r = AppState::decodeSyncdSnapshot(
                                $name,
                                $info['snapshot'],
                                $getKey,
                                $initialVersionMap[$name] ?? null,
                                $this->macVerificationEnabled('snapshot'),
                                $this->logger
                            );
                            $states[$name] = $r['state'];
                            $globalMutationMap = array_merge($globalMutationMap, $r['mutationMap']);
                            $this->setAppStateVersion($name, $r['state']);
                            $this->logger->info('restored state of ' . $name . ' from snapshot to v' . $r['state']['version']);
                        }
                        if ($info['patches'] !== []) {
                            $r = AppState::decodePatches(
                                $name,
                                $info['patches'],
                                $states[$name],
                                $getKey,
                                $downloadExternal,
                                $initialVersionMap[$name] ?? null,
                                $this->logger,
                                $this->macVerificationEnabled('patch')
                            );
                            $this->setAppStateVersion($name, $r['state']);
                            $this->logger->info('synced ' . $name . ' to v' . $r['state']['version']);
                            $initialVersionMap[$name] = (int)$r['state']['version'];
                            $globalMutationMap = array_merge($globalMutationMap, $r['mutationMap']);
                        }
                        if (!$info['hasMorePatches']) {
                            $toHandle = array_values(array_diff($toHandle, [$name]));
                        }
                    } catch (MissingAppStateKeyException $e) {
                        $attempts[$name] = ($attempts[$name] ?? 0) + 1;
                        if ($attempts[$name] >= 2) {
                            $this->logger->warn(['name' => $name], $name . ' blocked on missing key, parking');
                            $this->blockedAppStateCollections[$name] = true;
                            $toHandle = array_values(array_diff($toHandle, [$name]));
                        } else {
                            $forceSnapshot[] = $name;
                        }
                    } catch (\Throwable $e) {
                        $attempts[$name] = ($attempts[$name] ?? 0) + 1;
                        if ($attempts[$name] >= 2) {
                            $this->logger->warn(['name' => $name, 'err' => $e->getMessage()], 'failed to sync ' . $name . ', giving up');
                            $toHandle = array_values(array_diff($toHandle, [$name]));
                        } else {
                            $forceSnapshot[] = $name;
                        }
                    }
                }
                // collections not present in response are done
                foreach ($toHandle as $name) {
                    if (!isset($decoded[$name]) && !in_array($name, $forceSnapshot, true)) {
                        $toHandle = array_values(array_diff($toHandle, [$name]));
                    }
                }
            }
        }, $this->creds()->me['id'] ?? 'resync-app-state');

        foreach ($globalMutationMap as $mutation) {
            $this->processSyncAction($mutation, $isInitialSync);
        }
    }

    /** appPatch(patchCreate) — apply one app-state patch. */
    public function appPatch(array $patchCreate): void
    {
        $name = $patchCreate['type'];
        $myKeyId = $this->creds()->myAppStateKeyId ?? null;
        if (!$myKeyId) {
            throw new \RuntimeException('App state key not present!');
        }
        $initial = null;
        $encodeResult = null;
        $this->authState->keys->transaction(function () use ($patchCreate, $name, $myKeyId, &$initial, &$encodeResult) {
            $this->resyncAppState([$name], false);
            $initial = $this->getAppStateVersion($name);
            $encodeResult = AppState::encodeSyncdPatch($patchCreate, $myKeyId, $initial, fn($id) => $this->getAppStateSyncKey($id));
            $state = $encodeResult['state'];
            $patchBytes = WAProto::encodeProto('SyncdPatch', $encodeResult['patch']);
            $this->query(new BinaryNode('iq', [
                'to' => 's.whatsapp.net',
                'type' => 'set',
                'xmlns' => 'w:sync:app:state',
            ], [
                new BinaryNode('sync', [], [
                    new BinaryNode('collection', [
                        'name' => $name,
                        'version' => (string)($state['version'] - 1),
                        'return_snapshot' => 'false',
                    ], [new BinaryNode('patch', [], new Bytes($patchBytes))]),
                ]),
            ]));
            $this->setAppStateVersion($name, $state);
        }, $this->creds()->me['id'] ?? 'app-patch');

        if (!empty($this->config['emitOwnEvents']) && $encodeResult !== null && $initial !== null) {
            $patchWithVersion = $encodeResult['patch'];
            $patchWithVersion['version'] = ['version' => $encodeResult['state']['version']];
            $r = AppState::decodePatches(
                $name,
                [$patchWithVersion],
                $initial,
                fn($id) => $this->getAppStateSyncKey($id),
                fn(array $b) => [],
                null,
                $this->logger,
                $this->macVerificationEnabled('patch')
            );
            foreach ($r['mutationMap'] as $mutation) {
                $this->processSyncAction($mutation, false);
            }
        }
    }

    /** chatModify(modification, jid) */
    public function chatModify(array $mod, string $jid): void
    {
        $patch = AppState::chatModificationToAppPatch($mod, $jid);
        $this->appPatch($patch);
    }

    /** processSyncAction — turn one decoded mutation into ev emissions. */
    public function processSyncAction(array $mutation, bool $isInitialSync): void
    {
        $action = $mutation['syncAction']['value'] ?? [];
        $index = $mutation['index'] ?? [];
        $type = $index[0] ?? null;
        $id = $index[1] ?? null;
        $msgId = $index[2] ?? null;
        $fromMe = ($index[3] ?? null) === '1';
        $conditional = null; // event-buffer conditional checks are no-ops in the PHP port
        $me = $this->creds()->me ?? [];

        if (isset($action['muteAction'])) {
            $this->ev->emit('chats.update', [[
                'id' => $id,
                'muteEndTime' => !empty($action['muteAction']['muted']) ? (int)($action['muteAction']['muteEndTimestamp'] ?? 0) : null,
                'conditional' => $conditional,
            ]]);
        } elseif (isset($action['archiveChatAction']) || $type === 'archive' || $type === 'unarchive') {
            $isArchived = isset($action['archiveChatAction']) ? !empty($action['archiveChatAction']['archived']) : $type === 'archive';
            $this->ev->emit('chats.update', [[
                'id' => $id, 'archived' => $isArchived, 'conditional' => $conditional,
            ]]);
        } elseif (isset($action['markChatAsReadAction'])) {
            $read = !empty($action['markChatAsReadAction']['read']);
            $this->ev->emit('chats.update', [[
                'id' => $id,
                'unreadCount' => ($isInitialSync && $read) ? null : ($read ? 0 : -1),
                'conditional' => $conditional,
            ]]);
        } elseif (isset($action['deleteMessageForMeAction']) || $type === 'deleteMessageForMe') {
            $this->ev->emit('messages.delete', ['keys' => [[
                'remoteJid' => $id, 'id' => $msgId, 'fromMe' => $fromMe,
            ]]]);
        } elseif (isset($action['contactAction'])) {
            $this->emitContactAction($action['contactAction'], $id);
        } elseif (isset($action['pushNameSetting'])) {
            $name = $action['pushNameSetting']['name'] ?? null;
            if ($name && ($me['name'] ?? null) !== $name) {
                $this->creds()->me = array_merge($this->creds()->me ?? [], ['name' => $name]);
                $this->ev->emit('creds.update', ['me' => $this->creds()->me]);
                $this->authState->saveCreds();
                if (isset($this->user)) {
                    $this->user = array_merge($this->user ?? [], ['name' => $name]);
                }
            }
        } elseif (isset($action['pinAction'])) {
            $this->ev->emit('chats.update', [[
                'id' => $id,
                'pinned' => !empty($action['pinAction']['pinned']) ? (int)($action['timestamp'] ?? 0) : null,
                'conditional' => $conditional,
            ]]);
        } elseif (isset($action['unarchiveChatsSetting'])) {
            $unarchive = !empty($action['unarchiveChatsSetting']['unarchiveChats']);
            $this->creds()->accountSettings = array_merge(
                is_array($this->creds()->accountSettings ?? null) ? $this->creds()->accountSettings : [],
                ['unarchiveChats' => $unarchive]
            );
            $this->ev->emit('creds.update', ['accountSettings' => $this->creds()->accountSettings]);
            $this->authState->saveCreds();
        } elseif (isset($action['starAction']) || $type === 'star') {
            $starred = $action['starAction']['starred'] ?? ($index[count($index) - 1] === '1');
            $this->ev->emit('messages.update', [[
                'key' => ['remoteJid' => $id, 'id' => $msgId, 'fromMe' => $fromMe],
                'update' => ['starred' => !!$starred],
            ]]);
        } elseif (isset($action['deleteChatAction']) || $type === 'deleteChat') {
            if (!$isInitialSync && $id !== null) {
                $this->ev->emit('chats.delete', [$id]);
            }
        } elseif (isset($action['labelEditAction'])) {
            $l = $action['labelEditAction'];
            $this->ev->emit('labels.edit', [
                'id' => $id,
                'name' => $l['name'] ?? null,
                'color' => $l['color'] ?? null,
                'deleted' => $l['deleted'] ?? null,
                'predefinedId' => isset($l['predefinedId']) ? (string)$l['predefinedId'] : null,
            ]);
        } elseif (isset($action['labelAssociationAction'])) {
            $isChat = $type === 'label_jid';
            $this->ev->emit('labels.association', [
                'type' => !empty($action['labelAssociationAction']['labeled']) ? 'add' : 'remove',
                'association' => $isChat
                    ? ['type' => 'label_jid', 'chatId' => $index[2] ?? null, 'labelId' => $index[1] ?? null]
                    : ['type' => 'label_message', 'chatId' => $index[2] ?? null, 'messageId' => $index[3] ?? null, 'labelId' => $index[1] ?? null],
            ]);
        } elseif (!empty($action['localeSetting']['locale'])) {
            $this->ev->emit('settings.update', ['setting' => 'locale', 'value' => $action['localeSetting']['locale']]);
        } elseif (isset($action['timeFormatAction'])) {
            $this->ev->emit('settings.update', ['setting' => 'timeFormat', 'value' => $action['timeFormatAction']]);
        } elseif (isset($action['pnForLidChatAction'])) {
            if (!empty($action['pnForLidChatAction']['pnJid']) && $id !== null) {
                $this->signalRepository->lidMapping->storeLIDPNMapping($id, $action['pnForLidChatAction']['pnJid']);
                $this->ev->emit('lid-mapping.update', ['lid' => $id, 'pn' => $action['pnForLidChatAction']['pnJid']]);
            }
        } elseif (isset($action['privacySettingRelayAllCalls'])) {
            $this->ev->emit('settings.update', ['setting' => 'privacySettingRelayAllCalls', 'value' => $action['privacySettingRelayAllCalls']]);
        } elseif (isset($action['statusPrivacy'])) {
            $this->ev->emit('settings.update', ['setting' => 'statusPrivacy', 'value' => $action['statusPrivacy']]);
        } elseif (isset($action['lockChatAction'])) {
            $this->ev->emit('chats.lock', ['id' => $id, 'locked' => !empty($action['lockChatAction']['locked'])]);
        } elseif (isset($action['privacySettingDisableLinkPreviewsAction'])) {
            $this->ev->emit('settings.update', ['setting' => 'disableLinkPreviews', 'value' => $action['privacySettingDisableLinkPreviewsAction']]);
        } elseif (isset($action['notificationActivitySettingAction']['notificationActivitySetting'])) {
            $this->ev->emit('settings.update', ['setting' => 'notificationActivitySetting', 'value' => $action['notificationActivitySettingAction']['notificationActivitySetting']]);
        } elseif (isset($action['lidContactAction'])) {
            $c = $action['lidContactAction'];
            $this->ev->emit('contacts.upsert', [[
                'id' => $id,
                'name' => $c['fullName'] ?? $c['firstName'] ?? $c['username'] ?? null,
                'username' => $c['username'] ?? null,
                'lid' => $id,
                'phoneNumber' => null,
            ]]);
        } elseif (isset($action['privacySettingChannelsPersonalisedRecommendationAction'])) {
            $this->ev->emit('settings.update', ['setting' => 'channelsPersonalisedRecommendation', 'value' => $action['privacySettingChannelsPersonalisedRecommendationAction']]);
        } else {
            $this->logger->debug(['index' => $index], 'unprocessable app state update');
        }
    }

    private function emitContactAction(array $contactAction, ?string $id): void
    {
        if (!$id) {
            return;
        }
        $lidJid = $contactAction['lidJid'] ?? null;
        $idIsPn = Jid::isPnUser($id);
        $this->ev->emit('contacts.upsert', [[
            'id' => $id,
            'name' => $contactAction['fullName'] ?? $contactAction['firstName'] ?? $contactAction['username'] ?? null,
            'username' => $contactAction['username'] ?? null,
            'lid' => $lidJid,
            'phoneNumber' => $idIsPn ? $id : ($contactAction['pnJid'] ?? null),
        ]]);
        if ($lidJid && Jid::isLidUser($lidJid) && $idIsPn) {
            $this->signalRepository->lidMapping->storeLIDPNMapping($lidJid, $id);
            $this->ev->emit('lid-mapping.update', ['lid' => $lidJid, 'pn' => $id]);
        }
    }
}
