<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Ot\TextDiff;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Ot\UndoStack;
use Porta\Plugin\Api\OpenBuffers;

/**
 * One shared file as this side's editor has it: the text the session last
 * agreed on (the SHADOW), the buffer on screen, and the carets of the others.
 *
 * Local edits are not reported by the editor, so they are READ: the buffer
 * against the shadow, as one operation. A remote change is applied to both in
 * the same turn — after reading whatever was typed before it — so the next
 * read finds only what this side typed, and nothing echoes back.
 *
 * Undo is this side's own ({@see UndoStack}): every change read here is
 * remembered, every remote one rewrites what is remembered.
 */
final class SharedText
{
    private readonly UndoStack $undo;

    private ?int $version;

    /** @var array<string, array{int, int|null}> peer => caret and anchor, as byte offsets of the shadow */
    private array $carets = [];

    /**
     * @param \Closure(): ?int                                                                       $versionOf the buffer's version, null once it is gone
     * @param \Closure(): ?string                                                                    $text      the buffer's text, null once it is gone
     * @param \Closure(list<array{line: int, column: int, endLine: int, endColumn: int, text: string}>): ?string $edit applies ranges as one step: null, or why not
     */
    public function __construct(
        public readonly string $path,
        public private(set) string $shadow,
        private readonly \Closure $versionOf,
        private readonly \Closure $text,
        private readonly \Closure $edit,
    ) {
        $this->undo = new UndoStack();
        $this->version = ($this->versionOf)();
    }

    /** One open buffer of the editor's, holding $text now. */
    public static function of(OpenBuffers $buffers, string $path, string $text): self
    {
        return new self(
            $path,
            $text,
            static fn (): ?int => $buffers->version($path),
            static fn (): ?string => $buffers->text($path),
            static fn (array $edits): ?string => $buffers->edits($path, $edits),
        );
    }

    /**
     * What was typed here since the last read, as one operation — null when
     * nothing was, or the buffer is gone. It is this side's to undo.
     */
    public function read(): ?TextOperation
    {
        $version = ($this->versionOf)();
        if (null === $version || $version === $this->version) {
            return null;
        }
        $this->version = $version;
        $text = (string) ($this->text)();
        $operation = TextDiff::between($this->shadow, $text);
        $typed = !$operation->isNoop();
        if ($typed) {
            $this->undo->did($operation, $this->shadow);
            $this->shadow = $text;
            $this->moveCarets($operation);
        }

        return $typed ? $operation : null;
    }

    /**
     * Someone else's change: onto the buffer and the shadow, and through
     * what this side may undo. Read first — this assumes the two agree.
     *
     * @throws \InvalidArgumentException when it does not fit the shadow — a peer out of step
     */
    public function apply(TextOperation $operation): void
    {
        $this->write($operation);
        $this->undo->transform($operation);
    }

    /**
     * Take back this side's last change (or redo the last undo): the change
     * that does it, already applied here, to send on as this side's own — or
     * null with nothing to take back.
     */
    public function undo(): ?TextOperation
    {
        return $this->replay($this->undo->undo(...));
    }

    public function redo(): ?TextOperation
    {
        return $this->replay($this->undo->redo(...));
    }

    /**
     * Where $peer's caret is now, at a 0-based line and byte column of the
     * shadow — the position it said, clamped into it — with the anchor of
     * its selection, when it has one.
     */
    public function placeCaret(string $peer, int $line, int $column, ?int $anchorLine, ?int $anchorColumn): void
    {
        $this->carets[$peer] = [
            $this->offset($line, $column),
            null === $anchorLine ? null : $this->offset($anchorLine, (int) $anchorColumn),
        ];
    }

    public function dropCaret(string $peer): void
    {
        unset($this->carets[$peer]);
    }

    /**
     * Every other peer's caret here, as the editor counts — moved along by
     * every change since they said where it was.
     *
     * @return array<string, array{line: int, column: int, anchorLine: int|null, anchorColumn: int|null}> no anchor without a selection
     */
    public function carets(): array
    {
        $carets = [];
        foreach ($this->carets as $peer => [$caret, $anchor]) {
            [$line, $column] = $this->position($caret);
            [$anchorLine, $anchorColumn] = null === $anchor ? [null, null] : $this->position($anchor);
            $carets[$peer] = ['line' => $line, 'column' => $column, 'anchorLine' => $anchorLine, 'anchorColumn' => $anchorColumn];
        }

        return $carets;
    }

    /** Whether the buffer is still open — a closed one is reopened before anything else lands in it. */
    public function isOpen(): bool
    {
        return null !== ($this->versionOf)();
    }

    /**
     * The buffer was closed and opened again — from disk, without what the
     * session agreed on since: put the shadow back on screen, as nobody's
     * change. The text it had is not read as an edit. The buffer is open:
     * whoever calls this just opened it.
     */
    public function restore(): void
    {
        $text = (string) ($this->text)();
        ($this->edit)(TextDiff::between($text, $this->shadow)->toEdits($text));
        $this->version = ($this->versionOf)();
    }

    /**
     * For a side that may not edit: whatever got into the buffer anyway —
     * a formatter, a plugin, nothing the keyboard sends — is put back. True
     * when there was something to put back.
     */
    public function revert(): bool
    {
        $version = ($this->versionOf)();
        if (null === $version || $version === $this->version) {
            return false;
        }
        $this->restore();

        return true;
    }

    /** @param \Closure(string): ?TextOperation $step */
    private function replay(\Closure $step): ?TextOperation
    {
        // What is on screen but not read yet comes first: the step is
        // rewritten against it, or it would undo the wrong thing.
        $this->read();
        $operation = $step($this->shadow);
        if (null !== $operation) {
            $this->write($operation);
        }

        return $operation;
    }

    private function write(TextOperation $operation): void
    {
        $edits = $operation->toEdits($this->shadow);
        $shadow = $operation->apply($this->shadow);
        ($this->edit)($edits);
        $this->shadow = $shadow;
        $this->version = ($this->versionOf)();
        $this->moveCarets($operation);
    }

    private function moveCarets(TextOperation $operation): void
    {
        foreach ($this->carets as $peer => [$caret, $anchor]) {
            $this->carets[$peer] = [$operation->transformOffset($caret), null === $anchor ? null : $operation->transformOffset($anchor)];
        }
    }

    private function offset(int $line, int $column): int
    {
        $start = 0;
        for ($i = 0; $i < $line; ++$i) {
            $newline = \strpos($this->shadow, needle: "\n", offset: $start);
            if (false === $newline) {
                return \strlen($this->shadow);
            }
            $start = $newline + 1;
        }
        $end = \strpos($this->shadow, needle: "\n", offset: $start);
        $length = (false === $end ? \strlen($this->shadow) : $end) - $start;

        return $start + \max(0, \min($column, $length));
    }

    /** @return array{int, int} */
    private function position(int $offset): array
    {
        $before = \substr($this->shadow, offset: 0, length: $offset);
        $line = \substr_count($before, needle: "\n");
        $lastNewline = \strrpos($before, needle: "\n");

        return [$line, false === $lastNewline ? $offset : $offset - $lastNewline - 1];
    }
}
