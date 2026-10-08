<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * One local connection carried through a session: what its peer here sends
 * goes out as messages, what comes back in is written to it.
 *
 * It is read only as fast as the session sends: past {@see HIGH_WATER}
 * bytes queued for the other end, reading waits until they have gone out —
 * a large download must not fill the session's queue and cut everyone off.
 */
final class Tunnel
{
    /** Bytes the session may have queued before this stops reading. */
    public const int HIGH_WATER = 1_048_576;

    private const int CHUNK = 65_536;

    private readonly NonBlockingWriter $writer;

    private readonly string $watcher;

    private bool $closed = false;

    /**
     * @param resource                $stream  connected
     * @param \Closure(string): void  $out     bytes read here, for the other end
     * @param \Closure(): void        $ended   the connection here hung up, or failed
     */
    public function __construct(
        private $stream,
        private readonly Connection $session,
        private readonly \Closure $out,
        private readonly \Closure $ended,
    ) {
        \stream_set_blocking($stream, enable: false);
        $this->writer = new NonBlockingWriter($stream, $this->hangUp(...));
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($stream, $this->read(...)));
    }

    /** Bytes from the other end. */
    public function write(string $bytes): void
    {
        $this->writer->write($bytes);
    }

    /** The other end hung up: what it sent is delivered first, then this closes too. */
    public function finish(): void
    {
        EventLoop::disable($this->watcher);
        if (0 === $this->writer->pendingBytes()) {
            $this->close();

            return;
        }
        $this->writer->onDrain($this->close(...));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        EventLoop::cancel($this->watcher);
        $this->writer->close();
        \fclose($this->stream);
    }

    private function read(): void
    {
        if ($this->session->backlog() > self::HIGH_WATER) {
            EventLoop::disable($this->watcher);
            $this->session->afterDrain(function (): void {
                if (!$this->closed) {
                    EventLoop::enable($this->watcher);
                }
            });

            return;
        }
        $bytes = \fread($this->stream, length: self::CHUNK);
        if (false === $bytes || ('' === $bytes && \feof($this->stream))) {
            $this->hangUp();

            return;
        }
        ($this->out)($bytes);
    }

    /** Only ever while open: closing cancels the read and the writer that would call it. */
    private function hangUp(): void
    {
        $this->close();
        ($this->ended)();
    }
}
