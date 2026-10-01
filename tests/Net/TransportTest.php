<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Dialer;
use Porta\AssistPlugin\Net\Listener;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

/**
 * A session's connections, on real loopback sockets: listened for, dialled,
 * encrypted against the certificate the invite names, read line by line,
 * closed once.
 */
final class TransportTest extends TestCase
{
    use PumpsLoop;

    /** @var list<Connection> */
    private array $accepted = [];

    private Certificate $certificate;

    protected function setUp(): void
    {
        $this->certificate = Certificate::create();
    }

    protected function tearDown(): void
    {
        $this->certificate->forget();
    }

    private function listener(float $handshakeTimeout = 10.0): Listener
    {
        return new Listener(function (Connection $connection): void {
            $this->accepted[] = $connection;
        }, $handshakeTimeout);
    }

    /** @return array{Connection, Connection} the dialled end and the accepted one */
    private function pair(int $port): array
    {
        $dialled = null;
        new Dialer()->dial('127.0.0.1', $port, $this->certificate->fingerprint, static function (Connection|string $result) use (&$dialled): void {
            $dialled = $result;
        });
        $this->await(function () use (&$dialled): bool {
            return null !== $dialled && [] !== $this->accepted;
        }, what: 'both ends');
        $this->assertInstanceOf(Connection::class, $dialled);

        return [$dialled, \array_shift($this->accepted)];
    }

    /**
     * What each dial ended in, once $count have.
     *
     * @param \Closure(\Closure(Connection|string): void): void $dial
     *
     * @return list<Connection|string>
     */
    private function dials(int $count, \Closure $dial): array
    {
        $results = [];
        $dial(static function (Connection|string $result) use (&$results): void {
            $results[] = $result;
        });
        $this->await(static function () use (&$results, $count): bool {
            return \count($results) === $count;
        }, what: 'every dial answered');

        return $results;
    }

    private static function freePort(): int
    {
        $closed = \stream_socket_server('tcp://127.0.0.1:0');
        \assert(false !== $closed, description: 'loopback is there');
        $port = (int) \substr((string) \stream_socket_get_name($closed, remote: false), offset: 10);
        \fclose($closed);

        return $port;
    }

    public function testMessagesCrossBothWaysAndALastOneIsDeliveredBeforeTheClose(): void
    {
        $listener = $this->listener();
        $port = $listener->start('127.0.0.1', 0, $this->certificate);
        $this->assertIsInt($port);
        $this->assertStringContainsString('cannot listen on tcp://127.0.0.1:'.$port, (string) $this->listener()->start('127.0.0.1', $port, $this->certificate), 'a port in use is said, not thrown');
        [$guest, $host] = $this->pair($port);
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
        $listener->stop();
        $listener->stop();
    }

    public function testWhatIsNotAMessageIsSkippedAndNothingIsReadPastAClose(): void
    {
        $listener = $this->listener();
        $port = $listener->start('127.0.0.1', 0, $this->certificate);
        $this->assertIsInt($port);
        [$guest, $host] = $this->pair($port);
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
        $listener->stop();
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

    public function testOnlyTheHostTheInviteNamesIsTalkedTo(): void
    {
        $listener = $this->listener();
        $port = $listener->start('127.0.0.1', 0, $this->certificate);
        $this->assertIsInt($port);
        $impostor = Certificate::create();

        [$result] = $this->dials(1, static fn (\Closure $done) => new Dialer()->dial('127.0.0.1', $port, $impostor->fingerprint, $done));
        $impostor->forget();
        $this->assertSame('127.0.0.1:'.$port.': the host is not the one the invite names', $result);
        $listener->stop();
    }

    public function testAPeerThatDoesNotSpeakTlsIsLetGo(): void
    {
        // A client that never shakes hands: the host lets it go.
        $listener = $this->listener(handshakeTimeout: 0.2);
        $port = $listener->start('127.0.0.1', 0, $this->certificate);
        $this->assertIsInt($port);
        $plain = \stream_socket_client('tcp://127.0.0.1:'.$port);
        $this->assertNotFalse($plain);
        \stream_set_blocking($plain, enable: false);
        $this->await(static fn (): bool => '' === \fread($plain, length: 10) && \feof($plain), what: 'the silent client let go');
        $this->assertSame([], $this->accepted);
        $listener->stop();

        // A host that answers in the clear, and one that never answers.
        $clear = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($clear);
        $clearPort = (int) \substr((string) \stream_socket_get_name($clear, remote: false), offset: 10);
        $fingerprint = $this->certificate->fingerprint;
        $results = $this->dials(2, function (\Closure $done) use ($clear, $clearPort, $fingerprint): void {
            new Dialer(timeout: 0.3)->dial('127.0.0.1', $clearPort, $fingerprint, $done);
            new Dialer(timeout: 0.3)->dial('127.0.0.1', $clearPort, $fingerprint, $done);
            $this->await(
                static function () use ($clear): bool {
                    // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns
                    $first = @\stream_socket_accept($clear, timeout: 0);
                    if (false === $first) {
                        return false;
                    }
                    \fwrite($first, data: "HTTP/1.0 400 nope\r\n\r\n");
                    \fclose($first);

                    return true;
                },
                what: 'the first one answered in the clear',
            );
        });
        $this->assertContains('127.0.0.1:'.$clearPort.': the secure connection failed', $results);
        $this->assertContains('127.0.0.1:'.$clearPort.': the handshake took too long', $results);
    }

    public function testADialThatCannotConnectSaysWhy(): void
    {
        $port = self::freePort();
        $fingerprint = $this->certificate->fingerprint;
        $results = $this->dials(3, static function (\Closure $done) use ($port, $fingerprint): void {
            new Dialer()->dial('127.0.0.1', $port, $fingerprint, $done);
            new Dialer()->dial('no-such-host.invalid', 1, $fingerprint, $done);
            new Dialer(timeout: 0.2)->dial('10.255.255.1', 9, $fingerprint, $done);
        });

        $this->assertNotEmpty(\array_filter($results, static fn (mixed $r): bool => \is_string($r) && \str_starts_with($r, 'cannot reach no-such-host.invalid:1')));
        $this->assertContains('127.0.0.1:'.$port.' refused the connection', $results);
        $this->assertContains('10.255.255.1:9 did not answer', $results);
    }

    public function testTheKeyGoesWithTheSession(): void
    {
        $certificate = Certificate::create();
        $this->assertSame(0o600, \fileperms($certificate->file) & 0o777, 'the key is this user\'s only');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $certificate->fingerprint);
        $certificate->forget();
        $certificate->forget();
        $this->assertFileDoesNotExist($certificate->file);
    }
}
