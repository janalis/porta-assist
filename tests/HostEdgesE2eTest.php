<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\Role;
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

    /** Each guest hears of the others as they come, move, change role, put agents to work and go. */
    public function testEachGuestHearsOfTheOthers(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $root.'/src/A.php', [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root): void {
                $invite = $this->invited($ide);
                $ana = $this->guest($tui, $terminal, $invite, 'Ana');
                $anaSide = $this->side();
                $boName = \str_repeat('B', times: 40);
                $this->askIn($invite, $boName.'BBBBB');
                $this->awaitPainted($tui, $terminal, $boName.' asks to join'); // cut to forty, here as everywhere
                $terminal->simulateInput("\r");
                $bo = $this->expect('welcome');
                $this->assertSame(
                    [['host', 'host'], ['id' => $ana['peer'], 'name' => 'Ana', 'role' => 'edit']],
                    [[$bo['peers'][0]['id'] ?? null, $bo['peers'][0]['role'] ?? null], $bo['peers'][1] ?? null],
                    'who is here already: the host, and Ana',
                );
                $this->assertCount(2, $bo['peers'], 'not himself');
                $this->assertSame(
                    ['id' => \substr(\hash('xxh128', \gethostname().$root), offset: 0, length: 16), 'name' => \basename($root)],
                    $bo['project'],
                    'the project, named the same for every guest of this host',
                );

                // Bo says where he is, and puts an agent to work.
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->say(['t' => 'presence', 'path' => 'src/A.php', 'line' => 1, 'column' => 2]);
                $this->say(['t' => 'agent', 'id' => 'codex', 'name' => 'Codex', 'path' => 'src/A.php', 'line' => 0, 'column' => 1]);
                $boSide = $this->become($anaSide);
                $this->assertSame(['t' => 'peer', 'id' => $bo['peer'], 'name' => $boName, 'role' => 'edit'], $this->expect('peer'), 'Ana is told who came — his name cut to forty');
                $this->assertSame(
                    ['t' => 'presence', 'peer' => $bo['peer'], 'name' => $boName, 'path' => 'src/A.php', 'line' => 1, 'column' => 2, 'anchorLine' => null, 'anchorColumn' => null, 'top' => null],
                    $this->presenceOf($bo['peer']),
                );
                $this->assertSame(
                    [
                        't' => 'presence',
                        'peer' => $bo['peer'].'~codex',
                        'name' => 'Codex',
                        'agent' => true,
                        'path' => 'src/A.php',
                        'line' => 0,
                        'column' => 1,
                        'anchorLine' => null,
                        'anchorColumn' => null,
                        'top' => null,
                    ],
                    $this->presenceOf($bo['peer'].'~codex'),
                );

                // Ana, the first in, edits the file Bo has open: he gets it — not only those before her.
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $rev = Wire::int($this->expect('opened'), 'rev');
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => $rev, 'ops' => ['// Ana', 16]]);
                $this->expect('ack');
                $anaSide = $this->become($boSide);
                $this->assertSame(['t' => 'op', 'path' => 'src/A.php', 'ops' => ['// Ana', 16]], $this->expect('op'));
                $boSide = $this->become($anaSide);

                // The host types and saves at once: what it typed comes first, then the save, of that text.
                $terminal->simulateInput("Z\x13"); // Z, ctrl+s
                $saved = $this->expect('saved');
                $this->assertNotSame([], \array_filter($this->inbox, static fn (array $m): bool => 'op' === $m['t']), 'the typing, before the save');
                $this->assertSame(\hash('xxh128', (string) \file_get_contents($root.'/src/A.php')), $saved['hash']);

                // A file Bo writes: Ana is told it changed, to fetch.
                $anaSide = $this->become($boSide);
                $this->say(['t' => 'write', 'path' => 'src/Bo.php', 'data' => \base64_encode('<?php // Bo')]);
                $boSide = $this->become($anaSide);
                $this->assertSame(['t' => 'changed', 'files' => [['src/Bo.php', 11, \hash('xxh128', data: '<?php // Bo')]], 'gone' => []], $this->expect('changed'));

                // The host lets him only read along: Ana is told.
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $host->setRole($bo['peer'], Role::ReadOnly);
                $this->assertSame(['t' => 'peer', 'id' => $bo['peer'], 'name' => $boName, 'role' => 'read'], $this->expect('peer'));

                // His agent stops, then he goes: Ana is told of each.
                $anaSide = $this->become($boSide);
                $this->say(['t' => 'agentLeft', 'id' => 'codex']);
                $this->say(['t' => 'open', 'path' => 'barrier.php']);
                $this->expect('error');
                $boSide = $this->become($anaSide);
                $this->assertSame(['t' => 'left', 'peer' => $bo['peer'].'~codex'], $this->expect('left'));
                $anaSide = $this->become($boSide);
                $this->hangUp();
                $this->become($anaSide);
                $this->assertSame(['t' => 'left', 'peer' => $bo['peer']], $this->expect('left'));
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
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'saved' === $m['t'])), 'its save of a file of its own, outside: told to no one');
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
     * The guest this test plays now — its connection and what it has read
     * of it — to {@see become()} again later.
     *
     * @return array{mixed, string, list<array<string, mixed>>}
     */
    private function side(): array
    {
        return [$this->peer, $this->buffered, $this->inbox];
    }

    /**
     * Play the guest $side was: the one played until now, returned.
     *
     * @param array{mixed, string, list<array<string, mixed>>} $side
     *
     * @return array{mixed, string, list<array<string, mixed>>}
     */
    private function become(array $side): array
    {
        $was = $this->side();
        [$this->peer, $this->buffered, $this->inbox] = $side;

        return $was;
    }

    /** @return array<string, mixed> the next presence of $peer's, those of others passed over */
    private function presenceOf(string $peer): array
    {
        do {
            $presence = $this->expect('presence');
        } while ($peer !== $presence['peer']);

        return $presence;
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
