<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Workspace\Paths;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Decoded;
use Porta\Ui\Glyphs;

/**
 * The host's debugger, from a guest: told where it stopped, its stack
 * listed with the step verbs beside it — paused from here while it runs — a frame opened here with its
 * variables shown, and what each holds — and stepped from here when the host
 * has let this guest.
 */
final class GuestDebug
{
    private string $status = 'idle';

    private string $where = '';

    /** @var list<array{id: int, name: string, path: string, line: int}> */
    private array $frames = [];

    /** @var array<int, string> reference => the name of the variable whose children were asked for */
    private array $opening = [];

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
            'debug-children' => $this->children($message),
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
        if ('idle' === $this->status) {
            $this->api->flash($this->api->trans('The host is not debugging'));

            return;
        }
        if ('running' === $this->status) {
            $this->api->pick(
                $this->api->trans('The host\'s debugger is running'),
                [['label' => $this->api->trans('Pause Program'), 'hint' => '', 'value' => 'pause']],
                fn (string $verb) => ($this->send)(['t' => 'debug-step', 'verb' => $verb]),
            );

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
            if (null === $frame || !\ctype_digit($value)) {
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
        foreach (Decoded::objects(Wire::list($message, 'frames')) as $frame) {
            if (!\is_int($frame['id'] ?? null)) {
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
        $entries = [];
        foreach (Decoded::objects(Wire::list($message, 'scopes')) as $scope) {
            $entries = [...$entries, ...self::entries(Decoded::objects(Wire::list($scope, 'variables')), Wire::text($scope, 'scope'))];
        }
        $this->list($this->api->trans('Variables'), $entries);
    }

    /** @param array<string, mixed> $message what a variable holds */
    private function children(array $message): void
    {
        $reference = Wire::int($message, 'reference');
        $name = $this->opening[$reference] ?? '';
        unset($this->opening[$reference]);
        $this->list($name, self::entries(Decoded::objects(Wire::list($message, 'variables')), $name));
    }

    /**
     * Variables to pick from: one that holds more opens what it holds,
     * asked of the host; any other has its value copied.
     *
     * @param list<array{name: string, value: string, reference: int, hint: string}> $entries
     */
    private function list(string $title, array $entries): void
    {
        if ([] === $entries) {
            $this->api->flash($this->api->trans('No variables'));

            return;
        }
        $rows = [];
        foreach ($entries as $index => $entry) {
            $rows[] = ['label' => $entry['name'].' = '.$entry['value'], 'hint' => $entry['reference'] > 0 ? $entry['hint'].' '.Glyphs::ELLIPSIS : $entry['hint'], 'value' => (string) $index];
        }
        $this->api->pick($title, $rows, function (string $index) use ($entries): void {
            $entry = $entries[(int) $index];
            if ($entry['reference'] <= 0) {
                $this->api->copyToClipboard($entry['value']);

                return;
            }
            $this->opening[$entry['reference']] = $entry['name'];
            ($this->send)(['t' => 'debug-children', 'reference' => $entry['reference']]);
        });
    }

    /**
     * @param list<array<array-key, mixed>> $variables as the host sent them
     *
     * @return list<array{name: string, value: string, reference: int, hint: string}>
     */
    private static function entries(array $variables, string $hint): array
    {
        $entries = [];
        foreach ($variables as $variable) {
            $entries[] = ['name' => Wire::text($variable, 'name'), 'value' => Wire::text($variable, 'value', max: 500), 'reference' => Wire::int($variable, 'reference'), 'hint' => $hint];
        }

        return $entries;
    }
}
