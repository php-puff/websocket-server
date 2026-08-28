<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/websocket-server
 * https://github.com/php-puff/websocket-server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\WebsocketServer;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Puff\Async\EventLoop;
use Puff\Async\EventLoopInterface;
use Puff\Async\FiberScheduler;
use Puff\Async\SchedulerInterface;
use Puff\Server\Connection;
use Puff\Server\Protocol\TcpInterface;
use Puff\Server\TcpServer;

final class Server implements TcpInterface
{
    /** @var array<string, mixed> */
    private array $config;
    private TcpServer $tcp;
    private SchedulerInterface $scheduler;
    private LoggerInterface $logger;
    private HandlerInterface|Closure|null $handler;
    /** @var array<int, Handshake> */
    private array $upgraded = [];
    /** @var array<int, list<array{string, int}>> */
    private array $queues = [];
    /** @var array<int, true> */
    private array $processing = [];
    /** @var array<int, array{string, int}> */
    private array $fragments = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        HandlerInterface|callable|null $handler = null,
        array $config = [],
        ?EventLoopInterface $loop = null,
        ?SchedulerInterface $scheduler = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = \array_replace([
            'addr' => '127.0.0.1:8791',
            'backlog' => 256,
            'idle_timeout' => 60.0,
            'max_message_size' => 2 * 1024 * 1024,
            'max_read_buffer' => 2 * 1024 * 1024 + 14,
            'max_handshake_size' => 16 * 1024,
            'max_headers' => 100,
            'max_pending_messages' => 128,
            'allowed_origins' => [],
            'protocols' => [],
        ], $config);
        $this->validateConfig();
        $this->handler = $handler instanceof HandlerInterface || $handler === null
            ? $handler
            : Closure::fromCallable($handler);
        $this->scheduler = $scheduler ?? new FiberScheduler();
        $this->logger = $logger ?? new NullLogger();
        $this->tcp = new TcpServer($this, $this->config, $loop ?? EventLoop::get());
    }

    public function start(): void
    {
        $this->tcp->start();
    }

    public function stop(): void
    {
        $this->tcp->stop();
    }

    public function address(): string
    {
        return $this->tcp->address();
    }

    /** @return array{addr: string, url: string, connections: int} */
    public function info(): array
    {
        return [
            'addr' => $this->address(),
            'url' => 'ws://' . $this->address(),
            'connections' => $this->tcp->connectionCount(),
        ];
    }

    public function send(Connection $connection, string $message, int $opcode = Frame::TEXT): bool
    {
        if (\strlen($message) > (int) $this->config['max_message_size']) {
            throw new \InvalidArgumentException('WebSocket message is too large.');
        }
        return $this->tcp->send($connection, Frame::encode($message, $opcode));
    }

    public function connected(Connection $connection, TcpServer $server): void
    {
    }

    public function receive(Connection $connection, TcpServer $server): void
    {
        try {
            isset($this->upgraded[$connection->id]) ? $this->frames($connection) : $this->handshake($connection);
        } catch (HandshakeException $exception) {
            $this->reject($connection, $exception->status);
        } catch (ProtocolException $exception) {
            $this->logger->notice($exception->getMessage(), ['peer' => $connection->remoteAddress]);
            $this->disconnect($connection, $exception->closeCode);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            isset($this->upgraded[$connection->id])
                ? $this->disconnect($connection, 1011)
                : $this->reject($connection, 500);
        }
    }

    public function closed(Connection $connection, TcpServer $server): void
    {
        $handshake = $this->upgraded[$connection->id] ?? null;
        unset(
            $this->upgraded[$connection->id],
            $this->queues[$connection->id],
            $this->processing[$connection->id],
            $this->fragments[$connection->id],
        );
        if ($handshake !== null && $this->handler instanceof HandlerInterface) {
            try {
                $this->handler->close($handshake, $connection, $this);
            } catch (\Throwable $exception) {
                $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            }
        }
    }

    private function handshake(Connection $connection): void
    {
        $end = \strpos($connection->readBuffer, "\r\n\r\n");
        if ($end === false) {
            if (\strlen($connection->readBuffer) > (int) $this->config['max_handshake_size']) {
                throw new HandshakeException('WebSocket handshake is too large.', 431);
            }
            return;
        }
        if ($end + 4 > (int) $this->config['max_handshake_size']) {
            throw new HandshakeException('WebSocket handshake is too large.', 431);
        }
        $head = \substr($connection->readBuffer, 0, $end);
        $connection->readBuffer = (string) \substr($connection->readBuffer, $end + 4);
        $handshake = Handshake::parse(
            $head,
            $connection,
            $this->strings('allowed_origins'),
            $this->strings('protocols'),
            (int) $this->config['max_headers'],
        );

        $reply = null;
        if ($this->handler instanceof HandlerInterface) {
            $reply = $this->handler->open($handshake, $connection, $this);
        }
        $accept = \base64_encode(\sha1($handshake->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $protocol = $handshake->protocol === null ? '' : "Sec-WebSocket-Protocol: {$handshake->protocol}\r\n";
        $this->tcp->send(
            $connection,
            "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n{$protocol}\r\n",
        );
        $this->upgraded[$connection->id] = $handshake;
        if ($reply !== null) {
            $this->send($connection, (string) $reply);
        }
        if ($connection->readBuffer !== '') {
            $this->frames($connection);
        }
    }

    private function frames(Connection $connection): void
    {
        while (($frame = Frame::decode($connection->readBuffer, (int) $this->config['max_message_size'])) !== null) {
            $connection->readBuffer = (string) \substr($connection->readBuffer, $frame['consumed']);
            $opcode = $frame['opcode'];
            $payload = $frame['payload'];
            if ($opcode === Frame::CLOSE) {
                $this->tcp->send($connection, Frame::encode($payload, Frame::CLOSE), true);
                return;
            }
            if ($opcode === Frame::PING) {
                $this->send($connection, $payload, Frame::PONG);
                continue;
            }
            if ($opcode === Frame::PONG) {
                continue;
            }
            $message = $this->message($connection, $opcode, $payload, $frame['final']);
            if ($message !== null) {
                $this->enqueue($connection, $message[0], $message[1]);
            }
        }
    }

    /** @return array{string, int}|null */
    private function message(Connection $connection, int $opcode, string $payload, bool $final): ?array
    {
        $id = $connection->id;
        if ($opcode === Frame::CONTINUATION) {
            if (!isset($this->fragments[$id])) {
                throw new ProtocolException('Unexpected WebSocket continuation frame.');
            }
            $this->fragments[$id][0] .= $payload;
            if (\strlen($this->fragments[$id][0]) > (int) $this->config['max_message_size']) {
                throw new ProtocolException('WebSocket message is too large.', 1009);
            }
            if (!$final) {
                return null;
            }
            [$payload, $opcode] = $this->fragments[$id];
            unset($this->fragments[$id]);
        } else {
            if (isset($this->fragments[$id])) {
                throw new ProtocolException('A fragmented WebSocket message is incomplete.');
            }
            if (!$final) {
                $this->fragments[$id] = [$payload, $opcode];
                return null;
            }
        }
        if ($opcode === Frame::TEXT && !Frame::isUtf8($payload)) {
            throw new ProtocolException('WebSocket text message must be valid UTF-8.', 1007);
        }
        return [$payload, $opcode];
    }

    private function enqueue(Connection $connection, string $payload, int $opcode): void
    {
        $id = $connection->id;
        $this->queues[$id] ??= [];
        if (\count($this->queues[$id]) >= (int) $this->config['max_pending_messages']) {
            throw new ProtocolException('WebSocket message queue is full.', 1013);
        }
        $this->queues[$id][] = [$payload, $opcode];
        $this->process($connection);
    }

    private function process(Connection $connection): void
    {
        $id = $connection->id;
        if (isset($this->processing[$id]) || !isset($this->upgraded[$id]) || ($this->queues[$id] ?? []) === []) {
            return;
        }
        $message = \array_shift($this->queues[$id]);
        $this->processing[$id] = true;
        $this->scheduler->async(function () use ($connection, $message, $id): void {
            try {
                $handshake = $this->upgraded[$id] ?? null;
                if ($handshake === null) {
                    return;
                }
                [$payload, $opcode] = $message;
                $reply = $this->handler instanceof HandlerInterface
                    ? $this->handler->message($handshake, $payload, $opcode, $connection, $this)
                    : ($this->handler !== null ? ($this->handler)($payload, $connection, $this, $opcode, $handshake) : $payload);
                if ($reply !== null) {
                    $this->send($connection, (string) $reply, $opcode);
                }
            } catch (ProtocolException $exception) {
                $this->logger->notice($exception->getMessage(), ['peer' => $connection->remoteAddress]);
                $this->disconnect($connection, $exception->closeCode);
            } catch (\Throwable $exception) {
                $this->logger->error($exception->getMessage(), ['exception' => $exception]);
                $this->disconnect($connection, 1011);
            } finally {
                unset($this->processing[$id]);
                $this->process($connection);
            }
        })->ignore();
    }

    private function disconnect(Connection $connection, int $code): void
    {
        $this->tcp->send($connection, Frame::close($code), true);
    }

    private function reject(Connection $connection, int $status): void
    {
        $reasons = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            426 => 'Upgrade Required',
            431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error',
        ];
        $reason = $reasons[$status] ?? 'Bad Request';
        $version = $status === 426 ? "Sec-WebSocket-Version: 13\r\n" : '';
        $this->tcp->send(
            $connection,
            "HTTP/1.1 {$status} {$reason}\r\nConnection: close\r\n{$version}Content-Length: 0\r\n\r\n",
            true,
        );
    }

    /** @return list<string> */
    private function strings(string $key): array
    {
        $values = $this->config[$key] ?? [];
        return \is_array($values) ? \array_values(\array_filter($values, 'is_string')) : [];
    }

    private function validateConfig(): void
    {
        foreach (['max_message_size', 'max_handshake_size', 'max_headers', 'max_pending_messages'] as $key) {
            if (!\is_int($this->config[$key]) || $this->config[$key] < 1) {
                throw new \InvalidArgumentException("WebSocket option [{$key}] must be a positive integer.");
            }
        }
        if ((int) $this->config['max_read_buffer'] < (int) $this->config['max_message_size'] + 14) {
            throw new \InvalidArgumentException('WebSocket max_read_buffer must allow the message payload and frame header.');
        }
        foreach (['allowed_origins', 'protocols'] as $key) {
            if (!\is_array($this->config[$key]) || \count($this->strings($key)) !== \count($this->config[$key])) {
                throw new \InvalidArgumentException("WebSocket option [{$key}] must contain strings only.");
            }
        }
    }

}
