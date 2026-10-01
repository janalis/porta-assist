<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Wire;
use Porta\Plugin\Api\PluginApi;

/**
 * The host's run configurations, as a guest starts them: asked of the host,
 * picked here, run there — when the host has let this guest.
 */
final readonly class GuestRuns
{
    /** @param \Closure(array<string, mixed>): void $send to the host */
    public function __construct(
        private PluginApi $api,
        private \Closure $send,
    ) {}

    /** Ask the host what can be run; the pick comes with its answer. */
    public function ask(): void
    {
        ($this->send)(['t' => 'runs']);
    }

    /** @param array<string, mixed> $message */
    public function receive(array $message): void
    {
        match ($message['t']) {
            'runs' => $this->offered($message),
            'running' => $this->api->notify(
                true === ($message['allowed'] ?? null) ? $this->api->trans('You can run the host\'s run configurations') : $this->api->trans('You can no longer run the host\'s run configurations'),
                slot: 'assist',
            ),
            default => $this->api->notify(
                'unknown' === Wire::string($message, 'reason')
                    ? $this->api->trans('The host has no run configuration %label% any more', ['%label%' => Wire::text($message, 'label')])
                    : $this->api->trans('The host has not let you run its configurations'),
                slot: 'assist',
            ),
        };
    }

    /** @param array<string, mixed> $message what the host offers, and whether this guest may run it */
    private function offered(array $message): void
    {
        $labels = \array_map(Wire::clean(...), Wire::strings($message, 'labels'));
        if (true !== ($message['allowed'] ?? null)) {
            $this->api->flash($this->api->trans('The host has not let you run its configurations'));

            return;
        }
        if ([] === $labels) {
            $this->api->flash($this->api->trans('The host has no run configuration'));

            return;
        }
        $rows = \array_map(static fn (string $label): array => ['label' => $label, 'hint' => '', 'value' => $label], $labels);
        $this->api->pick($this->api->trans('Run on the host'), $rows, fn (string $label) => ($this->send)(['t' => 'run', 'label' => $label]));
    }
}
