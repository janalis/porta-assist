<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Support;

use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\RelayHandshake;
use Porta\AssistPlugin\Net\Wire;
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
 * under test, or a host the guest IDE under test joins — speaking the wire
 * by hand, and pumping the loop the IDE runs on while it waits.
 */
trait TalksAssist
{
    /** Satisfied by {@see \Porta\Tests\Support\PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /** @var resource|null */
    private $peer;

    /** @var resource|null */
    private $listening;

    private string $buffered = '';

    /** The certificate this side presents when it plays the host. */
    private ?Certificate $certificate = null;

    /** @var list<array<string, mixed>> */
    private array $inbox = [];

    /** Run one of the IDE's commands, as the palette would. */
    private function command(Ide $ide, string $id): void
    {
        Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new CommandRunRequested($id));
    }

    /** Share the project open in $ide: the invite, read off the toast that hands it out. */
    private function share(Ide $ide): Address
    {
        $this->command($ide, 'assist:share');
        $invite = '';
        $this->await(static function () use ($ide, &$invite): bool {
            $match = [];
            if (1 === \preg_match('/porta assist (\S+)/', Toasts::last($ide), $match)) {
                $invite = $match[1];
            }

            return '' !== $invite;
        }, what: 'the invite');

        return Address::parse($invite);
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

    /** Be a guest named $name: connect to the host on $address and ask in with its token. */
    private function askIn(Address $address, string $name): void
    {
        $this->dial($address);
        $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => $name, 'token' => $address->token]);
        $this->expect('pending');
    }

    /** Be a guest: connect to the host IDE at $address, encrypted, expecting its certificate. */
    private function dial(Address $address): void
    {
        $context = \stream_context_create(Certificate::clientContext());
        $socket = \stream_socket_client('tcp://127.0.0.1:'.$address->port, timeout: 2.0, context: $context);
        $this->assertNotFalse($socket, 'the host listens');
        if (null !== $address->relay) {
            $this->assertSame(['paired' => true], $this->relayed($socket, ['relay' => 'guest', 'session' => $address->relay]), 'joined to the host');
        }
        $this->secure($socket, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->assertTrue(Certificate::presentedBy($socket, $address->fingerprint), 'the host the invite names');
        $this->peer = $socket;
        $this->buffered = '';
        $this->inbox = [];
    }

    /**
     * Say $hello to a relay on $socket: its one-line answer, read a byte at a
     * time — what follows it is the session's TLS, not to be read here.
     *
     * @param resource             $socket
     * @param array<string, mixed> $hello
     *
     * @return array<array-key, mixed>
     */
    private function relayed($socket, array $hello): array
    {
        \stream_set_blocking($socket, enable: false);
        \stream_set_read_buffer($socket, size: 0);
        \fwrite($socket, \json_encode($hello, \JSON_THROW_ON_ERROR)."\n");
        $line = '';
        $this->await(static function () use ($socket, &$line): bool {
            while ('' !== ($byte = (string) \fread($socket, length: 1))) {
                $line .= $byte;
                if ("\n" === $byte) {
                    return true;
                }
            }

            return \feof($socket);
        }, what: 'the relay to answer');

        return RelayHandshake::decode($line);
    }

    /** Be a host: listen, and say where — {@see $certificate} is what a guest must expect. */
    private function listen(): int
    {
        $this->certificate = Certificate::create();
        $server = \stream_socket_server('tcp://127.0.0.1:0', context: \stream_context_create($this->certificate->serverContext()));
        $this->assertNotFalse($server);
        \stream_set_blocking($server, enable: false);
        $this->listening = $server;
        $name = (string) \stream_socket_get_name($server, remote: false);

        return (int) \substr($name, offset: (int) \strrpos($name, needle: ':') + 1);
    }

    /** Be a host: wait for the guest IDE to connect. */
    private function acceptGuest(): void
    {
        $server = $this->listening;
        $this->assertNotNull($server);
        $accepted = null;
        $this->await(
            static function () use ($server, &$accepted): bool {
                // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns; null is the answer
                $stream = @\stream_socket_accept($server, timeout: 0);
                if (false !== $stream) {
                    $accepted = $stream;
                }

                return null !== $accepted;
            },
            what: 'the guest IDE to connect',
        );
        \assert(\is_resource($accepted), description: 'the await saw it accepted');
        $this->secure($accepted, \STREAM_CRYPTO_METHOD_TLS_SERVER);
        $this->peer = $accepted;
        $this->buffered = '';
        $this->inbox = [];
    }

    /**
     * Encrypt $stream, pumping the loop while the IDE on the other end does
     * its half: a blocking handshake here would wait for a loop it blocks.
     *
     * @param resource $stream
     */
    private function secure($stream, int $method): void
    {
        \stream_set_blocking($stream, enable: false);
        $this->await(
            static function () use ($stream, $method): bool {
                // @mago-expect lint:no-error-control-operator -- a refused handshake warns; the false is turned into the failure
                $done = @\stream_socket_enable_crypto($stream, enable: true, crypto_method: $method);
                if (false === $done) {
                    throw new \RuntimeException('the TLS handshake failed');
                }

                return true === $done;
            },
            what: 'the TLS handshake',
        );
    }

    /** The fingerprint a guest IDE must expect of this side as a host. */
    private function fingerprint(): string
    {
        $this->assertNotNull($this->certificate, 'listen() first');

        return $this->certificate->fingerprint;
    }

    /** @param array<string, mixed> $message */
    private function say(array $message): void
    {
        $this->assertIsResource($this->peer);
        \fwrite($this->peer, Wire::encode($message));
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
        if (null !== $this->listening) {
            \fclose($this->listening);
        }
        $this->listening = null;
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
        $this->buffered .= (string) $bytes;
        while (false !== ($end = \strpos($this->buffered, needle: "\n"))) {
            $message = Wire::decode(\substr($this->buffered, offset: 0, length: $end));
            $this->buffered = \substr($this->buffered, $end + 1);
            if (null !== $message) {
                $this->inbox[] = $message;
            }
        }
    }
}
