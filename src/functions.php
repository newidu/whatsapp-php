<?php
declare(strict_types=1);

namespace BaileysPHP;

use BaileysPHP\Auth\MultiFileAuthState;
use BaileysPHP\Socket\WASocket;

// Namespaced helper functions ported from Baileys' function-style API.

/** useMultiFileAuthState(folder) */
function useMultiFileAuthState(string $folder): MultiFileAuthState
{
    return MultiFileAuthState::use($folder);
}

/** makeWASocket(config) */
function makeWASocket(array $config = []): WASocket
{
    return WASocket::makeWASocket($config);
}

/** makeCacheableSignalKeyStore(store, logger?) — speeds up the file key store */
function makeCacheableSignalKeyStore(\BaileysPHP\Auth\SignalKeyStore $store, ?\BaileysPHP\Utils\Logger $logger = null): \BaileysPHP\Auth\CacheableSignalKeyStore
{
    return new \BaileysPHP\Auth\CacheableSignalKeyStore($store, $logger);
}

/** makeInMemoryStore(options) — chats/contacts/messages store (bind() it to $sock->ev) */
function makeInMemoryStore(array $options = []): \BaileysPHP\Store\InMemoryStore
{
    return new \BaileysPHP\Store\InMemoryStore($options);
}
