<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * A TLS handshake on a non-blocking socket, on the loop: tried again each
 * time the peer sends more, until it is done, refused, or too slow.
 */
final class Tls
{
    /** Why a connection is refused when the certificate is not the one the invite names. */
    public const string IMPOSTOR = 'the host is not the one the invite names';

    /**
     * Shake hands on $stream as a client or a server; $done gets null once
     * the connection is encrypted — and, for a client given $fingerprint,
     * the peer is the one with that certificate — or why it is not.
     *
     * @param resource                $stream connected, non-blocking, its context already carrying the ssl options
     * @param \Closure(?string): void $done
     */
    public static function handshake($stream, int $method, \Closure $done, float $timeout = 10.0, ?string $fingerprint = null): void
    {
        $watcher = null;
        $timer = null;
        $step = static function () use ($stream, $method, $done, $fingerprint, &$watcher, &$timer): void {
            // @mago-expect lint:no-error-control-operator -- a refused handshake warns as well as returning false; false is the answer
            $result = @\stream_socket_enable_crypto($stream, enable: true, crypto_method: $method);
            if (0 === $result) {
                return;
            }
            EventLoop::cancel((string) $watcher);
            EventLoop::cancel((string) $timer);
            $done(match (true) {
                !$result => 'the secure connection failed',
                null !== $fingerprint && !Certificate::presentedBy($stream, $fingerprint) => self::IMPOSTOR,
                default => null,
            });
        };
        $watcher = EventLoop::unreference(EventLoop::onReadable($stream, $step));
        $timer = EventLoop::unreference(EventLoop::delay($timeout, static function () use (&$watcher, $done): void {
            EventLoop::cancel($watcher);
            $done('the handshake took too long');
        }));
        $step();
    }
}
