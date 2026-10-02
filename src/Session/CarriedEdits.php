<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Ot\ClientDocument;
use Porta\AssistPlugin\Ot\TextOperation;

/**
 * What a guest typed and never got acknowledged, kept across a dropped
 * connection by relative path — each file's {@see ClientDocument} as it
 * was — and carried onto the host's copy when the file is shared again.
 */
final class CarriedEdits
{
    /** @var array<string, ClientDocument> */
    private array $kept = [];

    public function keep(string $relative, ClientDocument $client): void
    {
        $this->kept[$relative] = $client;
    }

    /** What to carry onto $host, the host's text of $relative now; null when nothing was kept, or nothing is left of it. */
    public function take(string $relative, string $host): ?TextOperation
    {
        $client = $this->kept[$relative] ?? null;
        unset($this->kept[$relative]);

        return $client?->carry($host);
    }

    public function drop(string $relative): void
    {
        unset($this->kept[$relative]);
    }

    public function clear(): void
    {
        $this->kept = [];
    }
}
