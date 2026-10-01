<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * The host's listening socket: every connection it accepts is handed on —
 * nobody is IN until the session has checked what they say first.
 */
final class Listener
{
    /** @var resource|null */
    private $server;

    private ?string $watcher = null;

    /** @param \Closure(Connection): void $onConnection */
    public function __construct(
        private readonly \Closure $onConnection,
    ) {}

    /**
     * Listen on $bind:$port (0 picks a free one): the port it listens on, or
     * why it cannot.
     */
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
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($server, function () use ($server): void {
            // @mago-expect lint:no-error-control-operator -- a client that gave up between readable and accept warns; false is handled
            $stream = @\stream_socket_accept($server, timeout: 0);
            if (false !== $stream) {
                ($this->onConnection)(new Connection($stream));
            }
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
