<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Ide;
use Porta\Run\RunController;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Workbench\AgentActivityKind;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The guest's side, as a host meets it: an IDE opened on a guest's copy
 * joins by itself, asks for the files it has open and takes the host's text
 * for them, sends what its user types and applies what the others type — and
 * stops editing when the host says it may only read.
 */
final class GuestSessionE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    private function copy(int $port): TempProject
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// stale copy\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, ['host' => '127.0.0.1', 'port' => $port, 'fingerprint' => $this->fingerprint(), 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);

        return $project;
    }

    public function testTheCopyJoinsTakesTheHostsTextAndEditsAlong(): void
    {
        $port = $this->listen();
        $project = $this->copy($port);
        $path = (string) \realpath($project->path('src/A.php'));
        $this->assertSame(0o600, \fileperms($project->path(GuestSession::FILE)) & 0o777, 'the ticket is for this user only');

        $this->runIde($project, $path, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($path): void {
                $this->acceptGuest();
                $hello = $this->expect('hello');
                $this->assertSame(['Bo', self::TICKET], [$hello['name'], $hello['ticket']]);
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'edit', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to Hal'), what: 'the guest told');

                // What is open here is asked of the host, and its text replaces the copy's.
                $this->assertSame('src/A.php', $this->expect('open')['path']);
                $this->say(['t' => 'opened', 'path' => 'src/A.php', 'rev' => 0, 'text' => "<?php\n// host\n"]);
                $this->await(fn (): bool => "<?php\n// host\n" === $this->bufferText($ide, $path), what: 'the host\'s text');

                // The host's change lands; the guest's goes out on the revision it was made on.
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'ops' => [14, "echo 1;\n"]]);
                $this->await(fn (): bool => "<?php\n// host\necho 1;\n" === $this->bufferText($ide, $path), what: 'the host\'s change');
                $terminal->simulateInput('Z');
                $op = $this->expect('op');
                $this->assertSame([1, ['Z', 22]], [$op['rev'], $op['ops']]);
                $this->say(['t' => 'ack', 'path' => 'src/A.php']);

                // The host's caret, with its name.
                $this->say(['t' => 'presence', 'peer' => 'host', 'name' => 'Hal', 'path' => 'src/A.php', 'line' => 1, 'column' => 3]);
                $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Hal');

                // Read only now: a key changes nothing, and says why.
                $this->say(['t' => 'role', 'role' => 'read']);
                $this->await(static fn (): bool => self::toasted($ide, 'read along'), what: 'the guest told');
                $terminal->simulateInput('Q');
                $this->await(static fn (): bool => \in_array('This file is read-only', Toasts::all($ide), strict: true), what: 'the refusal');
                $this->assertSame("Z<?php\n// host\necho 1;\n", $this->bufferText($ide, $path));

                // The host saved the same text: the copy saves too, and says nothing back.
                $this->say(['t' => 'saved', 'path' => 'src/A.php', 'hash' => \hash('xxh128', data: "Z<?php\n// host\necho 1;\n")]);
                $this->await(static fn (): bool => "Z<?php\n// host\necho 1;\n" === \file_get_contents($path), what: 'the copy saved');

                $this->say(['t' => 'bye', 'reason' => 'ended']);
                $this->await(static fn (): bool => self::toasted($ide, 'The host ended the session'), what: 'the guest told');
                $this->drain();
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'save' === $m['t'])), 'the host\'s own save is not asked back');
                $this->hangUp();
            },
        ]);
    }

    /** Let the guest IDE in: accept it, read its hello, welcome it with $role. */
    private function welcomeGuest(string $role = 'edit'): void
    {
        $this->acceptGuest();
        $this->expect('hello');
        $this->say([
            't' => 'welcome',
            'peer' => 'g1',
            'role' => $role,
            'ticket' => self::TICKET,
            'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host'], ['id' => 'g2', 'name' => 'Cy', 'role' => 'read'], 'junk'],
        ]);
    }

    /** The guest asked for $path: answer with $text at revision 0. */
    private function shareFile(string $path, string $text): void
    {
        $this->assertSame($path, $this->expect('open')['path']);
        $this->say(['t' => 'opened', 'path' => $path, 'rev' => 0, 'text' => $text]);
    }

    public function testALostHostIsTriedAgainAndTheSharedFilesWaitReadOnly(): void
    {
        $port = $this->listen();
        $project = $this->copy($port);
        $path = (string) \realpath($project->path('src/A.php'));

        $this->runIde($project, $path, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($path, $project): void {
                $this->welcomeGuest();
                $this->shareFile('src/A.php', "one\n");
                $this->await(fn (): bool => "one\n" === $this->bufferText($ide, $path), what: 'shared');

                $this->dropPeer();
                $this->await(static fn (): bool => self::toasted($ide, 'Lost the host (the connection was closed)'), what: 'the guest told');
                $terminal->simulateInput('x');
                $this->await(static fn (): bool => self::toasted($ide, 'This file is read-only'), what: 'nothing typed while away');
                $this->assertSame("one\n", $this->bufferText($ide, $path));

                // Back by itself, on its ticket; the host's copy is the one to carry on from.
                $this->welcomeGuest();
                $this->shareFile('src/A.php', "one\ntwo\n");
                $this->await(fn (): bool => "one\ntwo\n" === $this->bufferText($ide, $path), what: 'the host\'s copy');
                $terminal->simulateInput('y');
                $this->assertSame(['y', 8], $this->expect('op')['ops'], 'writable again');

                // Nobody listening any more: the retry says why, and leaving ends it.
                $this->hangUp();
                $this->await(static fn (): bool => self::toasted($ide, 'refused the connection'), timeout: 15.0, what: 'the retry refused');
                $this->command($ide, 'assist:leave');
                $this->await(static fn (): bool => self::toasted($ide, 'Left the session'), what: 'left');
                $this->assertFileDoesNotExist($project->path(GuestSession::FILE), 'the copy forgets the session');
                $this->command($ide, 'assist:leave');
            },
        ]);
    }

    public function testTheHostsWordOnFilesPeersAndRightsIsCarriedOut(): void
    {
        $port = $this->listen();
        $project = $this->copy($port);
        $root = (string) \realpath($project->dir);
        $path = $root.'/src/A.php';
        \file_put_contents($root.'/notes.txt', data: "notes\nmore\n");
        \file_put_contents($root.'/old.txt', data: 'old');

        $this->runIde($project, $path, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($path, $root): void {
                $this->joinWithAFile($ide, $path);
                $this->undoOwnChanges($tui, $terminal);
                $this->outOfStep($ide);
                $this->whoIsIn($tui, $terminal, $ide);
                $this->followTheHost($terminal, $ide, $root);
                $this->filesFromTheHost($root);
                $this->fileOperations($ide, $root, $path);
                $this->agents($ide, $root);
                $this->readOnlyThenRemoved($terminal, $ide, $root);
            },
        ]);
    }

    private function joinWithAFile(Ide $ide, string $path): void
    {
        $this->welcomeGuest();
        $this->shareFile('src/A.php', "<?php\n");
        $this->await(fn (): bool => "<?php\n" === $this->bufferText($ide, $path), what: 'shared');
        $this->say(['t' => 'pending']);
    }

    private function undoOwnChanges(Tui $tui, VirtualTerminal $terminal): void
    {
        // The guest's own undo: its change, taken back and made again.
        $terminal->simulateInput('Z');
        $this->assertSame(['Z', 6], $this->expect('op')['ops']);
        $this->say(['t' => 'ack', 'path' => 'src/A.php']);
        $terminal->simulateInput("\x1a");
        $this->assertSame([-1, 6], $this->expect('op')['ops']);
        $this->say(['t' => 'ack', 'path' => 'src/A.php']);
        $tui->handleInput("\x1b[122;6u"); // ctrl+shift+z
        $this->assertSame(['Z', 6], $this->expect('op')['ops']);
        $this->say(['t' => 'ack', 'path' => 'src/A.php']);
        $terminal->simulateInput("q\x7f"); // typed and taken back before anyone looked
        $tui->handleInput("\x1b[122;6u");
        $this->say(['t' => 'ack', 'path' => 'nope.php']);
        $this->say(['t' => 'op', 'path' => 'nope.php', 'ops' => [1]]);
    }

    private function outOfStep(Ide $ide): void
    {
        // Out of step with the host: the file starts over from its copy.
        $this->say(['t' => 'op', 'path' => 'src/A.php', 'ops' => 'garbage']);
        $this->shareFile('src/A.php', "Z<?php\n");
        $this->say(['t' => 'reopen', 'path' => 'src/A.php']);
        $this->shareFile('src/A.php', "Z<?php\n");
        $this->say(['t' => 'error', 'path' => 'src/A.php', 'message' => 'nope']);
        $this->await(static fn (): bool => self::toasted($ide, 'src/A.php: nope'), what: 'the host\'s complaint');
    }

    private function whoIsIn(Tui $tui, VirtualTerminal $terminal, Ide $ide): void
    {
        // Who is in: Cy left, Dee arrived, the host's agent is at work.
        $this->say(['t' => 'peer', 'id' => 'g3', 'name' => 'Dee', 'role' => 'edit']);
        $this->say(['t' => 'left', 'peer' => 'g2']);
        $this->say(['t' => 'presence', 'peer' => 'host~claude', 'name' => 'Claude', 'agent' => true, 'path' => 'src/A.php', 'line' => 0, 'column' => 1]);
        $this->say(['t' => 'presence', 'peer' => 'g3', 'name' => 'Dee', 'path' => 'notes.txt', 'line' => -1, 'column' => 0]);
        $this->say(['t' => 'presence', 'peer' => 'g3', 'name' => 'Dee', 'path' => '../outside', 'line' => 0, 'column' => 0]);
        $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Claude');
        $this->command($ide, 'assist:participants');
        $this->awaitPainted($tui, $terminal, 'Dee');
        $this->assertStringNotContainsString('Cy', \implode("\n", $this->screenGrid($tui, $terminal)));
    }

    private function followTheHost(VirtualTerminal $terminal, Ide $ide, string $root): void
    {
        // Following the host into a file the session does not share yet.
        $terminal->simulateInput("\r"); // Hal, the first row
        $this->await(static fn (): bool => self::toasted($ide, 'Following Hal'), what: 'following');
        $this->say(['t' => 'presence', 'peer' => 'host', 'name' => 'Hal', 'path' => 'notes.txt', 'line' => 1, 'column' => 2, 'anchorLine' => 1, 'anchorColumn' => 0]);
        $this->await(fn (): bool => $root.'/notes.txt' === $this->api($ide)->activeFile(), what: 'the host\'s file');
        $this->assertSame('notes.txt', $this->expect('open')['path'], 'asked of the host, as any file opened');
        $this->say(['t' => 'left', 'peer' => 'host']);
        $this->await(static fn (): bool => self::toasted($ide, 'Stopped following Hal'), what: 'gone, not followed');
    }

    private function filesFromTheHost(string $root): void
    {
        // Files: saved elsewhere, changed, moved and deleted on the host.
        $this->say(['t' => 'saved', 'path' => 'src/A.php', 'hash' => 'not this text']);
        $this->say(['t' => 'saved', 'path' => 'old.txt', 'hash' => 'h']);
        $this->assertSame(['old.txt'], $this->expect('fetch')['paths']);
        $this->say(['t' => 'file', 'path' => 'old.txt', 'data' => \base64_encode('from the host')]);
        $this->say(['t' => 'file', 'path' => 'old.txt', 'data' => '***']);
        $this->say(['t' => 'file', 'path' => 'src/A.php', 'data' => \base64_encode('shared: never written this way')]);
        $this->await(static fn (): bool => 'from the host' === \file_get_contents($root.'/old.txt'), what: 'fetched');
        $this->say(['t' => 'changed', 'files' => [['old.txt', 13, \hash('xxh128', data: 'from the host')], ['new.txt', 1, 'h'], ['src/A.php', 1, 'h']], 'gone' => ['notes.txt', 'src/A.php']]);
        $this->assertSame(['new.txt'], $this->expect('fetch')['paths'], 'only what differs, and nothing shared');
        $this->say(['t' => 'changed', 'files' => [['old.txt', 13, \hash('xxh128', data: 'from the host')]], 'gone' => []]);
        $this->say(['t' => 'moved', 'from' => 'old.txt', 'to' => 'docs/old.txt']);
        $this->await(static fn (): bool => \is_file($root.'/docs/old.txt'), what: 'moved');
        $this->say(['t' => 'moved', 'from' => '../x', 'to' => 'y']);
        $this->say(['t' => 'deleted', 'path' => 'docs/old.txt']);
        $this->say(['t' => 'deleted', 'path' => '../x']);
        $this->say(['t' => 'deleted', 'path' => 'not-here.txt']);
        $this->await(static fn (): bool => !\is_file($root.'/docs/old.txt'), what: 'deleted');
    }

    private function fileOperations(Ide $ide, string $root, string $path): void
    {
        // The guest's own file operations, asked of the host.
        $buffers = $this->api($ide)->openBuffers();
        $buffers->create($root.'/mine.txt', 'mine');
        $buffers->open($root.'/mine.txt');
        $this->assertSame('mine.txt', $this->expect('open')['path']);
        $buffers->edit($root.'/mine.txt', 0, 0, 0, 0, '!');
        $buffers->save($root.'/mine.txt');
        $this->assertSame(['mine.txt', '!mine'], [($write = $this->expect('write'))['path'], \base64_decode(Wire::string($write, 'data'), strict: true)]);
        $buffers->move($root.'/mine.txt', $root.'/ours.txt');
        $this->assertSame(['mine.txt', 'ours.txt'], [($move = $this->expect('move'))['from'], $move['to']]);
        $this->assertSame('ours.txt', $this->expect('open')['path'], 'asked again under its new name');
        $buffers->delete($root.'/ours.txt');
        $this->assertSame('ours.txt', $this->expect('delete')['path']);
        $buffers->save($path);
        $this->assertSame('src/A.php', $this->expect('save')['path']);
        $buffers->move($path, $root.'/src/B.php');
        $this->assertSame('src/B.php', $this->expect('open')['path'], 'the moved shared file, asked again under its name');
    }

    private function agents(Ide $ide, string $root): void
    {
        // The guest's agents are participants for the others.
        $events = Services::resolve($ide, EventDispatcherInterface::class);
        $events->dispatch(new AgentActivity('codex', 'Codex', $root.'/src/B.php', AgentActivityKind::Editing, 0, 1, 10.0));
        $events->dispatch(new AgentActivity('assist:host', 'Hal', $root.'/src/B.php', AgentActivityKind::Editing, 0, 1, 10.0));
        $events->dispatch(new AgentActivity('far', 'Far', '/elsewhere/x.php', AgentActivityKind::Editing, 0, 1, 10.0));
        $this->assertSame(['codex', 'Codex', 'src/B.php'], [($agent = $this->expect('agent'))['id'], $agent['name'], $agent['path']]);
        $guest = Probe::get($this->plugin($ide), 'guest');
        $this->assertInstanceOf(GuestSession::class, $guest);
        $this->assertContains(['id' => 'me~codex', 'name' => 'Codex', 'kind' => 'agent'], $guest->participants(), 'its own agents too');
        $events->dispatch(new AgentLeft('codex'));
        $events->dispatch(new AgentLeft('codex'));
        $this->assertSame('codex', $this->expect('agentLeft')['id']);
    }

    private function readOnlyThenRemoved(VirtualTerminal $terminal, Ide $ide, string $root): void
    {
        // Read only, then sent away.
        $this->say(['t' => 'opened', 'path' => 'src/B.php', 'rev' => 0, 'text' => "Z<?php\n"]);
        $this->say(['t' => 'role', 'role' => 'read']);
        $this->say(['t' => 'op', 'path' => 'src/B.php', 'ops' => [7, '// host']]);
        $this->await(fn (): bool => "Z<?php\n// host" === $this->bufferText($ide, $root.'/src/B.php'), what: 'the host\'s change, read only');
        $terminal->simulateInput("\x1a"); // nothing to undo while reading
        $this->say(['t' => 'whatever']);
        $this->say(['t' => 'opened', 'path' => '../outside.txt', 'rev' => 0, 'text' => 'no']);
        $this->say(['t' => 'opened', 'path' => 'fresh.txt', 'rev' => 0, 'text' => "a file the copy lacked\n"]);
        $this->await(fn (): bool => "a file the copy lacked\n" === $this->bufferText($ide, $root.'/fresh.txt'), what: 'written and opened');
        \file_put_contents($root.'/pic.bin', data: "\x89PNG\x00\xff\xfe");
        $this->say(['t' => 'opened', 'path' => 'pic.bin', 'rev' => 0, 'text' => 'not text here']);
        $this->say(['t' => 'moved', 'from' => 'fresh.txt', 'to' => 'docs/fresh.txt']);
        $this->assertSame('docs/fresh.txt', $this->expect('open')['path'], 'an open file moved by the host, asked again');
        $this->say(['t' => 'role', 'role' => 'edit']);
        $this->await(static fn (): bool => self::toasted($ide, 'You can edit now'), what: 'editing again');
        $this->say(['t' => 'bye', 'reason' => 'removed']);
        $this->await(static fn (): bool => self::toasted($ide, 'The host removed you'), what: 'removed');
        $this->hangUp();
    }

    public function testTheHostsLanguageServersAreTheCopys(): void
    {
        $project = new TempProject([
            'notes.txt' => 'no PHP open: no server started',
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, [
            'host' => '127.0.0.1',
            'port' => 9,
            'fingerprint' => \str_repeat('0', times: 64),
            'ticket' => self::TICKET,
            'name' => 'Bo',
            'languages' => ['php', 'twig'],
        ]);

        $this->runIde($project, $project->path('notes.txt'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $servers = $this->api($ide)->codeIntelligence()->languageServers();
                foreach (['php', 'twig'] as $language) {
                    $this->assertSame(['assist-lsp', $language], \array_slice($servers[$language]['command'] ?? [], -2), $language.', through `porta assist-lsp`');
                }
            },
        ]);
    }

    public function testAHostsTerminalIsShownHereAndTypedIntoOnlyWhenAllowed(): void
    {
        $port = $this->listen();
        $project = $this->copy($port);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $this->welcomeGuest();
                $this->say(['t' => 'terminal', 'id' => 'shell:1', 'title' => 'zsh 1', 'data' => \base64_encode("\$ ls\r\nsrc\r\n")]);
                $runs = $this->api($ide)->runs();
                $this->await(static fn (): bool => \str_contains((string) $runs->scrollback('assist:shell:1'), 'src'), what: 'the terminal shown');
                $this->assertContains(['id' => 'assist:shell:1', 'title' => '⇄ zsh 1', 'running' => false], $runs->terminals());
                $this->say(['t' => 'terminal-out', 'id' => 'shell:1', 'data' => \base64_encode("tests\r\n")]);
                $this->await(static fn (): bool => \str_contains((string) $runs->scrollback('assist:shell:1'), 'tests'), what: 'what it printed after');

                // Typed into the tab: refused, until the host says so.
                $controller = Services::resolve($ide, RunController::class);
                $sessions = Probe::get($controller, 'sessions');
                $this->assertInstanceOf(TerminalSessions::class, $sessions);
                $tab = $sessions->find('assist:shell:1');
                $this->assertInstanceOf(TerminalSession::class, $tab);
                $sessions->makeActive($tab);
                $controller->forwardInput('l');
                $this->await(static fn (): bool => self::toasted($ide, 'ask them to let you type'), what: 'the refusal');
                $this->say(['t' => 'typing', 'allowed' => true]);
                $this->await(static fn (): bool => self::toasted($ide, 'You can type in the shared terminals'), what: 'allowed');
                $controller->forwardInput('l');
                $typed = $this->expect('terminal-in');
                $this->assertSame(['shell:1', 'l'], [$typed['id'], \base64_decode(Wire::string($typed, 'data'), strict: true)]);
                $this->say(['t' => 'typing', 'allowed' => false]);
                $this->await(static fn (): bool => self::toasted($ide, 'read-only for you again'), what: 'read-only again');

                // No longer shared; another one ends with the session.
                $this->say(['t' => 'terminal-end', 'id' => 'shell:1']);
                $this->say(['t' => 'terminal-end', 'id' => 'never-shown']);
                $this->await(static fn (): bool => '⇄ zsh 1 (ended)' === $tab->title, what: 'marked ended');
                $this->assertStringContainsString('[no longer shared]', (string) $runs->scrollback('assist:shell:1'));
                $this->say(['t' => 'terminal', 'id' => 'run:test', 'title' => 'test', 'data' => '']);
                $this->await(static fn (): bool => null !== $runs->scrollback('assist:run:test'), what: 'the second one shown');
                $this->say(['t' => 'bye', 'reason' => 'ended']);
                $this->await(static fn (): bool => \str_ends_with((string) ($sessions->find('assist:run:test')->title ?? ''), '(ended)'), what: 'ended with the session');
                $this->hangUp();
            },
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'turned away' => ['host', 'The host turned you away'];
        yield 'full' => ['full', 'The session is full'];
        yield 'not answered' => ['unanswered', 'The host did not answer'];
        yield 'an old ticket' => ['token', 'The session is over'];
    }

    #[DataProvider('refusals')]
    public function testARefusalIsSaidAndNotRetried(string $reason, string $says): void
    {
        $port = $this->listen();
        $project = $this->copy($port);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($reason, $says): void {
                $this->acceptGuest();
                $this->expect('hello');
                $this->say(['t' => 'denied', 'reason' => $reason]);
                $this->dropPeer();
                $this->await(static fn (): bool => self::toasted($ide, $says), what: 'the refusal said');
                $this->pump(1.2);
                $this->assertFalse(self::toasted($ide, 'Lost the host'), 'no retry');
                $this->hangUp();
            },
        ]);
    }

    public function testWhatTheHostSendsToBeShownCannotReachTheTerminal(): void
    {
        $port = $this->listen();
        $project = $this->copy($port);
        $evil = static fn (string $mark): string => "X\x1b]52;c;".$mark."\x07\u{202E}Y";

        $this->runIde($project, (string) \realpath($project->path('src/A.php')), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($evil): void {
                $this->acceptGuest();
                $this->expect('hello');
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'edit', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => $evil('HOSTNAME'), 'role' => 'host']]]);
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to X]52;c;HOSTNAMEY'), what: 'the host\'s name, as text');
                $this->say(['t' => 'peer', 'id' => 'g2', 'name' => $evil('PEER'), 'role' => 'edit']);
                $this->say(['t' => 'focus', 'peer' => 'g2', 'name' => $evil('FOCUS')]);
                $this->await(static fn (): bool => self::toasted($ide, 'X]52;c;FOCUSY asked everyone'), what: 'the asker\'s name, as text');
                $this->say(['t' => 'error', 'path' => $evil('PATH'), 'message' => $evil('MESSAGE')]);
                $this->await(static fn (): bool => self::toasted($ide, 'X]52;c;PATHY: X]52;c;MESSAGEY'), what: 'the error, as text');
                $this->say(['t' => 'terminal', 'id' => 'run:x', 'title' => $evil('TITLE'), 'data' => '']);
                $this->await(static fn (): bool => self::toasted($ide, 'shared a terminal: X]52;c;TITLEY'), what: 'the title, as text');
                $this->say(['t' => 'run-refused', 'label' => $evil('LABEL'), 'reason' => 'unknown']);
                $this->await(static fn (): bool => self::toasted($ide, 'no run configuration X]52;c;LABELY'), what: 'the label, as text');
                $this->command($ide, 'assist:run');
                $this->expect('runs');
                $this->say(['t' => 'runs', 'labels' => [$evil('OFFERED')], 'allowed' => true]);
                $this->awaitPainted($tui, $terminal, 'X]52;c;OFFEREDY');
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'X]52;c;PEERY');
                $terminal->simulateInput("\x1b");
                $this->shareFile('src/A.php', "<?php\n");
                $this->say(['t' => 'presence', 'peer' => 'g3', 'name' => $evil('CARET'), 'path' => 'src/A.php', 'line' => 0, 'column' => 1]);
                $this->awaitPainted($tui, $terminal, 'X]52;c;CARETY');

                foreach (['HOSTNAME', 'PEER', 'FOCUS', 'PATH', 'MESSAGE', 'TITLE', 'LABEL', 'OFFERED', 'CARET'] as $mark) {
                    $this->assertStringNotContainsString("\x1b]52;c;".$mark, $terminal->getOutput(), $mark.' never reached the terminal');
                }
                $this->hangUp();
            },
        ]);
    }
}
