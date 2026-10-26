<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Certificate;
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
use Porta\Tests\Support\Toasts;
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
                $nobody = static fn (): int => \count(\array_filter(Toasts::all($ide), static fn (string $toast): bool => 'Nobody else is in the session' === $toast));
                $before = $nobody();
                $this->command($ide, 'assist:participants');
                $this->assertGreaterThan($before, $nobody(), 'nobody to list either');

                // A relay or a mailbox server that is not one: said, and nothing shared.
                $api = $this->api($ide);
                $api->changeSetting('transit', 'nowhere');
                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'Cannot share: the relay "nowhere" is not host:port'), what: 'the relay refused');
                $this->assertNull(Probe::get($this->plugin($ide), 'host'));
                $api->changeSetting('transit', $this->wormhole()->transit);
                $api->changeSetting('mailbox', 'ws://127.0.0.1:1/v1');
                $this->command($ide, 'assist:share');
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $certificate = Probe::get($host, 'certificate');
                $this->assertInstanceOf(Certificate::class, $certificate);
                $this->await(static fn (): bool => self::toasted($ide, 'Cannot share: 127.0.0.1:1 refused the connection'), what: 'the mailbox server unreachable');
                $this->assertFileDoesNotExist($certificate->file, 'its key gone with the share that failed');
                $this->assertNull(Probe::get($this->plugin($ide), 'host'));
                Services::resolve($ide, Identity::class)->rename('Hal');

                // Shared — twice is the same session, its code copied again.
                $code = $this->share($ide);
                $copied = "\x1b]52;c;".\base64_encode('porta assist '.$code->text())."\x07";
                $this->assertStringContainsString($copied, $terminal->getOutput(), 'the command to run, copied');
                $this->assertSame($code->text(), $this->share($ide)->text());
                $this->assertSame('⇄ 0', $this->cell($ide)?->text);
                $this->assertSame(['', 'Copy the code: '.$code->text(), 'Participants', 'Stop sharing'], \array_keys($this->menu($ide)));
                $terminal->clearOutput();
                ($this->menu($ide)['Copy the code: '.$code->text()]->run)();
                $this->assertStringContainsString($copied, $terminal->getOutput(), 'copied again from the menu');
                $invite = $this->redeem($code);

                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $welcome = $this->expect('welcome');
                $this->assertSame([['id' => 'host', 'name' => 'Hal', 'role' => 'host']], $welcome['peers'], 'the name the host chose');
                $this->assertSame(['⇄ 1', 'Sharing this project — 1 in'], [$this->cell($ide)?->text, $this->cell($ide)?->tooltip]);
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
                $this->await(fn (): bool => '⇄ 1 → Ana' === $this->cell($ide)?->text, what: 'following from the command');

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
                $terminal->clearOutput();
                $terminal->simulateInput('porta assist '.\strtolower($code->text())."\r");
                $this->await(static fn (): bool => self::toasted($ide, 'run it in a terminal: porta assist '.$code->text()), what: 'the command handed over');
                $this->assertStringContainsString($copied, $terminal->getOutput(), 'and copied, as the host wrote it');
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

                // Cy reads along, the host's agent is at work: each listed by what they are, each to follow.
                $this->say(['t' => 'peer', 'id' => 'g2', 'name' => 'Cy', 'role' => 'read']);
                $this->say(['t' => 'presence', 'peer' => 'host~claude', 'name' => 'Claude', 'agent' => true, 'path' => 'src/A.php', 'line' => 0, 'column' => 0]);
                $this->await(fn (): bool => \array_key_exists('Follow Claude', $this->menu($ide)), what: 'the agent among them');
                $this->assertSame(['Follow Hal', 'Follow Cy', 'Follow Claude'], \array_slice(\array_keys($this->menu($ide)), offset: 0, length: 3));
                $this->command($ide, 'assist:participants');
                $this->awaitPainted($tui, $terminal, 'reading along');
                $row = fn (string $name): string => (
                    \array_find($this->screenGrid($tui, $terminal), static fn (string $line): bool => 1 === \preg_match('/\b'.$name.'\b.*\S\s*$/u', $line) && !\str_contains($line, 'shared')) ?? ''
                );
                $this->assertStringContainsString('reading along', $row('Cy'));
                $this->assertStringContainsString('agent', $row('Claude'));
                $this->assertMatchesRegularExpression('/\bHal\b.*\bhost\b/u', $row('Hal'));
                $terminal->simulateInput("\x1b");
                ($this->menu($ide)['Follow Hal']->run)();
                $this->assertSame('⇄ Hal → Hal', $this->cell($ide)?->text, 'who this side follows, beside whose session it is');
                ($this->menu($ide)['Stop following']->run)();
                $this->expect('presence');
                $this->pump(0.3);
                $this->drain();
                $this->inbox = [];
                ($this->menu($ide)['Ask everyone to follow me']->run)();
                $this->assertSame('focus', $this->expect('focus')['t']);
                $this->assertSame('src/A.php', $this->expect('presence')['path'], 'where this guest is, sent again at once');
                $this->await(static fn (): bool => self::toasted($ide, 'Asked everyone to follow you'), what: 'the ask said');
                $guest = Probe::get($this->plugin($ide), 'guest');
                $this->assertIsObject($guest);
                ($this->menu($ide)['Leave the session']->run)();
                $this->assertSame('good', $this->expect('bye')['for'], 'for good: the host need not keep its way back');
                $this->assertNull(Probe::get($guest, 'lostFor'), 'leaving is not losing the host');
                $this->await(fn (): bool => null === $this->cell($ide), what: 'gone from the status bar');
                $this->hangUp();
            },
        ]);
    }
}
