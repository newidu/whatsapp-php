<?php
declare(strict_types=1);

namespace BaileysPHP;

/** Thrown for connection-level failures; carries a DisconnectReason-style status code (like @hapi/boom in Node). */
final class ConnectionException extends \RuntimeException
{
    public function __construct(string $message, public int $statusCode = 500, public mixed $data = null)
    {
        parent::__construct($message, $statusCode);
    }
}
