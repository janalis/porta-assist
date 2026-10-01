<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\Support\FiberWait;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * A WebSocket client (RFC 6455), as much of one as a {@see Mailbox} needs:
 * `ws://` only — what goes through it is sealed or public already — text
 * messages each way, pings answered, a close noticed. Messages are waited
 * for by the fiber that wants the next one; those nobody waits for yet are
 * kept in order.
 */
final class WebSocket
{
    private const string ACCEPT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private readonly NonBlockingWriter $writer;

    private readonly Frames $frames;

    private readonly string $watcher;

    /** @var list<string> messages nobody asked for yet */
    private array $inbox = [];

    /** @var (\Closure(string|null): void)|null the fiber waiting for the next message */
    private ?\Closure $waiting = null;

    private ?string $closed = null;

    /** @param resource $stream connected, non-blocking, past the handshake */
    private function __construct(
        private $stream,
        string $early,
    ) {
        $this->writer = new NonBlockingWriter($stream, $this->hangUp(...));
        $this->frames = new Frames($this->hangUp(...));
        $this->watcher = EventLoop::unreference(EventLoop::onReadable($stream, function (): void {
            $bytes = \fread($this->stream, length: 65_536);
            if (false === $bytes || '' === $bytes && \feof($this->stream)) {
                $this->hangUp('the server hung up');

                return;
            }
            $this->received($bytes);
        }));
        $this->received($early);
    }

    /**
     * Connect to $url — `ws://host:port/path` — and shake hands: the socket,
     * or why there is none.
     */
    public static function connect(string $url, float $timeout): self|string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || 'ws' !== ($parts['scheme'] ?? null) || !\array_key_exists('host', $parts)) {
            return \sprintf('"%s" is not a ws:// address', $url);
        }
        $host = \trim($parts['host'], characters: '[]');
        $port = $parts['port'] ?? 80;
        $stream = Tcp::connect($host, $port, $timeout);
        if (\is_string($stream)) {
            return $stream;
        }
        $key = \base64_encode(\random_bytes(16));
        $path = ($parts['path'] ?? '/').(\is_string($parts['query'] ?? null) ? '?'.$parts['query'] : '');
        \fwrite($stream, \sprintf(
            "GET %s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n",
            $path,
            $parts['host'],
            $port,
            $key,
        ));
        $response = self::response($stream, $timeout);
        $head = \strstr($response ?? '', needle: "\r\n\r\n", before_needle: true);
        $expected = \base64_encode(\sha1($key.self::ACCEPT, binary: true));
        if (
            null === $response
            || false === $head
            || 1 !== \preg_match('/^HTTP\/1\.1 101\b/', $head)
            || 1 !== \preg_match('/^sec-websocket-accept:\s*'.\preg_quote($expected, delimiter: '/').'\s*$/im', $head)
        ) {
            \fclose($stream);

            return \sprintf('%s is not a WebSocket server', $url);
        }

        return new self($stream, \substr($response, \strlen($head) + 4));
    }

    /** The next message, waited for at most $timeout seconds (null: as long as it takes) — null when the socket closed or none came. */
    public function receive(?float $timeout = null): ?string
    {
        if ([] !== $this->inbox) {
            return \array_shift($this->inbox);
        }
        if (null !== $this->closed) {
            return null;
        }
        $wait = function (\Closure $done): void {
            $this->waiting = $done;
        };
        /** @var string|null $message */
        $message = null === $timeout ? FiberWait::for($wait) : FiberWait::within($timeout, $wait, null);
        // A timeout answered instead: nothing waits any more.
        $this->waiting = null;

        return $message;
    }

    public function send(string $text): void
    {
        $this->writer->write(Frames::encode(Frames::TEXT, $text, \random_bytes(4)));
    }

    /** A ping, for a server that drops a quiet socket. */
    public function ping(): void
    {
        $this->writer->write(Frames::encode(Frames::PING, '', \random_bytes(4)));
    }

    /** Why the socket closed — null while it is open. */
    public function closed(): ?string
    {
        return $this->closed;
    }

    /** Say goodbye and close; a fiber waiting for a message gets none. */
    public function close(): void
    {
        if (null === $this->closed) {
            $this->writer->write(Frames::encode(Frames::CLOSE, \pack('n', 1000), \random_bytes(4)));
        }
        $this->hangUp('closed');
    }

    private function received(string $bytes): void
    {
        foreach ($this->frames->feed($bytes) as [$opcode, $payload]) {
            match ($opcode) {
                Frames::TEXT => $this->deliver($payload),
                Frames::PING => $this->writer->write(Frames::encode(Frames::PONG, $payload, \random_bytes(4))),
                Frames::CLOSE => $this->hangUp('the server closed the socket'),
                default => null,
            };
        }
    }

    private function deliver(string $message): void
    {
        $waiting = $this->waiting;
        if (null === $waiting) {
            $this->inbox[] = $message;

            return;
        }
        // Let go first: the fiber resumes later, and a second message in the
        // same read must be kept, not handed to a wait already answered.
        $this->waiting = null;
        $waiting($message);
    }

    private function hangUp(string $why): void
    {
        if (null !== $this->closed) {
            return;
        }
        $this->closed = $why;
        EventLoop::cancel($this->watcher);
        $this->writer->close();
        \fclose($this->stream);
        $waiting = $this->waiting;
        $this->waiting = null;
        if (null !== $waiting) {
            $waiting(null);
        }
    }

    /**
     * The server's answer to the handshake, and whatever it sent right
     * after — null when it hung up or took longer than $timeout.
     *
     * @param resource $stream
     */
    private static function response($stream, float $timeout): ?string
    {
        $response = '';
        $watcher = '';
        /** @var bool $complete */
        $complete = FiberWait::within(
            $timeout,
            static function (\Closure $done) use ($stream, &$response, &$watcher): void {
                $watcher = EventLoop::unreference(EventLoop::onReadable($stream, static function () use ($stream, &$response, $done): void {
                    $bytes = \fread($stream, length: 8_192);
                    if (false === $bytes || '' === $bytes && \feof($stream)) {
                        $done(false);

                        return;
                    }
                    $response .= $bytes;
                    if (\str_contains($response, "\r\n\r\n") || \strlen($response) > 16_384) {
                        $done(true);
                    }
                }));
            },
            false,
        );
        EventLoop::cancel($watcher);

        return true === $complete ? $response : null;
    }
}
