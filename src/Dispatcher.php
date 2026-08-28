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

final class Dispatcher implements HandlerInterface
{
    /** @var callable(mixed, array<string, mixed>): mixed */
    private $invoker;

    /**
     * Routes may be declared for one path or globally with `*`.
     *
     * @param array<string, mixed>                         $routes
     * @param callable(mixed, array<string, mixed>): mixed $invoker
     */
    public function __construct(private readonly array $routes, callable $invoker)
    {
        $this->invoker = $invoker;
    }

    public function open(Handshake $handshake, Connection $connection, Server $server): mixed
    {
        return $this->invoke($handshake, 'open', [], $connection, $server, false, true);
    }

    public function message(
        Handshake $handshake,
        string $payload,
        int $opcode,
        Connection $connection,
        Server $server,
    ): mixed {
        if ($opcode !== Frame::TEXT) {
            throw new ProtocolException('Event routes accept text JSON messages only.', 1003);
        }
        try {
            $message = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($message)) {
                throw new \JsonException('The message must be a JSON object.');
            }

            $event = $message['event'] ?? null;
            if (!\is_string($event) || $event === '') {
                return $this->error('invalid_event', 'The message event is required.');
            }

            return $this->invoke($handshake, $event, $message['data'] ?? null, $connection, $server);
        } catch (\JsonException $exception) {
            return $this->error('invalid_json', $exception->getMessage());
        }
    }

    public function close(Handshake $handshake, Connection $connection, Server $server): void
    {
        $this->invoke($handshake, 'close', [], $connection, $server, false, true);
    }

    private function invoke(
        Handshake $handshake,
        string $event,
        mixed $data,
        Connection $connection,
        Server $server,
        bool $required = true,
        bool $lifecycle = false,
    ): mixed {
        $handler = $this->handler($handshake->path, $event, $lifecycle);
        if ($handler === null) {
            return $required ? $this->error('route_not_found', "No route for [{$handshake->path}] {$event}.") : null;
        }

        $result = ($this->invoker)($handler, [
            'data' => $data,
            'event' => $event,
            'path' => $handshake->path,
            'handshake' => $handshake,
            'headers' => $handshake->headers,
            'query' => $handshake->query,
            'cookies' => $handshake->cookies,
            'connection' => $connection,
            'server' => $server,
        ]);

        return $this->encode($result);
    }

    /** @return callable|string|array{0: object|class-string, 1: string}|null */
    private function handler(string $path, string $event, bool $lifecycle): callable|string|array|null
    {
        $group = $lifecycle ? 'lifecycle' : 'events';
        foreach ([$path, '*'] as $scope) {
            $routes = $this->routes[$scope] ?? null;
            if (!\is_array($routes)) {
                continue;
            }
            $groupRoutes = $routes[$group] ?? null;
            if (\is_array($groupRoutes)) {
                $handler = $this->routeHandler($groupRoutes[$event] ?? null);
                if ($handler !== null) {
                    return $handler;
                }
            }
            // The concise path => event => handler form is also supported.
            $handler = $this->routeHandler($routes[$event] ?? null);
            if ($handler !== null) {
                return $handler;
            }
        }

        $groupRoutes = $this->routes[$group] ?? null;
        if (\is_array($groupRoutes)) {
            $handler = $this->routeHandler($groupRoutes[$event] ?? null);
            if ($handler !== null) {
                return $handler;
            }
        }
        // A flat event => handler table applies to every connection path.
        return $this->routeHandler($this->routes[$event] ?? null);
    }

    /** @return callable|string|array{0: object|class-string, 1: string}|null */
    private function routeHandler(mixed $handler): callable|string|array|null
    {
        if (\is_callable($handler) || \is_string($handler)) {
            return $handler;
        }
        if (
            \is_array($handler)
            && \array_keys($handler) === [0, 1]
            && (\is_object($handler[0]) || (\is_string($handler[0]) && \class_exists($handler[0])))
            && \is_string($handler[1])
        ) {
            return [$handler[0], $handler[1]];
        }
        return null;
    }

    private function encode(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }
        if (\is_string($result)) {
            return $result;
        }
        return \json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function error(string $code, string $message): string
    {
        return (string) \json_encode([
            'event' => 'error',
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
