<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Editor\BufferManager;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The host's side, as a guest meets it: asked in by the host, the project
 * listed and fetched, a shared file edited by both at once — each seeing the
 * other's text and caret, and each one's undo taking back only their own.
 */
final class HostSessionE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\necho 'a';\n",
            'README.md' => "# demo\n",
            'node_modules/x.js' => 'never listed',
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
    }

    private function text(Ide $ide, string $path): string
    {
        return (string) Services::resolve($ide, BufferManager::class)->get($path)?->document->getText();
    }

    public function testAGuestAskedInEditsAlongsideTheHost(): void
    {
        $project = self::project();
        $path = (string) \realpath($project->path('src/A.php'));

        $this->runIde($project, $path, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($path): void {
                $invite = $this->invited($ide);

                // A wrong token is not even asked about.
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Eve', 'token' => \str_repeat('0', times: 32)]);
                $this->assertSame('token', $this->expect('denied')['reason']);

                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'token' => $invite->token]);
                $this->expect('pending');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r"); // the first answer: let them edit
                $welcome = $this->expect('welcome');
                $this->assertSame('edit', $welcome['role']);
                $user = \getenv('USER');
                $this->assertSame([['id' => 'host', 'name' => \is_string($user) && '' !== $user ? $user : 'host', 'role' => 'host']], $welcome['peers'], 'the host, by login name');

                $this->say(['t' => 'manifest']);
                $this->assertSame(['README.md', 'src/A.php'], \array_column(Wire::list($this->expect('manifest'), 'files'), 0), 'the project, not its settings');
                $this->say(['t' => 'fetch', 'paths' => ['src/A.php', '../escape', '.porta.json', 'node_modules/x.js']]);
                $this->assertSame("<?php\necho 'a';\n", \base64_decode(Wire::string($this->expect('file'), 'data'), strict: true));
                $this->assertTrue($this->expect('file')['missing'], 'nothing outside the project');
                $this->assertTrue($this->expect('file')['missing'], 'nor its settings');
                $this->assertTrue($this->expect('file')['missing'], 'nor a file the listing never offered');
                $this->expect('fetched');

                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $opened = $this->expect('opened');
                $this->assertSame(0, $opened['rev']);
                $this->assertSame("<?php\necho 'a';\n", $opened['text']);

                // The guest types at the end of the first line; the host at its start.
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 0, 'ops' => [5, ' // guest', 11]]);
                $this->expect('ack');
                $this->assertSame("<?php // guest\necho 'a';\n", $this->text($ide, $path));
                $terminal->simulateInput('#');
                $this->assertSame(['#', 25], $this->expect('op')['ops'], 'the host\'s keystroke, read off its buffer');

                // Each one's caret, shown on the other side.
                $this->say(['t' => 'presence', 'path' => 'src/A.php', 'line' => 1, 'column' => 4, 'anchorLine' => 1, 'anchorColumn' => 0]);
                $this->awaitPainted($tui, $terminal, Glyphs::AGENT.' Ana');
                do {
                    $caret = $this->expect('presence');
                } while (1 !== $caret['column']);
                $this->assertSame(['host', 'src/A.php', 0], [$caret['peer'], $caret['path'], $caret['line']], 'the host\'s caret, after its "#"');

                // The host's Ctrl+Z takes back the host's "#", not the guest's comment.
                $terminal->simulateInput("\x1a");
                $this->await(fn (): bool => "<?php // guest\necho 'a';\n" === $this->text($ide, $path), what: 'the host\'s own change undone, the guest\'s kept');
                $this->assertSame([-1, 25], $this->expect('op')['ops']);
                $terminal->simulateInput("\x1a"); // nothing of the host's left to undo

                // A save asked by the guest is the host's: on disk, and said to everyone.
                $this->say(['t' => 'save', 'path' => 'src/A.php']);
                $saved = $this->expect('saved');
                $this->assertSame(\hash('xxh128', data: "<?php // guest\necho 'a';\n"), $saved['hash']);
                $this->assertSame("<?php // guest\necho 'a';\n", \file_get_contents($path));

                $this->say(['t' => 'bye']);
                $this->await(static fn (): bool => self::toasted($ide, 'Ana left'), what: 'the host told');

                // A goodbye for the moment keeps the way back open; one for good closes it.
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'ticket' => $welcome['ticket']]);
                $this->expect('welcome');
                $this->say(['t' => 'bye', 'for' => 'good']);
                $this->await($this->hungUp(...), what: 'let go');
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $this->assertSame([], Probe::get($host, 'tickets'), 'the ticket lets nobody in now');
                $this->assertSame([], Probe::get($host, 'doors'), 'and its door no longer waits at the relay');
                $this->hangUp();
            },
        ]);
    }
}
