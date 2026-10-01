<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The host's debugger, shared: everyone is told where it stopped and its
 * stack, any guest reads a frame's variables, and a guest steps it only once
 * the host has let them.
 */
final class HostDebugE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    /** Ask in as $name and be let in. */
    private function letIn(Tui $tui, VirtualTerminal $terminal, Address $address, string $name): void
    {
        $this->askIn($address, $name);
        $this->awaitPainted($tui, $terminal, $name.' asks to join');
        $terminal->simulateInput("\r");
        $this->expect('welcome');
    }

    /**
     * The next debug state that says $status — the session reports each
     * step on its way there.
     *
     * @return array<string, mixed>
     */
    private function debugState(string $status): array
    {
        do {
            $state = $this->expect('debug');
        } while ($status !== $state['status']);

        return $state;
    }

    public function testTheHostsDebuggerIsSharedAndSteppedOnlyWhenLet(): void
    {
        $project = new TempProject([
            'prog.php' => "<?php\n// l2\n// l3\n// l4\n// l5\n// l6\n// l7\n// keep running\n",
            '.porta.json' => \json_encode(
                [
                    'enabledPlugins' => ['assist'],
                    'languages' => ['php' => ['debug' => ['adapter' => \PHP_BINARY.' '.\dirname(__DIR__, levels: 3).'/tests/Support/mock-dap-adapter.php']]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);

        $this->runIde($project, $project->path('prog.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $address = $this->share($ide);
                $this->letIn($tui, $terminal, $address, 'Ana');
                $ana = $this->peer;
                // Nobody debugging: nothing is said of it.
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error');
                $this->drain();
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'debug' === $m['t'])), 'not told of a debugger that is not there');

                // A breakpoint on line 5, and the host debugs: she is told where it stopped.
                $tui->getById('editor')->getDocument()->setCursor(4, 0);
                $tui->handleInput("\x1b[19;5~"); // ctrl+f8
                $tui->handleInput("\x1b[20;2~"); // shift+f9
                $paused = $this->debugState('paused');
                $this->assertSame(['prog.php', 4], [$paused['path'], $paused['line']]);
                $this->assertSame([['id' => 1, 'name' => '{main}', 'path' => 'prog.php', 'line' => 4], ['id' => 2, 'name' => 'require', 'path' => 'prog.php', 'line' => 0]], $paused['frames']);

                // A newcomer is told too.
                $this->letIn($tui, $terminal, $address, 'Bo');
                $this->assertSame('paused', $this->expect('debug')['status']);
                $this->hangUp();
                $this->peer = $ana;

                // Any guest reads a frame's variables.
                $this->say(['t' => 'debug-vars', 'frame' => 1]);
                $variables = $this->expect('debug-vars');
                $this->assertSame(1, $variables['frame']);
                $this->assertSame(
                    [['scope' => 'Locals', 'variables' => [['name' => '$x', 'value' => '42', 'reference' => 0], ['name' => '$arr', 'value' => 'array(2)', 'reference' => 1001]]]],
                    $variables['scopes'],
                );
                // And what one holds.
                $this->say(['t' => 'debug-children', 'reference' => 1001]);
                $children = $this->expect('debug-children');
                $this->assertSame([1001, [['name' => '0', 'value' => "'a'", 'reference' => 0], ['name' => '1', 'value' => "'b'", 'reference' => 0]]], [$children['reference'], $children['variables']]);

                // Not let: refused. Let: her step goes through.
                $this->say(['t' => 'debug-step', 'verb' => 'stepOver']);
                $this->expect('debug-refused');
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'Ana');
                $terminal->simulateInput(self::DOWN."\r");
                $this->awaitPainted($tui, $terminal, 'Let them step the debugger');
                $terminal->simulateInput(\str_repeat(self::DOWN, times: 7)."\r");
                $this->assertTrue($this->expect('debugging')['allowed']);
                $this->say(['t' => 'debug-step', 'verb' => 'stepOver']);
                $this->assertSame(5, $this->debugState('paused')['line'], 'one line on');
                $this->say(['t' => 'debug-step', 'verb' => 'stepIn']);
                $this->assertSame(6, $this->debugState('paused')['line']);
                $this->say(['t' => 'debug-step', 'verb' => 'stepOut']);
                $this->assertSame(7, $this->debugState('paused')['line']);
                $this->assertMatchesRegularExpression('/step: next.*step: stepIn.*step: stepOut/s', (string) $this->api($ide)->runs()->scrollback('debug'), 'each verb the step it names');
                $this->say(['t' => 'debug-step', 'verb' => 'nonsense']);
                // Resumed, it runs on; she pauses it where it is.
                $this->say(['t' => 'debug-step', 'verb' => 'continue']);
                $this->assertSame(['', -1, []], \array_values(\array_intersect_key($this->debugState('running'), \array_flip(['path', 'line', 'frames']))), 'running: nothing stopped');
                $this->say(['t' => 'debug-step', 'verb' => 'pause']);
                $this->assertSame(7, $this->debugState('paused')['line'], 'paused where it was');
                $this->api($ide)->runs()->debugger()->stop();
                $this->debugState('idle');
                $this->command($ide, 'assist:stop');
                $this->hangUp();
            },
        ]);
    }
}
