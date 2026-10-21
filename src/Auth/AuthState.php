<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/** Baileys AuthenticationState: { creds, keys } */
final class AuthState
{
    /**
     * Optional persistence callback, registered by useMultiFileAuthState()
     * so the socket can save creds after internal updates (pre-key counters,
     * app-state key id, props hash...). Custom auth states may leave this
     * null and persist on the 'creds.update' event instead.
     * @var null|callable():void
     */
    public $saveCredsCallback = null;

    public function __construct(
        public Creds $creds,
        public SignalKeyStore $keys,
    ) {}

    /** Persist creds via the registered callback (no-op when none is set). */
    public function saveCreds(): void
    {
        if ($this->saveCredsCallback !== null) {
            ($this->saveCredsCallback)();
        }
    }
}
