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
use Puff\Async\EventLoop;
use Puff\Async\Runtime;
use Puff\Server\Connection;
use Puff\WebsocketServer\Frame;
use Puff\WebsocketServer\HandlerInterface;
use Puff\WebsocketServer\Handshake;
use Puff\WebsocketServer\Server;

final class ServerTest extends TestCase
{
    private ?Server $server = null;

    protected function setUp(): void
    {
        EventLoop::reset();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        EventLoop::reset();
    }

    public function testHandshakeAndMessagesAreProcessedInConnectionOrder(): void
    {
        $events = [];
        $handler = new class (static function (string $payload) use (&$events): void {
            $events[] = "start:{$payload}";
            if ($payload === 'first') {
                Runtime::delay(0.001);
            }
            $events[] = "end:{$payload}";
        }) implements HandlerInterface {
            public function __construct(private readonly \Closure $messageHandler)
            {
            }

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
                ($this->messageHandler)($payload);
                return null;
            }

            public function close(Handshake $handshake, Connection $connection, Server $server): void
            {
            }
        };

        $loop = EventLoop::get();
        $this->server = new Server($handler, ['addr' => '127.0.0.1:0'], $loop);
        $this->server->start();
        $client = \stream_socket_client('tcp://' . $this->server->address(), $errorCode, $error, 1);
        self::assertIsResource($client, $error ?? 'Unable to connect to the WebSocket test server.');
        \stream_set_blocking($client, false);

        \fwrite($client, $this->request());
        $response = '';
        $timedOut = false;
        $loop->delay(0.2, static function () use (&$timedOut): void {
            $timedOut = true;
        });
        $loop->runUntil(static function () use ($client, &$response, &$timedOut): bool {
            $response .= (string) \fread($client, 8192);
            return $timedOut || \str_contains($response, "\r\n\r\n");
        });
        self::assertStringStartsWith('HTTP/1.1 101 Switching Protocols', $response);

        \fwrite($client, $this->clientFrame('first') . $this->clientFrame('second'));
        $timedOut = false;
        $loop->delay(0.2, static function () use (&$timedOut): void {
            $timedOut = true;
        });
        $loop->runUntil(static function () use (&$events, &$timedOut): bool {
            return \count($events) === 4 || $timedOut;
        });

        self::assertFalse($timedOut);
        self::assertSame(['start:first', 'end:first', 'start:second', 'end:second'], $events);
        \fclose($client);
    }

    private function request(): string
    {
        return "GET /chat?token=test HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: keep-alive, Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Sec-WebSocket-Key: ' . \base64_encode(\str_repeat('s', 16)) . "\r\n\r\n";
    }

    private function clientFrame(string $payload): string
    {
        $mask = "\x01\x02\x03\x04";
        $masked = '';
        for ($index = 0, $length = \strlen($payload); $index < $length; ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }
        return \chr(0x80 | Frame::TEXT) . \chr(0x80 | \strlen($payload)) . $mask . $masked;
    }
}
