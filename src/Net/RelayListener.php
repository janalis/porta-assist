<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * The host's end of a {@see Relay}: in place of a listening socket, one
 * connection out to the relay, which says when a guest arrives — and for
 * each, a connection of the host's own, joined to the guest's by the
 * relay, encrypted with the session's certificate end to end, and handed
 * on as a listening socket would hand on an accepted one.
 */
final class RelayListener
{
    /** @var resource|null */
    private $control;

    private ?string $watcher = null;

    private string $session = '';

    /** @var \Closure(): void|null calls off the exchange that asks for a session */
    private ?\Closure $handshake = null;

    /**
     * @param \Closure(Connection): void $onConnection
     * @param \Closure(string): void     $onLost       the relay hung up on the host
     */
    public function __construct(
        private readonly \Closure $onConnection,
        private readonly \Closure $onLost,
        private readonly float $timeout = 10.0,
    ) {}

    /**
     * Join the relay at $host:$port: $done gets null once joined —
     * {@see session()} is then what guests name — or why not.
     *
     * @param \Closure(?string): void $done
     */
    public function start(string $host, int $port, Certificate $certificate, \Closure $done): void
    {
        $control = $this->connect($host, $port);
        if (\is_string($control)) {
            $done($control);

            return;
        }
        \stream_set_blocking($control, enable: false);
        $this->control = $control;
        $this->handshake = RelayHandshake::exchange(
            $control,
            ['relay' => 'host'],
            function (?array $answer) use ($control, $host, $port, $certificate, $done): void {
                $this->handshake = null;
                if (!\is_string($answer['session'] ?? null)) {
                    $this->stop();
                    $done(\sprintf('the relay at %s:%d did not take the session', $host, $port));

                    return;
                }
                $this->session = $answer['session'];
                $this->watch($control, $host, $port, $certificate);
                $done(null);
            },
            $this->timeout,
        );
    }

    /**
     * @param resource $control
     */
    private function watch($control, string $host, int $port, Certificate $certificate): void
    {
        $lines = '';
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($control, function () use ($control, &$lines, $host, $port, $certificate): void {
            $bytes = \fread($control, length: 4_096);
            if (false === $bytes || '' === $bytes && \feof($control)) {
                $this->stop();
                ($this->onLost)(\sprintf('the relay at %s:%d hung up', $host, $port));

                return;
            }
            $lines .= $bytes;
            while (false !== ($end = \strpos($lines, needle: "\n"))) {
                $message = RelayHandshake::decode(\substr($lines, offset: 0, length: $end));
                $lines = \substr($lines, $end + 1);
                if (\is_int($message['incoming'] ?? null)) {
                    $this->answer($host, $port, $message['incoming'], $certificate);
                }
            }
        }));
    }

    /** The id guests name to reach this host through the relay. */
    public function session(): string
    {
        return $this->session;
    }

    public function stop(): void
    {
        if (null !== $this->handshake) {
            ($this->handshake)();
            $this->handshake = null;
        }
        if (null !== $this->watcher) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        if (null !== $this->control) {
            \fclose($this->control);
            $this->control = null;
        }
    }

    /** A guest arrived: the host's own connection for it, joined, encrypted, handed on. */
    private function answer(string $host, int $port, int $conn, Certificate $certificate): void
    {
        // Carrying the certificate: this end is the server of the session's TLS.
        $stream = $this->connect($host, $port, $certificate->serverContext());
        if (\is_string($stream)) {
            return;
        }
        \stream_set_blocking($stream, enable: false);
        RelayHandshake::exchange(
            $stream,
            ['relay' => 'accept', 'session' => $this->session, 'conn' => $conn],
            function (?array $answer) use ($stream): void {
                if (true !== ($answer['paired'] ?? null)) {
                    \fclose($stream);

                    return;
                }
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
                    $this->timeout,
                );
            },
            $this->timeout,
        );
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return resource|string
     */
    private function connect(string $host, int $port, array $context = [])
    {
        $errno = 0;
        $error = '';
        $endpoint = \sprintf('tcp://%s:%d', \str_contains($host, ':') ? '['.$host.']' : $host, $port);
        // @mago-expect lint:no-error-control-operator -- an unreachable relay warns as well as returning false; the message is carried in $error
        $stream = @\stream_socket_client($endpoint, $errno, $error, $this->timeout, context: \stream_context_create($context));

        return false === $stream ? \sprintf('cannot reach the relay at %s:%d: %s', $host, $port, $error) : $stream;
    }
}
