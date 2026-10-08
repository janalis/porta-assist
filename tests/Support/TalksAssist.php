<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Support;

use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Door;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\HostSession;
use Porta\Editor\BufferManager;
use Porta\Event\CommandRunRequested;
use Porta\Ide;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The other end of a session, for an E2e: a guest talking to the host IDE
 * under test, or a host the guest IDE under test joins — meeting it through
 * a {@see FakeWormhole} of the test's own, speaking the wire by hand, and
 * pumping the loop the IDE runs on while it waits.
 *
 * @noinspection StaticInvocationViaThisInspection -- assertIsResource() is PHPUnit's, redeclared abstract so mago narrows through it; custos cannot see that
 */
trait TalksAssist
{
    /** Satisfied by {@see \Porta\Tests\Support\PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /**
     * Satisfied by PHPUnit's Assert on the composing class — declared so the
     * guards below narrow where an analyser reads the trait alone.
     *
     * @phpstan-assert resource $actual
     */
    abstract public static function assertIsResource(mixed $actual, string $message = ''): void;

    /** @var resource|null */
    private $peer;

    private string $buffered = '';

    /** The certificate this side presents when it plays the host. */
    private ?Certificate $certificate = null;

    /** Where this side, as the host, waits for the guest IDE. */
    private ?Door $door = null;

    /** @var list<resource> the guest IDE's connections through {@see $door}, not taken yet */
    private array $arrived = [];

    private ?FakeWormhole $wormhole = null;

    /** @var array<string, int> the next channel of each invite this side holds as a guest, by its key */
    private array $channels = [];

    /** @var list<array<string, mixed>> */
    private array $inbox = [];

    /** Run one of the IDE's commands, as the palette would. */
    private function command(Ide $ide, string $id): void
    {
        Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new CommandRunRequested($id));
    }

    /** The mailbox server and relay of this test, started on first use. */
    private function wormhole(): FakeWormhole
    {
        return $this->wormhole ??= new FakeWormhole();
    }

    /** Share the project open in $ide, through {@see wormhole()}: the code, read off the toast that hands it out. */
    private function share(Ide $ide): Code
    {
        $api = $this->api($ide);
        $api->changeSetting('mailbox', $this->wormhole()->mailbox);
        $api->changeSetting('transit', $this->wormhole()->transit);
        $this->command($ide, 'assist:share');
        $code = '';
        $this->await(static function () use ($ide, &$code): bool {
            $match = [];
            if (1 === \preg_match('/porta assist (\S+)\)/', Toasts::last($ide), $match)) {
                $code = $match[1];
            }

            return '' !== $code;
        }, what: 'the code');

        return Code::parse($code);
    }

    /** Share the project open in $ide, and redeem the code as a guest would: what the host sends who held it. */
    private function invited(Ide $ide): Invite
    {
        return $this->redeem($this->share($ide));
    }

    /** The code the host in $ide hands out now, once it is not $not. */
    private function code(Ide $ide, ?Code $not = null): Code
    {
        $code = null;
        $this->await(function () use ($ide, $not, &$code): bool {
            $host = Probe::get($this->plugin($ide), 'host');
            $code = $host instanceof HostSession ? $host->code() : null;

            return null !== $code && $code->text() !== $not?->text();
        }, what: 'the code handed out');
        \assert($code instanceof Code, description: 'the await saw it');

        return $code;
    }

    /** Hand $code to the host waiting with it, as `porta assist` does — the loop the IDE runs on running meanwhile. */
    private function redeem(Code $code): Invite
    {
        return GuestRendezvous::blocking(fn (): Invite => GuestRendezvous::redeem($this->wormhole()->mailbox, $code, 5.0));
    }

    /** The plugin as the IDE loaded it — its sessions are read through {@see Probe}. */
    private function plugin(Ide $ide): object
    {
        $instances = Probe::get(Probe::get(Services::resolve($ide, PluginLifecycle::class), 'plugins'), 'instances');
        \assert(\is_array($instances) && \is_object($instances['assist'] ?? null), description: 'the plugin is enabled');

        return $instances['assist'];
    }

    /** Whether a live toast says $text — the newest or not: a later flash may sit above it. */
    private static function toasted(Ide $ide, string $text): bool
    {
        return [] !== \array_filter(Toasts::all($ide), static fn (string $toast): bool => \str_contains($toast, $text));
    }

    /** The plugin's own API — the editor as the plugin sees it. */
    private function api(Ide $ide): PluginApi
    {
        $api = Probe::get($this->plugin($ide), 'api');
        \assert($api instanceof PluginApi, description: 'set when the plugin activated');

        return $api;
    }

    /** What an open buffer holds now, by its real path; '' when none is open. */
    private function bufferText(Ide $ide, string $path): string
    {
        return (string) Services::resolve($ide, BufferManager::class)->get($path)?->document->getText();
    }

    /** Be a guest named $name: connect to the host through $invite and ask in with its token. */
    private function askIn(Invite $invite, string $name): void
    {
        $this->dial($invite);
        $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => $name, 'token' => $invite->token]);
        $this->expect('pending');
    }

    /** Be a guest: connect to the host through $invite — on its next channel — encrypted, expecting its certificate. */
    private function dial(Invite $invite): void
    {
        $this->peer = $this->connect($invite);
        $this->buffered = '';
        $this->inbox = [];
    }

    /**
     * A connection of a guest's to the host, through $invite, on its next
     * channel — encrypted, the host checked to be the one it names.
     *
     * @return resource
     */
    private function connect(Invite $invite)
    {
        $n = $this->nextChannel($invite);
        $stream = GuestRendezvous::blocking(static fn (): mixed => GuestRendezvous::connect($invite, $n, 5.0));
        $this->assertIsResource($stream, 'the host is reached through the relay');

        return $stream;
    }

    /** The next channel through $invite's door, taken: the host waits on only a few past the last one joined. */
    private function nextChannel(Invite $invite): int
    {
        $door = \bin2hex($invite->key->bytes);
        $n = $this->channels[$door] ?? 0;
        $this->channels[$door] = $n + 1;

        return $n;
    }

    /**
     * Be a host, waiting at {@see wormhole()}'s relay: what the guest IDE is
     * to be sent — {@see $certificate} is what it must expect.
     */
    private function listen(): Invite
    {
        $invite = $this->invite();
        $this->open($invite);

        return $invite;
    }

    /** What a host would send a guest IDE, before it waits for it: {@see open()} starts that. */
    private function invite(): Invite
    {
        $this->certificate = Certificate::create();

        return new Invite(new SessionKey(\random_bytes(32)), $this->wormhole()->transit, $this->certificate->fingerprint, \bin2hex(\random_bytes(16)));
    }

    /** Be the host $invite names: wait at the relay for the guest IDE. */
    private function open(Invite $invite): void
    {
        $this->assertNotNull($this->certificate, 'invite() first');
        $this->arrived = [];
        $this->door = new Door($invite->key, $invite->transit, $this->certificate, function ($stream): void {
            $this->arrived[] = $stream;
        });
        $this->door->open();
    }

    /** Be a host: wait for the guest IDE to connect. */
    private function acceptGuest(): void
    {
        $this->assertNotNull($this->door, 'listen() first');
        $this->await(fn (): bool => [] !== $this->arrived, what: 'the guest IDE to connect');
        $this->peer = \array_shift($this->arrived);
        $this->buffered = '';
        $this->inbox = [];
    }

    /** @param array<string, mixed> $message */
    private function say(array $message): void
    {
        $this->sayTogether($message);
    }

    /**
     * Several messages in ONE write, so the other end reads them together and
     * handles them in one turn of its loop — what a test of "this arrived
     * before that had happened" needs. Sent one write apiece, a slower machine
     * reads them apart, with the loop turning in between.
     *
     * @param array<string, mixed> ...$messages
     */
    private function sayTogether(array ...$messages): void
    {
        $this->assertIsResource($this->peer);
        \fwrite($this->peer, \implode('', \array_map(Wire::encode(...), $messages)));
    }

    /**
     * The next message of type $type, waited for — those of other types
     * that arrive meanwhile stay queued for later.
     *
     * @return array<string, mixed>
     */
    private function expect(string $type, float $timeout = 10.0): array
    {
        $found = null;
        $this->await(
            function () use ($type, &$found): bool {
                $this->drain();
                foreach ($this->inbox as $i => $message) {
                    if ($type !== $message['t']) {
                        continue;
                    }
                    $found = $message;
                    unset($this->inbox[$i]);
                    $this->inbox = \array_values($this->inbox);

                    return true;
                }

                return false;
            },
            $timeout,
            'a '.$type.' message',
        );
        \assert(\is_array($found), description: 'the await saw it arrive');

        return $found;
    }

    /** Whether the other end hung up. */
    private function hungUp(): bool
    {
        $this->drain();

        return null === $this->peer;
    }

    /** Drop the connection without a word, still listening: the guest IDE will try again. */
    private function dropPeer(): void
    {
        if (null !== $this->peer) {
            \fclose($this->peer);
        }
        $this->peer = null;
    }

    private function hangUp(): void
    {
        if (null !== $this->peer) {
            \fclose($this->peer);
        }
        $this->peer = null;
        $this->door?->close();
        $this->door = null;
    }

    private function drain(): void
    {
        if (null === $this->peer) {
            return;
        }
        $bytes = \fread($this->peer, length: 1_048_576);
        if ('' === $bytes && \feof($this->peer)) {
            \fclose($this->peer);
            $this->peer = null;

            return;
        }
        $this->buffered .= $bytes;
        while (false !== ($end = \strpos($this->buffered, needle: "\n"))) {
            $message = Wire::decode(\substr($this->buffered, offset: 0, length: $end));
            $this->buffered = \substr($this->buffered, $end + 1);
            if (null !== $message) {
                $this->inbox[] = $message;
            }
        }
    }
}
