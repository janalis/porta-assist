<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Revolt\EventLoop;

/**
 * One exchange with a {@see Relay} on a non-blocking connection, on the
 * loop: say what this connection is, read the one line the relay answers.
 *
 * The answer is read a byte at a time, up to its newline and not past it:
 * what follows on a joined connection is the session's TLS, and a byte
 * read here would be lost to it.
 */
final class RelayHandshake
{
    /**
     * A relay's line, as an object; empty when it is not one — said by
     * either side of the relay, since none of its lines carry a type.
     *
     * @return array<array-key, mixed>
     */
    public static function decode(string $line): array
    {
        // @mago-expect analysis:mixed-assignment -- narrowed below
        $decoded = \json_decode($line, associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Why a relay turned a connection away, as its answer says.
     *
     * @param array<array-key, mixed> $answer
     */
    public static function refusal(array $answer): string
    {
        return \is_string($answer['error'] ?? null) ? Wire::clean($answer['error']) : 'nothing';
    }

    /**
     * Say $hello on $stream; $done gets the relay's answer, or null when it
     * closed or took longer than $timeout — unless the exchange is called
     * off first, with the closure this returns.
     *
     * @param resource                                     $stream connected, non-blocking
     * @param array<string, mixed>                         $hello
     * @param \Closure(array<array-key, mixed>|null): void $done
     *
     * @return \Closure(): void
     */
    public static function exchange($stream, array $hello, \Closure $done, float $timeout = 10.0): \Closure
    {
        // Unbuffered: a read must not take more than the byte asked for.
        \stream_set_read_buffer($stream, size: 0);
        \fwrite($stream, \json_encode($hello, \JSON_THROW_ON_ERROR)."\n");
        $line = '';
        $watcher = null;
        $timer = null;
        $cancel = static function () use (&$watcher, &$timer): void {
            EventLoop::cancel((string) $watcher);
            EventLoop::cancel((string) $timer);
        };
        $finish = static function (?array $answer) use ($cancel, $done): void {
            $cancel();
            $done($answer);
        };
        $watcher = EventLoop::unreference(EventLoop::onReadable($stream, static function () use ($stream, &$line, $finish): void {
            while (true) {
                $byte = \fread($stream, length: 1);
                if (false === $byte || '' === $byte && \feof($stream)) {
                    $finish(null);

                    return;
                }
                if ('' === $byte) {
                    return;
                }
                if ("\n" !== $byte) {
                    $line .= $byte;

                    continue;
                }
                $finish(self::decode($line));

                return;
            }
        }));
        $timer = EventLoop::unreference(EventLoop::delay($timeout, static fn () => $finish(null)));

        return $cancel;
    }
}
