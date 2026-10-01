<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Tunnel;
use Porta\AssistPlugin\Net\Wire;

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
        $stream = \array_key_exists($port, $this->shared) && !\array_key_exists($conn, $this->tunnels[$guest->id] ?? []) ? self::connect($port) : null;
        if (null === $stream) {
            $guest->send(['t' => 'tunnel-close', 'conn' => $conn]);

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
    }

    /** @param array<string, mixed> $message bytes for one of a guest's connections */
    public function data(Guest $guest, array $message): void
    {
        $bytes = \base64_decode(Wire::string($message, 'data'), strict: true);
        if (false !== $bytes) {
            ($this->tunnels[$guest->id][Wire::int($message, 'conn', -1)]['tunnel'] ?? null)?->write($bytes);
        }
    }

    /** @param array<string, mixed> $message one of a guest's connections, closed at its end */
    public function closed(Guest $guest, array $message): void
    {
        $conn = Wire::int($message, 'conn', -1);
        ($this->tunnels[$guest->id][$conn]['tunnel'] ?? null)?->finish();
        unset($this->tunnels[$guest->id][$conn]);
    }

    /** A guest gone: what it had open goes with it. */
    public function gone(Guest $guest): void
    {
        foreach ($this->tunnels[$guest->id] ?? [] as $tunnel) {
            $tunnel['tunnel']->close();
        }
        unset($this->tunnels[$guest->id]);
    }

    /**
     * The session is over: nothing is shared any more. Its guests are gone
     * already — the session lets them go first — and what they had open
     * went with each, through {@see gone()}.
     */
    public function stop(): void
    {
        $this->tunnels = [];
        $this->shared = [];
    }

    /** @return resource|null a connection to the port on this machine, or null when nothing answers there */
    private static function connect(int $port)
    {
        foreach (['127.0.0.1', '[::1]'] as $host) {
            // @mago-expect lint:no-error-control-operator -- a refused connection warns as well as returning false; false is the answer
            $stream = @\stream_socket_client(\sprintf('tcp://%s:%d', $host, $port), timeout: 2.0);
            if (false !== $stream) {
                return $stream;
            }
        }

        return null;
    }
}
