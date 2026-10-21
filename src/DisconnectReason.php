<?php
declare(strict_types=1);

namespace BaileysPHP;

/** Subset of Baileys src/Types (DisconnectReason enum values). */
final class DisconnectReason
{
    public const connectionClosed = 428;
    public const connectionLost = 408;
    public const connectionReplaced = 440;
    public const timedOut = 408;
    public const loggedOut = 401;
    public const badSession = 500;
    public const restartRequired = 515;
    public const multideviceMismatch = 411;
    public const forbidden = 403;
    public const unavailableService = 503;
}
