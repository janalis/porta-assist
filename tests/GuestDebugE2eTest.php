<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The host's debugger, from the guest's end: told where it stopped, its stack
 * listed beside the step verbs, a frame's variables shown — and what is no
 * longer true once the host is gone, forgotten.
 */
final class GuestDebugE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use ScreenReads;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    private const string DOWN = "\x1b[B";

    private function welcome(): void
    {
        $this->acceptGuest();
        $this->expect('hello');
        $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'edit', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);
    }

    /**
     * Paused: a frame of the project's opened at its line, its variables
     * listed and copied — and what one of them holds; one outside the
     * project, its variables asked for all the same.
     *
     * @param \Closure(string): bool $said
     */
    private function framesAndVariables(Tui $tui, VirtualTerminal $terminal, Ide $ide, \Closure $said): void
    {
        // A frame here: opened at its line, its variables asked for and shown; one copied.
        $this->command($ide, 'assist:debugger');
        $this->awaitPainted($tui, $terminal, '{main}');
        $grid = $this->screenGrid($tui, $terminal);
        $this->assertStringContainsString('src/A.php:3', $grid[$this->rowOf($grid, '{main}') ?? 0], 'each frame with where it is, its line counted from one');
        $this->assertStringNotContainsString(':', $grid[$this->rowOf($grid, ' lib ') ?? 0], 'one outside the project: no place');
        $terminal->simulateInput(\str_repeat(self::DOWN, times: 4)."\r");
        $this->assertSame(1, $this->expect('debug-vars')['frame']);
        $this->assertSame(['line' => 2], \array_intersect_key((array) $this->api($ide)->caretPosition(), ['line' => 0]));
        $this->say([
            't' => 'debug-vars',
            'frame' => 1,
            'scopes' => ['junk', ['scope' => 'Locals', 'variables' => [['name' => '$x', 'value' => '42'], 'junk']], ['scope' => 'Globals', 'variables' => [['name' => '$g', 'value' => '7']]]],
        ]);
        $this->awaitPainted($tui, $terminal, '$x = 42');
        $this->awaitPainted($tui, $terminal, '$g = 7'); // every scope, past what is not one
        $terminal->simulateInput("\r");
        $this->assertStringContainsString("\x1b]52;c;".\base64_encode('42'), $terminal->getOutput(), 'its value copied');

        // One that holds more: opened, what it holds asked of the host and listed under its name.
        $this->say([
            't' => 'debug-vars',
            'frame' => 1,
            'scopes' => [['scope' => 'Locals', 'variables' => [['name' => '$x', 'value' => '42'], ['name' => '$user', 'value' => 'User', 'reference' => 5]]]],
        ]);
        $this->awaitPainted($tui, $terminal, '$user = User');
        $this->awaitPainted($tui, $terminal, 'Locals '.Glyphs::ELLIPSIS);
        $grid = $this->screenGrid($tui, $terminal);
        $this->assertStringContainsString(Glyphs::ELLIPSIS, $grid[$this->rowOf($grid, '$user = User') ?? 0], 'one that holds more says so');
        $this->assertStringNotContainsString(Glyphs::ELLIPSIS, $grid[$this->rowOf($grid, '$x = 42') ?? 0], 'one that holds nothing more does not');
        $terminal->simulateInput(self::DOWN."\r");
        $this->assertSame(5, $this->expect('debug-children')['reference']);
        $this->say(['t' => 'debug-children', 'reference' => 5, 'variables' => [['name' => 'email', 'value' => "'ana@example.org'", 'reference' => 0]]]);
        $this->awaitPainted($tui, $terminal, "email = 'ana@example.org'");
        $this->awaitPainted($tui, $terminal, '─ $user ─'); // listed under its name, the window's title
        $terminal->simulateInput("\r");
        $this->assertStringContainsString("\x1b]52;c;".\base64_encode("'ana@example.org'"), $terminal->getOutput());
        $this->say(['t' => 'debug-children', 'reference' => 9, 'variables' => []]);
        $this->await(static fn (): bool => $said('No variables'), what: 'nothing held');

        // A frame outside the project: nothing to open, its variables still asked for.
        $this->command($ide, 'assist:debugger');
        $this->awaitPainted($tui, $terminal, 'lib');
        $terminal->simulateInput(\str_repeat(self::DOWN, times: 5)."\r");
        $this->assertSame(2, $this->expect('debug-vars')['frame']);
        $this->say(['t' => 'debug-vars', 'frame' => 2, 'scopes' => []]);
        $this->await(static fn (): bool => $said('No variables'), what: 'none');
    }

    public function testTheGuestWatchesAndStepsTheHostsDebugger(): void
    {
        $invite = $this->listen();
        $project = new TempProject([
            'src/A.php' => "<?php\n// two\n// three\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, ['invite' => $invite, 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $said = static fn (string $text): bool => \in_array($text, Toasts::all($ide), strict: true);
                $this->command($ide, 'assist:debugger');
                $this->await(static fn (): bool => $said('Not in a session'), what: 'not in yet');
                $this->welcome();
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to Hal'), what: 'in');
                $this->command($ide, 'assist:debugger');
                $this->await(static fn (): bool => $said('The host is not debugging'), what: 'not debugging');
                // Idle while idle: nothing to say.
                $this->say(['t' => 'debug', 'status' => 'idle', 'path' => '', 'line' => -1, 'frames' => []]);
                $this->say(['t' => 'debug', 'status' => 'running', 'path' => '', 'line' => -1, 'frames' => []]);
                // Running says nothing by itself: awaited where it lands, then paused from here.
                $this->await(function () use ($ide): bool {
                    $guest = Probe::get($this->plugin($ide), 'guest');

                    return \is_object($guest) && 'running' === Probe::get(Probe::get($guest, 'debug'), 'status');
                }, what: 'running');
                $this->command($ide, 'assist:debugger');
                $this->awaitPainted($tui, $terminal, 'Pause Program');
                $terminal->simulateInput("\r");
                $this->assertSame('pause', $this->expect('debug-step')['verb']);
                $this->assertFalse($said('The host stopped debugging'), 'it never started');

                // Paused: said, and listed.
                $this->say([
                    't' => 'debug',
                    'status' => 'paused',
                    'path' => 'src/A.php',
                    'line' => 2,
                    'frames' => [
                        ['id' => 1, 'name' => '{main}', 'path' => 'src/A.php', 'line' => 2],
                        ['name' => 'no id'], // left out — not the frames after it
                        ['id' => 2, 'name' => 'lib', 'path' => '', 'line' => 0],
                        'junk',
                    ],
                ]);
                $this->await(static fn (): bool => $said('The host\'s debugger paused at src/A.php:3'), what: 'paused, said');
                $this->command($ide, 'assist:debugger');
                $this->awaitPainted($tui, $terminal, 'Host\'s debugger — src/A.php:3');
                $terminal->simulateInput(self::DOWN."\r");
                $this->assertSame('stepOver', $this->expect('debug-step')['verb']);

                $this->framesAndVariables($tui, $terminal, $ide, $said);

                // What the host says about stepping, and the end of it.
                $this->say(['t' => 'debugging', 'allowed' => true]);
                $this->await(static fn (): bool => $said('You can step the host\'s debugger'), what: 'let');
                $this->say(['t' => 'debugging', 'allowed' => false]);
                $this->await(static fn (): bool => $said('You can no longer step the host\'s debugger'), what: 'no longer');
                $this->say(['t' => 'debug-refused']);
                $this->await(static fn (): bool => $said('The host has not let you step its debugger'), what: 'refused');
                $this->say(['t' => 'debug', 'status' => 'idle', 'path' => '', 'line' => -1, 'frames' => []]);
                $this->await(static fn (): bool => $said('The host stopped debugging'), what: 'stopped');

                // Paused again, then the host is lost: back, nothing is paused any more.
                $this->say(['t' => 'debug', 'status' => 'paused', 'path' => 'src/A.php', 'line' => 0, 'frames' => []]);
                $this->await(static fn (): bool => $said('The host\'s debugger paused at src/A.php:1'), what: 'paused again');
                $this->dropPeer();
                $this->welcome();
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to Hal'), what: 'back');
                // The same words as at the start: wait for those to go, so it is THIS answer.
                $this->await(static fn (): bool => !$said('The host is not debugging'), what: 'the earlier answer gone');
                $this->command($ide, 'assist:debugger');
                $this->await(static fn (): bool => $said('The host is not debugging'), what: 'forgotten');
                $this->hangUp();
            },
        ]);
    }
}
