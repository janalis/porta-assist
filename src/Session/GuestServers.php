<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Tunnel;
use Porta\AssistPlugin\Net\Wire;
use Porta\Plugin\Api\PluginApi;
use Revolt\EventLoop;

/**
 * The host's servers, reached here: each port the host shares is listened
 * on at this machine's loopback — on the same number when it is free, on
 * another when not — and each connection made to it is carried through the
 * session to the host's. Lasts as long as the session's connection.
 */
final class GuestServers
{
    /** @var array<int, array{local: int, server: resource, watcher: string}> the host's port => where it is reached here */
    private array $servers = [];

    /** @var array<int, Tunnel> by connection id */
    private array $tunnels = [];

    /** @var array<int, int> connection id => the host's port it goes to */
    private array $ports = [];

    private int $next = 0;

    /** @param \Closure(): string $hostName who the host is, once the session has said */
    public function __construct(
        private readonly PluginApi $api,
        private readonly Connection $connection,
        private readonly \Closure $hostName,
    ) {}

    /** @return array<int, int> the host's port => the port it is reached at here */
    public function shared(): array
    {
        return \array_map(static fn (array $server): int => $server['local'], $this->servers);
    }

    /** @param array<string, mixed> $message */
    public function receive(array $message): void
    {
        match ($message['t']) {
            'server' => $this->listen(Wire::int($message, 'port')),
            'server-end' => $this->endAnnounced(Wire::int($message, 'port')),
            'tunnel-data' => ($this->tunnels[Wire::int($message, 'conn', -1)] ?? null)?->write((string) \base64_decode(Wire::string($message, 'data'), strict: true)),
            default => $this->closed(Wire::int($message, 'conn', -1)),
        };
    }

    /** The session's connection is gone: so is everything reached through it. */
    public function stop(): void
    {
        foreach (\array_keys($this->servers) as $port) {
            $this->end($port);
        }
    }

    private function listen(int $port): void
    {
        if (\array_key_exists($port, $this->servers)) {
            return;
        }
        $server = self::bind($port);
        if (false === $server) {
            $server = self::bind(0);
        }
        if (false === $server) {
            // Loopback with any port refused: nothing this side can do.
            // @codeCoverageIgnoreStart
            return;

            // @codeCoverageIgnoreEnd
        }
        \stream_set_blocking($server, enable: false);
        $name = (string) \stream_socket_get_name($server, remote: false);
        $local = (int) \substr($name, offset: (int) \strrpos($name, needle: ':') + 1);
        $watcher = EventLoop::unreference(EventLoop::onReadable($server, function () use ($server, $port): void {
            // @mago-expect lint:no-error-control-operator -- a client that gave up between readable and accept warns; false is handled
            $stream = @\stream_socket_accept($server, timeout: 0);
            if (false === $stream) {
                // The kernel took back a connection it had announced: a race no test can force.
                // @codeCoverageIgnoreStart
                return;

                // @codeCoverageIgnoreEnd
            }
            $this->tunnel($port, $stream);
        }));
        $this->servers[$port] = ['local' => $local, 'server' => $server, 'watcher' => $watcher];
        $this->api->notify(
            $this->api->trans('%name% shared localhost:%port% — open http://127.0.0.1:%local%', ['%name%' => ($this->hostName)(), '%port%' => $port, '%local%' => $local]),
            seconds: 15.0,
            slot: 'assist',
        );
    }

    /** @return resource|false a server on loopback at $port (0 picks one), or false when it is taken */
    private static function bind(int $port)
    {
        // @mago-expect lint:no-error-control-operator -- a port taken here warns as well as returning false; false is the answer
        return @\stream_socket_server('tcp://127.0.0.1:'.$port);
    }

    /** @param resource $stream a connection made here to the host's $port */
    private function tunnel(int $port, $stream): void
    {
        $conn = $this->next++;
        $this->tunnels[$conn] = new Tunnel(
            $stream,
            $this->connection,
            fn (string $bytes) => $this->connection->send(['t' => 'tunnel-data', 'conn' => $conn, 'data' => \base64_encode($bytes)]),
            function () use ($conn): void {
                unset($this->tunnels[$conn], $this->ports[$conn]);
                $this->connection->send(['t' => 'tunnel-close', 'conn' => $conn]);
            },
        );
        $this->connection->send(['t' => 'tunnel-open', 'port' => $port, 'conn' => $conn]);
        $this->ports[$conn] = $port;
    }

    private function closed(int $conn): void
    {
        ($this->tunnels[$conn] ?? null)?->finish();
        unset($this->tunnels[$conn], $this->ports[$conn]);
    }

    /** The host stopped sharing $port: end it, and say so. */
    private function endAnnounced(int $port): void
    {
        if (!\array_key_exists($port, $this->servers)) {
            return;
        }
        $this->end($port);
        $this->api->notify($this->api->trans('%name% stopped sharing localhost:%port%', ['%name%' => ($this->hostName)(), '%port%' => $port]), slot: 'assist');
    }

    /** End $port's forward quietly — the session ending says so once itself. */
    private function end(int $port): void
    {
        $server = $this->servers[$port] ?? null;
        if (null === $server) {
            return;
        }
        unset($this->servers[$port]);
        EventLoop::cancel($server['watcher']);
        \fclose($server['server']);
        foreach (\array_keys(\array_filter($this->ports, static fn (int $of): bool => $of === $port)) as $conn) {
            $this->tunnels[$conn]->close();
            unset($this->tunnels[$conn], $this->ports[$conn]);
        }
    }
}
