<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Presence\Identity;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * "Ask everyone to follow me": whoever asks — the host or a guest — the
 * others follow them, and are taken to where they are at once.
 */
final class FocusE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    /** @return resource Ask in as $name and be let in: the connection. */
    private function letIn(Tui $tui, VirtualTerminal $terminal, Invite $invite, string $name)
    {
        $this->askIn($invite, $name);
        $this->awaitPainted($tui, $terminal, $name.' asks to join');
        $terminal->simulateInput("\r");
        $this->expect('welcome');
        $this->assertIsResource($this->peer);

        return $this->peer;
    }

    public function testTheHostAndAGuestEachAskEveryoneToFollow(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            'src/B.php' => "<?php\n\n\necho 'b';\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        $b = (string) \realpath($project->path('src/B.php'));

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($b): void {
                $this->command($ide, 'assist:focus');
                $this->await(static fn (): bool => self::toasted($ide, 'Nobody else is in the session'), what: 'nobody to ask');
                Services::resolve($ide, Identity::class)->rename('Hal');
                $invite = $this->invited($ide);
                $ana = $this->letIn($tui, $terminal, $invite, 'Ana');
                $bo = $this->letIn($tui, $terminal, $invite, 'Bo');

                // The host asks: both follow it, shown where it is — its caret
                // sent again, though it has not moved since each saw it.
                foreach ([$ana, $bo] as $guest) {
                    $this->peer = $guest;
                    $caret = $this->expect('presence');
                    $this->assertSame(['host', 'Hal', 'src/A.php'], [$caret['peer'], $caret['name'] ?? null, $caret['path'] ?? null], 'the host\'s caret, by name');
                }
                $this->pump(0.3);
                foreach ([$ana, $bo] as $guest) {
                    $this->peer = $guest;
                    $this->drain();
                    $this->inbox = [];
                }
                $this->command($ide, 'assist:focus');
                foreach ([$ana, $bo] as $guest) {
                    $this->peer = $guest;
                    $this->assertSame(['t' => 'focus', 'peer' => 'host', 'name' => 'Hal'], $this->expect('focus'));
                    $caret = $this->expect('presence');
                    $this->assertSame(['host', 'Hal', 'src/A.php'], [$caret['peer'], $caret['name'] ?? null, $caret['path'] ?? null]);
                }

                // Ana asks: the host follows her to where she is; Bo is asked too, she is not.
                $this->peer = $ana;
                $this->say(['t' => 'focus']);
                $this->say(['t' => 'presence', 'path' => 'src/B.php', 'line' => 3, 'column' => 2]);
                $this->await(static fn (): bool => self::toasted($ide, 'Ana asked everyone to follow them'), what: 'the host told');
                $this->await(fn (): bool => $b === $this->api($ide)->activeFile(), what: 'the host taken to her file');
                $this->peer = $bo;
                $asked = $this->expect('focus');
                $this->assertSame(['t', 'peer', 'name'], \array_keys($asked));
                $this->assertSame(['focus', 'Ana'], [$asked['t'], $asked['name']]);
                $this->assertNotSame('host', $asked['peer'], 'her, to follow');
                $this->peer = $ana;
                $this->say(['t' => 'open', 'path' => 'nope.php']);
                $this->expect('error'); // answered in order: anything for her came before it
                $this->drain();
                $this->assertSame([], \array_values(\array_filter($this->inbox, static fn (array $m): bool => 'focus' === $m['t'])), 'not asked to follow herself');
                $this->command($ide, 'assist:stop');
                $this->pump(0.2);
                $this->hangUp();
            },
        ]);
    }

    public function testAGuestFollowsWhoeverAsks(): void
    {
        $invite = $this->listen();
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            'src/B.php' => "<?php\n\necho 'b';\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, ['invite' => $invite, 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);
        $b = (string) \realpath($project->path('src/B.php'));

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($b): void {
                $this->acceptGuest();
                $this->expect('hello');
                $this->say([
                    't' => 'welcome',
                    'peer' => 'g1',
                    'role' => 'edit',
                    'ticket' => self::TICKET,
                    'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host'], ['id' => 'g2', 'name' => 'Cy', 'role' => 'edit']],
                ]);
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to Hal'), what: 'in');

                $this->say(['t' => 'focus', 'peer' => 'g2', 'name' => 'Cy']);
                $this->say(['t' => 'presence', 'peer' => 'g2', 'name' => 'Cy', 'path' => 'src/B.php', 'line' => 2, 'column' => 0]);
                $this->await(static fn (): bool => self::toasted($ide, 'Cy asked everyone to follow them'), what: 'told');
                $this->await(fn (): bool => $b === $this->api($ide)->activeFile(), what: 'taken to Cy\'s file');
                $follow = Probe::get($this->plugin($ide), 'follow');
                $this->assertIsObject($follow);
                $this->assertSame(['g2', 'Cy'], $follow->target);
                $this->pump(0.2);
                $this->hangUp();
            },
        ]);
    }
}
