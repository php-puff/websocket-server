<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer;

use Psr\Log\LoggerInterface;
use Puff\Application\Application;
use Puff\Application\Contract;
use Puff\Async\EventLoop;
use Puff\Config\Config;

final class WebSocketApplication implements Contract
{
    private ?Server $server = null;

    /** @var array<string, mixed> */
    private array $config = [];

    public function name(): string
    {
        return 'WebSocket';
    }

    public function boot(Application $app): void
    {
        $container = $app->container();
        $this->config = (array) $container->get(Config::class)->get('websocket.server', []);
        $handler = $this->config['handler'] ?? null;
        if ($handler === null && isset($this->config['routes'])) {
            $handler = new Dispatcher(
                $this->routes($this->config['routes']),
                static function (mixed $route, array $parameters) use ($container): mixed {
                    if (!\is_callable($route) && !\is_string($route) && !\is_array($route)) {
                        throw new \InvalidArgumentException('Invalid WebSocket route handler.');
                    }
                    return $container->call($route, $parameters);
                },
            );
        }
        $handler ??= static fn (string $message): string => $message;
        $logger = $container->bound(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $this->server = new Server($handler, $this->config, EventLoop::get(), null, $logger);
    }

    public function start(): void
    {
        $this->server()->start();
    }

    public function stop(): void
    {
        $this->server?->stop();
    }

    public function workers(): int
    {
        return \max(1, (int) ($this->config['workers'] ?? 1));
    }

    /** @return array{name: string, addr: string, url: string, workers: int, connections: int} */
    public function info(): array
    {
        return [
            ...$this->server()->info(),
            'name' => $this->name(),
            'workers' => $this->workers(),
        ];
    }

    public function server(): Server
    {
        return $this->server ?? throw new \LogicException('WebSocket worker has not been booted.');
    }

    /**
     * @param  string|array<int|string, mixed> $sources
     * @return array<string, mixed>
     */
    private function routes(string|array $sources): array
    {
        $routes = [];
        foreach ((array) $sources as $source) {
            $loaded = \is_string($source) && \is_file($source) ? require $source : $source;
            if (!\is_array($loaded)) {
                throw new \InvalidArgumentException('WebSocket routes must return an array.');
            }
            $routes = \array_replace_recursive($routes, $loaded);
        }
        return $routes;
    }
}
