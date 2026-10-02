<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Event\FileRenamed;
use Porta\Event\WorkingTreeChanged;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Workbench\AgentActivityKind;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The host's files as a guest changes them — written, moved, deleted, through
 * the host's editor — and what changes under the host, sent on; the agents at
 * work on either side, shown to the other; following someone around.
 */
final class HostFilesE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\necho 'a';\n",
            'README.md' => "# demo\nsecond line\n",
            'notes.txt' => "notes\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
    }

    private function editor(Tui $tui, VirtualTerminal $terminal, Ide $ide): string
    {
        $invite = $this->invited($ide);
        $this->askIn($invite, 'Ana');
        $this->awaitPainted($tui, $terminal, 'Ana asks to join');
        $terminal->simulateInput("\r");

        return Wire::string($this->expect('welcome'), 'peer');
    }

    private function emit(Ide $ide, object $event): void
    {
        Services::resolve($ide, EventDispatcherInterface::class)->dispatch($event);
    }

    public function testAGuestsFileOperationsAreTheHostsToMake(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root): void {
                $this->editor($tui, $terminal, $ide);
                $this->say(['t' => 'manifest']);
                $this->expect('manifest');

                // Written: a new file, and one the host does not have open.
                $this->say(['t' => 'write', 'path' => 'src/New.php', 'data' => \base64_encode('<?php // new')]);
                $this->say(['t' => 'write', 'path' => 'notes.txt', 'data' => \base64_encode('rewritten')]);
                $this->say(['t' => 'write', 'path' => 'src/A.php', 'data' => \base64_encode('open on the host')]);
                $this->assertSame('the host has it open', $this->expect('error')['message']);
                $this->say(['t' => 'write', 'path' => 'src/Bad.php', 'data' => '***']);
                $this->assertSame('<?php // new', \file_get_contents($root.'/src/New.php'));
                $this->assertSame('rewritten', \file_get_contents($root.'/notes.txt'));
                $this->assertFileDoesNotExist($root.'/src/Bad.php', 'not base64: nothing written');
                // Where no listing looks, what a guest wrote is still the others' to fetch.
                $this->say(['t' => 'write', 'path' => 'node_modules/w.js', 'data' => \base64_encode('mine')]);
                $this->say(['t' => 'fetch', 'paths' => ['node_modules/w.js']]);
                $this->assertSame('mine', \base64_decode(Wire::string($this->expect('file'), 'data'), strict: true));
                $this->expect('fetched');

                // Moved and deleted, the host's editor doing it: everyone told.
                $this->say(['t' => 'move', 'from' => 'src/New.php', 'to' => 'src/Moved.php']);
                $this->assertSame(['src/New.php', 'src/Moved.php'], [($moved = $this->expect('moved'))['from'], $moved['to']]);
                $this->say(['t' => 'move', 'from' => 'src/Gone.php', 'to' => 'src/Else.php']);
                $this->assertSame('src/Gone.php', $this->expect('error')['path']);
                $this->say(['t' => 'delete', 'path' => 'src/Moved.php']);
                $this->assertSame('src/Moved.php', $this->expect('deleted')['path']);
                $this->assertFileDoesNotExist($root.'/src/Moved.php');
                $terminal->simulateInput('!');
                $this->say(['t' => 'delete', 'path' => 'src/A.php']);
                $this->assertStringContainsString('unsaved changes', Wire::string($this->expect('error'), 'message'));

                // Changed under the editor: listed again once it settles.
                \file_put_contents($root.'/notes.txt', data: 'changed on disk');
                \unlink($root.'/README.md');
                $this->emit($ide, new WorkingTreeChanged());
                $this->emit($ide, new WorkingTreeChanged());
                $changed = $this->expect('changed');
                $this->assertSame([['notes.txt', 15, \hash('xxh128', data: 'changed on disk')]], $changed['files']);
                $this->assertSame(['README.md'], $changed['gone']);
                $this->emit($ide, new WorkingTreeChanged());
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error');
                $this->assertSame([], \array_filter($this->inbox, static fn (array $m): bool => 'changed' === $m['t']), 'nothing changed, nothing said');

                // The file moves outside the project (a shell's `mv`; the plugin
                // API's own move stays inside it): to the others, it is gone.
                $outside = new TempProject([]);
                \rename($root.'/notes.txt', $outside->path('notes.txt'));
                $this->emit($ide, new FileRenamed($root.'/notes.txt', $outside->path('notes.txt')));
                $this->assertSame('notes.txt', $this->expect('deleted')['path']);
                $this->hangUp();
            },
        ]);
    }

    public function testAgentsOnEitherSideAreParticipantsAndAnyoneCanBeFollowed(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root): void {
                $ana = $this->editor($tui, $terminal, $ide);

                // The host's own agent, told to the guest; one of the plugin's own peers is not.
                $this->emit($ide, new AgentActivity('claude', 'Claude', $root.'/src/A.php', AgentActivityKind::Editing, 1, 2, 10.0));
                $this->emit($ide, new AgentActivity('assist:'.$ana, 'Ana', $root.'/src/A.php', AgentActivityKind::Editing, 1, 2, 10.0));
                $this->emit($ide, new AgentActivity('elsewhere', 'X', '/elsewhere/a.php', AgentActivityKind::Editing, 1, 2, 10.0));
                // The host's own caret may come first: on a loaded box its first
                // sample lands after the welcome, and is broadcast when taken.
                do {
                    $claude = $this->expect('presence');
                } while ('host' === $claude['peer']);
                $this->assertSame(['host~claude', 'Claude', true, 'src/A.php', 1, 2], [$claude['peer'], $claude['name'], $claude['agent'], $claude['path'], $claude['line'], $claude['column']]);

                // The guest's agent, shown here with its name.
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->say(['t' => 'agent', 'id' => 'codex', 'name' => 'Codex', 'path' => 'src/A.php', 'line' => 0, 'column' => 1]);
                $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Codex');

                // Everyone in the participants, and the host follows the guest.
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'Codex');
                $terminal->simulateInput("\r");
                $this->awaitPainted($tui, $terminal, 'Follow Ana');
                $terminal->simulateInput("\r");
                $this->await(static fn (): bool => self::toasted($ide, 'Following Ana'), what: 'following');
                $this->say(['t' => 'presence', 'path' => 'README.md', 'line' => 1, 'column' => 3, 'top' => 1]);
                $this->await(fn (): bool => $root.'/README.md' === $this->api($ide)->activeFile(), what: 'the file Ana is in, opened');
                $this->assertSame(['line' => 1, 'column' => 3, 'top' => 1], $this->api($ide)->caretPosition(), 'at her caret, scrolled as she is');
                $terminal->simulateInput(self::DOWN);
                $this->await(static fn (): bool => self::toasted($ide, 'Stopped following Ana'), what: 'the user moved on');

                // Following the host's agent: where it goes, the host's editor goes.
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'Codex');
                $terminal->simulateInput(self::DOWN."\r");
                $this->await(static fn (): bool => self::toasted($ide, 'Following Claude'), what: 'following the agent');
                $this->emit($ide, new AgentActivity('claude', 'Claude', $root.'/src/A.php', AgentActivityKind::Editing, 1, 4, 10.0));
                $this->await(fn (): bool => $root.'/src/A.php' === $this->api($ide)->activeFile(), what: 'the agent\'s file');
                $this->emit($ide, new AgentLeft('claude'));
                $this->await(static fn (): bool => self::toasted($ide, 'Stopped following Claude'), what: 'gone, not followed');
                $this->assertSame('host~claude', $this->expect('left')['peer']);
                $this->emit($ide, new AgentLeft('claude'));

                // The guest's agent leaves, then the guest — and with it, anything of its own.
                $this->say(['t' => 'agentLeft', 'id' => 'codex']);
                $this->say(['t' => 'agent', 'id' => 'other', 'name' => 'Other', 'path' => 'notes.txt', 'line' => -1, 'column' => 0]);
                $this->say(['t' => 'presence', 'path' => 'notes.txt', 'line' => -1, 'column' => 0]);
                $this->say(['t' => 'close', 'path' => 'src/A.php']);
                $this->say(['t' => 'bye']);
                $this->await(static fn (): bool => self::toasted($ide, 'Ana left'), what: 'Ana gone');
                $this->hangUp();
            },
        ]);
    }

    public function testAGuestsChangeToAFileTheHostClosedReopensIt(): void
    {
        $project = self::project();
        $path = (string) \realpath($project->path('src/A.php'));

        $this->runIde($project, $path, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($path): void {
                $this->editor($tui, $terminal, $ide);
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 0, 'ops' => [16, '// one']]);
                $this->expect('ack');

                $this->api($ide)->openBuffers()->save($path);
                $terminal->simulateInput("\x1bw"); // alt+w: close the tab
                $this->await(fn (): bool => '' === $this->bufferText($ide, $path), what: 'the tab closed');
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 1, 'ops' => [22, '!']]);
                $this->expect('ack');
                $this->assertSame("<?php\necho 'a';\n// one!", $this->bufferText($ide, $path), 'opened again with everything the session agreed on');

                // Out of step: a revision the host never had, ops that are not ops, a file never opened.
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 9, 'ops' => [1]]);
                $this->assertSame('src/A.php', $this->expect('reopen')['path']);
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 2, 'ops' => 'garbage']);
                $this->expect('reopen');
                $this->say(['t' => 'op', 'path' => 'README.md', 'rev' => 0, 'ops' => [1]]);
                $this->expect('reopen');

                // Nothing of the host's to undo or redo here: the keys do nothing.
                $terminal->simulateInput("\x1a");
                $terminal->simulateInput("\x19");
                $this->say(['t' => 'fetch', 'paths' => ['src/A.php']]);
                $this->expect('file');
                $this->assertSame("<?php\necho 'a';\n// one!", $this->bufferText($ide, $path));

                // Stopping tells the guest the session is over.
                $this->command($ide, 'assist:stop');
                $this->assertSame('ended', $this->expect('bye')['reason']);
                $this->hangUp();
            },
        ]);
    }

    public function testABatchLargerThanTheQueueArrivesWhole(): void
    {
        $big = \str_repeat('x', times: 3 * 1_048_576);
        $project = new TempProject([
            'a.bin' => $big,
            'b.bin' => $big,
            'c.bin' => $big,
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('a.bin'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($big): void {
                $this->editor($tui, $terminal, $ide);

                $this->say(['t' => 'fetch', 'paths' => ['a.bin', 'b.bin', 'c.bin']]);

                foreach (['a.bin', 'b.bin', 'c.bin'] as $name) {
                    $file = $this->expect('file', timeout: 20.0);
                    $this->assertSame($name, $file['path']);
                    $this->assertSame($big, \base64_decode(Wire::string($file, 'data'), strict: true));
                }
                $this->expect('fetched');
                $this->assertFalse($this->hungUp(), 'twelve megabytes of base64, and still connected');
                $this->hangUp();
            },
        ]);
    }
}
