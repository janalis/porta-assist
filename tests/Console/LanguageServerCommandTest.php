<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Console\LanguageServerCommand;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\FakeWormhole;
use Porta\Support\JsonRpcFramer;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `porta assist-lsp <language>`, as a guest's IDE starts it: the editor's
 * frames go to the host over a connection of the bridge's own, through the
 * session's relay — the host's answers come back framed as a language
 * server's — until either side ends.
 */
final class LanguageServerCommandTest extends TestCase
{
    private ?Process $host = null;

    private ?Process $relay = null;

    private ?FakeWormhole $wormhole = null;

    protected function tearDown(): void
    {
        $this->host?->stop(0);
        $this->relay?->stop(0);
    }

    /** A fake host serving $mode, waiting at the relay: what a guest of it holds. */
    private function host(string $mode): Invite
    {
        $this->wormhole ??= new FakeWormhole();
        $this->host?->stop(0);
        $this->host = new Process([\PHP_BINARY, __DIR__.'/../Support/fake-assist-host.php', $mode], env: ['PORTA_FAKE_TRANSIT' => $this->wormhole->transit, 'PORTA_FAKE_DOOR' => '1']);
        $invite = Invite::fromArray((array) \json_decode(self::firstLine($this->host), associative: true, flags: \JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(Invite::class, $invite);

        return $invite;
    }

    /** @var list<TempProject> kept for the test: a project let go is deleted */
    private array $projects = [];

    /**
     * A copy of a shared project, a guest of the host $invite names: its directory.
     *
     * @param list<string> $languages
     */
    private function store(TempProject $project, Invite $invite, array $languages = ['php']): string
    {
        $this->projects[] = $project;
        GuestSession::store($project->dir, ['invite' => $invite, 'ticket' => \str_repeat('ab', times: 16), 'name' => 'Bo', 'languages' => $languages]);

        return $project->dir;
    }

    /** The first line $process prints, once it has. */
    private static function firstLine(Process $process): string
    {
        $process->start();
        $first = '';
        $process->waitUntil(static function (string $type, string $output) use (&$first): bool {
            $first .= $output;

            return \str_contains($first, "\n");
        });

        return \trim($first);
    }

    /** A copy of a shared project whose host is the fake one: its directory. */
    private function copy(TempProject $project): string
    {
        return $this->store($project, $this->host('lsp'));
    }

    /**
     * Run the bridge for $language on $root, the editor having sent $frames.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return array{CommandTester, string} the run, and what the editor was sent
     */
    private function bridge(string $root, string $language, array $frames, float $ping = Connection::PING, float $silence = Connection::SILENCE): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$editor, $input] = $pair;
        foreach ($frames as $frame) {
            \fwrite($editor, JsonRpcFramer::encode($frame));
        }
        $output = \fopen('php://memory', mode: 'wb+');
        if (false === $output) {
            $this->fail('php://memory did not open');
        }
        $application = new Application();
        $application->addCommand(new LanguageServerCommand($root, $input, $output, timeout: 2.0, ping: $ping, silence: $silence));
        $tester = new CommandTester($application->find('assist-lsp'));
        $tester->execute(['language' => $language], ['capture_stderr_separately' => true]);
        \rewind($output);

        return [$tester, (string) \stream_get_contents($output)];
    }

    public function testTheEditorsFramesReachTheHostAndItsAnswersComeBack(): void
    {
        $project = new TempProject([]);
        $root = $this->copy($project);

        [$tester, $sent] = $this->bridge($root, 'php', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
            ['jsonrpc' => '2.0', 'method' => 'exit'],
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(JsonRpcFramer::encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'initialize']), $sent, 'the answer, framed for the editor');
    }

    public function testWithoutAHostServingTheLanguageThereIsNoServer(): void
    {
        $project = new TempProject([]);
        $root = $this->copy($project);
        [$tester] = $this->bridge($root, 'cobol', []);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The host runs no cobol language server for this session (language)', $tester->getErrorOutput());

        // Port 1 on loopback: nothing listens there, and nothing unprivileged can
        // start to — a port closed by this test could be taken by a parallel one.
        $port = 1;
        $gone = $this->store(new TempProject([]), new Invite(new SessionKey(\str_repeat('k', times: 32)), '127.0.0.1:'.$port, \str_repeat('0', times: 64), \str_repeat('ab', times: 16)), []);
        [$tester] = $this->bridge($gone, 'php', []);
        $this->assertStringContainsString('127.0.0.1:'.$port.' refused the connection', $tester->getErrorOutput());

        $stranger = new TempProject([]);
        [$tester] = $this->bridge($stranger->dir, 'php', []);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('is not a copy of a shared project', $tester->getErrorOutput());
    }

    public function testAHostThatTurnsItAwayOrNeverAnswersIsNoServer(): void
    {
        foreach (['deny:host' => '(host)', 'silent' => '()'] as $mode => $says) {
            [$tester] = $this->bridge($this->store(new TempProject([]), $this->host($mode)), 'php', []);
            $this->assertSame(1, $tester->getStatusCode(), $mode);
            $this->assertStringContainsString('The host runs no php language server for this session '.$says, $tester->getErrorOutput(), $mode);
        }
    }

    public function testAHostThatFallsSilentIsLeftAndPingedUntilThen(): void
    {
        $root = $this->store(new TempProject([]), $this->host('lsp-mute'));

        [$tester] = $this->bridge($root, 'php', [], ping: 0.1, silence: 0.6);

        $this->assertSame(1, $tester->getStatusCode(), 'the editor sees its server exit, and starts it again');
        $this->assertStringContainsString('The host stopped answering.', $tester->getErrorOutput());
        $this->assertInstanceOf(Process::class, $this->host);
        $this->assertStringContainsString("ping\n", $this->host->getIncrementalOutput(), 'asked, while it waited');
    }

    public function testEachBridgeTakesAChannelOfItsOwn(): void
    {
        $root = $this->copy(new TempProject([]));
        $this->assertSame(0, GuestSession::channel($root));
        // The host waits on a few channels past the last one used: a channel
        // taken and never used is stepped over.
        [$tester, $sent] = $this->bridge($root, 'php', [
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => []],
            ['jsonrpc' => '2.0', 'method' => 'exit'],
        ]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(JsonRpcFramer::encode(['jsonrpc' => '2.0', 'id' => 7, 'result' => 'initialize']), $sent);
        $this->assertSame(2, GuestSession::channel($root));
    }

    public function testWhatARelaySaysIsPrintedAsTextNotAsMarkup(): void
    {
        // A "relay" whose refusal is console markup for a hyperlink.
        $this->relay = new Process([
            \PHP_BINARY,
            '-r',
            '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1], "\n"; $c = stream_socket_accept($s, 20); fgets($c); fwrite($c, "<href=https://evil.example>click</>\n"); sleep(1);',
        ]);
        $port = self::firstLine($this->relay);
        $project = $this->store(new TempProject([]), new Invite(new SessionKey(\str_repeat('k', times: 32)), '127.0.0.1:'.$port, \str_repeat('0', times: 64), \str_repeat('ab', times: 16)), []);

        [$tester] = $this->bridge($project, 'php', []);

        $this->assertStringContainsString('says <href=https://evil.example>click</>', $tester->getErrorOutput(), 'the tag shown as written');
    }
}
