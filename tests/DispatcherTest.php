<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Server\Connection;
use Puff\WebsocketServer\Dispatcher;
use Puff\WebsocketServer\Frame;
use Puff\WebsocketServer\Handshake;
use Puff\WebsocketServer\Server;

final class DispatcherTest extends TestCase
{
    public function testServerInfoUsesTheSharedShape(): void
    {
        $info = (new Server())->info();

        self::assertSame(['addr', 'url', 'connections'], \array_keys($info));
        self::assertSame('ws://127.0.0.1:8791', $info['url']);
    }

    public function testDispatchesByPathAndEvent(): void
    {
        $dispatcher = new Dispatcher([
            '/chat' => ['events' => [
                'message.echo' => static fn (array $data): array => ['message' => $data['message']],
            ]],
        ], static function (mixed $handler, array $parameters): mixed {
            if (!\is_callable($handler)) {
                throw new \LogicException('Expected a callable WebSocket handler.');
            }
            return $handler($parameters['data']);
        });

        $response = $dispatcher->message(
            $this->handshake('/chat'),
            '{"event":"message.echo","data":{"message":"Hello"}}',
            Frame::TEXT,
            $this->connection(),
            new Server(),
        );

        self::assertSame('{"message":"Hello"}', $response);
    }

    public function testReturnsStructuredErrorForUnknownRoute(): void
    {
        $dispatcher = new Dispatcher([], static fn (): null => null);
        $response = $dispatcher->message(
            $this->handshake('/missing'),
            '{"event":"unknown"}',
            Frame::TEXT,
            $this->connection(),
            new Server(),
        );

        self::assertSame('route_not_found', \json_decode((string) $response, true)['error']['code']);
    }

    private function connection(): Connection
    {
        $stream = \fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create a temporary stream.');
        }
        return new Connection(1, $stream);
    }

    private function handshake(string $path): Handshake
    {
        return Handshake::parse(
            "GET {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: keep-alive, Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Sec-WebSocket-Key: ' . \base64_encode(\str_repeat('a', 16)),
            $this->connection(),
        );
    }
}
