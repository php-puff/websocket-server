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
use Puff\WebsocketServer\Frame;

final class FrameTest extends TestCase
{
    public function testEncodesServerTextFrame(): void
    {
        self::assertSame("\x81\x05hello", Frame::encode('hello'));
    }

    public function testDecodesMaskedClientFrame(): void
    {
        $mask = "\x01\x02\x03\x04";
        $payload = 'hello';
        $masked = '';
        for ($index = 0; $index < \strlen($payload); ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }
        self::assertSame([
            'opcode' => Frame::TEXT,
            'payload' => 'hello',
            'consumed' => 11,
            'final' => true,
        ], Frame::decode("\x81\x85" . $mask . $masked));
    }

    public function testRejectsUnmaskedClientFrame(): void
    {
        $this->expectException(\Puff\WebsocketServer\ProtocolException::class);
        Frame::decode("\x81\x05hello");
    }

    public function testRejectsPayloadFromHeaderBeforeItIsBuffered(): void
    {
        $this->expectException(\Puff\WebsocketServer\ProtocolException::class);
        $this->expectExceptionCode(0);
        Frame::decode("\x81\xfe\x10\x00", 1024);
    }

    public function testRejectsFragmentedControlFrame(): void
    {
        $this->expectException(\Puff\WebsocketServer\ProtocolException::class);
        Frame::decode("\x09\x80\x00\x00\x00\x00");
    }

    public function testBuildsCloseFrame(): void
    {
        self::assertSame("\x88\x02\x03\xe8", Frame::close());
    }
}
