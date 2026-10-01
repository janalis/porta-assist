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

    /** Take the client's connection, and its request — answered with $answer, or as a WebSocket server would. */
    private function answer(?string $answer = null): void
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
            $request .= (string) \fread($client, length: 8_192);

            return \str_contains($request, "\r\n\r\n");
        }, what: 'the request');
        $this->assertStringStartsWith("GET /v1?x=1 HTTP/1.1\r\n", $request);
        $key = [];
        \preg_match('/^sec-websocket-key:\s*(\S+)/im', $request, $key);
        \fwrite(
            $client,
            $answer ?? "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: ".\base64_encode(\sha1(($key[1] ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', binary: true))."\r\n\r\n",
        );
        $this->client = $client;
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
        $this->assertSame(
            [
                [Frames::PONG, 'are you there'],
                [Frames::TEXT, 'hello'],
                [Frames::PING, ''],
            ],
            $this->heard(3),
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

    public function testAMailboxServerThatRefusesSaysNonsenseOrHangsUpIsSaid(): void
    {
        $refusing = new FakeWormhole(['FAKE_WORMHOLE_REFUSE' => "too old \x1b[31m"]);
        $this->assertSame('the mailbox server says: too old [31m', $this->failure(static fn (): Mailbox => Mailbox::connect($refusing->mailbox, 'ab', 2.0)));
        $this->assertSame('127.0.0.1:1 refused the connection', $this->failure(static fn (): Mailbox => Mailbox::connect('ws://127.0.0.1:1/v1', 'ab', 2.0)));

        $url = $this->serve();
        $result = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 0.3));
        $this->answer();
        \fwrite($this->client, Frames::encode(Frames::TEXT, 'not json').Frames::encode(Frames::TEXT, '{"type": 3}'));
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server sent no welcome', $failure->getMessage(), 'what is not a message skipped');

        $result = $this->start(static fn (): mixed => Mailbox::connect($url, 'ab', 2.0));
        $this->answer();
        \fclose($this->client);
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server hung up (the server hung up)', $failure->getMessage());

        // A claim the server refuses for its own reason: passed on.
        $result = $this->start(static fn (): mixed => GuestRendezvous::redeem($url, Code::parse('5-AAAA-AAAA'), 2.0));
        $this->answer();
        \fwrite($this->client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
        $this->heard(2);
        \fwrite($this->client, Frames::encode(Frames::TEXT, '{"type": "error", "error": "nope"}'));
        $failure = $result();
        $this->assertInstanceOf(RendezvousFailed::class, $failure);
        $this->assertSame('the mailbox server says: nope', $failure->getMessage());
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
        $this->answer();
        $host->stop();
        \fwrite($this->client, Frames::encode(Frames::TEXT, '{"type": "welcome", "welcome": {}}'));
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
