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
        $address = Resolver::resolve($host, $timeout);

        return null === $address
            ? \sprintf('cannot reach %s: its name could not be looked up', $where)
            : self::open($where, $address, $port, $timeout, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return resource|string
     */
    private static function open(string $where, string $address, int $port, float $timeout, array $context)
    {
        $errno = 0;
        $error = '';
        // @mago-expect lint:no-error-control-operator -- a refused address warns as well as returning false; the message is carried in $error
        $stream = @\stream_socket_client(
            \sprintf('tcp://%s', \str_contains($address, ':') ? '['.\trim($address, characters: '[]').']:'.$port : $address.':'.$port),
            $errno,
            $error,
            timeout: $timeout,
            flags: \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            context: \stream_context_create($context),
        );
        if (false === $stream) {
            // An address already looked up fails here only when the system
            // is out of sockets: nothing a test can arrange.
            // @codeCoverageIgnoreStart
            return \sprintf('cannot reach %s: %s', $where, $error);

            // @codeCoverageIgnoreEnd
        }

        return self::connected($where, $stream, $timeout);
    }

    /**
     * $stream once its connection attempt is over and worked, or why it did not.
     *
     * @param resource $stream
     *
     * @return resource|string
     */
    private static function connected(string $where, $stream, float $timeout)
    {
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
