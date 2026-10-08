<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Presence\Identity;
use Porta\Presence\Participant;
use Porta\Presence\Participants;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The plugin as the user drives it: the commands, the status bar's cell and
 * its menu — on the host's side and on a guest's.
 */
final class AssistUiE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    private function plugins(Ide $ide): PluginManager
    {
        $plugins = Probe::get(Services::resolve($ide, PluginLifecycle::class), 'plugins');
        $this->assertInstanceOf(PluginManager::class, $plugins, 'the lifecycle holds the plugins');

        return $plugins;
    }

    /** The plugin's cell in the status bar, or null when it shows none. */
    private function cell(Ide $ide): ?StatusCell
    {
        return \array_find($this->plugins($ide)->statusSegments(), static fn (mixed $segment): bool => $segment instanceof StatusCell && \str_starts_with($segment->text, '⇄'));
    }

    /** @return array<string, MenuItem> the cell's menu, by label */
    private function menu(Ide $ide): array
    {
        $cell = $this->cell($ide);
        $this->assertNotNull($cell?->menu);
        $items = [];
        foreach (($cell->menu)() as $item) {
            $items[$item->label] = $item;
        }

        return $items;
    }

    /** @return array<string, bool> which of the plugin's menu items are enabled, by command */
    private function enabled(Ide $ide): array
    {
        $enabled = [];
        foreach ($this->plugins($ide)->menuSpecs() as $spec) {
            if ('assist' !== $spec->plugin) {
                continue;
            }
            $enabled[\substr($spec->spec, offset: 2)] = null === $spec->when || ($spec->when)();
        }

        return $enabled;
    }

    public function testTheHostsCommandsCellAndMenu(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $this->assertNull($this->cell($ide), 'nothing shown before sharing');
                $this->assertSame(
                    [
                        'assist:share' => true,
                        'assist:join' => true,
                        'assist:follow' => false,
                        'assist:participants' => false,
                        'assist:stop' => false,
                        'assist:leave' => false,
                        'assist:share_terminal' => false,
                        'assist:share_server' => false,
                        'assist:servers' => false,
                        'assist:run' => false,
                        'assist:focus' => false,
                        'assist:debugger' => false,
                    ],
                    $this->enabled($ide),
                );
                $this->command($ide, 'assist:follow');
                $this->await(static fn (): bool => self::toasted($ide, 'Nobody else is in the session'), what: 'nobody to follow');
                $this->command($ide, 'assist:participants');

                // A relay or a mailbox server that is not one: said, and nothing shared.
                $api = $this->api($ide);
                $api->changeSetting('transit', 'nowhere');
                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'Cannot share: the relay "nowhere" is not host:port'), what: 'the relay refused');
                $this->assertNull(Probe::get($this->plugin($ide), 'host'));
                $api->changeSetting('transit', $this->wormhole()->transit);
                $api->changeSetting('mailbox', 'ws://127.0.0.1:1/v1');
                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'Cannot share: 127.0.0.1:1 refused the connection'), what: 'the mailbox server unreachable');
                $this->assertNull(Probe::get($this->plugin($ide), 'host'));
                Services::resolve($ide, Identity::class)->rename('Hal');

                // Shared — twice is the same session, its code copied again.
                $code = $this->share($ide);
                $this->assertSame($code->text(), $this->share($ide)->text());
                $this->assertSame('⇄ 0', $this->cell($ide)?->text);
                $this->assertSame(['', 'Copy the code: '.$code->text(), 'Participants', 'Stop sharing'], \array_keys($this->menu($ide)));
                ($this->menu($ide)['Copy the code: '.$code->text()]->run)();
                $invite = $this->redeem($code);

                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $welcome = $this->expect('welcome');
                $this->assertSame([['id' => 'host', 'name' => 'Hal', 'role' => 'host']], $welcome['peers'], 'the name the host chose');
                $this->assertSame('⇄ 1', $this->cell($ide)?->text);
                // A code lets one guest in: the next is ready to hand out.
                $this->await(
                    fn (): bool => (
                        [] !== \array_filter(
                            \array_keys($this->menu($ide)),
                            static fn (string $label): bool => \str_starts_with($label, 'Copy the code: ') && 'Copy the code: '.$code->text() !== $label,
                        )
                    ),
                    what: 'the next code',
                );
                $roster = Services::resolve($ide, Participants::class);
                $this->assertEquals([new Participant('g0', 'Ana', 'assist')], $roster->all(), 'in core\'s roster too');
                $this->assertSame(
                    [
                        'assist:share' => true,
                        'assist:join' => false,
                        'assist:follow' => true,
                        'assist:participants' => true,
                        'assist:stop' => true,
                        'assist:leave' => false,
                        'assist:share_terminal' => true,
                        'assist:share_server' => true,
                        'assist:servers' => false,
                        'assist:run' => false,
                        'assist:focus' => true,
                        'assist:debugger' => false,
                    ],
                    $this->enabled($ide),
                );

                // Following, from the menu and from the command.
                ($this->menu($ide)['Follow Ana']->run)();
                $this->assertSame('⇄ 1 → Ana', $this->cell($ide)?->text);
                ($this->menu($ide)['Stop following']->run)();
                $this->command($ide, 'assist:follow');
                $this->awaitPainted($tui, $terminal, 'Follow');
                $terminal->simulateInput("\r");
                $this->await(static fn (): bool => self::toasted($ide, 'Following Ana'), what: 'following from the command');

                ($this->menu($ide)['Participants']->run)();
                $this->awaitPainted($tui, $terminal, 'Participants');
                $terminal->simulateInput("\x1b");
                ($this->menu($ide)['Stop sharing']->run)();
                $this->assertSame('ended', $this->expect('bye')['reason']);
                $this->assertNull($this->cell($ide));
                $this->assertSame([], $roster->all(), 'and out of it once the session is over');

                // Joining from inside the IDE hands over the command to run.
                $this->command($ide, 'assist:join');
                $this->awaitPainted($tui, $terminal, 'Code from the host');
                $terminal->simulateInput('porta assist '.\strtolower($code->text())."\r");
                $this->await(static fn (): bool => self::toasted($ide, 'run it in a terminal: porta assist '.$code->text()), what: 'the command handed over');
                $this->command($ide, 'assist:join');
                $this->awaitPainted($tui, $terminal, 'Code from the host');
                $terminal->simulateInput("nonsense\r");
                $this->await(static fn (): bool => self::toasted($ide, '"nonsense" is not a code like 42-K7QM-X2PA'), what: 'refused');
                $this->hangUp();
            },
        ]);
    }

    public function testAGuestsCellAndMenu(): void
    {
        $invite = $this->listen();
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
        GuestSession::store($project->dir, ['invite' => $invite, 'ticket' => self::TICKET, 'name' => 'Bo', 'languages' => []]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $this->acceptGuest();
                $this->expect('hello');
                $this->assertSame('⇄ …', $this->cell($ide)?->text, 'not in yet');
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'read', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);
                $this->await(fn (): bool => '⇄ Hal' === $this->cell($ide)?->text, what: 'in');
                $this->assertSame('In Hal\'s session, reading along', $this->cell($ide)?->tooltip);
                $this->say(['t' => 'role', 'role' => 'edit']);
                $this->await(fn (): bool => 'In Hal\'s session, editing' === $this->cell($ide)?->tooltip, what: 'editing');
                $this->say(['t' => 'server', 'port' => 0]);
                $this->await(static fn (): bool => self::toasted($ide, 'Hal shared localhost:0'), what: 'a server shared');
                $this->assertSame(
                    [
                        'assist:share' => false,
                        'assist:join' => false,
                        'assist:follow' => true,
                        'assist:participants' => true,
                        'assist:stop' => false,
                        'assist:leave' => true,
                        'assist:share_terminal' => false,
                        'assist:share_server' => false,
                        'assist:servers' => true,
                        'assist:run' => true,
                        'assist:focus' => true,
                        'assist:debugger' => true,
                    ],
                    $this->enabled($ide),
                );

                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'it is theirs to share'), what: 'not ours to share');
                $this->assertSame(['Follow Hal', 'Ask everyone to follow me', '', 'Participants', 'Leave the session'], \array_keys($this->menu($ide)));
                $this->expect('presence');
                $this->pump(0.3);
                $this->drain();
                $this->inbox = [];
                ($this->menu($ide)['Ask everyone to follow me']->run)();
                $this->assertSame('focus', $this->expect('focus')['t']);
                $this->assertSame('src/A.php', $this->expect('presence')['path'], 'where this guest is, sent again at once');
                $this->await(static fn (): bool => self::toasted($ide, 'Asked everyone to follow you'), what: 'the ask said');
                ($this->menu($ide)['Leave the session']->run)();
                $this->assertSame('good', $this->expect('bye')['for'], 'for good: the host need not keep its way back');
                $this->await(fn (): bool => null === $this->cell($ide), what: 'gone from the status bar');
                $this->hangUp();
            },
        ]);
    }
}
