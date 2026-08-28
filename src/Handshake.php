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

final readonly class Handshake
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, string>       $query
     * @param array<string, string>       $cookies
     */
    private function __construct(
        public string $target,
        public string $path,
        public array $headers,
        public array $query,
        public array $cookies,
        public string $key,
        public ?string $protocol,
        public string $remoteAddress,
        public ?int $remotePort,
    ) {
    }

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $supportedProtocols
     */
    public static function parse(
        string $head,
        Connection $connection,
        array $allowedOrigins = [],
        array $supportedProtocols = [],
        int $maxHeaders = 100,
    ): self {
        $lines = \explode("\r\n", $head);
        $request = (string) \array_shift($lines);
        if (!\preg_match('#^GET ([^\x00-\x20]+) HTTP/1\.1$#D', $request, $matches)) {
            throw new HandshakeException('Invalid WebSocket request line.');
        }

        $target = $matches[1];
        $parts = \parse_url($target);
        if ($parts === false || isset($parts['scheme'], $parts['host']) || !\str_starts_with($target, '/')) {
            throw new HandshakeException('Invalid WebSocket request target.');
        }
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $headers = self::parseHeaders($lines, $maxHeaders);

        if (self::line($headers, 'host') === '') {
            throw new HandshakeException('The Host header is required.');
        }
        if (!self::hasToken($headers, 'upgrade', 'websocket') || !self::hasToken($headers, 'connection', 'upgrade')) {
            throw new HandshakeException('WebSocket upgrade headers are invalid.');
        }
        if (self::line($headers, 'sec-websocket-version') !== '13') {
            throw new HandshakeException('WebSocket version 13 is required.', 426);
        }

        $key = self::line($headers, 'sec-websocket-key');
        $decodedKey = \base64_decode($key, true);
        if ($decodedKey === false || \strlen($decodedKey) !== 16 || \base64_encode($decodedKey) !== $key) {
            throw new HandshakeException('The Sec-WebSocket-Key header is invalid.');
        }

        self::validateOrigin(self::line($headers, 'origin'), $allowedOrigins);

        return new self(
            $target,
            $path,
            $headers,
            self::query($parts['query'] ?? ''),
            self::cookies(self::line($headers, 'cookie')),
            $key,
            self::protocol($headers, $supportedProtocols),
            $connection->remoteAddress,
            $connection->remotePort,
        );
    }

    public function header(string $name, string $default = ''): string
    {
        return self::line($this->headers, $name, $default);
    }

    public function origin(): ?string
    {
        $origin = $this->header('origin');
        return $origin === '' ? null : $origin;
    }

    /** @param list<string> $lines
     *  @return array<string, list<string>>
     */
    private static function parseHeaders(array $lines, int $maxHeaders): array
    {
        if ($maxHeaders < 1) {
            throw new \InvalidArgumentException('Maximum WebSocket header count must be positive.');
        }
        if (\count($lines) > $maxHeaders) {
            throw new HandshakeException('The WebSocket request has too many headers.', 431);
        }
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t" || !\str_contains($line, ':')) {
                throw new HandshakeException('Invalid WebSocket header line.');
            }
            [$name, $value] = \explode(':', $line, 2);
            $name = \strtolower($name);
            $value = \trim($value, " \t");
            if (!\preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)
                || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
            ) {
                throw new HandshakeException('Invalid WebSocket header.');
            }
            $headers[$name][] = $value;
        }
        return $headers;
    }

    /** @param array<string, list<string>> $headers */
    private static function line(array $headers, string $name, string $default = ''): string
    {
        return isset($headers[\strtolower($name)]) ? \implode(', ', $headers[\strtolower($name)]) : $default;
    }

    /** @param array<string, list<string>> $headers */
    private static function hasToken(array $headers, string $name, string $token): bool
    {
        foreach (\explode(',', self::line($headers, $name)) as $value) {
            if (\strcasecmp(\trim($value), $token) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $allowed */
    private static function validateOrigin(string $origin, array $allowed): void
    {
        $normalized = $origin === '' ? null : self::normalizeOrigin($origin);
        $allowed = \array_map(self::normalizeOrigin(...), $allowed);
        if ($allowed !== [] && ($normalized === null || !\in_array($normalized, $allowed, true))) {
            throw new HandshakeException('The WebSocket origin is not allowed.', 403);
        }
    }

    private static function normalizeOrigin(string $origin): string
    {
        $parts = \parse_url($origin);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array(\strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (($parts['path'] ?? '') !== '')
        ) {
            throw new HandshakeException('The Origin header is invalid.', 403);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return \strtolower($parts['scheme']) . '://' . \strtolower($parts['host']) . $port;
    }

    /** @param array<string, list<string>> $headers
     *  @param list<string> $supported
     */
    private static function protocol(array $headers, array $supported): ?string
    {
        foreach (\explode(',', self::line($headers, 'sec-websocket-protocol')) as $protocol) {
            $protocol = \trim($protocol);
            if ($protocol !== '' && \in_array($protocol, $supported, true)) {
                return $protocol;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    private static function query(string $query): array
    {
        $parsed = [];
        \parse_str($query, $parsed);
        $values = [];
        foreach ($parsed as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $values[$name] = $value;
            }
        }
        return $values;
    }

    /** @return array<string, string> */
    private static function cookies(string $header): array
    {
        $cookies = [];
        foreach (\explode(';', $header) as $part) {
            if (!\str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = \explode('=', \trim($part), 2);
            if ($name !== '') {
                $cookies[$name] = \urldecode($value);
            }
        }
        return $cookies;
    }
}
