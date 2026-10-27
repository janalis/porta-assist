<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Rendezvous\Door;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Net\Rendezvous\Tcp;
use Porta\AssistPlugin\Net\Rendezvous\Transit;
use Porta\AssistPlugin\Net\Tls;
use Porta\AssistPlugin\Tests\Support\FakeWormhole;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

/**
 * A session's connections, on real loopback sockets: joined through a
 * transit relay — the host waiting at a {@see Door}, the guest coming to it
 * — encrypted against the certificate the invite names, read line by line,
 * closed once.
 */
final class TransportTest extends TestCase
{
    use PumpsLoop;

    /** @var list<resource> what came through the door */
    private array $accepted = [];

    private Certificate $certificate;

    private FakeWormhole $wormhole;

    protected function setUp(): void
    {
        $this->certificate = Certificate::create();
        $this->wormhole = new FakeWormhole();
    }

    protected function tearDown(): void
    {
        $this->certificate->forget();
    }

    /**
     * A host waiting at the relay: the door, and what a guest of it holds.
     *
     * @return array{Door, Invite}
     */
    private function door(float $timeout = 10.0): array
    {
        $invite = new Invite(new SessionKey(\random_bytes(32)), $this->wormhole->transit, $this->certificate->fingerprint, \str_repeat('ab', times: 16));
        $door = new Door(
            $invite->key,
            $invite->transit,
            $this->certificate,
            function ($stream): void {
                $this->accepted[] = $stream;
            },
            $timeout,
        );
        $door->open();

        return [$door, $invite];
    }

    /** @return array{Connection, Connection} the guest's end and the host's */
    private function pair(): array
    {
        [, $invite] = $this->door();
        $guest = self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 0, 5.0));
        $this->assertIsResource($guest);
        $this->await(fn (): bool => [] !== $this->accepted, what: 'the host\'s end');

        return [new Connection($guest), new Connection(\array_shift($this->accepted))];
    }

    /**
     * Run $work, which waits on the loop, from the test: nothing else keeps the loop running.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function blocking(\Closure $work): mixed
    {
        return GuestRendezvous::blocking($work);
    }

    public function testMessagesCrossBothWaysAndALastOneIsDeliveredBeforeTheClose(): void
    {
        [$guest, $host] = $this->pair();
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $host->peer());

        $heard = [];
        $closed = [];
        $host->listen(static function (array $message) use (&$heard): void {
            $heard[] = $message;
        }, static function (string $why) use (&$closed): void {
            $closed[] = 'host: '.$why;
        });
        $guest->listen(static function (array $message) use (&$heard): void {
            $heard[] = $message;
        }, static function (string $why) use (&$closed): void {
            $closed[] = 'guest: '.$why;
        });

        $guest->send(['t' => 'hello']);
        $this->await(static function () use (&$heard): bool {
            return [['t' => 'hello']] === $heard;
        }, what: 'the host heard');
        $host->close('bye now', ['t' => 'bye']);
        $host->close('again');
        $host->send(['t' => 'too late']);
        $this->await(static function () use (&$closed): bool {
            return 2 === \count($closed);
        }, what: 'both closed');
        $this->assertSame([['t' => 'hello'], ['t' => 'bye']], $heard, 'the last message before the close, nothing after');
        $this->assertSame(['host: bye now', 'guest: the connection was closed'], $closed, 'each said once');
        $this->assertTrue($host->isClosed());
    }

    /**
     * A peer that resets the connection — gone with no TLS goodbye — is a
     * closed connection like any other, said once.
     *
     * The lost-host E2e once failed on the warning TLS's read can raise for a
     * reset (`SSL: Connection reset by peer`), now suppressed at the read.
     * This reset does not raise it — that takes a timing window in OpenSSL
     * this test cannot force — so it pins the close, not the silence.
     */
    public function testAPeerThatResetsTheConnectionIsAClosedOne(): void
    {
        // The host end is accepted through the sockets extension so the test
        // keeps a Socket it can reset: PHP will not hand one back out of a
        // stream that TLS has wrapped.
        $listener = \socket_create_listen(0);
        $this->assertInstanceOf(\Socket::class, $listener);
        \socket_getsockname($listener, $address, $port);
        $guestStream = \stream_socket_client('tcp://127.0.0.1:'.$port, context: \stream_context_create(Certificate::clientContext()));
        $this->assertIsResource($guestStream);
        $hostSocket = \socket_accept($listener);
        $this->assertInstanceOf(\Socket::class, $hostSocket);
        $hostStream = \socket_export_stream($hostSocket);
        $this->assertIsResource($hostStream);
        \stream_context_set_options($hostStream, $this->certificate->serverContext());
        \stream_set_blocking($guestStream, enable: false);
        \stream_set_blocking($hostStream, enable: false);
        $shaken = [];
        Tls::handshake($hostStream, \STREAM_CRYPTO_METHOD_TLS_SERVER, static function (?string $why) use (&$shaken): void {
            $shaken[] = $why;
        });
        Tls::handshake($guestStream, \STREAM_CRYPTO_METHOD_TLS_CLIENT, static function (?string $why) use (&$shaken): void {
            $shaken[] = $why;
        });
        $this->await(static function () use (&$shaken): bool {
            return 2 === \count($shaken);
        }, what: 'both ends encrypted');
        $this->assertSame([null, null], $shaken);

        $guest = new Connection($guestStream);
        $closed = [];
        $guest->listen(static function (array $message): void {}, static function (string $why) use (&$closed): void {
            $closed[] = $why;
        });
        $guest->send(['t' => 'unanswered']);
        // Linger zero: the close is a reset, with no TLS goodbye before it.
        \socket_set_option($hostSocket, \SOL_SOCKET, \SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        \socket_close($hostSocket);
        \socket_close($listener);

        $this->await(static function () use (&$closed): bool {
            return [] !== $closed;
        }, what: 'the guest to see the connection go');
        $this->assertSame(['the connection was closed'], $closed);
    }

    public function testWhatIsNotAMessageIsSkippedAndNothingIsReadPastAClose(): void
    {
        [$guest, $host] = $this->pair();
        $heard = [];
        $host->listen(static function (array $message) use (&$heard, $host): void {
            $heard[] = $message['t'];
            if ('stop' === $message['t']) {
                $host->close('told to');
            }
        }, static function (): void {});

        $socket = Probe::get($guest, 'stream');
        $this->assertIsResource($socket);
        \fwrite($socket, data: "not json\n{\"t\":\"one\"}\n{\"t\":\"stop\"}\n{\"t\":\"unread\"}\n");
        $this->await($host->isClosed(...), what: 'the close');
        $this->assertSame(['one', 'stop'], $heard);
    }

    public function testALineTooLongClosesTheConnection(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $client = \stream_socket_client((string) \stream_socket_get_name($server, remote: false));
        $this->assertNotFalse($client);
        $accepted = \stream_socket_accept($server);
        $this->assertNotFalse($accepted);
        $connection = new Connection($accepted, maxLine: 16);
        $why = null;
        $connection->listen(static function (array $message): void {}, static function (string $reason) use (&$why): void {
            $why = $reason;
        });

        \fwrite($client, \str_repeat('x', times: 64));
        $this->await(static function () use (&$why): bool {
            return null !== $why;
        }, what: 'the close');
        $this->assertStringContainsString('past 16 bytes', (string) $why);
    }

    public function testALastMessageToAPeerThatStopsReadingNeverHoldsUpTheLoop(): void
    {
        [$connection, $client] = $this->loopback();
        $closed = [];
        $connection->listen(static function (array $message): void {}, static function (string $why) use (&$closed): void {
            $closed[] = $why;
        });

        $before = \hrtime(as_number: true);
        $connection->close('removed', ['t' => 'bye', 'pad' => \str_repeat('x', times: 4_000_000)]);

        $this->assertLessThan(0.5, (\hrtime(as_number: true) - $before) / 1e9, 'no blocking write');
        $this->assertSame(['removed'], $closed, 'the owner hears it at once');
        $this->assertIsResource(Probe::get($connection, 'stream'), 'the last message still has its chance');
        $this->pump(Connection::LINGER + 0.3);
        $this->assertIsClosedResource(Probe::get($connection, 'stream'), 'then the socket goes regardless');
        \fclose($client);
    }

    public function testALastMessageToAPeerThatReadsArrivesWholeBeforeTheSocketCloses(): void
    {
        // A socket pair, not loopback TCP. TCP's buffers autotune, so a fresh
        // loopback socket took anywhere from ~330 KB to all of 600 KB at once
        // (all of it 85 times in 200), and between two writes the kernel moves
        // more to the peer. Then the last message went out inside close(),
        // nothing was left to drain, and the drain this test is about never
        // ran. A Unix pair holds a fixed 8 KB here (~200 KB on Linux).
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$ours, $client] = $pair;
        $connection = new Connection($ours);
        $connection->listen(static function (array $message): void {}, static function (): void {});
        $pad = \str_repeat('x', times: 1_000_000);

        $connection->close('removed', ['t' => 'bye', 'pad' => $pad]);
        $this->assertGreaterThan(0, $connection->backlog(), 'the message is still queued, so it leaves by the drain');

        // Read on the loop, not between await turns: a turn is ~20 ms, and one
        // buffer's worth per turn would outlast the linger.
        \stream_set_blocking($client, enable: false);
        $received = '';
        $reader = EventLoop::onReadable($client, static function () use (&$received, $client): void {
            $received .= \fread($client, length: 1_048_576);
        });
        try {
            // By value is right here: the handle never changes, only its state.
            $this->await(static fn (): bool => \feof($client), timeout: Connection::LINGER * 0.75, what: 'the whole message, then the close — once it has gone, not when the linger is up');
        } finally {
            EventLoop::cancel($reader);
        }
        $this->assertSame(['t' => 'bye', 'pad' => $pad], \json_decode(\rtrim($received), associative: true, flags: \JSON_THROW_ON_ERROR));
        \fclose($client);
    }

    /**
     * A ping is the connection's own: stepped over, not the lines after it.
     * It arms the heartbeat, which pings back once — and once armed, arming
     * again sends nothing more.
     */
    public function testAPingIsSteppedOverAndArmsTheHeartbeatOnce(): void
    {
        [$connection, $client] = $this->loopback();
        $heard = [];
        $connection->listen(static function (array $message) use (&$heard): void {
            $heard[] = $message['t'];
        }, static function (): void {});

        \fwrite($client, data: "{\"t\":\"ping\"}\n{\"t\":\"after\"}\n");
        $this->await(static function () use (&$heard): bool {
            return [] !== $heard;
        }, what: 'the message after the ping');
        $connection->keepAlive();
        $connection->send(['t' => 'mark']);

        $this->assertSame(['after'], $heard);
        $said = '';
        $this->await(static function () use ($client, &$said): bool {
            $said .= \fread($client, length: 8_192);

            return \str_contains($said, 'mark');
        }, what: 'what the connection said');
        $this->assertSame("{\"t\":\"ping\"}\n{\"t\":\"mark\"}\n", $said, 'one ping back, however often it is armed');

        $connection->close('done');
        $this->assertIsClosedResource(Probe::get($connection, 'stream'), 'nothing queued: the socket goes at once');
        \fclose($client);
    }

    /** Waiting for a drain with nothing queued is no wait — and no second call when something drains later. */
    public function testAfterADrainIsCalledOnce(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$ours, $client] = $pair;
        $connection = new Connection($ours);
        $connection->listen(static function (array $message): void {}, static function (): void {});
        $calls = 0;

        $connection->afterDrain(static function () use (&$calls): void {
            ++$calls;
        });
        $this->assertSame(1, $calls, 'at once');

        // Past what the socket takes at once: a Linux socket pair swallows
        // 200 kB whole, and nothing was left to drain.
        $connection->send(['t' => 'big', 'pad' => \str_repeat('x', times: 2_097_152)]);
        $this->assertGreaterThan(0, $connection->backlog(), 'the premise: something is queued');
        \stream_set_blocking($client, enable: false);
        $this->await(static function () use ($client, $connection): bool {
            \fread($client, length: 1_048_576);

            return 0 === $connection->backlog();
        }, what: 'the drain');
        $this->pump(0.05);
        $this->assertSame(1, $calls, 'not again when something else drained');
        $connection->close('done');
        \fclose($client);
    }

    public function testAHandlerThatThrowsLosesNoLineReadWithIt(): void
    {
        [$connection, $client] = $this->loopback();
        $heard = [];
        $errors = [];
        $connection->listen(static function (array $message) use (&$heard): void {
            $heard[] = $message['t'];
            if ('one' === $message['t']) {
                throw new \RuntimeException('handler broke');
            }
        }, static function (): void {});
        EventLoop::setErrorHandler(static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error->getMessage();
        });
        try {
            \fwrite($client, data: "{\"t\":\"one\"}\n{\"t\":\"two\"}\n");
            $this->await(static function () use (&$heard, &$errors): bool {
                return 2 === \count($heard) && [] !== $errors;
            }, what: 'both lines and the error');
        } finally {
            EventLoop::setErrorHandler(null);
            $connection->close('done');
            \fclose($client);
        }
        $this->assertSame(['one', 'two'], $heard);
        $this->assertSame(['handler broke'], $errors);
    }

    public function testAPeerThatFallsSilentIsTakenForGone(): void
    {
        [$connection, $client] = $this->loopback();
        $why = null;
        $connection->listen(static function (array $message): void {}, static function (string $reason) use (&$why): void {
            $why = $reason;
        });

        $connection->keepAlive(every: 0.05, silence: 0.3);

        $pings = '';
        \stream_set_blocking($client, enable: false);
        $this->await(static function () use (&$why, &$pings, $client): bool {
            $pings .= \fread($client, length: 4096);

            return null !== $why;
        }, what: 'the silence to be noticed');
        $this->assertSame('no answer in 0 seconds', $why);
        $this->assertGreaterThanOrEqual(2, \substr_count($pings, needle: '{"t":"ping"}'), 'it asked, more than once');
        \fclose($client);
    }

    public function testAPeerThatPingsIsKeptAndKeepsUsAliveInTurn(): void
    {
        [$guest, $host] = $this->pair();
        $closed = [];
        $heard = [];
        foreach (['guest' => $guest, 'host' => $host] as $name => $end) {
            $end->listen(static function (array $message) use (&$heard): void {
                $heard[] = $message['t'];
            }, static function (string $why) use (&$closed, $name): void {
                $closed[] = $name.': '.$why;
            });
        }

        $host->keepAlive(every: 0.05, silence: 0.3);
        $guest->keepAlive(every: 0.05, silence: 0.3);
        $this->pump(0.6);

        $this->assertSame([], $closed, 'both alive: each hears the other');
        $this->assertSame([], $heard, 'pings are the connection\'s own, never a message');
        $guest->close('done');
        $this->await(static function () use (&$closed): bool {
            return 2 === \count($closed);
        }, what: 'both closed');
    }

    public function testTheFirstPingArmsTheOtherEnd(): void
    {
        [$guest, $host] = $this->pair();
        $guest->listen(static function (array $message): void {}, static function (): void {});
        $host->listen(static function (array $message): void {}, static function (): void {});
        $this->assertNull(Probe::get($host, 'heartbeat'));

        $guest->keepAlive();

        $this->await(static fn (): bool => null !== Probe::get($host, 'heartbeat'), what: 'the ping to arrive');
        $guest->close('done');
        $host->close('done');
    }

    public function testAWaitAtTheRelayIsMadeAgainBeforeItCanGoStale(): void
    {
        $relay = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($relay);
        \stream_set_blocking($relay, enable: false);
        $door = new Door(new SessionKey(\random_bytes(32)), (string) \stream_socket_get_name($relay, remote: false), $this->certificate, static function (): void {}, 1.0, rewait: 0.2);
        $asked = [];
        $held = [];
        $buffers = [];

        $door->open();
        $this->await(
            static function () use ($relay, &$asked, &$held, &$buffers): bool {
                // @mago-expect lint:no-error-control-operator -- nothing waiting to be accepted warns; false is the answer
                while (false !== ($socket = @\stream_socket_accept($relay, timeout: 0))) {
                    // Non-blocking: each wait is a fresh connection sending ONE
                    // line, and a blocking read of one already read waits for a
                    // second that never comes — on a runner slower to close it
                    // than this loop is to come back, until the time limit.
                    \stream_set_blocking($socket, enable: false);
                    $held[] = $socket;
                    $buffers[] = '';
                }
                foreach ($held as $i => $socket) {
                    $buffers[$i] .= \fread($socket, length: 8192);
                    while (false !== ($end = \strpos($buffers[$i], needle: "\n"))) {
                        $asked[] = \trim(\substr($buffers[$i], offset: 0, length: $end));
                        $buffers[$i] = \substr($buffers[$i], $end + 1);
                    }
                }

                return \count($asked) > \count(\array_unique($asked));
            },
            what: 'the same channel asked for again',
        );

        $door->close();
        \array_map(\fclose(...), $held);
        \fclose($relay);
        $this->assertStringStartsWith('please relay ', $asked[0]);
    }

    /** @return array{Connection, resource} a connection on plain loopback, and the other end, raw */
    private function loopback(): array
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $client = \stream_socket_client((string) \stream_socket_get_name($server, remote: false));
        $this->assertNotFalse($client);
        $accepted = \stream_socket_accept($server);
        $this->assertNotFalse($accepted);
        \fclose($server);

        return [new Connection($accepted), $client];
    }

    public function testEachConnectionTakesTheNextChannelAndHolesAreSteppedOver(): void
    {
        [$door, $invite] = $this->door();
        // Channels 0 and 1 taken by guest processes that died: 2 still gets through.
        $this->assertIsResource(self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 2, 5.0)));
        $this->assertIsString(self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 2, 1.5)), 'a channel joined is spent: not waited on again');
        // Past the window from there, nothing waits; within it, the host does.
        $this->assertSame('nobody came to the relay at '.$this->wormhole->transit, self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 20, 0.3)));
        $this->assertIsResource(self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 2 + Door::WINDOW, 5.0)));
        $this->await(fn (): bool => 2 === \count($this->accepted), what: 'both through');
        // Waits far behind the last channel joined are called off.
        $this->assertSame([3, 4, 5, 7, 8, 9, 10], \array_keys((array) Probe::get($door, 'waits')));

        $door->close();
        $door->close();
        // Closed: nobody waits — or a wait the relay has not seen go yet,
        // which joins and then says nothing.
        $this->assertIsString(self::blocking(static fn (): mixed => GuestRendezvous::connect($invite, 9, 0.3)), 'closed: nobody lets the guest in');
    }

    public function testOnlyTheHostTheInviteNamesIsTalkedTo(): void
    {
        [, $invite] = $this->door();
        $impostor = Certificate::create();
        $impostor->forget();

        $result = self::blocking(static fn (): mixed => GuestRendezvous::connect(new Invite($invite->key, $invite->transit, $impostor->fingerprint, $invite->token), 0, 5.0));

        $this->assertSame('the host is not the one the invite names', $result);
    }

    public function testAPeerThatDoesNotSpeakTlsIsLetGo(): void
    {
        // A guest that never shakes hands: the host lets it go.
        [, $invite] = $this->door(timeout: 0.2);
        $plain = self::blocking(static fn (): mixed => Transit::at($invite->transit, 2.0)->pair($invite->key->channel(0), 'ab', 2.0));
        $this->assertIsResource($plain);
        $this->await(static fn (): bool => '' === \fread($plain, length: 10) && \feof($plain), what: 'the silent guest let go');
        $this->assertSame([], $this->accepted);

        // A host that answers in the clear, and one that never answers.
        $key = new SessionKey(\random_bytes(32));
        $invite = new Invite($key, $this->wormhole->transit, $this->certificate->fingerprint, \str_repeat('ab', times: 16));
        foreach (["HTTP/1.0 400 nope\r\n\r\n" => 'the secure connection failed', '' => 'the handshake took too long'] as $answer => $says) {
            $host = null;
            $transit = Transit::at($this->wormhole->transit, 2.0);
            EventLoop::queue(static function () use ($transit, $key, &$host): void {
                $host = $transit->pair($key->channel(0), 'cd', null);
            });
            $stream = self::blocking(static fn (): mixed => GuestRendezvous::pair($invite, 0, 2.0));
            $this->assertIsResource($stream);
            $this->await(static function () use (&$host): bool {
                return \is_resource($host);
            }, what: 'the host joined');
            \fwrite($host, $answer);
            $this->assertSame($says, self::blocking(static fn (): mixed => GuestRendezvous::secure($invite, $stream, 0.3)));
            \fclose($host);
        }
    }

    public function testAConnectionThatCannotBeMadeSaysWhy(): void
    {
        // Port 1 on loopback: nothing listens there, and nothing unprivileged can
        // start to — a port closed by this test could be taken by a parallel one.
        $this->assertSame('127.0.0.1:1 refused the connection', self::blocking(static fn (): mixed => Tcp::connect('127.0.0.1', 1, 2.0)));
        // Over DNS's 63-character label: no resolver is asked, so none can answer it (see ResolverTest).
        $nobody = \str_repeat('x', times: 64).'.invalid';
        $this->assertStringStartsWith("cannot reach {$nobody}:1", (string) self::blocking(static fn (): mixed => Tcp::connect($nobody, 1, 2.0)));
        $this->assertSame('10.255.255.1:9 did not answer', self::blocking(static fn (): mixed => Tcp::connect('10.255.255.1', 9, 0.2)));
        $this->assertStringStartsWith('::1:1 ', (string) self::blocking(static fn (): mixed => Tcp::connect('::1', 1, 2.0)), 'IPv6, spelled in brackets to connect');
    }

    public function testARelayThatCannotBeReachedIsWaitedAtAgainUntilTheDoorCloses(): void
    {
        $door = new Door(new SessionKey(\random_bytes(32)), '127.0.0.1:1', $this->certificate, static function (): void {}, 0.5);
        $door->open();
        $this->pump(0.2);
        $this->assertSame(Door::WINDOW - 1, Probe::get($door, 'last'), 'refused: not further along');
        $this->assertSame([], Probe::get($door, 'waits'), 'each pausing before it waits again');
        $door->close();
        $this->assertSame([], Probe::get($door, 'waits'));
    }

    public function testAWaitCalledOffOrHungUpOnSaysSo(): void
    {
        // Called off before it even connected.
        $transit = Transit::at($this->wormhole->transit, 2.0);
        $result = null;
        EventLoop::queue(static function () use ($transit, &$result): void {
            $result = $transit->pair('ab', 'cd', null);
        });
        $transit->cancel();
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the wait over');
        $this->assertSame('called off', $result);

        // A relay that hangs up before joining, and one that answers a byte at a time.
        $relay = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($relay);
        $address = (string) \stream_socket_get_name($relay, remote: false);
        $answers = [
            'the relay at '.$address.' hung up' => static function ($relay): void {
                \fclose($relay);
            },
            'joined, said in two pieces' => function ($relay): void {
                \fwrite($relay, data: 'o');
                $this->pump(0.05);
                \fwrite($relay, data: "k\n");
            },
        ];
        foreach ($answers as $says => $answer) {
            $result = null;
            $transit = Transit::at($address, 2.0);
            EventLoop::queue(static function () use ($transit, &$result): void {
                $result = $transit->pair('ab', 'cd', 2.0);
            });
            $accepted = null;
            $this->await(
                static function () use ($relay, &$accepted): bool {
                    // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns
                    $stream = @\stream_socket_accept($relay, timeout: 0);
                    $accepted = false === $stream ? null : $stream;

                    return null !== $accepted;
                },
                what: 'the relay asked',
            );
            \assert(\is_resource($accepted), description: 'the await saw it');
            $this->assertSame("please relay ab for side cd\n", \fgets($accepted));
            $answer($accepted);
            $this->await(static function () use (&$result): bool {
                return null !== $result;
            }, what: 'the relay\'s answer');
            \is_resource($result) ? $this->assertStringStartsWith('joined', $says) : $this->assertSame($says, $result);
        }
    }

    public function testTheKeyGoesWithTheSession(): void
    {
        $certificate = Certificate::create();
        $this->assertSame(0o600, \fileperms($certificate->file) & 0o777, 'the key is this user\'s only');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $certificate->fingerprint);
        $pem = (string) \file_get_contents($certificate->file);
        $parsed = \openssl_x509_parse($pem);
        $this->assertIsArray($parsed);
        $this->assertSame('porta-assist', $parsed['subject']['CN'] ?? null);
        $this->assertSame(30 * 86_400, $parsed['validTo_time_t'] - $parsed['validFrom_time_t'], 'good for a month');
        $this->assertSame('ecdsa-with-SHA256', $parsed['signatureTypeSN']);
        $key = \openssl_pkey_get_private($pem);
        $this->assertNotFalse($key);
        $details = \openssl_pkey_get_details($key);
        $this->assertSame([\OPENSSL_KEYTYPE_EC, 'prime256v1'], [$details['type'] ?? null, $details['ec']['curve_name'] ?? null], 'a P-256 key: quick to make, quick to shake hands with');
        $certificate->forget();
        $certificate->forget();
        $this->assertFileDoesNotExist($certificate->file);
    }
}
