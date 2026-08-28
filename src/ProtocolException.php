<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer;

final class ProtocolException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $closeCode = 1002)
    {
        parent::__construct($message);
    }
}
