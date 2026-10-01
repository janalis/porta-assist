<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Ot;

/**
 * The operation that turns one text into another — how a shared buffer's
 * local edits are read: the last text the session knows against the text on
 * screen.
 *
 * The common start and end are cut first, which is the whole diff for one
 * caret typing. What is left is diffed by LINES (Myers), and each changed
 * run of lines by its own common start and end, so edits made at several
 * carets far apart come out as several small changes, not one big one: a
 * collaborator typing between them keeps their text.
 *
 * Every cut falls between two UTF-8 characters, never inside one, so the
 * editor is never asked to put its caret mid-character.
 */
final class TextDiff
{
    /** Past this many changed lines the middle is one replacement: still right, just coarse. */
    private const int MAX_EDIT_DISTANCE = 2_000;

    public static function between(string $old, string $new): TextOperation
    {
        $operation = new TextOperation();
        if ($old === $new) {
            return $operation->retain(\strlen($old));
        }
        $prefix = self::commonPrefix($old, $new);
        $suffix = self::commonSuffix($old, $new, $prefix);
        $operation->retain($prefix);
        $oldMiddle = \substr($old, $prefix, \strlen($old) - $prefix - $suffix);
        $newMiddle = \substr($new, $prefix, \strlen($new) - $prefix - $suffix);
        self::lines($operation, $oldMiddle, $newMiddle);

        return $operation->retain($suffix);
    }

    /** The middle, line by line: equal lines retained, each changed run trimmed on its own. */
    private static function lines(TextOperation $operation, string $old, string $new): void
    {
        $a = self::split($old);
        $b = self::split($new);
        $script = self::script($a, $b);
        if (null === $script) {
            self::replace($operation, $old, $new);

            return;
        }
        $removed = '';
        $added = '';
        foreach ($script as [$kind, $line]) {
            if ('=' === $kind) {
                self::replace($operation, $removed, $added);
                $removed = '';
                $added = '';
                $operation->retain(\strlen($line));

                continue;
            }
            '-' === $kind ? ($removed .= $line) : ($added .= $line);
        }
        self::replace($operation, $removed, $added);
    }

    /** $old becomes $new: only what differs between their common start and end. */
    private static function replace(TextOperation $operation, string $old, string $new): void
    {
        $prefix = self::commonPrefix($old, $new);
        $suffix = self::commonSuffix($old, $new, $prefix);
        $operation
            ->retain($prefix)
            ->delete(\strlen($old) - $prefix - $suffix)
            ->insert(\substr($new, $prefix, \strlen($new) - $prefix - $suffix))
            ->retain($suffix);
    }

    /**
     * Myers' shortest edit script between two lists of lines, or null past
     * {@see MAX_EDIT_DISTANCE}.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{string, string}>|null each line with '=', '-' or '+'
     */
    private static function script(array $a, array $b): ?array
    {
        $n = \count($a);
        $m = \count($b);
        $max = \min($n + $m, self::MAX_EDIT_DISTANCE);
        $v = [1 => 0];
        $trace = [];
        for ($d = 0; $d <= $max; ++$d) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $down = $k === -$d || $k !== $d && $v[$k - 1] < $v[$k + 1];
                $x = $down ? $v[$k + 1] : $v[$k - 1] + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    ++$x;
                    ++$y;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b, $k);
                }
            }
        }

        return null;
    }

    /**
     * Walk the recorded frontiers back from the end to the start.
     *
     * @param list<array<int, int>> $trace
     * @param list<string>          $a
     * @param list<string>          $b
     *
     * @return list<array{string, string}>
     */
    private static function backtrack(array $trace, array $a, array $b, int $k): array
    {
        $x = \count($a);
        $y = \count($b);
        $script = [];
        for ($d = \count($trace) - 1; $d > 0; --$d) {
            $v = $trace[$d];
            $down = $k === -$d || $k !== $d && $v[$k - 1] < $v[$k + 1];
            $previousK = $down ? $k + 1 : $k - 1;
            $previousX = $v[$previousK];
            $previousY = $previousX - $previousK;
            while ($x > $previousX && $y > $previousY) {
                $script[] = ['=', $a[--$x]];
                --$y;
            }
            $script[] = $down ? ['+', $b[--$y]] : ['-', $a[--$x]];
            $k = $previousK;
        }

        // No snake leads out of the start: the common start was cut before
        // the lines were split, so the first lines here always differ.

        return \array_reverse($script);
    }

    /**
     * Lines with their newline kept, so they join back to the text.
     *
     * @return list<string>
     */
    private static function split(string $text): array
    {
        if ('' === $text) {
            return [];
        }
        $lines = \explode("\n", $text);
        $last = \array_pop($lines);
        $lines = \array_map(static fn (string $line): string => $line."\n", $lines);
        if ('' !== $last) {
            $lines[] = $last;
        }

        return $lines;
    }

    /** Bytes $a and $b start with in common, cut back to a character boundary. */
    private static function commonPrefix(string $a, string $b): int
    {
        $limit = \min(\strlen($a), \strlen($b));
        $length = 0;
        while ($length < $limit && $a[$length] === $b[$length]) {
            ++$length;
        }
        // Not inside a character: a continuation byte cannot start the rest.
        while ($length > 0 && (self::continues($a, $length) || self::continues($b, $length))) {
            --$length;
        }

        return $length;
    }

    /** Bytes $a and $b end with in common past $prefix, cut back to a character boundary. */
    private static function commonSuffix(string $a, string $b, int $prefix): int
    {
        $limit = \min(\strlen($a), \strlen($b)) - $prefix;
        $length = 0;
        while ($length < $limit && $a[\strlen($a) - $length - 1] === $b[\strlen($b) - $length - 1]) {
            ++$length;
        }
        while ($length > 0 && self::continues($a, \strlen($a) - $length)) {
            --$length;
        }

        return $length;
    }

    /** Whether the byte at $at continues a character begun before it. */
    private static function continues(string $text, int $at): bool
    {
        return $at < \strlen($text) && 0x80 === (\ord($text[$at]) & 0xC0);
    }
}
