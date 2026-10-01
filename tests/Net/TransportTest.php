<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Dialer;
use Porta\AssistPlugin\Net\Listener;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

/** A session's connections, on real loopback sockets: listened for, dialled, read line by line, closed once. */
final class TransportTest extends TestCase
{
    use PumpsLoop;

    /** @var list<Connection> */
    private array $accepted = [];

    private function listener(): Listener
    {
        return new Listener(function (Connection $connection): void {
            $this->accepted[] = $connection;
        });
    }

    /** @return array{Connection, Connection} the dialled end and the accepted one */
    private function pair(int $port): array
    {
        $dialled = null;
        new Dialer()->dial('127.0.0.1', $port, static function (Connection|string $result) use (&$dialled): void {
            $dialled = $result;
        });
        $this->await(function () use (&$dialled): bool {
            return null !== $dialled && [] !== $this->accepted;
        }, what: 'both ends');
        $this->assertInstanceOf(Connection::class, $dialled);

        return [$dialled, \array_shift($this->accepted)];
    }

    public function testMessagesCrossBothWaysAndALastOneIsDeliveredBeforeTheClose(): void
    {
        $listener = $this->listener();
        $port = $listener->start('127.0.0.1', 0);
        $this->assertIsInt($port);
        $this->assertStringContainsString('cannot listen on tcp://127.0.0.1:'.$port, (string) $this->listener()->start('127.0.0.1', $port), 'a port in use is said, not thrown');
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
        $port = $listener->start('127.0.0.1', 0);
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

        \fwrite($client, data: \str_repeat('x', times: 64));
        $this->await(static function () use (&$why): bool {
            return null !== $why;
        }, what: 'the close');
        $this->assertStringContainsString('past 16 bytes', (string) $why);
    }

    public function testADialThatCannotConnectSaysWhy(): void
    {
        $results = [];
        $record = static function (Connection|string $result) use (&$results): void {
            $results[] = $result;
        };
        $closed = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($closed);
        $port = (int) \substr((string) \stream_socket_get_name($closed, remote: false), offset: 10);
        \fclose($closed);

        new Dialer()->dial('127.0.0.1', $port, $record);
        new Dialer()->dial('no-such-host.invalid', 1, $record);
        new Dialer(timeout: 0.2)->dial('10.255.255.1', 9, $record);
        $this->await(static function () use (&$results): bool {
            return 3 === \count($results);
        }, what: 'all three answered');

        $this->assertContains('no-such-host.invalid:1', \array_map(static fn (mixed $r): string => \is_string($r) && \str_starts_with($r, 'cannot reach no-such-host.invalid:1')
            ? 'no-such-host.invalid:1'
            : '', $results));
        $this->assertContains('127.0.0.1:'.$port.' refused the connection', $results);
        $this->assertContains('10.255.255.1:9 did not answer', $results);
    }
}
