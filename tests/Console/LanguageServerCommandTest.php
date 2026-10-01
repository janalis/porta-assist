<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Console\LanguageServerCommand;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\Support\JsonRpcFramer;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `porta assist-lsp <language>`, as a guest's IDE starts it: the editor's
 * frames go to the host over the session's encrypted connection, the host's
 * answers come back framed as a language server's — until either side ends.
 */
final class LanguageServerCommandTest extends TestCase
{
    private ?Process $host = null;

    private ?Process $relay = null;

    protected function tearDown(): void
    {
        $this->host?->stop(0);
        $this->relay?->stop(0);
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
        $this->host = new Process([\PHP_BINARY, __DIR__.'/../Support/fake-assist-host.php', 'lsp']);
        [$port, $fingerprint] = \explode(' ', self::firstLine($this->host), limit: 2);
        GuestSession::store($project->dir, [
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'fingerprint' => $fingerprint,
            'ticket' => \str_repeat('ab', times: 16),
            'name' => 'Bo',
            'languages' => ['php'],
        ]);

        return $project->dir;
    }

    /**
     * Run the bridge for $language on $root, the editor having sent $frames.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return array{CommandTester, string} the run, and what the editor was sent
     */
    private function bridge(string $root, string $language, array $frames): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$editor, $input] = $pair;
        foreach ($frames as $frame) {
            \fwrite($editor, JsonRpcFramer::encode($frame));
        }
        $output = \fopen('php://memory', mode: 'w+');
        $this->assertNotFalse($output);
        $application = new Application();
        $application->addCommand(new LanguageServerCommand($root, $input, $output, timeout: 2.0));
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
        $gone = new TempProject([]);
        GuestSession::store($gone->dir, [
            'host' => '127.0.0.1',
            'port' => $port,
            'fingerprint' => \str_repeat('0', times: 64),
            'ticket' => \str_repeat('ab', times: 16),
            'name' => 'Bo',
            'languages' => [],
        ]);
        [$tester] = $this->bridge($gone->dir, 'php', []);
        $this->assertStringContainsString('Cannot reach 127.0.0.1:'.$port, $tester->getErrorOutput());

        $stranger = new TempProject([]);
        [$tester] = $this->bridge($stranger->dir, 'php', []);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('is not a copy of a shared project', $tester->getErrorOutput());
    }

    public function testAHostThatTurnsItAwayOrNeverAnswersIsNoServer(): void
    {
        foreach (['deny:host' => '(host)', 'silent' => '()'] as $mode => $says) {
            $project = new TempProject([]);
            $this->host?->stop(0);
            $this->host = new Process([\PHP_BINARY, __DIR__.'/../Support/fake-assist-host.php', $mode]);
            [$port, $fingerprint] = \explode(' ', self::firstLine($this->host), limit: 2);
            GuestSession::store($project->dir, [
                'host' => '127.0.0.1',
                'port' => (int) $port,
                'fingerprint' => $fingerprint,
                'ticket' => \str_repeat('ab', times: 16),
                'name' => 'Bo',
                'languages' => ['php'],
            ]);

            [$tester] = $this->bridge($project->dir, 'php', []);
            $this->assertSame(1, $tester->getStatusCode(), $mode);
            $this->assertStringContainsString('The host runs no php language server for this session '.$says, $tester->getErrorOutput(), $mode);
        }
    }

    public function testThroughARelayTheBridgeStillReachesTheHost(): void
    {
        $this->relay = new Process([\PHP_BINARY, __DIR__.'/../Support/assist-relay.php']);
        $relayPort = self::firstLine($this->relay);
        $this->host = new Process([\PHP_BINARY, __DIR__.'/../Support/fake-assist-host.php', 'lsp'], env: ['PORTA_ASSIST_RELAY' => $relayPort]);
        [$port, $fingerprint, $session] = \explode(' ', self::firstLine($this->host), limit: 3);
        $this->assertSame($relayPort, $port, 'reached at the relay');
        $session = [
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'fingerprint' => $fingerprint,
            'ticket' => \str_repeat('ab', times: 16),
            'name' => 'Bo',
            'languages' => ['php'],
            'relay' => $session,
        ];
        $project = new TempProject([]);
        GuestSession::store($project->dir, $session);

        [$tester, $sent] = $this->bridge($project->dir, 'php', [
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => []],
            ['jsonrpc' => '2.0', 'method' => 'exit'],
        ]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame(JsonRpcFramer::encode(['jsonrpc' => '2.0', 'id' => 7, 'result' => 'initialize']), $sent);

        // A session the relay does not know: the relay says so.
        $gone = new TempProject([]);
        GuestSession::store($gone->dir, ['relay' => \str_repeat('0', times: 16)] + $session);
        [$tester] = $this->bridge($gone->dir, 'php', []);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The relay at 127.0.0.1:'.$port.' says no such session', $tester->getErrorOutput());
    }
}
