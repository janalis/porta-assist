<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Porta\Support\LineFramer;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * One end of a session's TCP connection, on the IDE's event loop: messages
 * out through a queue that never blocks the loop, messages in as each line
 * completes. Closed once — by this side, by the peer hanging up, or by a
 * peer too far behind to keep up — and the owner hears why, once.
 *
 * Its watcher is unreferenced: a session must never be what keeps the IDE
 * from quitting.
 *
 * Both ends talk through a relay, so a peer whose network silently died —
 * a laptop asleep, a Wi-Fi changed — closes nothing: the relay keeps this
 * side's socket open, and only silence tells. {@see keepAlive()} listens for
 * it: a ping when there was nothing else to say, and a close when nothing
 * was heard for too long. A peer that pings is kept alive in turn — the
 * first ping arms this side too, so only one end has to start it.
 */
final class Connection
{
    /** A line past this is not a message this protocol sends: a file is sent alone and capped well below. */
    public const int MAX_LINE = 33_554_432;

    /** Seconds a last message gets to go out before the socket closes anyway. */
    public const float LINGER = 2.0;

    /** Seconds of saying nothing before a ping says the link is still there. */
    public const float PING = 10.0;

    /** Seconds of hearing nothing before the peer is taken for gone. */
    public const float SILENCE = 30.0;

    private const string PING_MESSAGE = 'ping';

    /** The timer that pings and listens for silence, once {@see keepAlive()} armed it. */
    private ?string $heartbeat = null;

    /** When the peer was last heard, and this side last spoke: hrtime seconds. */
    private float $heard;
    private float $said;

    private readonly NonBlockingWriter $writer;

    private readonly string $watcher;

    private bool $closed = false;

    /** @var \Closure(array<string, mixed>): void */
    private \Closure $onMessage;

    /** @var \Closure(string): void */
    private \Closure $onClose;

    /** @var list<\Closure(): void> waiting for what is queued to go out */
    private array $drained = [];

    /** @param resource $stream connected; switched to non-blocking here */
    public function __construct(
        private $stream,
        int $maxLine = self::MAX_LINE,
    ) {
        \stream_set_blocking($stream, enable: false);
        $this->heard = self::now();
        $this->said = $this->heard;
        $this->onMessage = static function (array $message): void {};
        $this->onClose = static function (string $reason): void {};
        $this->writer = new NonBlockingWriter($stream, $this->close(...));
        $framer = new LineFramer($this->close(...), $maxLine);
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($stream, function () use ($framer): void {
            $bytes = \fread($this->stream, length: 262_144);
            if (false === $bytes || ('' === $bytes && \feof($this->stream))) {
                $this->close('the connection was closed');

                return;
            }
            $this->heard = self::now();
            foreach ($framer->feed($bytes) as $line) {
                if ($this->closed) {
                    return;
                }
                $message = Wire::decode($line);
                if (null === $message) {
                    continue;
                }
                if (self::PING_MESSAGE === $message['t']) {
                    $this->keepAlive();

                    continue;
                }
                // The lines after a throwing one were read already: dropped
                // with it, an edit or an ack would be lost without a trace.
                // What threw is thrown again on the next tick, where the
                // loop's error handler still hears it.
                try {
                    ($this->onMessage)($message);
                } catch (\Throwable $error) {
                    EventLoop::queue(static fn () => throw $error);
                }
            }
        }));
    }

    /**
     * Who hears this connection: each message as it arrives, and why it
     * closed. Set once the owner is ready — nothing is read before the loop
     * runs again.
     *
     * @param \Closure(array<string, mixed>): void $onMessage
     * @param \Closure(string): void               $onClose
     */
    public function listen(\Closure $onMessage, \Closure $onClose): void
    {
        $this->onMessage = $onMessage;
        $this->onClose = $onClose;
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        if (!$this->closed) {
            $this->said = self::now();
            $this->writer->write(Wire::encode($message));
        }
    }

    /**
     * Ping the peer whenever this side has said nothing for $every seconds,
     * and take it for gone — closed, "no answer" — once nothing at all was
     * heard from it for $silence. Once is enough: the peer's first ping arms
     * it on the other end, so a session armed on one side is on both.
     */
    public function keepAlive(float $every = self::PING, float $silence = self::SILENCE): void
    {
        if (null !== $this->heartbeat || $this->closed) {
            return;
        }
        $this->heartbeat = EventLoop::unreference(EventLoop::repeat(\min($every, $silence) / 2, function () use ($every, $silence): void {
            $now = self::now();
            if (($now - $this->heard) >= $silence) {
                $this->close(\sprintf('no answer in %d seconds', (int) $silence));

                return;
            }
            if (($now - $this->said) >= $every) {
                $this->send(['t' => self::PING_MESSAGE]);
            }
        }));
        $this->send(['t' => self::PING_MESSAGE]);
    }

    /** Bytes queued for the peer and not sent yet. */
    public function backlog(): int
    {
        return $this->writer->pendingBytes();
    }

    /**
     * Call $then once what is queued has gone out — at once, when nothing
     * is: a sender that paces itself on {@see backlog()} resumes here.
     *
     * @param \Closure(): void $then
     */
    public function afterDrain(\Closure $then): void
    {
        if (0 === $this->backlog()) {
            $then();

            return;
        }
        $this->drained[] = $then;
        $this->writer->onDrain(function (): void {
            $waiting = $this->drained;
            $this->drained = [];
            foreach ($waiting as $then) {
                $then();
            }
        });
    }

    /** The peer's address, as the socket names it. */
    public function peer(): string
    {
        $name = \stream_socket_get_name($this->stream, remote: true);

        return false === $name ? '?' : $name;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Hang up, after a last message when there is one: "you were removed" is
     * worth more than a dead connection, so the socket stays open until it
     * has gone out — for {@see LINGER} seconds at most, and never by blocking
     * the loop: a peer that stopped reading would freeze the whole IDE.
     * Closed for the owner at once either way.
     *
     * @param array<string, mixed>|null $last
     */
    public function close(string $reason, ?array $last = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        EventLoop::cancel($this->watcher);
        if (null !== $this->heartbeat) {
            EventLoop::cancel($this->heartbeat);
            $this->heartbeat = null;
        }
        if (null !== $last) {
            $this->writer->write(Wire::encode($last));
        }
        $this->linger();
        ($this->onClose)($reason);
    }

    /** Let go of the socket once what is queued has gone — or its time is up. */
    private function linger(): void
    {
        if (0 === $this->writer->pendingBytes()) {
            $this->hangUp();

            return;
        }
        $timer = EventLoop::unreference(EventLoop::delay(self::LINGER, $this->hangUp(...)));
        $this->writer->onDrain(function () use ($timer): void {
            EventLoop::cancel($timer);
            $this->hangUp();
        });
    }

    private static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    /** Let go of the socket: once — the linger timer and the drain each cancel the other. */
    private function hangUp(): void
    {
        $this->writer->close();
        \fclose($this->stream);
    }
}
