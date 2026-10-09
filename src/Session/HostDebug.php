<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Workspace\Paths;
use Porta\Plugin\Api\Debugger;
use Porta\Plugin\Api\PluginApi;

/**
 * The host's debugger, as the guests see it: told to everyone as it starts,
 * pauses, resumes and ends — where it stopped and its stack, paths relative
 * to the project — each frame's variables and what each holds to whoever
 * asks, and stepped by a guest the host has let.
 */
final readonly class HostDebug
{
    /** @param \Closure(array<string, mixed>, ?string=): void $broadcast to every guest */
    public function __construct(
        private PluginApi $api,
        private Paths $paths,
        private \Closure $broadcast,
    ) {}

    /** The debugger changed: everyone is told how it stands. */
    public function changed(): void
    {
        ($this->broadcast)($this->state());
    }

    /** A guest just let in is told, when the host is debugging. */
    public function welcome(Guest $guest): void
    {
        if ('idle' !== $this->api->runs()->debugger()->status()) {
            $guest->send($this->state());
        }
    }

    /** @param array<string, mixed> $message */
    public function receive(Guest $guest, array $message): void
    {
        $debugger = $this->api->runs()->debugger();
        match ($message['t']) {
            'debug-vars' => self::variables($debugger, $guest, Wire::int($message, 'frame')),
            'debug-children' => self::children($debugger, $guest, Wire::int($message, 'reference')),
            default => self::step($debugger, $guest, $message),
        };
    }

    private static function variables(Debugger $debugger, Guest $guest, int $frame): void
    {
        $debugger->variables($frame, static fn (array $scopes) => $guest->send(['t' => 'debug-vars', 'frame' => $frame, 'scopes' => $scopes]));
    }

    private static function children(Debugger $debugger, Guest $guest, int $reference): void
    {
        $debugger->children($reference, static fn (array $variables) => $guest->send(['t' => 'debug-children', 'reference' => $reference, 'variables' => $variables]));
    }

    /** @param array<string, mixed> $message */
    private static function step(Debugger $debugger, Guest $guest, array $message): void
    {
        if (!$guest->debugging) {
            $guest->send(['t' => 'debug-refused']);

            return;
        }
        match (Wire::string($message, 'verb')) {
            'continue' => $debugger->continue(),
            'stepOver' => $debugger->stepOver(),
            'stepIn' => $debugger->stepIn(),
            'stepOut' => $debugger->stepOut(),
            'pause' => $debugger->pause(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $debugger = $this->api->runs()->debugger();
        $at = $debugger->stoppedAt();

        return [
            't' => 'debug',
            'status' => $debugger->status(),
            'path' => null === $at ? '' : (string) $this->paths->relative($at['path']),
            'line' => $at['line'] ?? -1,
            'frames' => \array_map(fn (array $frame): array => [...$frame, 'path' => (string) $this->paths->relative($frame['path'])], $debugger->frames()),
        ];
    }
}
