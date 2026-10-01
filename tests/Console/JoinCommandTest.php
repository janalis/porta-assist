<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Console\JoinCommand;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\Config\IdeConfig;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `porta assist <invite>`, against a host in a process of its own: asked
 * in, the copy brought in line — only what differs fetched, what the host
 * lacks removed — the session written for the IDE, and the IDE opened on it.
 */
final class JoinCommandTest extends TestCase
{
    private const string KEY = '00112233445566778899aabbccddeeff';

    private TempProject $home;

    /** @var list<string> */
    private array $launched = [];

    private ?Process $host = null;

    private ?Process $relay = null;

    protected function setUp(): void
    {
        $this->home = new TempProject([]);
        $this->launched = [];
    }

    protected function tearDown(): void
    {
        $this->host?->stop(0);
        $this->relay?->stop(0);
    }

    /** A fake host serving $mode — through the relay on $relay, if given; the invite to it. */
    private function host(string $mode, ?TempProject $project = null, ?string $relay = null): string
    {
        $this->host = new Process([\PHP_BINARY, __DIR__.'/../Support/fake-assist-host.php', $mode, $project->dir ?? '/nonexistent'], env: null === $relay ? null : ['PORTA_ASSIST_RELAY' => $relay]);
        [$port, $fingerprint, $session] = \explode(' ', self::firstLine($this->host).' ', limit: 3);

        return '127.0.0.1:'.$port.('' === \trim($session) ? '' : '/'.\trim($session)).'#'.self::KEY.'.'.$fingerprint;
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

    private function join(string $invite, int $exit = 0, ?string $name = 'Bo'): CommandTester
    {
        $application = new Application();
        $application->addCommand(new JoinCommand(
            function (string $root) use ($exit): int {
                $this->launched[] = $root;

                return $exit;
            },
            $this->home->dir,
            timeout: 0.5,
            answerTimeout: 1.0,
        ));
        $tester = new CommandTester($application->find('assist'));
        $tester->execute(['invite' => $invite, ...(null === $name ? [] : ['--name' => $name])], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTheCopyIsBroughtInLineAndTheIdeOpenedOnIt(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n", 'same.txt' => 'same', 'changed.txt' => 'new']);
        $copy = $this->home->path('.porta/assist/0123456789abcdef/demo');
        \mkdir($copy.'/src', recursive: true);
        \file_put_contents($copy.'/same.txt', data: 'same');
        \file_put_contents($copy.'/changed.txt', data: 'old');
        \file_put_contents($copy.'/gone.txt', data: 'the host no longer has it');
        \file_put_contents($copy.'/.porta.json', data: '{"enabledPlugins": ["git"], "theme": "light"}');

        $invite = $this->host('pending', $project);
        $tester = $this->join($invite);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertStringContainsString('Waiting for the host to let you in', $tester->getDisplay());
        $this->assertStringContainsString('In, reading along.', $tester->getDisplay());
        $this->assertStringContainsString('3 files shared: 2 to fetch, 1 removed.', $tester->getDisplay());
        $root = (string) \realpath($copy);
        $this->assertSame([$root], $this->launched);
        $this->assertTrue(new OnboardingState($root)->hasSeenWelcome(), 'no first-run card on someone else\'s project');
        $this->assertSame("<?php\n", \file_get_contents($root.'/src/A.php'));
        $this->assertSame('new', \file_get_contents($root.'/changed.txt'));
        $this->assertFileDoesNotExist($root.'/gone.txt');
        $session = GuestSession::load($root);
        $this->assertSame(
            [
                'host' => '127.0.0.1',
                'port' => $session['port'] ?? 0,
                'fingerprint' => \substr($invite, offset: -64),
                'ticket' => \str_repeat('feedface', times: 4),
                'name' => 'Bo',
                'languages' => ['php', 'twig'],
                'relay' => null,
            ],
            $session,
            'the certificate pinned for the IDE\'s reconnects too',
        );
        $this->assertSame(
            ['enabledPlugins' => ['git', 'assist'], 'theme' => 'light'],
            \json_decode((string) \file_get_contents($root.'/.porta.json'), associative: true),
            'the copy\'s own settings, with this plugin on',
        );
    }

    public function testThroughARelayTheSessionRemembersIt(): void
    {
        $this->relay = new Process([\PHP_BINARY, __DIR__.'/../Support/assist-relay.php']);
        $relay = self::firstLine($this->relay);
        $invite = $this->host('welcome', new TempProject(['a.txt' => 'a']), $relay);
        $this->assertMatchesRegularExpression('#^127\.0\.0\.1:'.$relay.'/[0-9a-f]{16}\##', $invite);

        $tester = $this->join($invite);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $session = GuestSession::load($this->launched[0] ?? '');
        $this->assertSame((int) $relay, $session['port'] ?? null);
        $this->assertSame(\substr($invite, offset: \strlen('127.0.0.1:'.$relay.'/'), length: 16), $session['relay'] ?? null, 'the IDE reconnects through it too');
    }

    public function testAFirstJoinEnablesThePluginOnTopOfTheDefaults(): void
    {
        $project = new TempProject(['a.txt' => 'a']);
        $tester = $this->join($this->host('chatty', $project), exit: 3, name: null);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Porta exited with 3', $tester->getErrorOutput());
        $config = \json_decode((string) \file_get_contents($this->launched[0].'/.porta.json'), associative: true);
        $user = \getenv('USER');
        $this->assertSame(\is_string($user) && '' !== $user ? $user : 'guest', GuestSession::load($this->launched[0])['name'] ?? null, 'no name given: the login name');
        $this->assertSame([...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'assist'], $config['enabledPlugins'] ?? null);
    }

    public function testEachWayOfNotGettingInIsSaid(): void
    {
        $cases = [
            'deny:host' => 'The host turned you away',
            'deny:full' => 'The session is full',
            'deny:unanswered' => 'The host did not answer',
            'deny:version' => 'speaks another version of assist',
            'deny:expired' => 'The invite is not valid any more',
            'silent' => 'The host did not answer',
            'garbled' => 'a welcome this Porta does not understand',
            'no-list' => 'The host did not list its files',
            'cut' => 'The host stopped sending files',
        ];
        $project = new TempProject(['a.txt' => 'a']);
        foreach ($cases as $mode => $says) {
            $invite = $this->host($mode, $project);
            $started = \hrtime(true);
            $tester = $this->join($invite);
            // The command's own timeout, not the socket's minute: a host's
            // TLS session ticket makes the socket readable with nothing to read.
            $this->assertLessThan(20.0, (\hrtime(true) - $started) / 1e9, $mode.' answered in its own time');
            $this->assertSame(1, $tester->getStatusCode(), $mode.': '.$tester->getDisplay());
            $this->assertStringContainsString($says, $tester->getErrorOutput(), $mode);
            $this->host?->stop(0);
        }
        $this->assertSame([], $this->launched, 'no IDE opened for any of them');
    }

    public function testABadInviteOrAnAbsentHostIsSaid(): void
    {
        $this->assertStringContainsString('is not host:port#token.fingerprint', $this->join('nonsense')->getErrorOutput());
        // Port 1 on loopback: nothing listens there, and nothing unprivileged can
        // start to — a port closed by this test could be taken by a parallel one.
        $port = 1;
        $this->assertStringContainsString('Cannot reach 127.0.0.1:'.$port, $this->join('127.0.0.1:'.$port.'#'.self::KEY.'.'.\str_repeat('0', times: 64))->getErrorOutput());
    }

    public function testAHostWithAnotherCertificateOrNoneIsNotJoined(): void
    {
        $project = new TempProject(['a.txt' => 'a']);
        $invite = $this->host('welcome', $project);
        $impostor = \substr($invite, offset: 0, length: -64).\str_repeat('0', times: 64);
        $this->assertStringContainsString('The host is not the one the invite names', $this->join($impostor)->getErrorOutput());
        $this->host?->stop(0);
        $this->assertStringContainsString('The secure connection to the host failed', $this->join($this->host('plain', $project))->getErrorOutput());
        $this->assertSame([], $this->launched);
    }
}
