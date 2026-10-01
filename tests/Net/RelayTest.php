<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Dialer;
use Porta\AssistPlugin\Net\Relay;
use Porta\AssistPlugin\Net\RelayHandshake;
use Porta\AssistPlugin\Net\RelayListener;
use Porta\Tests\Support\PumpsLoop;

/**
 * A host and its guests who cannot reach one another, joined by a relay
 * both can: what it pairs, what it turns away — and that the session's TLS
 * runs through it end to end, so the guest still checks the host's
 * certificate against the invite.
 */
final class RelayTest extends TestCase
{
    use PumpsLoop;

    private Certificate $certificate;

    /** @var list<Connection> */
    private array $accepted = [];

    /** @var list<string> */
    private array $lost = [];

    protected function setUp(): void
    {
        $this->certificate = Certificate::create();
    }

    protected function tearDown(): void
    {
        $this->certificate->forget();
    }

    /** @return array{Relay, int} */
    private function relay(float $waitForHost = 30.0): array
    {
        $relay = new Relay($waitForHost);
        $port = $relay->start('127.0.0.1', 0);
        $this->assertIsInt($port);

        return [$relay, $port];
    }

    private function host(): RelayListener
    {
        return new RelayListener(
            function (Connection $connection): void {
                $this->accepted[] = $connection;
            },
            function (string $why): void {
                $this->lost[] = $why;
            },
            timeout: 2.0,
        );
    }

    /** Start $host on the relay at $port: what it was told. */
    private function join(RelayListener $host, int $port): ?string
    {
        $said = false;
        $host->start('127.0.0.1', $port, $this->certificate, static function (?string $failed) use (&$said): void {
            $said = $failed;
        });
        $this->await(static function () use (&$said): bool {
            return false !== $said;
        }, what: 'the relay to answer the host');
        \assert(null === $said || \is_string($said), description: 'the await saw it answered');

        return $said;
    }

    /** What a guest dialling the relay at $port for $session ended in. */
    private function dial(int $port, string $session, ?string $fingerprint = null): Connection|string
    {
        $result = null;
        new Dialer(timeout: 2.0)->dial(
            '127.0.0.1',
            $port,
            $fingerprint ?? $this->certificate->fingerprint,
            static function (Connection|string $done) use (&$result): void {
                $result = $done;
            },
            $session,
        );
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the dial to end');
        \assert(null !== $result, description: 'the await saw it end');

        return $result;
    }

    /**
     * A plain connection to the relay at $port that said $hello: the line
     * it was answered with.
     *
     * @return array{resource, array<array-key, mixed>}
     */
    private function say(int $port, string $hello): array
    {
        $socket = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($socket);
        \stream_set_blocking($socket, enable: false);
        \fwrite($socket, $hello);

        return [$socket, $this->line($socket)];
    }

    /**
     * @param resource $socket
     *
     * @return array<array-key, mixed>
     */
    private function line($socket): array
    {
        $line = '';
        $this->await(static function () use ($socket, &$line): bool {
            $line .= (string) \fread($socket, length: 4_096);

            return \str_contains($line, "\n");
        }, what: 'a line from the relay');

        return RelayHandshake::decode($line);
    }

    /** @param resource $socket */
    private function awaitClosed($socket, string $what, float $timeout = 10.0): void
    {
        $this->await(static fn (): bool => '' === \fread($socket, length: 4_096) && \feof($socket), $timeout, $what);
    }

    public function testAGuestReachesTheHostThroughTheRelayEncryptedEndToEnd(): void
    {
        [$relay, $port] = $this->relay();
        $host = $this->host();
        $this->assertNull($this->join($host, $port));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $host->session());

        $guest = $this->dial($port, $host->session());
        $this->assertInstanceOf(Connection::class, $guest);
        $this->await(fn (): bool => [] !== $this->accepted, what: 'the host to be handed the guest');
        $accepted = \array_shift($this->accepted);

        $heard = [];
        $closed = null;
        $accepted->listen(static function (array $message) use (&$heard): void {
            $heard[] = $message;
        }, static function (string $why) use (&$closed): void {
            $closed = $why;
        });
        $answers = [];
        $guest->listen(static function (array $message) use (&$answers): void {
            $answers[] = $message;
        }, static function (): void {});
        $guest->send(['t' => 'hello', 'name' => 'Ana']);
        $accepted->send(['t' => 'pending']);
        $this->await(static function () use (&$heard, &$answers): bool {
            return [] !== $heard && [] !== $answers;
        }, what: 'a line each way');
        $this->assertSame([['t' => 'hello', 'name' => 'Ana']], $heard);
        $this->assertSame([['t' => 'pending']], $answers);

        // One end gone, the other goes with it.
        $guest->close('done');
        $this->await(static function () use (&$closed): bool {
            return null !== $closed;
        }, what: 'the host to see the guest go');

        // End to end: a guest expecting another certificate refuses this host.
        $impostor = Certificate::create();
        $this->assertSame('127.0.0.1:'.$port.': the host is not the one the invite names', $this->dial($port, $host->session(), $impostor->fingerprint));
        $impostor->forget();

        // The relay gone: the host is told, once.
        $relay->stop();
        $this->await(fn (): bool => [] !== $this->lost, what: 'the host to hear the relay is gone');
        $this->assertSame(['the relay at 127.0.0.1:'.$port.' hung up'], $this->lost);
        $host->stop();
    }

    public function testTheRelayTurnsAwayWhatItCannotJoin(): void
    {
        [$relay, $port] = $this->relay(waitForHost: 0.2);
        $this->assertSame('127.0.0.1:'.$port.': the relay says no such session', $this->dial($port, \str_repeat('0', times: 16)));

        [$socket, $answer] = $this->say($port, "GET / HTTP/1.0\n");
        $this->assertSame(['error' => 'not a relay request'], $answer);
        $this->awaitClosed($socket, 'the stranger let go');
        [$socket, $answer] = $this->say($port, \str_repeat('x', times: 2_000));
        $this->assertSame(['error' => 'say what you are first'], $answer);

        // A host, and what may — and may not — claim to answer for it.
        [$control, $session] = $this->say($port, "{\"relay\":\"host\"}\n");
        $session = (string) $session['session'];
        [, $answer] = $this->say($port, \sprintf("{\"relay\":\"accept\",\"session\":\"%s\",\"conn\":99}\n", $session));
        $this->assertSame(['error' => 'no such guest'], $answer);
        [, $answer] = $this->say($port, \sprintf("{\"relay\":\"accept\",\"session\":\"%s\",\"conn\":3}\n", $session));
        $this->assertSame(['error' => 'no such guest'], $answer, 'the host\'s own connection is no guest to join');

        // A guest the host never answers is turned away once it has waited.
        [$guest, $answer] = $this->say($port, \sprintf("{\"relay\":\"guest\",\"session\":\"%s\"}\n", $session));
        $this->assertSame(['error' => 'the host did not answer'], $answer);
        $this->assertSame('incoming', \array_key_first($this->line($control)));

        // A guest that gives up first is no longer there to join.
        $guest = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($guest);
        \fwrite($guest, \sprintf("{\"relay\":\"guest\",\"session\":\"%s\"}\n", $session));
        $incoming = $this->line($control);
        \fclose($guest);
        [, $answer] = $this->say($port, \sprintf("{\"relay\":\"accept\",\"session\":\"%s\",\"conn\":%d}\n", $session, (int) $incoming['incoming']));
        $this->assertSame(['error' => 'no such guest'], $answer);

        // The host gone: its session goes with it.
        \fclose($control);
        $this->assertSame('127.0.0.1:'.$port.': the relay says no such session', $this->dial($port, $session));
        $relay->stop();
    }

    public function testOnlyItsOwnHostMayAnswerAGuestAndOnlyOnce(): void
    {
        [$relay, $port] = $this->relay();
        [$first, $answer] = $this->say($port, "{\"relay\":\"host\"}\n");
        $session = (string) $answer['session'];
        // Kept: a host whose connection is gone has no session to claim with.
        [$second, $answer] = $this->say($port, "{\"relay\":\"host\"}\n");
        $other = (string) $answer['session'];
        $guest = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($guest);
        \stream_set_blocking($guest, enable: false);
        \fwrite($guest, data: \sprintf("{\"relay\":\"guest\",\"session\":\"%s\"}\n", $session));
        $conn = (int) $this->line($first)['incoming'];
        $accept = \sprintf("{\"relay\":\"accept\",\"session\":\"%%s\",\"conn\":%d}\n", $conn);

        [, $answer] = $this->say($port, \sprintf($accept, $other));
        $this->assertSame(['error' => 'no such guest'], $answer, 'another host\'s guest');
        \fclose($second);
        [$joined, $answer] = $this->say($port, \sprintf($accept, $session));
        $this->assertSame(['paired' => true], $answer);
        $this->assertSame(['paired' => true], $this->line($guest));
        [, $answer] = $this->say($port, \sprintf($accept, $session));
        $this->assertSame(['error' => 'no such guest'], $answer, 'joined already');

        // A host gone while its guest waits: nobody may answer for it.
        $waiting = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($waiting);
        \fwrite($waiting, data: \sprintf("{\"relay\":\"guest\",\"session\":\"%s\"}\n", $session));
        $conn = (int) $this->line($first)['incoming'];
        \fclose($first);
        $this->pump(0.1);
        [, $answer] = $this->say($port, \sprintf("{\"relay\":\"accept\",\"session\":\"%s\",\"conn\":%d}\n", $session, $conn));
        $this->assertSame(['error' => 'no such guest'], $answer, 'no host to answer for');

        // The bytes flow, once joined, both ways.
        \fwrite($guest, data: 'ping');
        $this->await(static fn (): bool => 'ping' === \fread($joined, length: 4), what: 'the guest\'s bytes');
        \fwrite($joined, data: 'pong');
        $this->await(static fn (): bool => 'pong' === \fread($guest, length: 4), what: 'the host\'s bytes');
        $relay->stop();
    }

    public function testAJoinedGuestOutlivesTheWaitForItsHost(): void
    {
        [$relay, $port] = $this->relay(waitForHost: 0.3);
        [$control, $answer] = $this->say($port, "{\"relay\":\"host\"}\n");
        $session = (string) $answer['session'];
        $guest = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($guest);
        \stream_set_blocking($guest, enable: false);
        \fwrite($guest, data: \sprintf("{\"relay\":\"guest\",\"session\":\"%s\"}\n", $session));
        [$joined] = $this->say($port, \sprintf("{\"relay\":\"accept\",\"session\":\"%s\",\"conn\":%d}\n", $session, (int) $this->line($control)['incoming']));
        $this->assertSame(['paired' => true], $this->line($guest));

        $this->pump(0.6);
        \fwrite($joined, data: 'still here');
        $this->await(static fn (): bool => 'still here' === \fread($guest, length: 10), what: 'the guest, still joined');
        $relay->stop();
    }

    public function testAHostTheRelayDoesNotServeIsTold(): void
    {
        $host = $this->host();
        $this->assertStringStartsWith('cannot reach the relay at 127.0.0.1:1: ', (string) $this->join($host, 1));

        // Not a relay: it answers, but with no session.
        $fake = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($fake);
        $port = (int) \substr((string) \stream_socket_get_name($fake, remote: false), offset: 10);
        $said = false;
        $host->start('127.0.0.1', $port, $this->certificate, static function (?string $failed) use (&$said): void {
            $said = $failed;
        });
        $control = $this->accept($fake);
        \fwrite($control, data: "{}\n");
        $this->await(static function () use (&$said): bool {
            return false !== $said;
        }, what: 'the host told');
        $this->assertSame('the relay at 127.0.0.1:'.$port.' did not take the session', $said);

        // Stopped before the relay answered: nothing more is said.
        $said = false;
        $host->start('127.0.0.1', $port, $this->certificate, static function (?string $failed) use (&$said): void {
            $said = $failed;
        });
        $control = $this->accept($fake);
        $host->stop();
        $this->awaitClosed($control, 'the host to hang up');
        $this->assertFalse($said);

        // Guests that never reach it: refused by the relay, not encrypted,
        // or not reachable at all — none is handed on, and the host stays.
        $said = false;
        $host->start('127.0.0.1', $port, $this->certificate, static function (?string $failed) use (&$said): void {
            $said = $failed;
        });
        $control = $this->accept($fake);
        // In two pieces: the host waits for the rest of the line.
        \fwrite($control, data: '{"session":');
        $this->pump(0.05);
        \fwrite($control, data: "\"0123456789abcdef\"}\n");
        $this->await(static function () use (&$said): bool {
            return null === $said;
        }, what: 'the host joined');
        \fwrite($control, data: "{\"note\":\"not for you\"}\n{\"incoming\":1}\n");
        $refused = $this->accept($fake);
        $this->assertSame(['relay' => 'accept', 'session' => '0123456789abcdef', 'conn' => 1], $this->line($refused));
        \fwrite($refused, data: "{\"error\":\"no such guest\"}\n");
        // At once: not after a handshake that could never start.
        $this->awaitClosed($refused, 'the refused answer let go', timeout: 1.0);
        \fwrite($control, data: "{\"incoming\":2}\n");
        $clear = $this->accept($fake);
        $this->line($clear);
        \fwrite($clear, data: "{\"paired\":true}\nHTTP/1.0 400 nope\r\n\r\n");
        $this->awaitClosed($clear, 'the unencrypted answer let go');

        // Joined, the guest's TLS hard on the heels of the answer, in the
        // same read: the host takes the answer and not a byte past it.
        \fwrite($control, data: "{\"incoming\":4}\n");
        $joined = $this->accept($fake);
        $this->line($joined);
        \stream_context_set_options($joined, Certificate::clientContext());
        \fwrite($joined, data: "{\"paired\":true}\n");
        $this->await(
            static function () use ($joined): bool {
                // @mago-expect lint:no-error-control-operator -- a refused handshake warns; the false is turned into the failure
                $done = @\stream_socket_enable_crypto($joined, enable: true, crypto_method: \STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (false === $done) {
                    throw new \RuntimeException('the TLS handshake failed');
                }

                return true === $done;
            },
            what: 'the handshake right behind the answer',
        );
        $this->await(fn (): bool => [] !== $this->accepted, what: 'the guest handed on');
        \fclose($fake);
        \fwrite($control, data: "{\"incoming\":3}\n");
        $this->pump(0.1);
        $this->assertCount(1, $this->accepted, 'only the guest that was joined and encrypted');
        $this->assertSame([], $this->lost);
        $this->accepted[0]->close('done');
        $host->stop();
    }

    public function testARelayThatHangsUpOnAGuestSaysNothing(): void
    {
        $fake = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($fake);
        $port = (int) \substr((string) \stream_socket_get_name($fake, remote: false), offset: 10);
        $result = null;
        new Dialer(timeout: 2.0)->dial(
            '127.0.0.1',
            $port,
            $this->certificate->fingerprint,
            static function (Connection|string $done) use (&$result): void {
                $result = $done;
            },
            '0123456789abcdef',
        );
        \fclose($this->accept($fake));
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the dial to end');
        $this->assertSame('127.0.0.1:'.$port.': the relay says nothing', $result);
    }

    /**
     * @param resource $server
     *
     * @return resource
     */
    private function accept($server)
    {
        $accepted = null;
        $this->await(
            static function () use ($server, &$accepted): bool {
                // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns
                $stream = @\stream_socket_accept($server, timeout: 0);
                if (false !== $stream) {
                    $accepted = $stream;
                }

                return null !== $accepted;
            },
            what: 'a connection',
        );
        \assert(\is_resource($accepted), description: 'the await saw it accepted');
        \stream_set_blocking($accepted, enable: false);

        return $accepted;
    }
}
