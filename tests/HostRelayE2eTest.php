<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Relay;
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
 * A host its guests cannot reach, shared through a relay both can: the
 * invite names the relay and the session, a guest asks in through it as
 * it would directly — and a relay that is wrong, absent or gone is said.
 */
final class HostRelayE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    public function testAGuestJoinsThroughTheRelay(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $api = $this->api($ide);
                $relay = new Relay();
                $port = $relay->start('127.0.0.1', 0);
                $this->assertIsInt($port);

                // Not an address, and nothing there: said, and nothing shared.
                $api->changeSetting('relay', 'nowhere');
                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'the relay "nowhere" is not host:port'), what: 'the bad relay said');
                $api->changeSetting('relay', '127.0.0.1:1');
                $this->command($ide, 'assist:share');
                $this->await(static fn (): bool => self::toasted($ide, 'cannot reach the relay at 127.0.0.1:1'), what: 'the absent relay said');
                $this->assertNull(Probe::get($this->plugin($ide), 'host'));

                // Stopped before the relay answered: nothing is shared.
                $api->changeSetting('relay', '127.0.0.1:'.$port);
                $this->command($ide, 'assist:share');
                $this->command($ide, 'assist:stop');
                $this->await(static fn (): bool => self::toasted($ide, 'Stopped sharing'), what: 'the stop');
                $this->pump(0.2);
                $this->assertFalse(self::toasted($ide, 'Sharing.'), 'the start called off');

                // Shared through it — asked twice before it answered, one session.
                $this->command($ide, 'assist:share');
                $address = $this->share($ide);
                $this->assertSame($port, $address->port, 'the invite names the relay');
                $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $address->relay);
                $this->pump(0.2);
                $this->assertSame([(string) $address->relay], \array_keys((array) Probe::get($relay, 'hosts')), 'the second ask waited for the first, not a session of its own');
                $this->askIn($address, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $this->assertSame('edit', $this->expect('welcome')['role']);

                // The relay gone: the host is told, and so is the guest — its way in went with it.
                $relay->stop();
                $this->await(static fn (): bool => self::toasted($ide, 'the relay at 127.0.0.1:'.$port.' hung up'), what: 'the relay gone');
                $this->await($this->hungUp(...), what: 'the guest cut off');
                $this->command($ide, 'assist:stop');
                $this->hangUp();
            },
        ]);
    }
}
