<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Who gets in, and with what right: the host answers each guest, may change
 * its mind about one, or send one away — and a guest let in once comes back
 * on its ticket without asking again, until it is sent away.
 */
final class HostAdmissionE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string DOWN = "\x1b[B";

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
    }

    /** The host's pick, answered: the row $downs below the first. */
    private function answer(Tui $tui, VirtualTerminal $terminal, string $asks, int $downs): void
    {
        $this->awaitPainted($tui, $terminal, $asks);
        $terminal->simulateInput(\str_repeat(self::DOWN, $downs)."\r");
    }

    /** Ask in as $name and be let in to edit: the peer id. */
    private function askInAndLetEdit(Tui $tui, VirtualTerminal $terminal, Invite $invite, string $name): string
    {
        $this->askIn($invite, $name);
        $this->answer($tui, $terminal, $name.' asks to join', downs: 0);

        return Wire::string($this->expect('welcome'), key: 'peer');
    }

    public function testAReadOnlyGuestIsLetEditRemovedAndKeptOut(): void
    {
        $project = self::project();

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                $invite = $this->invited($ide);

                // A name made to write to the terminal: shown as text, never sent to it.
                $this->askIn($invite, name: "Ev\x1b]52;c;aGFja2Vk\x07il\x1b[31m");
                $this->answer($tui, $terminal, asks: 'Ev]52;c;aGFja2Vkil[31m asks to join', downs: 2);
                $this->assertSame('host', $this->expect('denied')['reason']);
                $this->assertStringNotContainsString("\x1b]52;c;aGFja2Vk", $terminal->getOutput(), 'her clipboard write never reached the terminal — the invite\'s own copy does');

                $this->askIn($invite, name: 'Ana');
                $this->answer($tui, $terminal, asks: 'Ana asks to join', downs: 1);
                $welcome = $this->expect('welcome');
                $this->assertSame('read', $welcome['role']);
                $this->await(static fn (): bool => self::toasted($ide, text: 'Ana joined, reading along'), what: 'the host told');

                // Reading along, a change is not taken: the file starts over.
                $this->say(['t' => 'open', 'path' => 'src/A.php']);
                $this->expect('opened');
                $this->say(['t' => 'op', 'path' => 'src/A.php', 'rev' => 0, 'ops' => ['x', 6]]);
                $this->assertSame('src/A.php', $this->expect('reopen')['path']);
                $this->say(['t' => 'save', 'path' => 'src/A.php']);
                $this->say(['t' => 'write', 'path' => 'src/B.php', 'data' => \base64_encode('b')]);
                $this->say(['t' => 'move', 'from' => 'src/A.php', 'to' => 'src/C.php']);
                $this->say(['t' => 'delete', 'path' => 'src/A.php']);
                // Answered in order: once this is, the four before it were handled.
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error');

                // The participants: Ana, reading along — let her edit.
                $this->command($ide, id: 'assist:participants');
                $this->answer($tui, $terminal, asks: 'reading along', downs: 0);
                $this->answer($tui, $terminal, asks: 'Let them edit', downs: 1);
                $this->assertSame('edit', $this->expect('role')['role']);
                $this->assertFileDoesNotExist($project->path('src/B.php'), 'nothing she asked while reading was done');
                $this->assertFileExists($project->path('src/A.php'));

                // Back on another connection with her ticket: in at once, still editing.
                $old = $this->peer;
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ana', 'ticket' => $welcome['ticket']]);
                $again = $this->expect('welcome');
                $this->assertSame([$welcome['peer'], 'edit'], [$again['peer'], $again['role']]);
                $this->assertIsResource($old);
                $this->await(static fn (): bool => '' === \fread($old, length: 1_000_000) && \feof($old), what: 'the old connection closed');

                // Sent away: told so, and the ticket lets nobody back in.
                $this->command($ide, id: 'assist:participants');
                $this->answer($tui, $terminal, asks: 'editing', downs: 0);
                $this->answer($tui, $terminal, asks: 'Remove from the session', downs: 9);
                $this->assertSame('removed', $this->expect('bye')['reason']);
                $next = $this->nextChannel($invite);
                $this->assertIsString(GuestRendezvous::blocking(static fn (): mixed => GuestRendezvous::connect($invite, $next, timeout: 0.3)), 'her way in went with her');

                // A guest gone while the host was choosing: the choice finds nobody.
                $invite = $this->redeem($this->code($ide));
                $carl = $this->askInAndLetEdit($tui, $terminal, $invite, name: 'Carl');
                $this->command($ide, id: 'assist:participants');
                $this->answer($tui, $terminal, asks: 'Carl', downs: 0);
                $this->awaitPainted($tui, $terminal, needle: 'Let them edit');
                $this->dropPeer();
                $this->await(static fn (): bool => self::toasted($ide, text: 'Carl left'), what: 'Carl gone');
                $terminal->simulateInput(self::DOWN."\r");
                $this->assertNotSame('', $carl);

                // Turned away, and the other refusals.
                $this->askIn($invite, name: 'Bo');
                $this->answer($tui, $terminal, asks: 'Bo asks to join', downs: 2);
                $this->assertSame('host', $this->expect('denied')['reason']);
                foreach ([['t' => 'hello', 'v' => 99, 'name' => 'Old'], ['t' => 'open'], ['t' => 'hello', 'v' => Wire::VERSION, 'name' => '  ', 'token' => $invite->token]] as $i => $hello) {
                    $this->dial($invite);
                    $this->say($hello);
                    $this->assertSame(['version', 'version', 'name'][$i], $this->expect('denied')['reason']);
                }
                $this->hangUp();
            },
        ]);
    }

    public function testTheLimitsHoldAndAnUnansweredGuestIsLetGo(): void
    {
        $project = self::project();

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $invite = $this->invited($ide);
                $host = Probe::get($this->plugin($ide), property: 'host');
                $this->assertIsObject($host);
                Probe::set($host, property: 'answerTimeout', value: 0.3);
                Probe::set($host, property: 'helloTimeout', value: 0.3);
                Probe::set($host, property: 'maxPending', value: 1);
                $this->api($ide)->changeSetting('maxGuests', value: 1);

                // Nobody answered.
                $this->askIn($invite, name: 'Cy');
                $this->assertSame('unanswered', $this->expect('denied')['reason']);
                // The pick is still open: answering it now lets nobody in.
                $terminal->simulateInput("\r");

                // Silent for too long, then one waiting connection too many.
                $this->dial($invite);
                $this->await($this->hungUp(...), what: 'the silent one let go');
                $this->dial($invite);
                $silent = $this->peer;
                $this->dial($invite);
                $this->assertSame('busy', $this->expect('denied')['reason']);
                $this->assertIsResource($silent);
                \fclose($silent);
                $this->await(static fn (): bool => [] === Probe::get($host, property: 'pending'), what: 'the host to see it go');

                // One guest at most, and one who gave up before the answer.
                $this->askIn($invite, name: 'Di');
                $this->answer($tui, $terminal, asks: 'Di asks to join', downs: 0);
                $this->expect('welcome');
                $di = $this->peer;
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'name' => 'Ed', 'token' => $invite->token]);
                $this->assertSame('full', $this->expect('denied')['reason']);
                $this->api($ide)->changeSetting('maxGuests', value: 8);
                $this->askIn($invite, name: 'Fay');
                $this->hangUp();
                $this->await(static fn (): bool => [] === Probe::get($host, property: 'pending'), what: 'the host to see Fay give up');
                $this->assertNull(Probe::get(Probe::object($tui->getById('editor'), 'overlayHost'), property: 'overlay'), 'the question about Fay went with her');
                $this->assertIsResource($di);
                \fclose($di);
                $this->await(static fn (): bool => self::toasted($ide, text: 'Di left'), what: 'Di gone, Fay never in');

                // Stopping, with someone still at the door: share again later, from scratch.
                $this->dial($invite);
                $this->await(static fn (): bool => [] !== Probe::get($host, property: 'pending'), what: 'at the door');
                $this->command($ide, id: 'assist:stop');
                $this->await(static fn (): bool => self::toasted($ide, text: 'Stopped sharing'), what: 'stopped');
                $this->command($ide, id: 'assist:stop');
                $this->assertNull(Probe::get($this->plugin($ide), property: 'host'));
            },
        ]);
    }
}
