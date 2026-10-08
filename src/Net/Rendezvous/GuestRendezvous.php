<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Tls;
use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * The guest's side of meeting a host: the {@see Code} the host read out,
 * redeemed at the mailbox for the host's {@see Invite} — and then, as
 * often as the guest needs one, a connection to the host through the relay
 * the invite names.
 *
 * Both wait in the calling fiber; from a command, which has none, wrap them
 * in {@see blocking()}.
 */
final class GuestRendezvous
{
    /**
     * Prove to the host waiting at $code's nameplate that this side holds
     * the same code, and get its invite.
     *
     * @throws RendezvousFailed saying why for a person
     */
    public static function redeem(string $url, Code $code, float $timeout): Invite
    {
        $mailbox = Mailbox::connect($url, \bin2hex(\random_bytes(8)), $timeout);
        try {
            try {
                $mailbox->claim($code->nameplate);
            } catch (RendezvousFailed $e) {
                throw new RendezvousFailed(\str_ends_with($e->getMessage(), 'crowded') ? 'That code was used already — ask the host for a new one' : $e->getMessage());
            }
            $spake = new Spake2($code->text(), host: false);
            $mailbox->add('pake', $spake->message);
            try {
                $key = $spake->key($mailbox->receive('pake', $timeout * 3));
                $sealed = $mailbox->receive('invite', $timeout * 3);
            } catch (RendezvousFailed) {
                // Let go of the nameplate this side claimed: a host may yet be handed it.
                $mailbox->leave('lonely');

                throw new RendezvousFailed('No host is waiting with that code — check it, or ask the host for a new one');
            }
            $key = new SessionKey($key ?? \random_bytes(32));
            $opened = $key->open('invite', $sealed);
            // @mago-expect analysis:mixed-assignment -- narrowed by Invite::fromArray
            // @noinspection JsonEncodingApiUsageInspection -- a peer's line that is not JSON is dropped, not thrown: it comes off the network
            $fields = null === $opened ? null : \json_decode($opened, associative: true);
            $invite = \is_array($fields) ? Invite::fromArray($fields) : null;
            // Said either way: the host learns from this whether the code was right.
            $mailbox->add('ack', $key->seal('ack', null === $invite ? 'no' : 'ok'));
            $mailbox->leave(null === $invite ? 'scary' : 'happy');
            if (null === $invite) {
                throw new RendezvousFailed('That code is not right — check it with the host');
            }

            return $invite;
        } finally {
            $mailbox->hangUp();
        }
    }

    /**
     * Connect to the host through the relay its invite names, on the
     * guest's $n-th channel, encrypted, checked to be the host the invite
     * names: the non-blocking stream, or why there is none.
     *
     * @return resource|string
     */
    public static function connect(Invite $invite, int $n, float $timeout)
    {
        $stream = self::pair($invite, $n, $timeout);

        return \is_string($stream) ? $stream : self::secure($invite, $stream, $timeout);
    }

    /**
     * {@see connect()}'s first half: joined to the host on the $n-th channel
     * — which is spent once it is, whatever comes after — or why not.
     *
     * @return resource|string
     */
    public static function pair(Invite $invite, int $n, float $timeout)
    {
        try {
            $transit = Transit::at($invite->transit, $timeout);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return $transit->pair($invite->key->channel($n), \bin2hex(\random_bytes(8)), $timeout, Certificate::clientContext());
    }

    /**
     * {@see connect()}'s second half: $stream encrypted, checked to be the
     * host the invite names — or why not, $stream closed.
     *
     * @param resource $stream
     *
     * @return resource|string
     */
    public static function secure(Invite $invite, $stream, float $timeout)
    {
        /** @var string|null $failed */
        $failed = FiberWait::for(static fn (\Closure $done) => Tls::handshake($stream, \STREAM_CRYPTO_METHOD_TLS_CLIENT, $done, $timeout, $invite->fingerprint));
        if (null !== $failed) {
            \fclose($stream);

            return $failed;
        }

        return $stream;
    }

    /**
     * Run $work — which waits on the loop — from a command, outside any
     * fiber: the loop runs until it is done.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function blocking(\Closure $work): mixed
    {
        // Every watcher of the rendezvous is unreferenced, so the IDE can
        // quit mid-way; this one keeps the loop running for the command.
        $alive = EventLoop::repeat(3_600, static function (): void {});
        try {
            return $work();
        } finally {
            EventLoop::cancel($alive);
        }
    }
}
