<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A server the host shares, reached at the guest's end: listened on here —
 * on the host's port number when it is free — each connection carried to
 * the host and back, closed when the host stops sharing it or the session
 * is lost.
 */
final class GuestServersE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    /** @return int a port nothing listens on, very likely still free */
    private static function freePort(): int
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        \assert(false !== $probe, description: 'loopback listens');
        $port = (int) \substr((string) \stream_socket_get_name($probe, remote: false), offset: 10);
        \fclose($probe);

        return $port;
    }

    /** The host shares $port: where the guest IDE reaches it, as its toast says. */
    private function shared(Ide $ide, int $port): int
    {
        $this->say(['t' => 'server', 'port' => $port]);
        $local = 0;
        $this->await(static function () use ($ide, $port, &$local): bool {
            $match = [];
            if (1 === \preg_match('/Hal shared localhost:'.$port.' — open http:\/\/127\.0\.0\.1:(\d+)/', Toasts::last($ide), $match)) {
                $local = (int) $match[1];
            }

            return 0 !== $local;
        }, what: 'the server reached here');

        return $local;
    }

    /** @return resource a connection to the guest IDE's end of a server */
    private function connect(int $local)
    {
        $client = \stream_socket_client('tcp://127.0.0.1:'.$local, timeout: 2.0);
        $this->assertNotFalse($client);
        \stream_set_blocking($client, enable: false);

        return $client;
    }

    /** @param resource $stream */
    private function awaitClosed($stream, string $what): void
    {
        $this->await(static fn (): bool => '' === \fread($stream, length: 4_096) && \feof($stream), what: $what);
    }

    public function testTheHostsServerIsReachedHere(): void
    {
        $port = $this->listen();
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, ['host' => '127.0.0.1', 'port' => $port, 'fingerprint' => $this->fingerprint(), 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $this->command($ide, 'assist:servers');
                $this->await(static fn (): bool => self::toasted($ide, 'The host shares no server'), what: 'none yet');
                $this->acceptGuest();
                $this->expect('hello');
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'read', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);

                // On the host's number, free here; said once, however often the host says it.
                $wanted = self::freePort();
                $local = $this->shared($ide, $wanted);
                $this->assertSame($wanted, $local);
                $this->say(['t' => 'server', 'port' => $wanted]);

                // A connection here is carried to the host, both ways.
                $client = $this->connect($local);
                $open = $this->expect('tunnel-open');
                $this->assertSame($wanted, $open['port']);
                $conn = Wire::int($open, 'conn');
                \fwrite($client, data: 'GET / HTTP/1.0');
                $data = $this->expect('tunnel-data');
                $this->assertSame([$conn, 'GET / HTTP/1.0'], [$data['conn'], \base64_decode(Wire::string($data, 'data'), strict: true)]);
                $this->say(['t' => 'tunnel-data', 'conn' => $conn, 'data' => \base64_encode('HTTP/1.0 200 OK')]);
                $this->say(['t' => 'tunnel-data', 'conn' => 99, 'data' => \base64_encode('for nobody')]);
                $this->await(static fn (): bool => 'HTTP/1.0 200 OK' === \fread($client, length: 100), what: 'the answer here');
                // Closed here: the host is told. Closed there: closed here.
                \fclose($client);
                $this->assertSame($conn, $this->expect('tunnel-close')['conn']);
                $client = $this->connect($local);
                $conn = Wire::int($this->expect('tunnel-open'), 'conn');
                $this->say(['t' => 'tunnel-close', 'conn' => $conn]);
                $this->awaitClosed($client, 'the host\'s close here');

                // A number taken here: another one.
                $taken = \stream_socket_server('tcp://127.0.0.1:0');
                $this->assertNotFalse($taken);
                $takenPort = (int) \substr((string) \stream_socket_get_name($taken, remote: false), offset: 10);
                $elsewhere = $this->shared($ide, $takenPort);
                $this->assertNotSame($takenPort, $elsewhere);

                // Listed, its address copied.
                $this->command($ide, 'assist:servers');
                $this->awaitPainted($tui, $terminal, 'Shared servers');
                $terminal->simulateInput("\r");
                $this->await(static fn (): bool => self::toasted($ide, 'Address copied: http://127.0.0.1:'.$local), what: 'the address copied');

                // No longer shared: what is open closes, and the port is let go.
                $client = $this->connect($local);
                $this->expect('tunnel-open');
                $this->say(['t' => 'server-end', 'port' => $wanted]);
                $this->say(['t' => 'server-end', 'port' => 1]);
                $this->await(static fn (): bool => self::toasted($ide, 'Hal stopped sharing localhost:'.$wanted), what: 'the guest told');
                $this->awaitClosed($client, 'the connection closed with the share');
                // @mago-expect lint:no-error-control-operator -- a refused connection warns; the false is what is asserted
                $this->assertFalse(@\stream_socket_client('tcp://127.0.0.1:'.$local, timeout: 1.0), 'nothing listens there any more');

                // The host lost: what was reached through it goes too.
                $client = $this->connect($elsewhere);
                $this->expect('tunnel-open');
                $this->dropPeer();
                $this->awaitClosed($client, 'closed with the session');
                // @mago-expect lint:no-error-control-operator -- a refused connection warns; the false is what is asserted
                $this->assertFalse(@\stream_socket_client('tcp://127.0.0.1:'.$elsewhere, timeout: 1.0));
                \fclose($taken);

                // Back, sharing again, then the host ends it: that is what is
                // said — not a toast per server over it.
                $this->acceptGuest();
                $this->expect('hello');
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'read', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);
                $client = $this->connect($this->shared($ide, self::freePort()));
                $this->expect('tunnel-open');
                $this->say(['t' => 'bye', 'reason' => 'ended']);
                $this->awaitClosed($client, 'closed with the session');
                $this->assertSame('The host ended the session', Toasts::last($ide));
                $this->hangUp();
            },
        ]);
    }
}
