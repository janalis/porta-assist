<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Tunnel;
use Porta\Tests\Support\PumpsLoop;

/**
 * A local connection carried through a session: bytes each way, the close
 * of either end — and reading held back while the session is behind.
 */
final class TunnelTest extends TestCase
{
    use PumpsLoop;

    /** @return array{resource, resource} */
    private function pair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        \stream_set_blocking($pair[0], enable: false);

        return $pair;
    }

    /**
     * A tunnel on a fresh local connection: the app's end, the tunnel, and
     * what it sent out and whether it ended, as they happen.
     *
     * @return array{resource, Tunnel, \ArrayObject<int, string>, \ArrayObject<int, bool>}
     */
    private function tunnel(Connection $session): array
    {
        [$app, $local] = $this->pair();
        $out = new \ArrayObject();
        $ended = new \ArrayObject();
        $tunnel = new Tunnel(
            $local,
            $session,
            static function (string $bytes) use ($out): void {
                $out[] = $bytes;
            },
            static function () use ($ended): void {
                $ended[] = true;
            },
        );

        return [$app, $tunnel, $out, $ended];
    }

    public function testBytesGoBothWaysAndEitherEndCloses(): void
    {
        [, $session] = $this->pair();
        $connection = new Connection($session);
        [$app, $tunnel, $out, $ended] = $this->tunnel($connection);

        \fwrite($app, data: 'GET / HTTP/1.0');
        $this->await(static fn (): bool => 'GET / HTTP/1.0' === \implode('', (array) $out), what: 'the request carried');
        $tunnel->write('HTTP/1.0 200 OK');
        $this->await(static fn (): bool => 'HTTP/1.0 200 OK' === \fread($app, length: 100), what: 'the answer delivered');

        // The app here hangs up: the other end is told, once.
        \fclose($app);
        $this->await(static fn (): bool => [true] === (array) $ended, what: 'the end told');
        $tunnel->close();
        $this->assertSame([true], (array) $ended, 'closing again changes nothing');

        // Its last words, written as it hangs up, still go out first.
        [$app, , $out, $ended] = $this->tunnel($connection);
        \fwrite($app, data: 'last words');
        \fclose($app);
        $this->await(static fn (): bool => [true] === (array) $ended, what: 'the end told');
        $this->assertSame('last words', \implode('', (array) $out));

        // The other end hangs up: what it sent arrives first — all of it,
        // past what the socket takes at once — and only then the close.
        [$app, $tunnel, $out, $ended] = $this->tunnel($connection);
        $body = \str_repeat('x', times: 300_000);
        $tunnel->write($body);
        $tunnel->finish();
        \fwrite($app, data: 'too late');
        $received = '';
        $this->await(static function () use ($app, &$received): bool {
            $received .= \fread($app, length: 1_048_576);

            return \feof($app);
        }, what: 'everything, then the close');
        $this->assertSame(\strlen($body), \strlen($received));
        $this->assertSame([], (array) $ended, 'the other end is not told of its own close');
        $this->assertSame([], (array) $out, 'nothing read here once the other end is gone');

        // Nothing queued: it closes at once.
        [$app, $tunnel] = $this->tunnel($connection);
        $tunnel->finish();
        $this->await(static fn (): bool => '' === \fread($app, length: 10) && \feof($app), what: 'the close');
        $connection->close('done');
    }

    public function testReadingWaitsWhileTheSessionIsBehind(): void
    {
        // Over loopback TCP: its buffers drain a large queue in a few turns
        // where a socket pair's would take hundreds.
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $peer = \stream_socket_client('tcp://'.\stream_socket_get_name($server, remote: false));
        $session = \stream_socket_accept($server);
        $this->assertNotFalse($peer);
        $this->assertNotFalse($session);
        \stream_set_blocking($peer, enable: false);
        $connection = new Connection($session);
        $resumed = false;
        $connection->afterDrain(static function () use (&$resumed): void {
            $resumed = true;
        });
        $this->assertTrue($resumed, 'nothing queued: at once');

        // The peer is not reading: the session's queue fills past the mark.
        // Far past it: an unread loopback pair goes on absorbing up to ~3 MB
        // while the loop turns, and 3x the mark once fell under it during the
        // pump below on a loaded box, so reading rightly resumed.
        $connection->send(['t' => 'file', 'data' => \str_repeat('a', times: 6 * Tunnel::HIGH_WATER)]);
        $this->assertGreaterThan(Tunnel::HIGH_WATER, $connection->backlog());
        [$app, $held, $out] = $this->tunnel($connection);
        [$gone, $closed] = $this->tunnel($connection);
        \fwrite($app, data: 'more');
        \fwrite($gone, data: 'never sent');
        $this->pump(0.2);
        $this->assertGreaterThan(Tunnel::HIGH_WATER, $connection->backlog(), 'still behind after the pump, or the next assertion proves nothing');
        $this->assertSame([], (array) $out, 'held back while the session is behind');
        $closed->close();

        // The peer catches up: reading resumes.
        // Everything there is, each turn: a non-blocking fread returns one
        // 8 KiB chunk whatever length it is asked for, and at one chunk a turn
        // the ~8 MB queue outlasted the 10 s limit — the "flake" was arithmetic.
        $this->await(static function () use ($peer, $connection): bool {
            do {
                $chunk = (string) \fread($peer, length: 1_048_576);
            } while ('' !== $chunk);

            return 0 === $connection->backlog();
        }, what: 'the session to catch up');
        $this->await(static fn (): bool => 'more' === \implode('', (array) $out), what: 'the held bytes sent');
        $held->close();
        $connection->close('done');
    }
}
