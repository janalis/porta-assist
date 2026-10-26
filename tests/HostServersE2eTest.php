<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A server of the host's — a dev server on a local port — reached by the
 * guests: only a port the host shared is connected to, every connection a
 * guest opens is carried both ways, and either end closing closes the other.
 */
final class HostServersE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    /** @var array<int, resource> the host's dev servers, by port */
    private array $dev = [];

    protected function tearDown(): void
    {
        foreach ($this->dev as $dev) {
            \fclose($dev);
        }
    }

    /** @return int the port of a dev server on this machine's loopback */
    private function devServer(): int
    {
        $dev = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($dev);
        \stream_set_blocking($dev, enable: false);
        $port = (int) \substr((string) \stream_socket_get_name($dev, remote: false), offset: 10);
        $this->dev[$port] = $dev;

        return $port;
    }

    /** @return resource the dev server's end of the next connection the host made to the one on $port */
    private function devAccepts(int $port)
    {
        $dev = $this->dev[$port] ?? null;
        $this->assertNotNull($dev);
        $accepted = null;
        $this->await(
            static function () use ($dev, &$accepted): bool {
                // @mago-expect lint:no-error-control-operator -- nothing to accept yet warns
                $stream = @\stream_socket_accept($dev, timeout: 0);
                if (false !== $stream) {
                    $accepted = $stream;
                }

                return null !== $accepted;
            },
            what: 'the host to connect to its dev server',
        );
        \assert(\is_resource($accepted), description: 'the await saw it accepted');
        \stream_set_blocking($accepted, enable: false);

        return $accepted;
    }

    /** @param resource $stream */
    private function awaitClosed($stream, string $what): void
    {
        $this->await(static fn (): bool => '' === \fread($stream, length: 4_096) && \feof($stream), what: $what);
    }

    /** @return string what a tunnel message carries */
    private static function bytes(array $message): string
    {
        return (string) \base64_decode(Wire::string($message, 'data'), strict: true);
    }

    /** Ask in as $name and be let in. */
    private function letIn(Tui $tui, VirtualTerminal $terminal, Invite $invite, string $name): void
    {
        $this->askIn($invite, $name);
        $this->awaitPainted($tui, $terminal, $name.' asks to join');
        $terminal->simulateInput("\r");
        $this->expect('welcome');
    }

    public function testAGuestReachesOnlyTheServersTheHostShares(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        $port = $this->devServer();

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($port): void {
                $this->command($ide, 'assist:share_server');
                $this->await(static fn (): bool => self::toasted($ide, 'Share this project first'), what: 'nothing to share it in');

                $invite = $this->invited($ide);
                $this->letIn($tui, $terminal, $invite, 'Ana');

                // Asked for a port: what is not one is said; the port is shared with everyone.
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Share which local port?');
                $terminal->simulateInput("http\r");
                $this->await(static fn (): bool => self::toasted($ide, 'Not a port: http'), what: 'the refusal');
                foreach (['0', '65536'] as $notOne) {
                    $this->command($ide, 'assist:share_server');
                    $this->awaitPainted($tui, $terminal, 'Share which local port?');
                    $terminal->simulateInput($notOne."\r");
                    $this->await(static fn (): bool => self::toasted($ide, 'Not a port: '.$notOne), what: 'out of range: '.$notOne);
                }
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Share which local port?');
                $terminal->simulateInput(' '.$port." \r"); // as pasted, spaces and all
                $this->assertSame($port, $this->expect('server')['port']);

                // A connection at her end reaches the dev server, both ways.
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 1]);
                $served = $this->devAccepts($port);
                $this->say(['t' => 'tunnel-data', 'conn' => 1, 'data' => \base64_encode('GET / HTTP/1.0')]);
                $this->say(['t' => 'tunnel-data', 'conn' => 1, 'data' => '!not base64!']);
                $this->await(static fn (): bool => 'GET / HTTP/1.0' === \fread($served, length: 100), what: 'the request at the dev server');
                \fwrite($served, data: 'HTTP/1.0 200 OK');
                $this->assertSame('HTTP/1.0 200 OK', self::bytes($this->expect('tunnel-data')));

                // Never a port the host did not share — a server there or not —
                // nor a connection twice.
                $other = $this->devServer();
                $this->say(['t' => 'tunnel-open', 'port' => $other, 'conn' => 2]);
                $this->assertSame(2, $this->expect('tunnel-close')['conn']);
                // @mago-expect lint:no-error-control-operator -- a refused connection warns; the false is what is asserted
                $this->assertFalse(@\stream_socket_accept($this->dev[$other], timeout: 0), 'the unshared server never reached');
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 1]);
                $this->assertSame(1, $this->expect('tunnel-close')['conn']);

                // The dev server hangs up: she is told.
                \fclose($served);
                $this->assertSame(1, $this->expect('tunnel-close')['conn']);
                // She hangs up: the dev server's connection closes.
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 3]);
                $served = $this->devAccepts($port);
                $this->say(['t' => 'tunnel-close', 'conn' => 3]);
                $this->awaitClosed($served, 'her close at the dev server');

                // Shared, but nothing listens there: closed at once.
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $host->servers()->toggle(1);
                $this->assertSame(1, $this->expect('server')['port']);
                $this->say(['t' => 'tunnel-open', 'port' => 1, 'conn' => 4]);
                $this->assertSame(4, $this->expect('tunnel-close')['conn']);

                // A newcomer is told what is shared.
                $ana = $this->peer;
                $this->letIn($tui, $terminal, $invite, 'Bo');
                $this->assertSame($port, $this->expect('server')['port']);
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 1]);
                $bos = $this->devAccepts($port);
                $bo = $this->peer;
                $this->peer = $ana;

                // No longer shared: what is open through it closes; she is told.
                // What she has open on another server stays.
                $host->servers()->toggle($other);
                $this->assertSame($other, $this->expect('server')['port']);
                $this->say(['t' => 'tunnel-open', 'port' => $other, 'conn' => 6]);
                $kept = $this->devAccepts($other);
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 5]);
                $anas = $this->devAccepts($port);
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Share which server?');
                $terminal->simulateInput("\r");
                $this->assertSame($port, $this->expect('server-end')['port']);
                $this->awaitClosed($anas, 'her connection closed with the share');
                $this->awaitClosed($bos, 'his too');
                $this->say(['t' => 'tunnel-data', 'conn' => 6, 'data' => \base64_encode('still here')]);
                $this->await(static fn (): bool => 'still here' === \fread($kept, length: 100), what: 'the other server still reached');

                // Shared again, from the list; then Bo leaves with a connection open.
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Another port');
                $terminal->simulateInput(self::DOWN.self::DOWN."\r");
                $this->awaitPainted($tui, $terminal, 'Share which local port?');
                $terminal->simulateInput($port."\r");
                $this->assertSame($port, $this->expect('server')['port']);
                $this->peer = $bo;
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 7]);
                $bos = $this->devAccepts($port);
                $this->hangUp();
                $this->awaitClosed($bos, 'his connection gone with him');

                // The session stops: everything shared closes.
                $this->peer = $ana;
                $this->say(['t' => 'tunnel-open', 'port' => $port, 'conn' => 8]);
                $anas = $this->devAccepts($port);
                $this->command($ide, 'assist:stop');
                $this->awaitClosed($anas, 'hers gone with the session');
                $this->assertSame([], $host->servers()->shared());
                $this->hangUp();
            },
        ]);
    }

    public function testWhatHappensWhileAConnectionIsStillBeingMade(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        $port = $this->devServer();

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($port): void {
                $invite = $this->invited($ide);
                $this->letIn($tui, $terminal, $invite, 'Ana');
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Share which local port?');
                $terminal->simulateInput($port."\r");
                $this->expect('server');

                // Sent before the host is connected: held, then passed on.
                $this->sayTogether(
                    ['t' => 'tunnel-open', 'port' => $port, 'conn' => 1],
                    ['t' => 'tunnel-data', 'conn' => 1, 'data' => \base64_encode('early')],
                    ['t' => 'tunnel-data', 'conn' => 1, 'data' => \base64_encode(' bird')],
                    ['t' => 'tunnel-open', 'port' => $port, 'conn' => 1],
                );
                $this->assertSame(1, $this->expect('tunnel-close')['conn'], 'one connection id, once — even while it is made');
                $served = $this->devAccepts($port);
                $got = '';
                $this->await(static function () use ($served, &$got): bool {
                    $got .= \fread($served, length: 100);

                    return 'early bird' === $got;
                }, what: 'all that was sent early, in order');

                // Closed at her end before the host got through: not kept.
                $this->sayTogether(
                    ['t' => 'tunnel-open', 'port' => $port, 'conn' => 2],
                    ['t' => 'tunnel-close', 'conn' => 2],
                );
                $this->awaitClosed($this->devAccepts($port), 'the connection let go');

                // A port shared with nothing behind it — port 1, which no parallel
                // test can take, as a freed one could be: said closed, the IDE never held.
                $dead = 1;
                $this->command($ide, 'assist:share_server');
                $this->awaitPainted($tui, $terminal, 'Another port');
                $terminal->simulateInput(self::DOWN."\r");
                $this->awaitPainted($tui, $terminal, 'Share which local port?');
                $terminal->simulateInput($dead."\r");
                $this->assertSame($dead, $this->expect('server')['port']);
                $this->say(['t' => 'tunnel-open', 'port' => $dead, 'conn' => 4]);
                $this->assertSame(4, $this->expect('tunnel-close')['conn']);
                $this->hangUp();
            },
        ]);
    }
}
