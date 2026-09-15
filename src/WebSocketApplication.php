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
    /** @var list<Server> */
    private array $servers = [];

    private ?int $workers = null;

    public function name(): string
    {
        return 'WebSocket';
    }

    public function boot(Application $app): void
    {
        $this->servers = [];
        $this->workers = null;
        $container = $app->container();
        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            throw new \LogicException('Config service binding is invalid.');
        }
        $logger = $container->bound(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        foreach (\Puff\Server\ServerConfig::all($config->get('server', [])) as $server) {
            if ($server['type'] !== 'websocket') {
                continue;
            }
            $routes = $server['routes'] ?? [];
            $handler = new Dispatcher(
                $this->routes($routes),
                static function (mixed $route, array $parameters) use ($container): mixed {
                    if (!\is_callable($route) && !\is_string($route) && !\is_array($route)) {
                        throw new \InvalidArgumentException('Invalid WebSocket route handler.');
                    }
                    return $container->call($route, $parameters);
                },
            );
            $this->servers[] = new Server($handler, $server, EventLoop::get(), null, $logger);
            $this->setWorkers($server['workers']);
        }
        if ($this->servers === []) {
            throw new \LogicException('No WebSocket server is configured.');
        }
    }

    public function start(): void
    {
        foreach ($this->servers as $server) {
            $server->start();
        }
    }

    public function stop(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    public function workers(): int
    {
        return $this->workers ?? 1;
    }

    /** @return array{name: string, addr: string, url: string, workers: int, connections: int} */
    public function info(): array
    {
        $info = \array_map(static fn (Server $server): array => $server->info(), $this->servers);
        return [
            'name' => $this->name(),
            'addr' => \implode(', ', \array_column($info, 'addr')),
            'url' => \implode(', ', \array_column($info, 'url')),
            'workers' => $this->workers(),
        ];
    }

    public function server(): Server
    {
        return $this->servers[0] ?? throw new \LogicException('WebSocket worker has not been booted.');
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

    private function setWorkers(int $workers): void
    {
        if ($this->workers !== null && $this->workers !== $workers) {
            throw new \InvalidArgumentException('WebSocket servers must use the same workers value.');
        }
        $this->workers = $workers;
    }
}
