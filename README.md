# Puff WebSocket Server

Fiber-based RFC 6455 WebSocket server for Puff. It reuses `puff/server` for non-blocking TCP I/O and provides strict handshakes, masked frame parsing, fragmentation, Ping/Pong, graceful close frames and ordered per-connection message handling. Composer discovery mounts `Puff\WebsocketServer\WebSocketApplication` automatically.

```php
$app = new Puff\Application\Application($config);
$app->run(); // Starts every installed application on one shared event loop.
```

WebSocket instances are read from the shared `config/server.php` list. Each item with `type: 'websocket'` starts a listener.

```php
return [
    'type' => 'websocket',
    'addr' => '127.0.0.1:8791',
    'routes' => [dirname(__DIR__) . '/app/websocket.php'],
    'max_message_size' => 2 * 1024 * 1024,
    'max_handshake_size' => 16 * 1024,
    'max_headers' => 100,
    'max_pending_messages' => 128,
    'allowed_origins' => ['https://example.com'],
    'protocols' => ['json'],
];
```

Multiple WebSocket listeners are supported. Their shared Worker count is configured by `workers` in `config/config.php`.

An empty `allowed_origins` list accepts any syntactically valid Origin. Configure an explicit list for browser-facing production services. Messages from one connection run sequentially; separate connections can run concurrently.

Routes are grouped by handshake path and then by message event:

```php
return [
    '/chat' => [
        'events' => [
            'message.send' => [ChatController::class, 'send'],
        ],
        'lifecycle' => [
            'open' => [ChatController::class, 'open'],
            'close' => [ChatController::class, 'close'],
        ],
    ],
    '*' => [
        'events' => [
            'ping' => [SystemController::class, 'ping'],
        ],
    ],
];
```

Clients send JSON messages such as `{"event":"message.send","data":{"message":"Hello"}}`. Controller arguments are resolved by the application container. The following values are injectable by name or type:

- `data`, `event`, and `path`
- `Handshake $handshake`
- `headers`, `query`, and `cookies`
- `Connection $connection`
- `Server $server`

Arrays and objects returned by controllers are encoded as JSON. Event routing accepts text JSON frames; use a custom `HandlerInterface` for binary protocols.

## Custom handler

Implement `HandlerInterface` when the connection lifecycle or binary frames require direct control:

```php
use Puff\Server\Connection;
use Puff\WebsocketServer\Frame;
use Puff\WebsocketServer\Handshake;
use Puff\WebsocketServer\HandlerInterface;
use Puff\WebsocketServer\Server;

final class SocketHandler implements HandlerInterface
{
    public function open(Handshake $handshake, Connection $connection, Server $server): mixed
    {
        return null;
    }

    public function message(
        Handshake $handshake,
        string $payload,
        int $opcode,
        Connection $connection,
        Server $server,
    ): mixed {
        return $opcode === Frame::BINARY ? $payload : strtoupper($payload);
    }

    public function close(Handshake $handshake, Connection $connection, Server $server): void
    {
    }
}
```

Throw `HandshakeException` from `open()` to reject a connection before the `101 Switching Protocols` response.
