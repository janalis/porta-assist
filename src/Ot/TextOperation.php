<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Ot;

/**
 * One change to a text, as operational transformation sees it: a walk over
 * the WHOLE old text that retains, deletes and inserts — the shape ot.js gave
 * the idea, and the reason two people typing at once converge. Offsets are
 * BYTES of the `\n`-joined text, as the editor's columns are.
 *
 * Kept canonical as it is built — neighbours of a kind merge, an insert goes
 * before a delete at the same place — so two operations that do the same
 * thing compare equal.
 *
 * A component is a positive int (retain that many bytes), a string (insert
 * it) or a negative int (delete that many).
 */
final class TextOperation
{
    /** @var list<int|string> */
    private array $ops = [];

    /** How long a text this applies to must be. */
    public private(set) int $baseLength = 0;

    /** How long the text is once it has been applied. */
    public private(set) int $targetLength = 0;

    /**
     * The operation the wire carried, checked: anything but a list of
     * non-zero ints and non-empty strings is refused.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromWire(mixed $ops): self
    {
        if (!\is_array($ops) || !\array_is_list($ops)) {
            throw new \InvalidArgumentException('an operation is a list');
        }
        $operation = new self();
        // @mago-expect analysis:mixed-assignment -- each one is checked below
        foreach ($ops as $op) {
            match (true) {
                \is_int($op) && $op > 0 => $operation->retain($op),
                \is_int($op) && $op < 0 => $operation->delete(-$op),
                \is_string($op) && '' !== $op => $operation->insert($op),
                default => throw new \InvalidArgumentException('an operation retains, inserts or deletes'),
            };
        }

        return $operation;
    }

    /** @return list<int|string> */
    public function toWire(): array
    {
        return $this->ops;
    }

    public function retain(int $length): self
    {
        if ($length <= 0) {
            return $this;
        }
        $this->baseLength += $length;
        $this->targetLength += $length;
        $last = \array_key_last($this->ops);
        if (null !== $last && \is_int($this->ops[$last]) && $this->ops[$last] > 0) {
            $this->ops[$last] += $length;

            return $this;
        }
        $this->ops[] = $length;

        return $this;
    }

    public function insert(string $text): self
    {
        if ('' === $text) {
            return $this;
        }
        $this->targetLength += \strlen($text);
        $last = \array_key_last($this->ops);
        if (null === $last) {
            $this->ops[] = $text;

            return $this;
        }
        $op = $this->ops[$last];
        if (\is_string($op)) {
            $this->ops[$last] = $op.$text;

            return $this;
        }
        if ($op > 0) {
            $this->ops[] = $text;

            return $this;
        }
        // An insert goes BEFORE the delete it meets: "delete then insert"
        // and "insert then delete" are one change, and keeping one order
        // is what lets two equal operations compare equal.
        $before = $last - 1;
        if ($before >= 0 && \is_string($this->ops[$before])) {
            $this->ops[$before] .= $text;

            return $this;
        }
        $this->ops[$last] = $text;
        $this->ops[] = $op;

        return $this;
    }

    public function delete(int $length): self
    {
        if ($length <= 0) {
            return $this;
        }
        $this->baseLength += $length;
        $last = \array_key_last($this->ops);
        if (null !== $last && \is_int($this->ops[$last]) && $this->ops[$last] < 0) {
            $this->ops[$last] -= $length;

            return $this;
        }
        $this->ops[] = -$length;

        return $this;
    }

    /** Whether applying it changes nothing. */
    public function isNoop(): bool
    {
        return [] === $this->ops || 1 === \count($this->ops) && \is_int($this->ops[0]) && $this->ops[0] > 0;
    }

    public function equals(self $other): bool
    {
        return $this->ops === $other->ops;
    }

    /** @throws \InvalidArgumentException when $text is not as long as the operation expects */
    public function apply(string $text): string
    {
        if (\strlen($text) !== $this->baseLength) {
            throw new \InvalidArgumentException(\sprintf('the operation expects %d bytes, the text has %d', $this->baseLength, \strlen($text)));
        }
        $out = '';
        $at = 0;
        foreach ($this->ops as $op) {
            if (\is_string($op)) {
                $out .= $op;

                continue;
            }
            if ($op > 0) {
                $out .= \substr($text, $at, $op);
            }
            $at += \abs($op);
        }

        return $out;
    }

    /** The operation that undoes this one, given the text it applies to. */
    public function invert(string $text): self
    {
        $inverse = new self();
        $at = 0;
        foreach ($this->ops as $op) {
            if (\is_string($op)) {
                $inverse->delete(\strlen($op));

                continue;
            }
            $op > 0 ? $inverse->retain($op) : $inverse->insert(\substr($text, $at, -$op));
            $at += \abs($op);
        }

        return $inverse;
    }

    /**
     * Where a byte offset of the old text lands in the new one: text inserted
     * at or before it pushes it along — a collaborator's caret moves out of
     * the way of what is typed in front of it — and deleted text it sat in
     * pulls it to where the deletion was.
     */
    public function transformOffset(int $offset): int
    {
        $moved = $offset;
        $at = 0;
        foreach ($this->ops as $op) {
            if ($at > $offset) {
                break;
            }
            if (\is_string($op)) {
                $moved += \strlen($op);

                continue;
            }
            if ($op > 0) {
                $at += $op;

                continue;
            }
            $deleted = -$op;
            $moved -= ($offset - $at) < $deleted ? $offset - $at : $deleted;
            $at += $deleted;
        }

        return $moved;
    }

    /**
     * The edits it makes to $text, as editor ranges: 0-based line and byte
     * column of the OLD text, the end exclusive, in document order and never
     * overlapping — what {@see \Porta\Plugin\Api\OpenBuffers::edits()} takes.
     *
     * @return list<array{line: int, column: int, endLine: int, endColumn: int, text: string}>
     */
    public function toEdits(string $text): array
    {
        $starts = [0];
        $offset = 0;
        while (false !== ($newline = \strpos($text, needle: "\n", offset: $offset))) {
            $starts[] = $newline + 1;
            $offset = $newline + 1;
        }
        /** @return array{int, int} */
        $position = static function (int $offset) use ($starts): array {
            $low = 0;
            $high = \count($starts) - 1;
            while ($low < $high) {
                $mid = \intdiv($low + $high + 1, num2: 2);
                [$low, $high] = $starts[$mid] <= $offset ? [$mid, $high] : [$low, $mid - 1];
            }

            return [$low, $offset - $starts[$low]];
        };
        $edits = [];
        $at = 0;
        $from = null;
        $insert = '';
        $edit = static function (int $from, int $to, string $insert) use ($position): array {
            [$line, $column] = $position($from);
            [$endLine, $endColumn] = $position($to);

            return ['line' => $line, 'column' => $column, 'endLine' => $endLine, 'endColumn' => $endColumn, 'text' => $insert];
        };
        foreach ($this->ops as $op) {
            if (\is_int($op) && $op > 0) {
                if (null !== $from) {
                    $edits[] = $edit($from, $at, $insert);
                    $from = null;
                    $insert = '';
                }
                $at += $op;

                continue;
            }
            $from ??= $at;
            if (\is_string($op)) {
                $insert .= $op;

                continue;
            }
            $at -= $op;
        }
        if (null !== $from) {
            $edits[] = $edit($from, $at, $insert);
        }

        return $edits;
    }

    /**
     * This, then $next, as one operation.
     *
     * @throws \InvalidArgumentException when $next does not apply to what this produces
     */
    public function compose(self $next): self
    {
        if ($this->targetLength !== $next->baseLength) {
            throw new \InvalidArgumentException('the second operation does not start where the first ends');
        }
        $composed = new self();
        $first = $this->ops;
        $second = $next->ops;
        $i = 0;
        $j = 0;
        $a = $first[$i++] ?? null;
        $b = $second[$j++] ?? null;
        while (null !== $a || null !== $b) {
            if (\is_int($a) && $a < 0) {
                $composed->delete(-$a);
                $a = $first[$i++] ?? null;

                continue;
            }
            if (\is_string($b)) {
                $composed->insert($b);
                $b = $second[$j++] ?? null;

                continue;
            }
            // Past here $a retains or inserts and $b retains or deletes: the
            // lengths checked above leave neither run out before the other.
            \assert(null !== $a && null !== $b, description: 'the lengths were checked: neither runs out first');
            $aLength = \is_string($a) ? \strlen($a) : $a;
            $bLength = \abs($b);
            $length = \min($aLength, $bLength);
            match (true) {
                $b > 0 && \is_string($a) => $composed->insert(\substr($a, offset: 0, length: $length)),
                $b > 0 => $composed->retain($length),
                // Text inserted, then deleted: neither happened.
                \is_string($a) => null,
                default => $composed->delete($length),
            };
            [$a, $i] = self::rest($first, $i, $a, $length);
            [$b, $j] = self::rest($second, $j, $b, $length);
        }

        return $composed;
    }

    /**
     * Two operations made on the same text, each rewritten to apply after the
     * other: `$a` then `$b'` and `$b` then `$a'` reach the same text. Where
     * both insert at one place, `$a`'s text goes first — the side passed as
     * `$a` must be the same one on every peer, or they diverge.
     *
     * @return array{self, self} $a', $b'
     *
     * @throws \InvalidArgumentException when they were not made on the same text
     */
    public static function transform(self $a, self $b): array
    {
        if ($a->baseLength !== $b->baseLength) {
            throw new \InvalidArgumentException('the operations were not made on the same text');
        }
        $aPrime = new self();
        $bPrime = new self();
        $first = $a->ops;
        $second = $b->ops;
        $i = 0;
        $j = 0;
        $x = $first[$i++] ?? null;
        $y = $second[$j++] ?? null;
        while (null !== $x || null !== $y) {
            if (\is_string($x)) {
                $aPrime->insert($x);
                $bPrime->retain(\strlen($x));
                $x = $first[$i++] ?? null;

                continue;
            }
            if (\is_string($y)) {
                $aPrime->retain(\strlen($y));
                $bPrime->insert($y);
                $y = $second[$j++] ?? null;

                continue;
            }
            // Both retain or delete, over the same base: neither runs out first.
            \assert(\is_int($x) && \is_int($y), description: 'the base lengths were checked: neither runs out first');
            $length = \min(\abs($x), \abs($y));
            match (true) {
                $x > 0 && $y > 0 => [$aPrime->retain($length), $bPrime->retain($length)],
                $x < 0 && $y > 0 => $aPrime->delete($length),
                $x > 0 => $bPrime->delete($length),
                // Both deleting the same text: it is gone, neither says so again.
                default => null,
            };
            [$x, $i] = self::rest($first, $i, $x, $length);
            [$y, $j] = self::rest($second, $j, $y, $length);
        }

        return [$aPrime, $bPrime];
    }

    /**
     * What is left of component $op once $length of it is used, or the next
     * component when none is.
     *
     * @param list<int|string> $ops
     *
     * @return array{int|string|null, int}
     */
    private static function rest(array $ops, int $next, int|string $op, int $length): array
    {
        if (\is_string($op)) {
            return \strlen($op) > $length ? [\substr($op, $length), $next] : [$ops[$next] ?? null, $next + 1];
        }
        if (\abs($op) > $length) {
            return [$op > 0 ? $op - $length : $op + $length, $next];
        }

        return [$ops[$next] ?? null, $next + 1];
    }
}
