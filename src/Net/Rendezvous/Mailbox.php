<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\AssistPlugin\Net\Wire;
use Porta\Support\Decoded;
use Revolt\EventLoop;

/**
 * A client of a magic-wormhole mailbox server: where a host and a guest who
 * share a {@see Code} meet. The host is handed a nameplate — the code's
 * number — and claims it; the guest claims the same one; both open the
 * mailbox it names, and what either adds the other receives.
 *
 * The server sees the nameplate and every message, so nothing that matters
 * goes through it in the clear: the {@see Spake2} messages are worthless to
 * an onlooker, and the rest is sealed with the key they agree.
 *
 * Each call waits, in the calling fiber, for the server's answer; a refusal,
 * a socket that closes, or an answer that does not come throws
 * {@see RendezvousFailed}, saying why for a person.
 */
final class Mailbox
{
    /** The public server magic-wormhole runs. */
    public const string PUBLIC = 'ws://relay.magic-wormhole.io:4000/v1';

    /** What sets this program's nameplates apart from every other's on the same server. */
    public const string APPID = 'github.com/janalis/porta/assist';

    /** Seconds between pings, for a server that drops a quiet socket. */
    private const float PING_EVERY = 20.0;

    /** @var array<string, string> what the other side added, by phase */
    private array $phases = [];

    private readonly string $pinger;

    /** The mailbox the claimed nameplate names. */
    private string $mailbox = '';

    private function __construct(
        private readonly WebSocket $socket,
        private readonly string $side,
        private readonly float $timeout,
    ) {
        $this->pinger = EventLoop::unreference(EventLoop::repeat(self::PING_EVERY, $socket->ping(...)));
    }

    /**
     * Connect to the server at $url as $side — hex, the same each time this
     * side comes back — and bind to this program's nameplates.
     *
     * @throws RendezvousFailed
     */
    public static function connect(string $url, string $side, float $timeout): self
    {
        $socket = WebSocket::connect($url, $timeout);
        if (\is_string($socket)) {
            throw new RendezvousFailed($socket);
        }
        $mailbox = new self($socket, $side, $timeout);
        try {
            $welcome = $mailbox->expect('welcome');
        } catch (RendezvousFailed $e) {
            // No welcome, or a hang-up: the socket and its pings go too.
            $mailbox->hangUp();

            throw $e;
        }
        $refusal = \is_array($welcome['welcome'] ?? null) ? Wire::text($welcome['welcome'], 'error') : '';
        if ('' !== $refusal) {
            $mailbox->hangUp();

            throw new RendezvousFailed(\sprintf('the mailbox server says: %s', $refusal));
        }
        $mailbox->say(['type' => 'bind', 'appid' => self::APPID, 'side' => $side]);

        return $mailbox;
    }

    /** A free nameplate, handed out by the server. @throws RendezvousFailed */
    public function allocate(): string
    {
        $this->say(['type' => 'allocate']);

        return Wire::string($this->expect('allocated'), 'nameplate');
    }

    /** Claim $nameplate, and open the mailbox it names. @throws RendezvousFailed */
    public function claim(string $nameplate): void
    {
        $this->say(['type' => 'claim', 'nameplate' => $nameplate]);
        $this->mailbox = Wire::string($this->expect('claimed'), 'mailbox');
        $this->say(['type' => 'open', 'mailbox' => $this->mailbox]);
    }

    /** Leave $body in the mailbox, under $phase, for the other side. */
    public function add(string $phase, string $body): void
    {
        $this->say(['type' => 'add', 'phase' => $phase, 'body' => \bin2hex($body)]);
    }

    /**
     * What the other side added under $phase, waited for at most $timeout
     * seconds — or as long as it takes, when null.
     *
     * @throws RendezvousFailed
     */
    public function receive(string $phase, ?float $timeout): string
    {
        $deadline = null === $timeout ? null : \hrtime(true) + (int) ($timeout * 1e9);
        while (!\array_key_exists($phase, $this->phases)) {
            $this->next(null === $deadline ? null : \max(0.0, ($deadline - \hrtime(true)) / 1e9), $phase);
        }

        return $this->phases[$phase];
    }

    /**
     * Let the nameplate and the mailbox go, and hang up — the server forgets
     * both once each side has. Not waited for: what is said before the
     * socket closes reaches the server, in order.
     */
    public function leave(string $mood): void
    {
        $this->say(['type' => 'release']);
        $this->say(['type' => 'close', 'mailbox' => $this->mailbox, 'mood' => $mood]);
        $this->hangUp();
    }

    /** Hang up without a word — a fiber waiting on this mailbox is let go. */
    public function hangUp(): void
    {
        EventLoop::cancel($this->pinger);
        $this->socket->close();
    }

    /** @param array<string, mixed> $message */
    private function say(array $message): void
    {
        $this->socket->send(\json_encode($message + ['id' => \bin2hex(\random_bytes(2))], \JSON_THROW_ON_ERROR));
    }

    /**
     * The server's next answer of $type, the other side's messages kept as
     * they pass.
     *
     * @return array<string, mixed>
     *
     * @throws RendezvousFailed
     */
    private function expect(string $type): array
    {
        $deadline = \hrtime(true) + (int) ($this->timeout * 1e9);
        while (true) {
            $message = $this->next(\max(0.0, ($deadline - \hrtime(true)) / 1e9), $type);
            if ($type === $message['type']) {
                return $message;
            }
        }
    }

    /**
     * The next message from the server, waited for at most $timeout seconds
     * (null: as long as it takes) for $what.
     *
     * @return array<string, mixed>
     *
     * @throws RendezvousFailed
     */
    private function next(?float $timeout, string $what): array
    {
        $line = $this->socket->receive($timeout);
        if (null === $line) {
            throw new RendezvousFailed(null === $this->socket->closed() ? \sprintf('the mailbox server sent no %s', $what) : \sprintf('the mailbox server hung up (%s)', $this->socket->closed()));
        }
        $message = Decoded::json($line);
        if (null === $message || !\is_string($message['type'] ?? null)) {
            return ['type' => ''];
        }
        /** @var array<string, mixed> $message */
        if ('error' === $message['type']) {
            throw new RendezvousFailed(\sprintf('the mailbox server says: %s', Wire::text($message, 'error')));
        }
        $body = \hex2bin(Wire::string($message, 'body'));
        if ('message' === $message['type'] && $this->side !== Wire::string($message, 'side') && false !== $body) {
            $this->phases[Wire::string($message, 'phase')] ??= $body;
        }

        return $message;
    }
}
