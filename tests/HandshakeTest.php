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
use Puff\WebsocketServer\Handshake;
use Puff\WebsocketServer\HandshakeException;

final class HandshakeTest extends TestCase
{
    public function testParsesAuthenticationMetadataAndProtocol(): void
    {
        $handshake = Handshake::parse(
            $this->request('/chat?token=abc', "Origin: https://example.com\r\nCookie: PFID=session\r\nSec-WebSocket-Protocol: chat, json"),
            $this->connection(),
            ['https://example.com'],
            ['json'],
        );

        self::assertSame('/chat', $handshake->path);
        self::assertSame('abc', $handshake->query['token']);
        self::assertSame('session', $handshake->cookies['PFID']);
        self::assertSame('https://example.com', $handshake->origin());
        self::assertSame('json', $handshake->protocol);
        self::assertSame('127.0.0.1', $handshake->remoteAddress);
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $this->expectException(HandshakeException::class);
        Handshake::parse(\str_replace('Version: 13', 'Version: 12', $this->request('/')), $this->connection());
    }

    public function testRejectsUntrustedOrigin(): void
    {
        $this->expectException(HandshakeException::class);
        Handshake::parse(
            $this->request('/', 'Origin: https://evil.example'),
            $this->connection(),
            ['https://example.com'],
        );
    }

    private function request(string $target, string $headers = ''): string
    {
        return "GET {$target} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Sec-WebSocket-Key: ' . \base64_encode(\str_repeat('k', 16)) . "\r\n"
            . $headers;
    }

    private function connection(): Connection
    {
        $stream = \fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create a temporary stream.');
        }
        return new Connection(1, $stream, '127.0.0.1', 54321);
    }
}
