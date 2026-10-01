<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Connection;

/** One guest the host let in: who, with what right, on which connection, with which files open. */
final class Guest
{
    /** @var array<string, true> relative paths of the files it has open */
    public array $docs = [];

    /** Whether it may type into the terminals the host shares. */
    public bool $typing = false;

    /** @var array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}|null where it said its caret is */
    public ?array $caret = null;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public Role $role,
        public readonly string $ticket,
        public readonly Connection $connection,
    ) {}

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        $this->connection->send($message);
    }
}
