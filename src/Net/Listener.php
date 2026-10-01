<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * The host's listening socket: every connection it accepts is encrypted with
 * the session's certificate, then handed on — nobody is IN until the session
 * has checked what they say first. One that does not complete the handshake
 * is let go.
 */
final class Listener
{
    /** @var resource|null */
    private $server;

    private ?string $watcher = null;

    /** @param \Closure(Connection): void $onConnection */
    public function __construct(
        private readonly \Closure $onConnection,
        private readonly float $handshakeTimeout = 10.0,
    ) {}

    /**
     * Listen on $bind:$port (0 picks a free one): the port it listens on, or
     * why it cannot.
     */
    public function start(string $bind, int $port, Certificate $certificate): int|string
    {
        $errno = 0;
        $error = '';
        $endpoint = \sprintf('tcp://%s:%d', \str_contains($bind, ':') ? '['.$bind.']' : $bind, $port);
        $context = \stream_context_create($certificate->serverContext());
        // @mago-expect lint:no-error-control-operator -- a bind failure warns as well as returning false; the message is carried in $error
        $server = @\stream_socket_server($endpoint, $errno, $error, context: $context);
        if (false === $server) {
            return \sprintf('cannot listen on %s: %s', $endpoint, $error);
        }
        \stream_set_blocking($server, enable: false);
        $this->server = $server;
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($server, function () use ($server): void {
            // @mago-expect lint:no-error-control-operator -- a client that gave up between readable and accept warns; false is handled
            $stream = @\stream_socket_accept($server, timeout: 0);
            if (false === $stream) {
                // The kernel took back a connection it had announced: a race no test can force.
                // @codeCoverageIgnoreStart
                return;

                // @codeCoverageIgnoreEnd
            }
            \stream_set_blocking($stream, enable: false);
            Tls::handshake(
                $stream,
                \STREAM_CRYPTO_METHOD_TLS_SERVER,
                function (?string $failed) use ($stream): void {
                    if (null !== $failed) {
                        \fclose($stream);

                        return;
                    }
                    ($this->onConnection)(new Connection($stream));
                },
                $this->handshakeTimeout,
            );
        }));
        $name = (string) \stream_socket_get_name($server, remote: false);

        return (int) \substr($name, offset: (int) \strrpos($name, needle: ':') + 1);
    }

    public function stop(): void
    {
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (null !== $this->server) {
            \fclose($this->server);
            $this->server = null;
        }
    }
}
