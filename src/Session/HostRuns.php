<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\Plugin\Api\PluginApi;

/**
 * The host's run configurations, started by a guest the host has let: the
 * run goes in the host's terminal panel as if the host had started it, and
 * its terminal is shared, so everyone sees what it prints. A guest names a
 * configuration the host offers — never a command.
 */
final readonly class HostRuns
{
    public function __construct(
        private PluginApi $api,
        private HostTerminals $terminals,
    ) {}

    /** What a guest could run here now, and whether it may. */
    public function offer(Guest $guest): void
    {
        $guest->send(['t' => 'runs', 'labels' => \array_column($this->api->runs()->configurations(), 'label'), 'allowed' => $guest->running]);
    }

    public function run(Guest $guest, string $label): void
    {
        $command = $this->command($label);
        if (!$guest->running || null === $command) {
            $guest->send(['t' => 'run-refused', 'label' => $label, 'reason' => $guest->running ? 'unknown' : 'not-allowed']);

            return;
        }
        $this->api->runs()->start($label, $command);
        $this->terminals->show('run:'.$label, $label);
        $this->api->notify($this->api->trans('%name% ran %label%', ['%name%' => $guest->name, '%label%' => $label]), slot: 'assist');
    }

    /** @return list<string>|null what the configuration $label runs, when the host offers one */
    private function command(string $label): ?array
    {
        $offered = \array_column($this->api->runs()->configurations(), 'command', 'label');

        return $offered[$label] ?? null;
    }
}
