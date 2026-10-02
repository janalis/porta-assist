<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Rendezvous\Tcp;
use Porta\AssistPlugin\Net\Tunnel;
use Porta\AssistPlugin\Net\Wire;
use Revolt\EventLoop;

/**
 * The host's local servers, as the guests reach them: a port the host
 * shares — a dev server on localhost:8000, say — is listened on at each
 * guest's end, and every connection made to it there is carried through the
 * session to the port here. Only a port the host shared is ever connected
 * to: a guest names the port, never an address.
 */
final class HostServers
{
    /** @var array<int, true> the ports shared */
    private array $shared = [];

    /** @var array<string, array<int, array{port: int, tunnel: Tunnel}>> guest id => its connection id => where it goes */
    private array $tunnels = [];

    /**
     * Connections still being made here, and what the guest sent through
     * them meanwhile: the connect waits on the loop, never holds it.
     *
     * @var array<string, array<int, array{port: int, early: string}>> guest id => its connection id => what to send once connected
     */
    private array $connecting = [];

    /** Seconds a port here has to answer. */
    private const float TIMEOUT = 2.0;

    /** @param \Closure(array<string, mixed>, ?string=): void $broadcast to every guest, or every one but the one named */
    public function __construct(
        private readonly \Closure $broadcast,
    ) {}

    /** @return list<int> the ports shared */
    public function shared(): array
    {
        return \array_keys($this->shared);
    }

    /** Share a port with everyone — or, when it is shared already, stop: what is open through it is closed. */
    public function toggle(int $port): void
    {
        if (!\array_key_exists($port, $this->shared)) {
            $this->shared[$port] = true;
            ($this->broadcast)(['t' => 'server', 'port' => $port]);

            return;
        }
        unset($this->shared[$port]);
        foreach ($this->tunnels as $guest => $tunnels) {
            foreach ($tunnels as $conn => $tunnel) {
                if ($port !== $tunnel['port']) {
                    continue;
                }
                $tunnel['tunnel']->close();
                unset($this->tunnels[$guest][$conn]);
            }
        }
        ($this->broadcast)(['t' => 'server-end', 'port' => $port]);
    }

    /** A guest just let in is told what is shared. */
    public function welcome(Guest $guest): void
    {
        foreach ($this->shared() as $port) {
            $guest->send(['t' => 'server', 'port' => $port]);
        }
    }

    /**
     * A connection made at a guest's end: one to the port here, when it is
     * shared and answers — else the guest is told it closed.
     *
     * @param array<string, mixed> $message
     */
    public function open(Guest $guest, array $message): void
    {
        $port = Wire::int($message, 'port');
        $conn = Wire::int($message, 'conn', -1);
        $taken = \array_key_exists($conn, $this->tunnels[$guest->id] ?? []) || \array_key_exists($conn, $this->connecting[$guest->id] ?? []);
        if (!\array_key_exists($port, $this->shared) || $taken) {
            $guest->send(['t' => 'tunnel-close', 'conn' => $conn]);

            return;
        }
        $this->connecting[$guest->id][$conn] = ['port' => $port, 'early' => ''];
        EventLoop::queue(fn () => $this->connect($guest, $conn, $port));
    }

    /**
     * Connect to $port here, on the loop — a port that drops what it is sent
     * would otherwise freeze the IDE for the whole timeout — and tie it to
     * the guest's connection, when that is still wanted.
     */
    private function connect(Guest $guest, int $conn, int $port): void
    {
        $stream = Tcp::connect('127.0.0.1', $port, self::TIMEOUT);
        $stream = \is_string($stream) ? Tcp::connect('::1', $port, self::TIMEOUT) : $stream;
        $pending = $this->connecting[$guest->id][$conn] ?? null;
        unset($this->connecting[$guest->id][$conn]);
        if (\is_string($stream)) {
            $guest->send(['t' => 'tunnel-close', 'conn' => $conn]);

            return;
        }
        // Closed at the guest's end, the guest gone, or the port unshared
        // meanwhile — when it was, the guest was told so already.
        if (null === $pending || !\array_key_exists($port, $this->shared)) {
            \fclose($stream);

            return;
        }
        $this->tunnels[$guest->id][$conn] = [
            'port' => $port,
            'tunnel' => new Tunnel(
                $stream,
                $guest->connection,
                static fn (string $bytes) => $guest->send(['t' => 'tunnel-data', 'conn' => $conn, 'data' => \base64_encode($bytes)]),
                function () use ($guest, $conn): void {
                    unset($this->tunnels[$guest->id][$conn]);
                    $guest->send(['t' => 'tunnel-close', 'conn' => $conn]);
                },
            ),
        ];
        if ('' !== $pending['early']) {
            $this->tunnels[$guest->id][$conn]['tunnel']->write($pending['early']);
        }
    }

    /** @param array<string, mixed> $message bytes for one of a guest's connections */
    public function data(Guest $guest, array $message): void
    {
        $bytes = \base64_decode(Wire::string($message, 'data'), strict: true);
        $conn = Wire::int($message, 'conn', -1);
        if (false === $bytes) {
            return;
        }
        if (\array_key_exists($conn, $this->connecting[$guest->id] ?? [])) {
            $this->connecting[$guest->id][$conn]['early'] .= $bytes;

            return;
        }
        ($this->tunnels[$guest->id][$conn]['tunnel'] ?? null)?->write($bytes);
    }

    /** @param array<string, mixed> $message one of a guest's connections, closed at its end */
    public function closed(Guest $guest, array $message): void
    {
        $conn = Wire::int($message, 'conn', -1);
        ($this->tunnels[$guest->id][$conn]['tunnel'] ?? null)?->finish();
        unset($this->tunnels[$guest->id][$conn], $this->connecting[$guest->id][$conn]);
    }

    /** A guest gone: what it had open goes with it. */
    public function gone(Guest $guest): void
    {
        foreach ($this->tunnels[$guest->id] ?? [] as $tunnel) {
            $tunnel['tunnel']->close();
        }
        unset($this->tunnels[$guest->id], $this->connecting[$guest->id]);
    }

    /**
     * The session is over: nothing is shared any more. Its guests are gone
     * already — the session lets them go first — and what they had open
     * went with each, through {@see gone()}.
     */
    public function stop(): void
    {
        $this->tunnels = [];
        $this->connecting = [];
        $this->shared = [];
    }
}
