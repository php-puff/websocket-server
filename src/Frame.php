<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer;

final class Frame
{
    public const CONTINUATION = 0x0;
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    public static function encode(string $payload, int $opcode = self::TEXT): string
    {
        self::validateOpcode($opcode);
        $length = \strlen($payload);
        if ($opcode >= self::CLOSE && $length > 125) {
            throw new \InvalidArgumentException('A WebSocket control frame cannot exceed 125 bytes.');
        }
        $head = \chr(0x80 | ($opcode & 0x0f));
        if ($length < 126) {
            return $head . \chr($length) . $payload;
        }
        if ($length <= 0xffff) {
            return $head . \chr(126) . \pack('n', $length) . $payload;
        }
        return $head . \chr(127) . \pack('NN', $length >> 32, $length & 0xffffffff) . $payload;
    }

    /** @return array{opcode: int, payload: string, consumed: int, final: bool}|null */
    public static function decode(string $buffer, int $maxPayload = PHP_INT_MAX): ?array
    {
        if ($maxPayload < 0) {
            throw new \InvalidArgumentException('Maximum payload size must not be negative.');
        }
        $size = \strlen($buffer);
        if ($size < 2) {
            return null;
        }
        $first = \ord($buffer[0]);
        if (($first & 0x70) !== 0) {
            throw new ProtocolException('WebSocket extension bits are not supported.');
        }
        $final = ($first & 0x80) !== 0;
        $opcode = $first & 0x0f;
        self::validateOpcode($opcode, true);
        $second = \ord($buffer[1]);
        if (($second & 0x80) === 0) {
            throw new ProtocolException('Client WebSocket frames must be masked.');
        }
        $length = $second & 0x7f;
        $offset = 2;
        if ($length === 126) {
            if ($size < 4) {
                return null;
            }
            $unpacked = \unpack('nlength', \substr($buffer, 2, 2));
            if ($unpacked === false) {
                throw new \RuntimeException('Invalid WebSocket frame length.');
            }
            $length = $unpacked['length'];
            if ($length < 126) {
                throw new ProtocolException('WebSocket frame uses a non-minimal length encoding.');
            }
            $offset = 4;
        } elseif ($length === 127) {
            if ($size < 10) {
                return null;
            }
            $parts = \unpack('Nhigh/Nlow', \substr($buffer, 2, 8));
            if ($parts === false) {
                throw new \RuntimeException('Invalid WebSocket frame length.');
            }
            if (($parts['high'] & 0x80000000) !== 0) {
                throw new ProtocolException('WebSocket frame length is invalid.');
            }
            if ($parts['high'] === 0 && $parts['low'] < 0x10000) {
                throw new ProtocolException('WebSocket frame uses a non-minimal length encoding.');
            }
            if ($parts['high'] !== 0) {
                throw new ProtocolException('WebSocket frame is too large.', 1009);
            }
            $length = $parts['low'];
            $offset = 10;
        }
        if ($opcode >= self::CLOSE && (!$final || $length > 125)) {
            throw new ProtocolException('Invalid WebSocket control frame.');
        }
        if ($length > $maxPayload) {
            throw new ProtocolException('WebSocket frame is too large.', 1009);
        }
        if ($size < $offset + 4 + $length) {
            return null;
        }
        $mask = \substr($buffer, $offset, 4);
        $offset += 4;
        $payload = \substr($buffer, $offset, $length);
        for ($index = 0; $index < $length; ++$index) {
            $payload[$index] = $payload[$index] ^ $mask[$index % 4];
        }
        if ($opcode === self::CLOSE) {
            self::validateClosePayload($payload);
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
            'consumed' => $offset + $length,
            'final' => $final,
        ];
    }

    public static function close(int $code = 1000, string $reason = ''): string
    {
        if (!self::isValidCloseCode($code)) {
            throw new \InvalidArgumentException("Invalid WebSocket close code [{$code}].");
        }
        if (!self::isUtf8($reason)) {
            throw new \InvalidArgumentException('WebSocket close reason must be valid UTF-8.');
        }
        return self::encode(\pack('n', $code) . $reason, self::CLOSE);
    }

    private static function validateOpcode(int $opcode, bool $protocol = false): void
    {
        if (\in_array($opcode, [self::CONTINUATION, self::TEXT, self::BINARY, self::CLOSE, self::PING, self::PONG], true)) {
            return;
        }
        if ($protocol) {
            throw new ProtocolException("Invalid WebSocket opcode [{$opcode}].");
        }
        throw new \InvalidArgumentException("Invalid WebSocket opcode [{$opcode}].");
    }

    private static function validateClosePayload(string $payload): void
    {
        $length = \strlen($payload);
        if ($length === 1) {
            throw new ProtocolException('Invalid WebSocket close payload.');
        }
        if ($length < 2) {
            return;
        }
        $unpacked = \unpack('ncode', \substr($payload, 0, 2));
        $code = $unpacked === false ? 0 : $unpacked['code'];
        if (!self::isValidCloseCode($code)) {
            throw new ProtocolException("Invalid WebSocket close code [{$code}].");
        }
        if (!self::isUtf8((string) \substr($payload, 2))) {
            throw new ProtocolException('WebSocket close reason must be valid UTF-8.', 1007);
        }
    }

    private static function isValidCloseCode(int $code): bool
    {
        return ($code >= 1000 && $code <= 1014 && !\in_array($code, [1004, 1005, 1006], true))
            || ($code >= 3000 && $code <= 4999);
    }

    public static function isUtf8(string $value): bool
    {
        return \preg_match('//u', $value) === 1;
    }
}
