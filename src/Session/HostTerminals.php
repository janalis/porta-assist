<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Wire;
use Porta\Plugin\Api\PluginApi;

/**
 * The host's terminals, as the guests see them: the ones the host shares —
 * what each shows when it is shared, what it prints after — and the keys a
 * guest types into one, run only when the host has said that guest may.
 */
final class HostTerminals
{
    /** @var array<string, string> terminal id => title, the terminals shared */
    private array $shared = [];

    /**
     * @param \Closure(array<string, mixed>, ?string=): void $broadcast to every guest, or every one but the one named
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $broadcast,
    ) {}

    /** @return array<string, string> the terminals shared, id => title */
    public function shared(): array
    {
        return $this->shared;
    }

    /** Show a terminal to everyone — or, when it is shown already, stop. */
    public function toggle(string $id, string $title): void
    {
        $runs = $this->api->runs();
        if (\array_key_exists($id, $this->shared)) {
            $runs->watch($id, null);
            unset($this->shared[$id]);
            ($this->broadcast)(['t' => 'terminal-end', 'id' => $id]);

            return;
        }
        $scrollback = $runs->scrollback($id);
        if (null === $scrollback) {
            return;
        }
        $this->shared[$id] = $title;
        $runs->watch($id, fn (string $chunk) => ($this->broadcast)(['t' => 'terminal-out', 'id' => $id, 'data' => \base64_encode($chunk)]));
        ($this->broadcast)(['t' => 'terminal', 'id' => $id, 'title' => $title, 'data' => \base64_encode($scrollback)]);
    }

    /** Show a terminal to everyone, when it is not shown already. */
    public function show(string $id, string $title): void
    {
        if (!\array_key_exists($id, $this->shared)) {
            $this->toggle($id, $title);
        }
    }

    /** A guest just let in sees what is shared, as it stands. */
    public function welcome(Guest $guest): void
    {
        foreach ($this->shared as $id => $title) {
            $guest->send(['t' => 'terminal', 'id' => $id, 'title' => $title, 'data' => \base64_encode((string) $this->api->runs()->scrollback($id))]);
        }
    }

    /** @param array<string, mixed> $message keys a guest typed */
    public function input(Guest $guest, array $message): void
    {
        $id = Wire::string($message, 'id');
        $bytes = \base64_decode(Wire::string($message, 'data'), strict: true);
        if ($guest->typing && \array_key_exists($id, $this->shared) && false !== $bytes) {
            $this->api->runs()->write($id, $bytes);
        }
    }

    /** The session is over: the host's terminals are its own again. */
    public function stop(): void
    {
        foreach (\array_keys($this->shared) as $id) {
            $this->api->runs()->watch($id, null);
        }
        $this->shared = [];
    }
}
