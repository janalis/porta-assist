<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Lsp;

use Porta\Lsp\Uri;

/**
 * The guest's copy and the host's project, as a language server's messages
 * name them: every file URI or path under one root, rewritten to the same
 * place under the other — the guest's editor talks about its copy, the
 * host's server reads the host's files.
 */
final readonly class Roots
{
    public function __construct(
        private string $from,
        private string $to,
    ) {}

    /** The same two roots, the other way round. */
    public function reversed(): self
    {
        return new self($this->to, $this->from);
    }

    /**
     * $message with every string under the one root moved under the other —
     * keys too, as a workspace edit keys its changes by URI.
     *
     * @param array<array-key, mixed> $message
     *
     * @return array<array-key, mixed>
     */
    public function rewrite(array $message): array
    {
        $rewritten = [];
        // @mago-expect analysis:mixed-assignment -- any JSON value: strings are rewritten, arrays walked, the rest kept
        foreach ($message as $key => $value) {
            $key = \is_string($key) ? $this->move($key) : $key;
            $rewritten[$key] = match (true) {
                \is_string($value) => $this->move($value),
                \is_array($value) => $this->rewrite($value),
                default => $value,
            };
        }

        return $rewritten;
    }

    private function move(string $value): string
    {
        foreach ([[Uri::fromPath($this->from), Uri::fromPath($this->to)], [$this->from, $this->to]] as [$old, $new]) {
            if ($value === $old || \str_starts_with($value, $old.'/')) {
                return $new.\substr($value, \strlen($old));
            }
        }

        return $value;
    }
}
