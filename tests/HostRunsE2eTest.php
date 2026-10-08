<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A guest starting one of the host's run configurations: only once the host
 * has said they may, only one the host offers — and the run's terminal is
 * shown to everyone.
 */
final class HostRunsE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    private const string LABEL = 'Run current file: A.php';

    /** Pick, in the host's participants, $name and the action $downs rows below the first. */
    private function permit(Tui $tui, VirtualTerminal $terminal, Ide $ide, string $name, string $action, int $downs): void
    {
        $this->command($ide, 'assist:participants');
        $this->awaitPainted($tui, $terminal, $name);
        $terminal->simulateInput(self::DOWN."\r");
        $this->awaitPainted($tui, $terminal, $action);
        $terminal->simulateInput(\str_repeat(self::DOWN, $downs)."\r");
    }

    public function testAGuestTheHostLetsRunsTheHostsConfigurations(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\necho 'ran on the host', PHP_EOL;\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $invite = $this->invited($ide);
                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $this->expect('welcome');

                // Offered, but not hers to run yet.
                $this->say(['t' => 'runs']);
                $offer = $this->expect('runs');
                $this->assertContains(self::LABEL, Wire::strings($offer, 'labels'));
                $this->assertFalse($offer['allowed']);
                $this->say(['t' => 'run', 'label' => self::LABEL]);
                $this->assertSame(['t' => 'run-refused', 'label' => self::LABEL, 'reason' => 'not-allowed'], $this->expect('run-refused'));

                // Let: she runs it, everyone sees it run, and the host is told.
                $this->permit($tui, $terminal, $ide, 'Ana', 'Let them run the run configurations', downs: 5);
                $this->assertTrue($this->expect('running')['allowed']);
                $this->say(['t' => 'runs']);
                $this->assertTrue($this->expect('runs')['allowed']);
                $this->say(['t' => 'run', 'label' => 'rm -rf /']);
                $this->assertSame('unknown', $this->expect('run-refused')['reason'], 'never a command, only what the host offers');
                $this->say(['t' => 'run', 'label' => self::LABEL]);
                $this->assertSame('run:'.self::LABEL, $this->expect('terminal')['id']);
                $this->await(static fn (): bool => self::toasted($ide, 'Ana ran '.self::LABEL), what: 'the host told');
                $printed = '';
                $this->await(function () use (&$printed): bool {
                    $this->drain();
                    foreach ($this->inbox as $i => $message) {
                        if ('terminal-out' !== $message['t']) {
                            continue;
                        }
                        $printed .= \base64_decode(Wire::string($message, 'data'), strict: true);
                        unset($this->inbox[$i]);
                    }

                    return \str_contains($printed, 'ran on the host');
                }, what: 'the run\'s output, shared');

                // Run again: the terminal is shared already, and stays so.
                $this->say(['t' => 'run', 'label' => self::LABEL]);
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error'); // answered in order: the run before it was handled
                $this->drain();
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'terminal-end' === $m['t'])), 'not unshared by a second run');

                // Not any more.
                $this->permit($tui, $terminal, $ide, 'Ana', 'Stop them running the run configurations', downs: 6);
                $this->assertFalse($this->expect('running')['allowed']);
                $this->say(['t' => 'run', 'label' => self::LABEL]);
                $this->assertSame('not-allowed', $this->expect('run-refused')['reason']);
                $this->command($ide, 'assist:stop');
                $this->hangUp();
            },
        ]);
    }

    public function testAGuestBackOnItsTicketMayDoWhatItWasLetDoBefore(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $invite = $this->invited($ide);
                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $welcome = $this->expect('welcome');
                $this->permit($tui, $terminal, $ide, 'Ana', 'Let them run the run configurations', downs: 5);
                $this->assertTrue($this->expect('running')['allowed']);

                // Back before the host saw the old connection go.
                $old = $this->peer;
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'ticket' => $welcome['ticket']]);
                $this->expect('welcome');
                $this->assertTrue($this->expect('running')['allowed'], 'still hers');
                $this->assertIsResource($old);
                \fclose($old);

                // Gone, the host seeing it go — then back.
                $this->dropPeer();
                $this->await(static fn (): bool => self::toasted($ide, 'Ana left'), what: 'the host to see her go');
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'ticket' => $welcome['ticket']]);
                $this->expect('welcome');
                $this->assertTrue($this->expect('running')['allowed'], 'kept while she was away');
                $this->command($ide, 'assist:stop');
                $this->hangUp();
            },
        ]);
    }
}
