<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Ot;

/**
 * Undo for one person in a shared text: their own changes only.
 *
 * Each change of theirs is remembered as its inverse; each change someone
 * else makes rewrites every remembered inverse to apply after it, so an undo
 * takes back exactly what this person did — around what the others did since.
 * The ot.js UndoManager's idea, without its grouping by time: one entry per
 * change the session read, which is already a burst of typing.
 */
final class UndoStack
{
    private const int LIMIT = 200;

    /** @var list<TextOperation> */
    private array $undo = [];

    /** @var list<TextOperation> */
    private array $redo = [];

    /** A change of this person's, made on $text: remembered, and redo forgotten. */
    public function did(TextOperation $operation, string $text): void
    {
        $this->undo[] = $operation->invert($text);
        if (\count($this->undo) > self::LIMIT) {
            \array_shift($this->undo);
        }
        $this->redo = [];
    }

    /** Someone else's change: every step is rewritten to apply after it. */
    public function transform(TextOperation $operation): void
    {
        $this->undo = self::through($this->undo, $operation);
        $this->redo = self::through($this->redo, $operation);
    }

    /**
     * The change that undoes this person's last one, made on $text — it is
     * then theirs to redo. Null with nothing to undo.
     */
    public function undo(string $text): ?TextOperation
    {
        $operation = \array_pop($this->undo);
        if (null !== $operation) {
            $this->redo[] = $operation->invert($text);
        }

        return $operation;
    }

    /** The change that redoes the last undo, made on $text — undoable again. */
    public function redo(string $text): ?TextOperation
    {
        $operation = \array_pop($this->redo);
        if (null !== $operation) {
            $this->undo[] = $operation->invert($text);
        }

        return $operation;
    }

    /**
     * Each step rewritten to apply after $operation, newest first: a step is
     * the inverse of a change made AFTER the ones below it, so $operation is
     * carried down through each in turn. A step that has nothing left to
     * take back — the others deleted all of it — is dropped, so an undo
     * always does something.
     *
     * @param list<TextOperation> $steps
     *
     * @return list<TextOperation>
     */
    private static function through(array $steps, TextOperation $operation): array
    {
        $rewritten = [];
        foreach (\array_reverse($steps) as $step) {
            [$step, $operation] = TextOperation::transform($step, $operation);
            if (!$step->isNoop()) {
                $rewritten[] = $step;
            }
        }

        return \array_reverse($rewritten);
    }
}
