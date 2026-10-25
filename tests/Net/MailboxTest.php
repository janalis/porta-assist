<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Frames;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\HostRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\Mailbox;
use Porta\AssistPlugin\Net\Rendezvous\RendezvousFailed;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Net\Rendezvous\Spake2;
use Porta\AssistPlugin\Net\Rendezvous\Transit;
use Porta\AssistPlugin\Net\Rendezvous\WebSocket;
use Porta\AssistPlugin\Tests\Support\FakeWormhole;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

/**
 * The mailbox server's side of meeting, as a client sees it: a WebSocket
 * that answers pings and notices a close, a server that refuses, says
 * nonsense or hangs up — each said for a person, never thrown past them.
 */
final class MailboxTest extends TestCase
{
    use PumpsLoop;

    /** @var resource|null a server played by the test */
    private $server;

    /** @var resource|null the client's connection to it */
    private $client;

    /** A server played by the test: its ws:// address. */
    private function serve(): string
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        \stream_set_blocking($server, enable: false);
        $this->server = $server;

        return 'ws://'.\stream_socket_get_name($server, remote: false).'/v1?x=1';
    }

    /**
     * Take the client's connection, and its request — answered with $answer,
     * or as a WebSocket server would under $status: its first $firstWrite
     * bytes on their own, when that is more than none.
     *
     * @return resource the connection, also kept for {@see heard()}
     */
    private function answer(?string $answer = null, string $status = 'HTTP/1.1 101 Switching Protocols', int $firstWrite = 0)
    {
        $server = $this->server;
        $this->assertIsResource($server);
        $client = null;
        $this->await(
            static function () use ($server, &$client): bool {
                // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns
                $accepted = @\stream_socket_accept($server, timeout: 0);
                $client = false === $accepted ? null : $accepted;

                return null !== $client;
            },
            what: 'the client',
        );
        \assert(\is_resource($client), description: 'the await saw it');
        \stream_set_blocking($client, enable: false);
        $request = '';
        $this->await(static function () use ($client, &$request): bool {
            $request .= \fread($client, length: 8_192);

            return \str_contains($request, "\r\n\r\n");
        }, what: 'the request');
        $this->assertStringStartsWith("GET /v1?x=1 HTTP/1.1\r\n", $request);
        $key = [];
        \preg_match('/^sec-websocket-key:\s*(\S+)/im', $request, $key);
        $this->assertSame(16, \strlen((string) \base64_decode($key[1] ?? '', strict: true)), 'the key is sixteen random bytes, as a strict server checks');
        $reply = $answer ?? $status."\r\nSec-WebSocket-Accept: ".\base64_encode(\sha1(($key[1] ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', binary: true))."\r\n\r\n";
        if ($firstWrite > 0) {
            \fwrite($client, \substr($reply, offset: 0, length: $firstWrite));
            $this->pump(0.05); // the client reads it on its own
            $reply = \substr($reply, $firstWrite);
        }
        \fwrite($client, $reply);
        $this->client = $client;

        return $client;
    }

    /** @return list<array{int, string}> what the client sent, once $count frames have come */
    private function heard(int $count): array
    {
        $client = $this->client;
        $this->assertIsResource($client);
        $frames = new Frames(static function (): void {});
        $heard = [];
        $this->await(static function () use ($client, $frames, $count, &$heard): bool {
            $heard = [...$heard, ...$frames->feed((string) \fread($client, length: 8_192))];

            return \count($heard) >= $count;
        }, what: $count.' frames');

        return $heard;
    }

    /**
     * Start $work in a fiber of its own — it waits on the loop while the
     * test plays the server — and get what it returned, or threw.
     *
     * @return \Closure(): mixed what it ended in, once it has
     */
    private function start(\Closure $work): \Closure
    {
        $done = false;
        $result = null;
        EventLoop::queue(static function () use ($work, &$done, &$result): void {
            try {
                $result = $work();
            } catch (\Throwable $e) {
                $result = $e;
            }
            $done = true;
        });

        return function () use (&$done, &$result): mixed {
            $this->await(static function () use (&$done): bool {
                return $done;
            }, what: 'the client done');

            return $result;
        };
    }

    public function testAWebSocketShakesHandsAnswersPingsAndNoticesTheClose(): void
    {
        $this->assertSame('"http://x" is not a ws:// address', GuestRendezvous::blocking(static fn (): mixed => WebSocket::connect('http://x', 1.0)));
        $url = $this->serve();
        foreach (["HTTP/1.1 200 OK\r\n\r\n", "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: wrong\r\n\r\n"] as $answer) {
            $result = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
            $this->answer($answer);
            $this->assertSame($url.' is not a WebSocket server', $result());
        }
        $result = $this->start(static fn (): mixed => WebSocket::connect($url, 0.3));
        $this->answer("HTTP/1.1 101 Switching Protocols\r\n");
        $this->assertSame($url.' is not a WebSocket server', $result(), 'a handshake never finished');
        $result = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer("HTTP/1.1 101 Switching Protocols\r\n");
        $this->assertIsResource($this->client);
        \fclose($this->client);
        $this->assertSame($url.' is not a WebSocket server', $result(), 'hung up on, halfway');

        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer();
        $client = $this->client;
        $this->assertIsResource($client);
        \fwrite($client, Frames::encode(Frames::TEXT, 'early'));
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket);
        \fwrite($client, Frames::encode(Frames::PING, 'are you there').Frames::encode(Frames::PONG, 'unasked').Frames::encode(Frames::TEXT, 'one').Frames::encode(Frames::TEXT, 'two'));
        $this->assertSame('early', $this->start($socket->receive(...))());
        $this->assertSame('one', $this->start(static fn (): mixed => $socket->receive(1.0))());
        $this->assertSame('two', $this->start(static fn (): mixed => $socket->receive(1.0))());
        $this->assertNull($this->start(static fn (): mixed => $socket->receive(0.05))(), 'nothing more: the wait ends');
        $socket->send('hello');
        $socket->ping();
        $socket->send('after');
        $this->assertSame(
            [
                [Frames::PONG, 'are you there'],
                [Frames::TEXT, 'hello'],
                [Frames::PING, ''],
                [Frames::TEXT, 'after'],
            ],
            $this->heard(4),
            'the ping\'s mask is four bytes: what follows it reads whole',
        );

        $waiting = $this->start($socket->receive(...));
        \fwrite($client, Frames::encode(Frames::CLOSE, ''));
        $this->assertNull($waiting(), 'a fiber waiting is let go');
        $this->assertSame('the server closed the socket', $socket->closed());
        $this->assertNull($this->start($socket->receive(...))());
        $socket->close();

        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer();
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket);
        \fclose($this->client);
        $this->assertNull($this->start($socket->receive(...))());
        $this->assertSame('the server hung up', $socket->closed());

        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer();
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket);
        $socket->close();
        $this->assertSame([[Frames::CLOSE, \pack('n', 1000)]], $this->heard(1), 'a goodbye said');
        $this->assertSame('closed', $socket->closed());
    }

    /**
     * The status line is the server's first, and says 101: a 200 that only
     * mentions 101 further down is no WebSocket, however right its accept.
     */
    public function testOnlyASwitchingStatusLineOpensTheSocket(): void
    {
        $url = $this->serve();
        $result = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer(status: "HTTP/1.1 200 OK\r\nVia: HTTP/1.1 101 relay");
        $this->assertSame($url.' is not a WebSocket server', $result());

        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer(firstWrite: 20); // mid status line
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket, 'an answer that comes in two reads is put together');
        $socket->close();
    }

    /** A message the server sent just before it hung up is still received, and then the hang-up. */
    public function testTheLastMessageBeforeAHangUpIsStillReceived(): void
    {
        $url = $this->serve();
        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $client = $this->answer();
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket);

        \fwrite($client, Frames::encode(Frames::TEXT, 'last'));
        \fclose($client);
        $this->await(static fn (): bool => null !== $socket->closed(), what: 'the hang-up');

        $this->assertSame('last', $this->start($socket->receive(...))());
        $this->assertSame('the server hung up', $socket->closed());
    }

    public function testAServerThatStopsAnsweringPingsIsTakenForGone(): void
    {
        $url = $this->serve();
        $socket = $this->start(static fn (): mixed => WebSocket::connect($url, 2.0));
        $this->answer();
        $socket = $socket();
        $this->assertInstanceOf(WebSocket::class, $socket);
        $client = $this->client;
        $this->assertIsResource($client);

        $socket->ping();
        \fwrite($client, Frames::encode(Frames::PONG, ''));
        $this->start(static fn (): mixed => $socket->receive(0.05))();
        $socket->ping();
        $socket->ping();
        $this->assertNull($socket->closed(), 'an answer resets the count');

        $socket->ping();

        $this->assertSame('the server stopped answering', $socket->closed());
    }

    public function testAMailboxServerThatRefusesSaysNonsenseOrHangsUpIsSaid(): void
    {
        $refusing = new FakeWormhole(['FAKE_WORMHOLE_REFUSE' => "too old \x1b[31m"]);
        $this->assertSame('the mailbox server says: too old [31m', $this->failure(static fn (): Mailbox => Mailbox::connect($refusing->mailbox, 'ab', 2.0)));
        $this->assertSame('127.0.0.1:1 refused the connection', $this->failure(static fn (): Mailbox => Mailbox::connect('ws://127.0.0.1:1/v1', 'ab', 2.0)));

        $url = $this->serve();
        $result = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 0.3));
        $client = $this->answer();
        \fwrite($client, Frames::encode(Frames::TEXT, 'not json').Frames::encode(Frames::TEXT, '{"type": 3}'));
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server sent no welcome', $failure->getMessage(), 'what is not a message skipped');
        $this->assertSame([[Frames::CLOSE, \pack('n', 1000)]], $this->heard(1), 'and the socket let go');

        $result = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 2.0));
        \fwrite($this->answer(), Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {"error": "go away"}}'));
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server says: go away', $failure->getMessage());
        $this->assertSame([[Frames::CLOSE, \pack('n', 1000)]], $this->heard(1), 'refused: the socket let go');

        $result = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 2.0));
        \fclose($this->answer());
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server hung up (the server hung up)', $failure->getMessage());

        // A claim the server refuses for its own reason: passed on.
        $result = $this->start(static fn (): mixed => GuestRendezvous::redeem($url, Code::parse('5-AAAA-AAAA'), 2.0));
        $client = $this->answer();
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $this->heard(2);
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "error", "error": "nope"}'));
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server says: nope', $failure->getMessage());
        $this->assertSame([[Frames::CLOSE, \pack('n', 1000)]], $this->heard(1), 'the guest hangs up on a claim refused');
    }

    /** Leaving lets the nameplate and the mailbox go, says how it went, and hangs up — in that order. */
    public function testLeavingReleasesClosesAndHangsUp(): void
    {
        $url = $this->serve();
        $mailbox = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 2.0));
        $client = $this->answer();
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $mailbox = $mailbox();
        $this->assertInstanceOf(Mailbox::class, $mailbox);
        $this->heard(1);
        $claimed = $this->start(static fn (): mixed => $mailbox->claim('4'));
        $this->assertSame('claim', self::said($this->heard(1)[0])['type']);
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "claimed", "mailbox": "mb"}'));
        $claimed();
        $this->assertSame('open', self::said($this->heard(1)[0])['type']);

        $mailbox->leave('happy');

        $heard = $this->heard(3);
        $this->assertSame(['type' => 'release'], \array_diff_key(self::said($heard[0]), ['id' => 0]));
        $this->assertSame(['type' => 'close', 'mailbox' => 'mb', 'mood' => 'happy'], \array_diff_key(self::said($heard[1]), ['id' => 0]));
        $this->assertSame([Frames::CLOSE, \pack('n', 1000)], $heard[2]);
    }

    /** What the other side left under a phase is its first word there: a second under the same phase is not taken for it. */
    public function testTheFirstMessageUnderAPhaseIsTheOneKept(): void
    {
        $url = $this->serve();
        $mailbox = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 2.0));
        $client = $this->answer();
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $mailbox = $mailbox();
        $this->assertInstanceOf(Mailbox::class, $mailbox);
        \fwrite(
            $client,
            Frames::encode(Frames::TEXT, '{"type": "message", "side": "cd", "phase": "pake", "body": "aa"}')
                .Frames::encode(Frames::TEXT, '{"type": "message", "side": "cd", "phase": "pake", "body": "bb"}')
                .Frames::encode(Frames::TEXT, '{"type": "message", "side": "cd", "phase": "x", "body": "cc"}'),
        );

        $this->assertSame("\xcc", $this->start(static fn (): mixed => $mailbox->receive('x', 2.0))());
        $this->assertSame("\xaa", $mailbox->receive('pake', 0.0));
        $mailbox->hangUp();
    }

    /**
     * @param array{int, string} $frame
     *
     * @return array<string, mixed>
     */
    private static function said(array $frame): array
    {
        $said = \json_decode($frame[1], associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($said);

        /** @var array<string, mixed> $said */
        return $said;
    }

    public function testACodeTwoOthersHoldIsSaidToBeUsed(): void
    {
        $wormhole = new FakeWormhole();
        $claims = GuestRendezvous::blocking(static function () use ($wormhole): array {
            $first = Mailbox::connect($wormhole->mailbox, 'aa', 2.0);
            $first->claim('5');
            $second = Mailbox::connect($wormhole->mailbox, 'bb', 2.0);
            $second->claim('5');

            return [$first, $second];
        });
        $this->assertSame('That code was used already — ask the host for a new one', $this->failure(static fn (): Invite => GuestRendezvous::redeem(
            $wormhole->mailbox,
            Code::parse('5-AAAA-AAAA'),
            2.0,
        )));
        foreach ($claims as $claim) {
            $claim->hangUp();
        }

        $nowhere = new Invite(new SessionKey(\random_bytes(32)), 'nowhere', \str_repeat('0', times: 64), \str_repeat('ab', times: 16));
        $this->assertSame('the relay "nowhere" is not host:port', GuestRendezvous::blocking(static fn (): mixed => GuestRendezvous::connect($nowhere, 0, 1.0)));
    }

    public function testAHostStoppedBeforeTheServerAnsweredHandsOutNothing(): void
    {
        $url = $this->serve();
        $codes = [];
        $certificate = Certificate::create();
        $host = new HostRendezvous(
            $url,
            Transit::PUBLIC,
            $certificate,
            \str_repeat('ab', times: 16),
            static function (Code $code) use (&$codes): void {
                $codes[] = $code;
            },
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        $host->start();
        $client = $this->answer();
        $host->stop();
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $this->assertSame(Frames::CLOSE, $this->heard(2)[1][0] ?? null, 'bound, then hung up on');
        $this->pump(0.1);
        $this->assertSame([], $codes);
        $this->assertNull($host->code());
        $certificate->forget();
    }

    public function testAGuestThatNeverSaysWhetherTheCodeWasRightSpendsIt(): void
    {
        $wormhole = new FakeWormhole();
        $certificate = Certificate::create();
        $codes = [];
        $host = new HostRendezvous(
            $wormhole->mailbox,
            $wormhole->transit,
            $certificate,
            \str_repeat('ab', times: 16),
            static function (Code $code, string $why) use (&$codes): void {
                $codes[] = $why;
            },
            static function (): void {},
            static function (): void {},
            static function (): void {},
            timeout: 0.2,
        );
        $host->start();
        $this->await(static function () use (&$codes): bool {
            return [] !== $codes;
        }, what: 'the first code');
        $code = $host->code();
        $this->assertInstanceOf(Code::class, $code);
        GuestRendezvous::blocking(static function () use ($wormhole, $code): void {
            $guest = Mailbox::connect($wormhole->mailbox, 'cd', 2.0);
            $guest->claim($code->nameplate);
            $guest->add('pake', new Spake2($code->text(), host: false)->message);
            $guest->receive('invite', 2.0);
            $guest->hangUp();
        });
        $this->await(static function () use (&$codes): bool {
            return ['', 'wrong'] === $codes;
        }, what: 'the next code');
        $host->stop();
        $certificate->forget();
    }

    /** Stopped while its code waits: no code to hand out, the socket let go, and that is no loss to report. */
    public function testAHostStoppedWhileItsCodeWaitsLetsTheMailboxGo(): void
    {
        $url = $this->serve();
        $certificate = Certificate::create();
        $lost = [];
        $host = new HostRendezvous(
            $url,
            Transit::PUBLIC,
            $certificate,
            \str_repeat('ab', times: 16),
            static function (): void {},
            static function (): void {},
            static function (string $why) use (&$lost): void {
                $lost[] = $why;
            },
            static function (): void {},
        );
        $host->start();
        $client = $this->answer();
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $this->assertSame('allocate', self::said($this->heard(2)[1])['type']);
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "allocated", "nameplate": "3"}'));
        $this->assertSame('claim', self::said($this->heard(1)[0])['type']);
        \fwrite($client, Frames::encode(Frames::TEXT, '{"type": "claimed", "mailbox": "mb"}'));
        $this->heard(2); // open, and the pake added
        $this->await(static fn (): bool => null !== $host->code(), what: 'the code');

        $host->stop();

        $this->assertNull($host->code());
        $this->assertSame([[Frames::CLOSE, \pack('n', 1000)]], $this->heard(1));
        $this->pump(0.1);
        $this->assertSame([], $lost, 'a stop is not a lost server');
        $certificate->forget();
    }

    /**
     * A wrong code, and a code nobody waits with: each side lets the
     * nameplate go, so the server hands it out again, and two others can
     * hold it.
     */
    public function testEachSideLetsTheNameplateGo(): void
    {
        $wormhole = new FakeWormhole();
        $certificate = Certificate::create();
        $codes = [];
        $host = new HostRendezvous(
            $wormhole->mailbox,
            $wormhole->transit,
            $certificate,
            \str_repeat('ab', times: 16),
            static function (Code $code, string $why) use (&$codes): void {
                $codes[] = [$code->nameplate, $why];
            },
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        $host->start();
        $this->await(static function () use (&$codes): bool {
            return [] !== $codes;
        }, what: 'the first code');
        $first = $codes[0][0];
        $this->assertSame('That code is not right — check it with the host', $this->failure(static fn (): Invite => GuestRendezvous::redeem(
            $wormhole->mailbox,
            Code::parse($first.'-AAAA-AAAA'),
            2.0,
        )));
        $this->await(static function () use (&$codes): bool {
            return 2 === \count($codes);
        }, what: 'the next code');
        $this->assertSame([$first, 'wrong'], $codes[1], 'both let it go: the server hands out the same nameplate');
        $host->stop();
        $certificate->forget();

        $this->assertSame('No host is waiting with that code — check it, or ask the host for a new one', $this->failure(static fn (): Invite => GuestRendezvous::redeem(
            $wormhole->mailbox,
            Code::parse('7-AAAA-AAAA'),
            0.1,
        )));
        $held = GuestRendezvous::blocking(static function () use ($wormhole): int {
            $held = 0;
            foreach (['aa', 'bb'] as $side) {
                $other = Mailbox::connect($wormhole->mailbox, $side, 2.0);
                $other->claim('7'); // a third side would be told "crowded"
                $other->hangUp();
                ++$held;
            }

            return $held;
        });
        $this->assertSame(2, $held, 'two others hold it: the lonely guest let it go');
    }

    /** What $work failed with, waited for on the loop. */
    private function failure(\Closure $work): string
    {
        try {
            GuestRendezvous::blocking($work);
        } catch (RendezvousFailed $e) {
            return $e->getMessage();
        }
        $this->fail('it fails');
    }
}
