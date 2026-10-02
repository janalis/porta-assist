<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Event\AgentActivity;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\WorkingTreeChanged;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\AgentActivityKind;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Two guests at once — the second sees where the first already is — and the
 * edges: what the host does outside the project, a save the disk refuses, a
 * file that is not text, a shared file renamed under everyone.
 */
final class HostEdgesE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\necho 'a';\n",
            'pic.bin' => "\x89PNG\x00\xff\xfe",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
    }

    /** A guest named $name, let in to edit: its welcome. @return array<string, mixed> */
    private function guest(Tui $tui, VirtualTerminal $terminal, Invite $invite, string $name): array
    {
        $this->askIn($invite, $name);
        $this->awaitPainted($tui, $terminal, $name.' asks to join');
        $terminal->simulateInput("\r");

        return $this->expect('welcome');
    }

    public function testASecondGuestSeesWhereEveryoneAlreadyIs(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $root.'/src/A.php', [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root): void {
                // Before anyone listed the project, a change under the editor tells nobody.
                Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new WorkingTreeChanged());
                $invite = $this->invited($ide);

                // Ana: in a shared file, with an agent of her own; the host's agent at work too.
                $ana = $this->guest($tui, $terminal, $invite, 'Ana');
                $anaSocket = $this->peer;
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->say(['t' => 'presence', 'path' => 'src/A.php', 'line' => 99, 'column' => 0]);
                $this->say(['t' => 'agent', 'id' => 'codex', 'name' => 'Codex', 'path' => 'src/A.php', 'line' => 0, 'column' => 1]);
                $this->say(['t' => 'whatever']);
                Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new AgentActivity('claude', 'Claude', $root.'/src/A.php', AgentActivityKind::Editing, 1, 0, 10.0));
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->assertNotContains($ana['peer'], \array_column($this->inboxOf('presence'), 'peer'), 'her own caret is not told back to her');

                // Bo arrives: Ana's caret and both agents come with the welcome; Ana's caret again when he opens the file.
                $bo = $this->guest($tui, $terminal, $invite, 'Bo');
                $peers = \array_column($this->inboxOf('presence'), 'peer');
                $this->assertContains($ana['peer'], $peers);
                $this->assertContains('host~claude', $peers);
                $this->assertContains($ana['peer'].'~codex', $peers);
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $caret = $this->expect('presence');
                $this->assertSame([$ana['peer'], 2], [$caret['peer'], $caret['line']], 'where she is, clamped into the text');

                // Ana back on her ticket: her own agent is not told to her as someone else.
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'ticket' => $ana['ticket']]);
                $this->expect('welcome');
                $this->assertNotContains($ana['peer'].'~codex', \array_column($this->inboxOf('presence'), 'peer'));
                $this->assertIsResource($anaSocket);
                \fclose($anaSocket);
                $this->assertNotSame($ana['peer'], $bo['peer']);
                $this->hangUp();
            },
        ]);
    }

    public function testTheEdgesOfWhatTheHostShares(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);
        $outside = new TempProject(['far.txt' => 'far']);
        $far = (string) \realpath($outside->path('far.txt'));

        $this->runIde($project, $root.'/src/A.php', [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root, $outside, $far): void {
                $invite = $this->invited($ide);
                $this->guest($tui, $terminal, $invite, 'Ana');

                // Not text: not shared.
                $this->say(['t' => 'open', 'path' => 'pic.bin']);
                $this->assertSame('pic.bin', $this->expect('error')['path']);

                // The host selects: the guest is told where the selection began.
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $terminal->simulateInput("\x1b[1;2C\x1b[1;2C"); // shift+→ twice
                do {
                    $caret = $this->expect('presence');
                } while (2 !== $caret['column']);
                $this->assertSame([0, 0], [$caret['anchorLine'], $caret['anchorColumn']]);
                $terminal->simulateInput("\x1b[D\x1b[1;2D"); // ←, then shift+← back over one
                do {
                    $caret = $this->expect('presence');
                } while (null === $caret['anchorLine'] || 0 !== $caret['column']);
                $this->assertSame([0, 1], [$caret['anchorLine'], $caret['anchorColumn']], 'made backwards: the anchor is the far end');

                // The host's own files outside the project are nobody else's business.
                $buffers = $this->api($ide)->openBuffers();
                $buffers->open($far);
                $buffers->edit($far, 0, 0, 0, 0, '!');
                $buffers->save($far);
                // Moved and deleted by hand (the plugin API's own stay inside the
                // project): the host hears of it, and tells no one.
                $events = Services::resolve($ide, EventDispatcherInterface::class);
                \rename($far, $outside->path('farther.txt'));
                $events->dispatch(new FileRenamed($far, $outside->path('farther.txt')));
                \unlink($outside->path('farther.txt'));
                $events->dispatch(new FileDeleted($outside->path('farther.txt')));
                \file_put_contents($outside->path('x.txt'), data: 'x');
                $this->api($ide)->openFile($outside->path('x.txt'));
                $this->await(fn (): bool => \str_ends_with((string) $this->api($ide)->activeFile(), '/x.txt'), what: 'a file of nobody else\'s in front');
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $this->say(['t' => 'open', 'path' => 'barrier.php']);
                $this->expect('error');
                Probe::invoke($host, 'caret'); // the poll, now: nothing to tell
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'presence' === $m['t'] && \str_contains(Wire::string($m, 'path'), 'x.txt'))));

                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 0, 'ops' => [16, '// guest']]);
                $this->expect('ack');

                // The shared file renamed under everyone: it starts over under its new name.
                $buffers->move($root.'/src/A.php', $root.'/src/B.php');
                $moved = $this->expect('moved');
                $this->assertSame(['src/A.php', 'src/B.php'], [$moved['from'], $moved['to']]);
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 1, 'ops' => [1]]);
                $this->expect('reopen');
                $this->hangUp();
            },
        ]);
    }

    /**
     * The messages of type $type the host sent before it answered one more
     * request — it answers in order, so they are all here by then — taken
     * off the queue.
     *
     * @return list<array<string, mixed>>
     */
    private function inboxOf(string $type): array
    {
        $this->say(['t' => 'open', 'path' => 'barrier.php']);
        $this->expect('error');
        $found = \array_values(\array_filter($this->inbox, static fn (array $m): bool => $type === $m['t']));
        $this->inbox = \array_values(\array_filter($this->inbox, static fn (array $m): bool => $type !== $m['t']));

        return $found;
    }
}
