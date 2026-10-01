<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * A meeting point for a host and its guests when neither can reach the
 * other — both behind a NAT, say — run by `porta assist-relay` anywhere
 * both can reach.
 *
 * A host keeps one connection open and is given a session id, which goes
 * into its invite. A guest names that id; the host is told, opens a
 * connection of its own for that guest, and from then on the two are
 * joined and every byte copied across, untouched. The session's TLS runs
 * end to end through it: the relay sees only ciphertext, and a guest checks
 * the host's certificate against the invite as it would directly.
 *
 * Before a connection is joined it speaks one JSON line, and is answered
 * with one: `{"relay":"host"}` → `{"session":"…"}`, `{"relay":"guest",
 * "session":"…"}` → `{"paired":true}` once the host answered,
 * `{"relay":"accept","session":"…","conn":N}` → `{"paired":true}`; an
 * `{"error":"…"}` instead, and the connection is closed.
 */
final class Relay
{
    /** Bytes a connection may send before it has said what it is. */
    private const int MAX_HELLO = 1_024;

    /** @var resource|null */
    private $server;

    private ?string $watcher = null;

    /** @var array<string, int> session id => the host's control connection */
    private array $hosts = [];

    /** @var array<int, array{stream: resource, watcher: ?string, writer: NonBlockingWriter, buffer: string, peer: ?int, session: ?string, timer: ?string}> */
    private array $connections = [];

    private int $next = 0;

    public function __construct(
        private readonly float $waitForHost = 30.0,
    ) {}

    /** Listen on $bind:$port (0 picks one): the port, or why not. */
    public function start(string $bind, int $port): int|string
    {
        $errno = 0;
        $error = '';
        $endpoint = \sprintf('tcp://%s:%d', \str_contains($bind, ':') ? '['.$bind.']' : $bind, $port);
        // @mago-expect lint:no-error-control-operator -- a bind failure warns as well as returning false; the message is carried in $error
        $server = @\stream_socket_server($endpoint, $errno, $error);
        if (false === $server) {
            return \sprintf('cannot listen on %s: %s', $endpoint, $error);
        }
        \stream_set_blocking($server, enable: false);
        $this->server = $server;
        $this->watcher = EventLoop::onReadable($server, function () use ($server): void {
            // @mago-expect lint:no-error-control-operator -- a client that gave up between readable and accept warns; false is handled
            $stream = @\stream_socket_accept($server, timeout: 0);
            if (false === $stream) {
                // The kernel took back a connection it had announced: a race no test can force.
                // @codeCoverageIgnoreStart
                return;

                // @codeCoverageIgnoreEnd
            }
            $this->accept($stream);
        });
        $name = (string) \stream_socket_get_name($server, remote: false);

        return (int) \substr($name, offset: (int) \strrpos($name, needle: ':') + 1);
    }

    public function stop(): void
    {
        foreach (\array_keys($this->connections) as $id) {
            $this->close($id);
        }
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (null !== $this->server) {
            \fclose($this->server);
            $this->server = null;
        }
    }

    /** @param resource $stream */
    private function accept($stream): void
    {
        \stream_set_blocking($stream, enable: false);
        $id = $this->next++;
        $writer = new NonBlockingWriter($stream, fn () => $this->close($id));
        $this->connections[$id] = ['stream' => $stream, 'watcher' => null, 'writer' => $writer, 'buffer' => '', 'peer' => null, 'session' => null, 'timer' => null];
        $this->connections[$id]['watcher'] = EventLoop::onReadable($stream, fn () => $this->read($id));
    }

    private function read(int $id): void
    {
        $connection = $this->connections[$id];
        $bytes = \fread($connection['stream'], length: 65_536);
        if (false === $bytes || '' === $bytes && \feof($connection['stream'])) {
            $this->close($id);

            return;
        }
        $peer = $connection['peer'];
        if (null !== $peer) {
            $this->connections[$peer]['writer']->write($bytes);

            return;
        }
        $buffer = $connection['buffer'].$bytes;
        $end = \strpos($buffer, needle: "\n");
        if (false === $end) {
            \strlen($buffer) > self::MAX_HELLO ? $this->refuse($id, 'say what you are first') : ($this->connections[$id]['buffer'] = $buffer);

            return;
        }
        $this->connections[$id]['buffer'] = '';
        $this->hello($id, RelayHandshake::decode(\substr($buffer, offset: 0, length: $end)));
    }

    /** @param array<array-key, mixed> $hello */
    private function hello(int $id, array $hello): void
    {
        $session = \is_string($hello['session'] ?? null) ? $hello['session'] : '';
        $host = $this->hosts[$session] ?? null;
        $conn = \is_int($hello['conn'] ?? null) ? $hello['conn'] : -1;
        match ($hello['relay'] ?? null) {
            'host' => $this->host($id),
            'guest' => null === $host ? $this->refuse($id, 'no such session') : $this->guest($id, $host, $session),
            'accept' => null !== $host && $this->waiting($conn, $session) ? $this->pair($conn, $id) : $this->refuse($id, 'no such guest'),
            default => $this->refuse($id, 'not a relay request'),
        };
    }

    private function host(int $id): void
    {
        $session = \bin2hex(\random_bytes(8));
        $this->hosts[$session] = $id;
        $this->connections[$id]['session'] = $session;
        $this->say($id, ['session' => $session]);
    }

    /** A guest of $session: its host is told, and given a while to answer. */
    private function guest(int $id, int $host, string $session): void
    {
        $this->connections[$id]['session'] = $session;
        $this->connections[$id]['timer'] = EventLoop::delay($this->waitForHost, fn () => $this->refuse($id, 'the host did not answer'));
        $this->say($host, ['incoming' => $id]);
    }

    /** Whether connection $conn is a guest of $session not yet joined to its host. */
    private function waiting(int $conn, string $session): bool
    {
        $guest = $this->connections[$conn] ?? null;

        return null !== $guest && $session === $guest['session'] && null === $guest['peer'] && null !== $guest['timer'];
    }

    private function pair(int $guest, int $accept): void
    {
        EventLoop::cancel((string) $this->connections[$guest]['timer']);
        $this->connections[$guest]['peer'] = $accept;
        $this->connections[$accept]['peer'] = $guest;
        $this->say($guest, ['paired' => true]);
        $this->say($accept, ['paired' => true]);
    }

    private function refuse(int $id, string $why): void
    {
        $this->say($id, ['error' => $why]);
        $this->connections[$id]['writer']->flushBlocking();
        $this->close($id);
    }

    /** @param array<string, mixed> $message */
    private function say(int $id, array $message): void
    {
        $this->connections[$id]['writer']->write(\json_encode($message, \JSON_THROW_ON_ERROR)."\n");
    }

    /** A connection is gone: its partner goes with it, and a host takes its session with it. */
    private function close(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if (null === $connection) {
            return;
        }
        unset($this->connections[$id]);
        EventLoop::cancel((string) $connection['watcher']);
        EventLoop::cancel((string) $connection['timer']);
        $connection['writer']->close();
        \fclose($connection['stream']);
        if ($id === ($this->hosts[(string) $connection['session']] ?? null)) {
            unset($this->hosts[(string) $connection['session']]);
        }
        if (null !== $connection['peer']) {
            $this->close($connection['peer']);
        }
    }
}
