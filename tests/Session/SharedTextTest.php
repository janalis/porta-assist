<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Session;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Session\SharedText;

/**
 * One shared file against a buffer of the test's own: what typing is read
 * as, what a remote change does to the buffer, the carets and the undo, and
 * a buffer gone or reopened.
 */
final class SharedTextTest extends TestCase
{
    public function testTypingIsReadOnceAndAGoneBufferIsNothingTyped(): void
    {
        [$shared, $buffer] = self::shared("one\ntwo");

        $this->assertNull($shared->read(), 'nothing typed');
        self::type($buffer, "one\ntwo!");
        $this->assertSame([7, '!'], $shared->read()?->toWire());
        $this->assertSame("one\ntwo!", $shared->shadow);
        $this->assertNull($shared->read(), 'read once');

        $buffer['version'] = null;
        $this->assertNull($shared->read(), 'gone: nothing typed — not everything deleted');
        $this->assertSame("one\ntwo!", $shared->shadow);
        $this->assertFalse($shared->isOpen());
        $this->assertFalse($shared->revert(), 'nothing to put back into a buffer that is gone');
    }

    /** A caret is placed by line and byte column, clamped into the text; each peer's is its own. */
    public function testACaretIsPlacedClampedIntoTheText(): void
    {
        [$shared] = self::shared("ab\ncdef\ng");

        $shared->placeCaret('a', 1, 2, null, null);
        $shared->placeCaret('b', 1, 99, 0, -5);
        $shared->placeCaret('c', 9, 0, 2, 1);
        $shared->placeCaret('d', 0, 0, null, null);

        $this->assertSame(
            [
                'a' => ['line' => 1, 'column' => 2, 'anchorLine' => null, 'anchorColumn' => null],
                'b' => ['line' => 1, 'column' => 4, 'anchorLine' => 0, 'anchorColumn' => 0],
                'c' => ['line' => 2, 'column' => 1, 'anchorLine' => 2, 'anchorColumn' => 1],
                'd' => ['line' => 0, 'column' => 0, 'anchorLine' => null, 'anchorColumn' => null],
            ],
            $shared->carets(),
            'past the line\'s end: its end; before its start: its start; past the last line: the text\'s end',
        );
        $shared->dropCaret('c');
        $this->assertSame(['a', 'b', 'd'], \array_keys($shared->carets()));
    }

    /** Text typed here, and text another side changed, both move the carets along. */
    public function testEveryChangeMovesTheCarets(): void
    {
        [$shared, $buffer] = self::shared("ab\ncd");
        $shared->placeCaret('peer', 1, 1, 1, 0);

        self::type($buffer, "Xab\ncd");
        $shared->read();
        $this->assertSame(['line' => 1, 'column' => 1, 'anchorLine' => 1, 'anchorColumn' => 0], $shared->carets()['peer'], 'a line above grew: the caret is where it was in its own line');
        self::type($buffer, "Xab\nYcd");
        $shared->read();
        $this->assertSame(['line' => 1, 'column' => 2, 'anchorLine' => 1, 'anchorColumn' => 1], $shared->carets()['peer'], 'typed here, before it: pushed along');

        $shared->apply(
            new TextOperation()
                ->retain(4)
                ->insert("new\n")
                ->retain(3),
        );
        $this->assertSame("Xab\nnew\nYcd", $buffer['text']);
        $this->assertSame(['line' => 2, 'column' => 2, 'anchorLine' => 2, 'anchorColumn' => 1], $shared->carets()['peer'], 'changed elsewhere: moved too');
    }

    /** Undo takes back this side's own change, where it is now — after someone else's, after what is typed and not read yet. */
    public function testUndoTakesBackThisSidesOwnChangeWhereItIsNow(): void
    {
        [$shared, $buffer] = self::shared('ab');
        self::type($buffer, 'aXb');
        $shared->read();

        $shared->apply(
            new TextOperation()
                ->insert('123')
                ->retain(3),
        );
        $this->assertSame('123aXb', $buffer['text']);
        $this->assertSame([4, -1, 1], $shared->undo()?->toWire(), 'its own X, moved past what came in front of it');
        $this->assertSame('123ab', $buffer['text']);
        $this->assertSame([4, 'X', 1], $shared->redo()?->toWire());
        $this->assertSame('123aXb', $buffer['text']);

        self::type($buffer, '123aXbY');
        $this->assertSame([6, -1], $shared->undo()?->toWire(), 'what was on screen and not read yet is read first, and is what is undone');
        $this->assertSame('123aXb', $buffer['text']);
        $this->assertSame('123aXb', $shared->shadow);
    }

    /** A side that may not edit: what got into the buffer anyway is put back, once. */
    public function testWhatGotIntoAReadOnlyBufferIsPutBack(): void
    {
        [$shared, $buffer] = self::shared("keep\nthis");

        $this->assertFalse($shared->revert(), 'nothing got in');
        self::type($buffer, "kept\nthat!");
        $this->assertTrue($shared->revert());
        $this->assertSame("keep\nthis", $buffer['text']);
        $this->assertFalse($shared->revert(), 'put back: nothing more to');
        $this->assertNull($shared->read(), 'the putting back is nobody\'s typing');
    }

    /** Reopened from disk without what the session agreed on: the shadow goes back on screen, and is not read as typing. */
    public function testAReopenedBufferGetsTheSessionsTextBack(): void
    {
        [$shared, $buffer] = self::shared("agreed\ntext");
        $buffer['text'] = "on\ndisk";
        $buffer['version'] = 1;

        $shared->restore();

        $this->assertSame("agreed\ntext", $buffer['text']);
        $this->assertNull($shared->read());
    }

    /**
     * A shared text of $text over a buffer holding it at version 1.
     *
     * @return array{SharedText, \ArrayObject<string, mixed>}
     */
    private static function shared(string $text): array
    {
        /** @var \ArrayObject<string, mixed> $buffer */
        $buffer = new \ArrayObject(['text' => $text, 'version' => 1]);
        $shared = new SharedText(
            'a.php',
            $text,
            static fn (): ?int => \is_int($buffer['version']) ? $buffer['version'] : null,
            static fn (): ?string => null === $buffer['version'] ? null : (string) $buffer['text'],
            static function (array $edits) use ($buffer): ?string {
                $buffer['text'] = self::edited((string) $buffer['text'], $edits);
                $buffer['version'] = (int) $buffer['version'] + 1;

                return null;
            },
        );

        return [$shared, $buffer];
    }

    /** @param \ArrayObject<string, mixed> $buffer */
    private static function type(\ArrayObject $buffer, string $text): void
    {
        $buffer['text'] = $text;
        $buffer['version'] = (int) $buffer['version'] + 1;
    }

    /**
     * $text with $edits made, as the editor makes them: ranges of the old text, in document order.
     *
     * @param list<array{line: int, column: int, endLine: int, endColumn: int, text: string}> $edits
     */
    private static function edited(string $text, array $edits): string
    {
        \preg_match_all('/\n/', $text, $newlines, \PREG_OFFSET_CAPTURE);
        $starts = [0, ...\array_map(static fn (array $newline): int => $newline[1] + 1, $newlines[0])];
        foreach (\array_reverse($edits) as $edit) {
            $from = $starts[$edit['line']] + $edit['column'];
            $to = $starts[$edit['endLine']] + $edit['endColumn'];
            $text = \substr($text, offset: 0, length: $from).$edit['text'].\substr($text, $to);
        }

        return $text;
    }
}
