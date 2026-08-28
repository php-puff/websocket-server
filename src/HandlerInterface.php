<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer;

use Puff\Server\Connection;

interface HandlerInterface
{
    public function open(Handshake $handshake, Connection $connection, Server $server): mixed;

    public function message(
        Handshake $handshake,
        string $payload,
        int $opcode,
        Connection $connection,
        Server $server,
    ): mixed;

    public function close(Handshake $handshake, Connection $connection, Server $server): void;
}
