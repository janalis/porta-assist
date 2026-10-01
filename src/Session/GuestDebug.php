<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Workspace\Paths;
use Porta\Plugin\Api\PluginApi;

/**
 * The host's debugger, from a guest: told where it stopped, its stack
 * listed with the step verbs beside it, a frame opened here with its
 * variables shown — and stepped from here when the host has let this guest.
 */
final class GuestDebug
{
    private string $status = 'idle';

    private string $where = '';

    /** @var list<array{id: int, name: string, path: string, line: int}> */
    private array $frames = [];

    /** @param \Closure(array<string, mixed>): void $send to the host */
    public function __construct(
        private readonly PluginApi $api,
        private readonly Paths $paths,
        private readonly \Closure $send,
    ) {}

    /** @param array<string, mixed> $message */
    public function receive(array $message): void
    {
        match ($message['t']) {
            'debug' => $this->changed($message),
            'debug-vars' => $this->variables($message),
            'debugging' => $this->api->notify(
                true === ($message['allowed'] ?? null) ? $this->api->trans('You can step the host\'s debugger') : $this->api->trans('You can no longer step the host\'s debugger'),
                slot: 'assist',
            ),
            default => $this->api->notify($this->api->trans('The host has not let you step its debugger'), slot: 'assist'),
        };
    }

    /** The connection is gone: so is what it said of the host's debugger. */
    public function reset(): void
    {
        $this->status = 'idle';
        $this->frames = [];
    }

    /** The host's stack and the step verbs, to pick from. */
    public function show(): void
    {
        if ('paused' !== $this->status) {
            $this->api->flash('running' === $this->status ? $this->api->trans('The host\'s debugger is running') : $this->api->trans('The host is not debugging'));

            return;
        }
        $rows = [
            ['label' => $this->api->trans('Resume Program'), 'hint' => '', 'value' => 'continue'],
            ['label' => $this->api->trans('Step Over'), 'hint' => '', 'value' => 'stepOver'],
            ['label' => $this->api->trans('Step Into'), 'hint' => '', 'value' => 'stepIn'],
            ['label' => $this->api->trans('Step Out'), 'hint' => '', 'value' => 'stepOut'],
        ];
        foreach ($this->frames as $index => $frame) {
            $rows[] = ['label' => $frame['name'], 'hint' => '' === $frame['path'] ? '' : $frame['path'].':'.($frame['line'] + 1), 'value' => (string) $index];
        }
        $this->api->pick($this->api->trans('Host\'s debugger — %where%', ['%where%' => $this->where]), $rows, function (string $value): void {
            $frame = $this->frames[(int) $value] ?? null;
            if (!\ctype_digit($value) || null === $frame) {
                ($this->send)(['t' => 'debug-step', 'verb' => $value]);

                return;
            }
            $path = $this->paths->absolute($frame['path']);
            if (null !== $path) {
                $this->api->openFile($path);
                $this->api->jumpTo($frame['line']);
            }
            ($this->send)(['t' => 'debug-vars', 'frame' => $frame['id']]);
        });
    }

    /** @param array<string, mixed> $message */
    private function changed(array $message): void
    {
        $was = $this->status;
        $this->status = Wire::string($message, 'status');
        $this->frames = [];
        // @mago-expect analysis:mixed-assignment -- narrowed below
        foreach (Wire::list($message, 'frames') as $frame) {
            if (!\is_array($frame) || !\is_int($frame['id'] ?? null)) {
                continue;
            }
            $this->frames[] = ['id' => $frame['id'], 'name' => Wire::text($frame, 'name'), 'path' => Wire::string($frame, 'path'), 'line' => Wire::int($frame, 'line')];
        }
        $path = Wire::text($message, 'path');
        $this->where = '' === $path ? '' : $path.':'.(Wire::int($message, 'line') + 1);
        match (true) {
            'paused' === $this->status => $this->api->notify($this->api->trans('The host\'s debugger paused at %where%', ['%where%' => $this->where]), slot: 'assist'),
            'idle' === $this->status && 'idle' !== $was => $this->api->notify($this->api->trans('The host stopped debugging'), slot: 'assist'),
            default => null,
        };
    }

    /** @param array<string, mixed> $message a frame's scopes and their variables */
    private function variables(array $message): void
    {
        $rows = [];
        // @mago-expect analysis:mixed-assignment -- narrowed below
        foreach (Wire::list($message, 'scopes') as $scope) {
            if (!\is_array($scope)) {
                continue;
            }
            // @mago-expect analysis:mixed-assignment -- narrowed below
            foreach (Wire::list($scope, 'variables') as $variable) {
                if (!\is_array($variable)) {
                    continue;
                }
                $value = Wire::text($variable, 'value', max: 500);
                $rows[] = ['label' => Wire::text($variable, 'name').' = '.$value, 'hint' => Wire::text($scope, 'scope'), 'value' => $value];
            }
        }
        if ([] === $rows) {
            $this->api->flash($this->api->trans('No variables'));

            return;
        }
        $this->api->pick($this->api->trans('Variables'), $rows, fn (string $value) => $this->api->copyToClipboard($value));
    }
}
