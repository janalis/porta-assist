<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * A TCP connection made without blocking the loop — the IDE stays live
 * while a far server answers, or does not — waited for by the fiber that
 * asked. Its watchers are unreferenced: quitting mid-connect just quits.
 */
final class Tcp
{
    /**
     * The connected, non-blocking stream to $host:$port, or why there is none.
     *
     * @param array<array-key, mixed> $context
     *
     * @return resource|string
     */
    public static function connect(string $host, int $port, float $timeout, array $context = [])
    {
        $where = \sprintf('%s:%d', $host, $port);
        $errno = 0;
        $error = '';
        // @mago-expect lint:no-error-control-operator -- an unresolvable host warns as well as returning false; the message is carried in $error
        $stream = @\stream_socket_client(
            \sprintf('tcp://%s', \str_contains($host, ':') ? '['.$host.']:'.$port : $where),
            $errno,
            $error,
            timeout: $timeout,
            flags: \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            context: \stream_context_create($context),
        );
        if (false === $stream) {
            return \sprintf('cannot reach %s: %s', $where, $error);
        }
        $writable = '';
        $timer = '';
        /** @var bool|null $connected */
        $connected = FiberWait::for(static function (\Closure $done) use ($stream, $timeout, &$writable, &$timer): void {
            // Writable says the attempt is over, not that it worked: only a
            // connected socket has a peer to name.
            $writable = EventLoop::unreference(EventLoop::onWritable($stream, static fn () => $done(false !== \stream_socket_get_name($stream, remote: true))));
            $timer = EventLoop::unreference(EventLoop::delay($timeout, static fn () => $done(null)));
        });
        EventLoop::cancel($writable);
        EventLoop::cancel($timer);
        if (true !== $connected) {
            \fclose($stream);

            return null === $connected ? \sprintf('%s did not answer', $where) : \sprintf('%s refused the connection', $where);
        }
        \stream_set_blocking($stream, enable: false);

        return $stream;
    }
}
