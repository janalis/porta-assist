<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\AssistPlugin\Net\Certificate;
use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * The host's side of meeting guests: a {@see Code} at the mailbox, waiting
 * for whoever holds it. A guest who proves it holds the same code — through
 * {@see Spake2}, which the server in the middle learns nothing from — is
 * sent the {@see Invite}, sealed, and gets a {@see Door} of its own.
 *
 * A code lets ONE guest through: the mailbox joins two sides, and a guest
 * that vanished halfway would leave the nameplate crowded for good. So
 * each code, used or tried wrong, is followed by a fresh one, and
 * {@see code()} is always the one to hand out now.
 */
final class HostRendezvous
{
    /** Seconds before meeting the mailbox server again after it failed, doubling, up to the last. */
    private const array RETRY = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];

    private ?Code $code = null;

    private ?Spake2 $spake = null;

    private ?Mailbox $mailbox = null;

    /** Whether the code still waits at its nameplate — false once a guest took it, or it is lost. */
    private bool $armed = false;

    private bool $stopped = false;

    /** @var list<Door> */
    private array $doors = [];

    private readonly string $side;

    /**
     * @param \Closure(Code, string): void   $onCode   a code to hand out, and why it is new: '' the first; used, when a guest got in with the last; wrong, when one tried and did not; lost, when others took its nameplate
     * @param \Closure(string): void        $onFailed why no code could be made: nothing will be shared
     * @param \Closure(string): void        $onLost   why the mailbox server was lost after a code was handed out: it is tried again
     * @param \Closure(resource, Door): void $onStream a guest's connection, through its door
     */
    public function __construct(
        private readonly string $url,
        private readonly string $transit,
        private readonly Certificate $certificate,
        #[\SensitiveParameter]
        private readonly string $token,
        private readonly \Closure $onCode,
        private readonly \Closure $onFailed,
        private readonly \Closure $onLost,
        private readonly \Closure $onStream,
        private readonly float $timeout = 10.0,
    ) {
        $this->side = \bin2hex(\random_bytes(8));
    }

    public function start(): void
    {
        EventLoop::queue($this->run(...));
    }

    /** The code to hand out now — null before the first, and while a new one is being made. */
    public function code(): ?Code
    {
        return $this->armed ? $this->code : null;
    }

    /** No more guests: the code is let go, and every door closed. */
    public function stop(): void
    {
        $this->stopped = true;
        $this->armed = false;
        $this->mailbox?->hangUp();
        foreach ($this->doors as $door) {
            $door->close();
        }
        $this->doors = [];
    }

    private function run(): void
    {
        $tries = 0;
        $why = '';
        while (!$this->stopped) {
            try {
                $why = $this->meet($why);
                $tries = 0;
            } catch (RendezvousFailed $e) {
                if ($this->stopped) {
                    return;
                }
                if (null === $this->code) {
                    ($this->onFailed)($e->getMessage());
                    $this->stop();

                    return;
                }
                ($this->onLost)($e->getMessage());
                $delay = self::RETRY[\min($tries++, \count(self::RETRY) - 1)];
                FiberWait::for(static fn (\Closure $done): string => EventLoop::unreference(EventLoop::delay($delay, static fn () => $done(null))));
                $why = $this->armed ? $why : 'lost';
            }
        }
    }

    /**
     * Wait at the mailbox — with a fresh code, or back at the same one after
     * the server hung up — for one guest, and let it in or not: why the next
     * code is new.
     *
     * @throws RendezvousFailed
     */
    private function meet(string $why): string
    {
        $mailbox = Mailbox::connect($this->url, $this->side, $this->timeout);
        $this->mailbox = $mailbox;
        if ($this->stopped) {
            $mailbox->hangUp();

            return $why;
        }
        $spake = $this->armed && null !== $this->code && null !== $this->spake ? $this->reclaim($mailbox, $this->code, $this->spake) : $this->arm($mailbox, $why);
        $theirs = $mailbox->receive('pake', timeout: null);
        // Whatever comes of it, this code is spent.
        $this->armed = false;
        $key = $spake->key($theirs);
        $key = null === $key ? null : new SessionKey($key);
        $invite = new Invite($key ?? new SessionKey(\random_bytes(32)), $this->transit, $this->certificate->fingerprint, $this->token);
        $mailbox->add('invite', $invite->key->seal('invite', \json_encode($invite->toArray(), \JSON_THROW_ON_ERROR)));
        try {
            $joined = null !== $key && 'ok' === $key->open('ack', $mailbox->receive('ack', $this->timeout * 3));
        } catch (RendezvousFailed) {
            // A guest that went away halfway is one that did not get in.
            $joined = false;
        }
        $mailbox->leave($joined ? 'happy' : 'scary');
        if (!$joined) {
            return 'wrong';
        }
        $door = new Door($invite->key, $this->transit, $this->certificate, $this->onStream, $this->timeout);
        $this->doors[] = $door;
        $door->open();

        return 'used';
    }

    /**
     * A fresh code, at a nameplate the server hands out, waiting there.
     *
     * @throws RendezvousFailed
     */
    private function arm(Mailbox $mailbox, string $why): Spake2
    {
        $code = Code::at($mailbox->allocate());
        $mailbox->claim($code->nameplate);
        $spake = new Spake2($code->text(), host: true);
        $mailbox->add('pake', $spake->message);
        [$this->code, $this->spake, $this->armed] = [$code, $spake, true];
        ($this->onCode)($code, $why);

        return $spake;
    }

    /**
     * Back at the same nameplate after the server hung up — or, when others
     * took it meanwhile, the code is lost.
     *
     * @throws RendezvousFailed
     */
    private function reclaim(Mailbox $mailbox, Code $code, Spake2 $spake): Spake2
    {
        try {
            $mailbox->claim($code->nameplate);
        } catch (RendezvousFailed $e) {
            $this->armed = false;

            throw $e;
        }
        // Said again: a server that restarted has an empty mailbox, and a
        // guest takes the first of two the same.
        $mailbox->add('pake', $spake->message);

        return $spake;
    }
}
