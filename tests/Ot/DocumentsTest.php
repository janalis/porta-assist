<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Ot;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\ServerDocument;
use Porta\AssistPlugin\Ot\TextDiff;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Ot\UndoStack;

/** The host's order of changes refuses one that names no revision it had; undo and redo walk one person's changes. */
final class DocumentsTest extends TestCase
{
    public function testAChangeMustNameARevisionTheHostHad(): void
    {
        $server = new ServerDocument('ab');
        foreach ([-1, 1] as $revision) {
            try {
                $server->receive($revision, new TextOperation()->retain(2));
                $this->fail('revision '.$revision.' is refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(\sprintf('revision %d is not one of 0..0', $revision), $e->getMessage());
            }
        }
        $this->assertSame(0, $server->revision(), 'and nothing was logged');
    }

    public function testRedoTakesBackAnUndoAndANewChangeForgetsIt(): void
    {
        $undo = new UndoStack();
        $text = 'one';
        $typed = TextDiff::between($text, 'one two');
        $undo->did($typed, $text);
        $text = $typed->apply($text);

        $back = $undo->undo($text);
        $this->assertInstanceOf(TextOperation::class, $back);
        $text = $back->apply($text);
        $this->assertSame('one', $text);
        $again = $undo->redo($text);
        $this->assertInstanceOf(TextOperation::class, $again);
        $text = $again->apply($text);
        $this->assertSame('one two', $text);
        $this->assertNull($undo->redo($text), 'nothing more to redo');

        $back = $undo->undo($text);
        $this->assertInstanceOf(TextOperation::class, $back);
        $text = $back->apply($text);
        $undo->did(TextDiff::between($text, 'one!'), $text);
        $this->assertNull($undo->redo('one!'), 'a new change forgets what could be redone');
    }

    public function testOnlySoManyStepsAreKept(): void
    {
        $undo = new UndoStack();
        $text = '';
        for ($i = 0; $i < 205; ++$i) {
            $typed = new TextOperation()
                ->retain(\strlen($text))
                ->insert('x');
            $undo->did($typed, $text);
            $text .= 'x';
        }
        $steps = 0;
        while (null !== ($back = $undo->undo($text))) {
            $text = $back->apply($text);
            ++$steps;
        }
        $this->assertSame(200, $steps);
        $this->assertSame('xxxxx', $text, 'the oldest five are past reach');
    }

    public function testAStepTheOthersEmptiedIsDropped(): void
    {
        $undo = new UndoStack();
        $undo->did(
            new TextOperation()
                ->retain(1)
                ->insert('x')
                ->retain(1),
            'ab',
        );
        // Someone else deleted the whole text, the x with it.
        $undo->transform(new TextOperation()->delete(3));
        $this->assertNull($undo->undo(''), 'nothing left to take back');
    }
}
