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
use Puff\Application\Application;
use Puff\Application\Discovery;
use Puff\Application\Exception;
use Puff\Config\Config;
use Puff\Di\Container;
use Puff\WebsocketServer\WebSocketApplication;

final class WebSocketApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Exception::restore();
        Discovery::reset();
        Container::setInstance(null);
    }

    public function testReadsWebSocketConfiguration(): void
    {
        $config = new Config([
            'websocket' => ['server' => [
                'addr' => '127.0.0.1:8792',
                'workers' => 2,
            ]],
        ]);
        $container = new Container();
        $container->instance(Config::class, $config);
        $application = new Application($container, [WebSocketApplication::class], []);
        $worker = $application->container()->make(WebSocketApplication::class);

        self::assertSame(2, $worker->workers());
        self::assertSame('127.0.0.1:8792', $worker->server()->address());
        self::assertSame('WebSocket', $worker->name());
    }

    public function testComposerDiscoveryFindsWorker(): void
    {
        Discovery::reset();

        self::assertContains(WebSocketApplication::class, Discovery::apps());
    }
}
