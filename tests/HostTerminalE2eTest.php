<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Run\RunController;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A terminal of the host's, shown to everyone: what it shows when it is
 * shared, what it prints after — and typed into by a guest only once the
 * host has said they may.
 */
final class HostTerminalE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    /** Ask in as $name and be let in: the welcome. @return array<string, mixed> */
    private function letIn(Tui $tui, VirtualTerminal $terminal, Invite $invite, string $name): array
    {
        $this->askIn($invite, $name);
        $this->awaitPainted($tui, $terminal, $name.' asks to join');
        $terminal->simulateInput("\r");

        return $this->expect('welcome');
    }

    /** A terminal message's bytes. @param array<string, mixed> $message */
    private static function bytes(array $message): string
    {
        return (string) \base64_decode(Wire::string($message, 'data'), strict: true);
    }

    public function testATerminalIsShownToEveryoneAndTypedIntoOnlyWhenAllowed(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $invite = $this->invited($ide);
                $this->command($ide, 'assist:share_terminal');
                $this->await(static fn (): bool => self::toasted($ide, 'No terminal to share'), what: 'nothing to share yet');

                $ana = $this->letIn($tui, $terminal, $invite, 'Ana');
                $runs = $this->api($ide)->runs();
                $runs->start('cat', [\PHP_BINARY, '-r', 'echo "ready\n"; while (false !== ($l = fgets(STDIN))) { echo "got ", $l; }']);
                $this->await(static fn (): bool => \str_contains((string) $runs->scrollback('run:cat'), 'ready'), what: 'the run to start');

                // Shared: what it shows now, then what it prints.
                $this->command($ide, 'assist:share_terminal');
                $this->awaitPainted($tui, $terminal, 'Share which terminal?');
                $this->assertStringNotContainsString('shared — pick to stop', \implode("\n", $this->screenGrid($tui, $terminal)), 'not shared yet, not said to be');
                $terminal->simulateInput("\r");
                $shown = $this->expect('terminal');
                $this->assertSame(['run:cat', 'cat'], [$shown['id'], $shown['title']]);
                $this->assertStringContainsString('ready', self::bytes($shown));

                // Not hers to type into, until the host says so.
                $this->say(['t' => 'terminal-in', 'id' => 'run:cat', 'data' => \base64_encode("no\n")]);
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error'); // answered in order: the line before it was handled, and refused
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'Ana');
                $terminal->simulateInput("\r");
                $this->awaitPainted($tui, $terminal, 'Let them type in shared terminals');
                $terminal->simulateInput(\str_repeat(self::DOWN, times: 3)."\r");
                $this->assertTrue($this->expect('typing')['allowed']);
                $this->say(['t' => 'terminal-in', 'id' => 'run:cat', 'data' => \base64_encode("yes\n")]);
                $this->say(['t' => 'terminal-in', 'id' => 'run:other', 'data' => \base64_encode("no\n")]);
                $printed = '';
                $this->await(function () use (&$printed): bool {
                    $this->drain();
                    foreach ($this->inbox as $i => $message) {
                        if ('terminal-out' !== $message['t']) {
                            continue;
                        }
                        $printed .= self::bytes($message);
                        unset($this->inbox[$i]);
                    }

                    return \str_contains($printed, 'got yes');
                }, what: 'her line, printed');
                $this->assertStringNotContainsString('got no', (string) $runs->scrollback('run:cat'), 'nothing typed before she was let');

                // A newcomer sees it too.
                $anaSocket = $this->peer;
                $this->assertSame(['run:cat'], $this->letIn($tui, $terminal, $invite, 'Bo')['terminals'], 'which are shared, by id');
                $this->assertStringContainsString('got yes', self::bytes($this->expect('terminal')));
                $this->peer = $anaSocket;

                // Read-only again; then no longer shared.
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'Ana');
                $terminal->simulateInput("\r");
                $this->awaitPainted($tui, $terminal, 'Stop them typing in shared terminals');
                $terminal->simulateInput(\str_repeat(self::DOWN, times: 4)."\r");
                $this->assertFalse($this->expect('typing')['allowed']);
                $this->command($ide, 'assist:share_terminal');
                $this->awaitPainted($tui, $terminal, 'shared — pick to stop');
                $terminal->simulateInput("\r");
                $this->assertSame('run:cat', $this->expect('terminal-end')['id']);

                // A terminal gone before it was picked, a guest gone before the host chose: nothing happens.
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $host->terminals()->toggle('run:gone', 'gone');
                $host->permit('nobody', 'typing', true);
                $this->assertSame([], $host->terminals()->shared());

                // Shared once more, and the session stops: the host's terminal is its own again.
                $this->command($ide, 'assist:share_terminal');
                $this->awaitPainted($tui, $terminal, 'Share which terminal?');
                $terminal->simulateInput("\r");
                $this->expect('terminal');
                $watchers = static fn (): mixed => Probe::get(Services::resolve($ide, RunController::class), 'watchers');
                $this->assertArrayHasKey('run:cat', $watchers());
                $this->command($ide, 'assist:stop');
                $this->expect('bye');
                $this->assertArrayNotHasKey('run:cat', $watchers(), 'its output no longer read for anyone');
                $this->assertNotSame('', Wire::string($ana, 'peer'));
                $this->hangUp();
            },
        ]);
    }
}
