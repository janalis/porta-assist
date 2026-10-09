<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * One connection through a magic-wormhole transit relay: two connections
 * that name the same channel — from two different sides — are joined, and
 * every byte after is passed on as it is. Neither end needs to be reachable:
 * both connect out. The session's TLS runs over it, end to end, so the
 * relay passes on what it cannot read.
 *
 * One attempt per instance, waited for by the fiber that asked; another
 * fiber may {@see cancel()} it.
 */
final class Transit
{
    /** The public relay magic-wormhole runs. */
    public const string PUBLIC = 'transit.magic-wormhole.io:4001';

    /** @var (\Closure(bool|string|null): void)|null the wait for the relay's answer: true joined, false hung up, null called off */
    private ?\Closure $answer = null;

    private bool $cancelled = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeout,
    ) {}

    /**
     * The relay at $address — `host:port`, `[::1]:port` for IPv6.
     *
     * @throws \InvalidArgumentException naming what is wrong with $address
     */
    public static function at(string $address, float $timeout): self
    {
        $match = [];
        if (1 !== \preg_match('/^\s*(\[[0-9a-f:.]+\]|[^\s:\[\]]+):(\d{1,5})\s*$/i', $address, $match) || (int) $match[2] < 1 || (int) $match[2] > 65_535) {
            throw new \InvalidArgumentException(\sprintf('the relay "%s" is not host:port', \trim($address)));
        }

        return new self(\trim($match[1], characters: '[]'), (int) $match[2], $timeout);
    }

    /**
     * Ask to be joined on $channel as $side, and wait for the other side —
     * at most $wait seconds, or as long as it takes when null: the joined,
     * non-blocking stream, its context carrying $context's ssl options, or
     * why there is none.
     *
     * @param array<array-key, mixed> $context
     *
     * @return resource|string
     */
    public function pair(string $channel, string $side, ?float $wait, array $context = [])
    {
        $stream = Tcp::connect($this->host, $this->port, $this->timeout, $context);
        if (!\is_string($stream) && $this->calledOff()) {
            \fclose($stream);
            $stream = 'called off';
        }

        return \is_string($stream) ? $stream : $this->join($stream, $channel, $side, $wait);
    }

    /**
     * Ask the relay $stream reaches to join it on $channel as $side, and wait
     * for the other side: $stream once joined, or why it is not.
     *
     * @param resource $stream
     *
     * @return resource|string
     */
    private function join($stream, string $channel, string $side, ?float $wait)
    {
        $where = \sprintf('the relay at %s:%d', $this->host, $this->port);
        // Unbuffered, and read a byte at a time: what follows the relay's
        // "ok" is the session's TLS, and a byte read here would be lost to it.
        \stream_set_read_buffer($stream, size: 0);
        \fwrite($stream, \sprintf("please relay %s for side %s\n", $channel, $side));
        $line = '';
        $watcher = '';
        $start = function (\Closure $done) use ($stream, &$line, &$watcher): void {
            $this->answer = $done;
            $watcher = EventLoop::unreference(EventLoop::onReadable($stream, static function () use ($stream, &$line, $done): void {
                while (true) {
                    $byte = \fread($stream, length: 1);
                    if (false === $byte || ('' === $byte && \feof($stream))) {
                        $done(false);

                        return;
                    }
                    if ('' === $byte) {
                        return;
                    }
                    if ("\n" === $byte) {
                        $done(true);

                        return;
                    }
                    $line .= $byte;
                }
            }));
        };
        /** @var bool|string|null $joined */
        $joined = null === $wait ? FiberWait::for($start) : FiberWait::within($wait, $start, 'timeout');
        EventLoop::cancel($watcher);
        $this->answer = null;
        // Joined, then called off before this fiber ran again: called off.
        $joined = $this->calledOff() ? null : $joined;
        if (true === $joined && 'ok' === $line) {
            return $stream;
        }
        \fclose($stream);

        return match (true) {
            null === $joined => 'called off',
            'timeout' === $joined => \sprintf('nobody came to %s', $where),
            '' === $line => \sprintf('%s hung up', $where),
            default => \sprintf('%s says %s', $where, \substr((string) \preg_replace('/[^\x20-\x7e]/', replacement: '', subject: $line), offset: 0, length: 200)),
        };
    }

    private function calledOff(): bool
    {
        return $this->cancelled;
    }

    /** Stop waiting: {@see pair()} returns at once, with no connection. */
    public function cancel(): void
    {
        $this->cancelled = true;
        if (null !== $this->answer) {
            ($this->answer)(null);
        }
    }
}
