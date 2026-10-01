<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * A guest's way in: connect to a host without blocking the loop — the IDE
 * stays live while a far host answers, or does not. Its watchers are
 * unreferenced, like the connection's: quitting mid-dial just quits.
 */
final readonly class Dialer
{
    public function __construct(
        private float $timeout = 10.0,
    ) {}

    /**
     * Connect to $host:$port, encrypted, expecting the certificate with
     * $fingerprint; $done gets the connection, or why there is none.
     *
     * @param \Closure(Connection|string): void $done
     */
    public function dial(string $host, int $port, string $fingerprint, \Closure $done): void
    {
        $where = \sprintf('%s:%d', $host, $port);
        $timeout = $this->timeout;
        $errno = 0;
        $error = '';
        // @mago-expect lint:no-error-control-operator -- an unresolvable host warns as well as returning false; the message is carried in $error
        $stream = @\stream_socket_client(
            \sprintf('tcp://%s:%d', \str_contains($host, ':') ? '['.$host.']' : $host, $port),
            $errno,
            $error,
            timeout: $this->timeout,
            flags: \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            context: \stream_context_create(Certificate::clientContext()),
        );
        if (false === $stream) {
            $done(\sprintf('cannot reach %s: %s', $where, $error));

            return;
        }
        $timer = '';
        $writable = EventLoop::unreference(EventLoop::onWritable($stream, static function (string $watcher) use ($stream, $done, &$timer, $where, $timeout, $fingerprint): void {
            EventLoop::cancel($watcher);
            EventLoop::cancel($timer);
            // Writable says the attempt is over, not that it worked: only a
            // connected socket has a peer to name.
            if (false === \stream_socket_get_name($stream, remote: true)) {
                \fclose($stream);
                $done(\sprintf('%s refused the connection', $where));

                return;
            }
            \stream_set_blocking($stream, enable: false);
            Tls::handshake(
                $stream,
                \STREAM_CRYPTO_METHOD_TLS_CLIENT,
                static function (?string $failed) use ($stream, $done, $where): void {
                    if (null !== $failed) {
                        \fclose($stream);
                        $done(\sprintf('%s: %s', $where, $failed));

                        return;
                    }
                    $done(new Connection($stream));
                },
                $timeout,
                $fingerprint,
            );
        }));
        $timer = EventLoop::unreference(EventLoop::delay($this->timeout, static function () use ($writable, $stream, $done, $where): void {
            EventLoop::cancel($writable);
            \fclose($stream);
            $done(\sprintf('%s did not answer', $where));
        }));
    }
}
