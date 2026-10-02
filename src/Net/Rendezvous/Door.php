<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Tls;
use Porta\Support\Backoff;
use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * The host's way in for one guest who held the code: the guest's
 * connections — its IDE's, each of its language servers', the one after a
 * dropped one — reach the host through the transit relay, each on the next
 * channel the guest's {@see SessionKey} names.
 *
 * Neither end can be reached, so the host cannot wait to be called: it
 * waits AT the relay, on the next few channels at once, and each time one
 * is joined it waits on one more. A guest process that took a channel and
 * died before using it leaves a hole the others step over, as long as there
 * are fewer than {@see WINDOW} in a row.
 */
final class Door
{
    /** Channels waited on past the last one joined. */
    public const int WINDOW = 4;

    /**
     * Seconds one wait at the relay lasts before it is made again. A wait
     * sends nothing, so one whose socket died with the network — the host's
     * laptop asleep, its Wi-Fi changed — would otherwise wait forever, and
     * every guest's way back with it.
     */
    public const float REWAIT = 120.0;

    /** @var array<int, Transit> the waits, by channel */
    private array $waits = [];

    /** The highest channel waited on so far. */
    private int $last = -1;

    private bool $closed = false;

    private readonly string $side;

    /**
     * @param \Closure(resource, Door): void $onStream each connection the guest made: joined, encrypted, non-blocking
     */
    public function __construct(
        private readonly SessionKey $key,
        private readonly string $transit,
        private readonly Certificate $certificate,
        private readonly \Closure $onStream,
        private readonly float $timeout = 10.0,
        private readonly float $rewait = self::REWAIT,
    ) {
        $this->side = \bin2hex(\random_bytes(8));
    }

    /** Start waiting for the guest. */
    public function open(): void
    {
        $this->widen(0);
    }

    /** The guest is not let in any more: every wait is called off. */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->waits as $wait) {
            $wait->cancel();
        }
        $this->waits = [];
    }

    /** Wait on every channel up to $from + {@see WINDOW}, and no longer on those far behind it. */
    private function widen(int $from): void
    {
        while ($this->last < ($from + self::WINDOW - 1)) {
            $channel = ++$this->last;
            EventLoop::queue(fn () => $this->wait($channel));
        }
        foreach (\array_keys($this->waits) as $channel) {
            if ($channel >= ($from - self::WINDOW)) {
                continue;
            }
            $this->waits[$channel]->cancel();
            unset($this->waits[$channel]);
        }
    }

    private function wait(int $channel): void
    {
        $tries = 0;
        while (!$this->closed) {
            $since = \hrtime(true);
            $transit = Transit::at($this->transit, $this->timeout);
            $this->waits[$channel] = $transit;
            $stream = $transit->pair($this->key->channel($channel), $this->side, wait: $this->rewait, context: $this->certificate->serverContext());
            // @mago-expect analysis:redundant-null-coalesce -- close() and widen() unset it while pair() waits
            if (($this->waits[$channel] ?? null) !== $transit) {
                // Called off — by close(), or left behind by widen(): pair()
                // let go of whatever it had.
                return;
            }
            unset($this->waits[$channel]);
            if (\is_resource($stream)) {
                $this->widen($channel + 1);
                $this->secure($stream);

                return;
            }
            // A wait the relay dropped after a long while is not a relay in trouble.
            $tries = (\hrtime(true) - $since) > 60e9 ? 0 : $tries;
            $delay = Backoff::after($tries++);
            FiberWait::for(static fn (\Closure $done): string => EventLoop::unreference(EventLoop::delay($delay, static fn () => $done(null))));
        }
    }

    /** @param resource $stream */
    private function secure($stream): void
    {
        /** @var string|null $failed */
        $failed = FiberWait::for(fn (\Closure $done) => Tls::handshake($stream, \STREAM_CRYPTO_METHOD_TLS_SERVER, $done, $this->timeout));
        if (null !== $failed || $this->closed) {
            \fclose($stream);

            return;
        }
        ($this->onStream)($stream, $this);
    }
}
