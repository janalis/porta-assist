<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The host's run configurations, from the guest's end: asked of the host,
 * picked here, refused when the host has not let this guest — and what the
 * host says about it, said.
 */
final class GuestRunsE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string TICKET = '0123456789abcdef0123456789abcdef';

    public function testTheGuestPicksWhatTheHostOffers(): void
    {
        $invite = $this->listen();
        $project = $this->guestCopy($invite, self::TICKET);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $this->command($ide, 'assist:run');
                $this->await(static fn (): bool => self::toasted($ide, 'Not in a session'), what: 'not in yet');
                $this->acceptGuest();
                $this->expect('hello');
                $this->say(['t' => 'welcome', 'peer' => 'g1', 'role' => 'edit', 'ticket' => self::TICKET, 'peers' => [['id' => 'host', 'name' => 'Hal', 'role' => 'host']]]);
                $this->await(static fn (): bool => self::toasted($ide, 'Connected to Hal'), what: 'in');

                // Not let: said. Let, but nothing to run: said.
                $this->command($ide, 'assist:run');
                $this->expect('runs');
                $this->say(['t' => 'runs', 'labels' => ['composer test'], 'allowed' => false]);
                $this->await(static fn (): bool => self::toasted($ide, 'The host has not let you run its configurations'), what: 'not let');
                $this->command($ide, 'assist:run');
                $this->expect('runs');
                $this->say(['t' => 'runs', 'labels' => [], 'allowed' => true]);
                $this->await(static fn (): bool => self::toasted($ide, 'The host has no run configuration'), what: 'nothing to run');

                // Picked here, run there.
                $this->command($ide, 'assist:run');
                $this->expect('runs');
                $this->say(['t' => 'runs', 'labels' => ['composer test', 'make lint'], 'allowed' => true]);
                $this->awaitPainted($tui, $terminal, 'Run on the host');
                $terminal->simulateInput("\x1b[B\r");
                $this->assertSame('make lint', $this->expect('run')['label']);

                // What the host says about it.
                $this->say(['t' => 'run-refused', 'label' => 'make lint', 'reason' => 'unknown']);
                $this->await(static fn (): bool => self::toasted($ide, 'The host has no run configuration make lint any more'), what: 'gone');
                $this->say(['t' => 'run-refused', 'label' => 'make lint', 'reason' => 'not-allowed']);
                $this->await(static fn (): bool => 'The host has not let you run its configurations' === Toasts::last($ide), what: 'refused');
                $this->say(['t' => 'running', 'allowed' => true]);
                $this->await(static fn (): bool => self::toasted($ide, 'You can run the host\'s run configurations'), what: 'let');
                $this->say(['t' => 'running', 'allowed' => false]);
                $this->await(static fn (): bool => self::toasted($ide, 'You can no longer run'), what: 'no longer');
                $this->hangUp();
            },
        ]);
    }
}
