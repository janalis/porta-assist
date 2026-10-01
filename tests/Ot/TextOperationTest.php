<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Ot;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\TextOperation;

final class TextOperationTest extends TestCase
{
    public function testItIsKeptCanonicalAsItIsBuilt(): void
    {
        $operation = new TextOperation()
            ->retain(2)
            ->retain(1)
            ->retain(0)
            ->delete(2)
            ->delete(1)
            ->delete(0)
            ->insert('a')
            ->insert('b')
            ->insert('');

        $this->assertSame([3, 'ab', -3], $operation->toWire(), 'neighbours merge, the insert goes before the delete');
        $this->assertSame(6, $operation->baseLength);
        $this->assertSame(5, $operation->targetLength);
        $this->assertSame(
            ['x', -2],
            new TextOperation()
                ->delete(1)
                ->insert('x')
                ->delete(1)
                ->toWire(),
            'the insert goes first and the deletes around it join',
        );
        $this->assertSame(
            ['xy', -1],
            new TextOperation()
                ->insert('x')
                ->delete(1)
                ->insert('y')
                ->toWire(),
            'an insert joins the insert before the delete',
        );
        $this->assertTrue(new TextOperation()->isNoop());
        $this->assertTrue(
            new TextOperation()
                ->retain(4)
                ->isNoop(),
        );
        $this->assertFalse(
            new TextOperation()
                ->delete(4)
                ->isNoop(),
        );
        $this->assertFalse(
            new TextOperation()
                ->insert('x')
                ->isNoop(),
        );
    }

    public function testTheWireIsCheckedComponentByComponent(): void
    {
        $this->assertTrue(TextOperation::fromWire([2, 'x', -1])->equals(
            new TextOperation()
                ->retain(2)
                ->insert('x')
                ->delete(1),
        ));
        foreach (['not a list' => 'x', 'a map' => ['a' => 1], 'a zero' => [0], 'an empty insert' => [''], 'a float' => [1.5], 'a null' => [null]] as $what => $wire) {
            try {
                TextOperation::fromWire($wire);
                $this->fail($what.' is refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItAppliesAndItsInverseTakesItBack(): void
    {
        $operation = new TextOperation()
            ->retain(4)
            ->delete(2)
            ->insert('XY')
            ->retain(1)
            ->insert('!');

        $this->assertSame('abcdXYg!', $operation->apply('abcdefg'));
        $this->assertSame('abcdefg', $operation->invert('abcdefg')->apply('abcdXYg!'));
        $this->expectException(\InvalidArgumentException::class);
        $operation->apply('short');
    }

    public function testComposeIsTheTwoInTurn(): void
    {
        $text = 'hello world';
        $first = new TextOperation()
            ->retain(5)
            ->insert(',')
            ->retain(6);
        $second = new TextOperation()
            ->delete(1)
            ->insert('H')
            ->retain(5)
            ->delete(6)
            ->insert(' there');
        $composed = $first->compose($second);

        $this->assertSame($second->apply($first->apply($text)), $composed->apply($text));
        $this->assertSame('Hello, there', $composed->apply($text));
        $inserted = new TextOperation()
            ->insert('abc')
            ->retain(1);
        $this->assertSame(
            'ac!!',
            $inserted
                ->compose(
                    new TextOperation()
                        ->retain(1)
                        ->delete(1)
                        ->retain(2)
                        ->insert('!'),
                )
                ->apply('!'),
            'text inserted then partly deleted',
        );
        $this->expectException(\InvalidArgumentException::class);
        $first->compose($first);
    }

    public function testTransformConvergesAndTheFirstSidesInsertGoesFirst(): void
    {
        $text = 'abcdef';
        $cases = [
            'both insert at one place' => [
                new TextOperation()
                    ->retain(3)
                    ->insert('A')
                    ->retain(3),
                new TextOperation()
                    ->retain(3)
                    ->insert('B')
                    ->retain(3),
                'abcABdef',
            ],
            'overlapping deletes' => [
                new TextOperation()
                    ->retain(1)
                    ->delete(3)
                    ->retain(2),
                new TextOperation()
                    ->retain(2)
                    ->delete(3)
                    ->retain(1),
                'af',
            ],
            'an insert inside a delete' => [
                new TextOperation()
                    ->retain(1)
                    ->delete(4)
                    ->retain(1),
                new TextOperation()
                    ->retain(3)
                    ->insert('Z')
                    ->retain(3),
                'aZf',
            ],
            'a delete across an insert' => [
                new TextOperation()
                    ->retain(2)
                    ->insert('Q')
                    ->retain(4),
                new TextOperation()->delete(6),
                'Q',
            ],
            'longer retains on the left' => [
                new TextOperation()
                    ->retain(5)
                    ->insert('1')
                    ->retain(1),
                new TextOperation()
                    ->retain(1)
                    ->delete(1)
                    ->retain(4),
                'acde1f',
            ],
        ];
        foreach ($cases as $what => [$a, $b, $expected]) {
            [$aPrime, $bPrime] = TextOperation::transform($a, $b);
            $this->assertSame($expected, $bPrime->apply($a->apply($text)), $what.': a then b\'');
            $this->assertSame($expected, $aPrime->apply($b->apply($text)), $what.': b then a\'');
        }
        $this->expectException(\InvalidArgumentException::class);
        TextOperation::transform(new TextOperation()->retain(1), new TextOperation()->retain(2));
    }

    public function testAnOffsetMovesWithTheTextAroundIt(): void
    {
        $operation = new TextOperation()
            ->retain(2)
            ->insert('XX')
            ->retain(2)
            ->delete(3)
            ->retain(3);

        $this->assertSame(1, $operation->transformOffset(1), 'before the insert: stays');
        $this->assertSame(4, $operation->transformOffset(2), 'at the insert: pushed past it');
        $this->assertSame(6, $operation->transformOffset(4), 'at the delete');
        $this->assertSame(6, $operation->transformOffset(6), 'inside the delete: pulled to it');
        $this->assertSame(6, $operation->transformOffset(7), 'right after it: back by its length');
        $this->assertSame(8, $operation->transformOffset(9));
    }

    public function testItIsReadAsEditorRangesOfTheOldText(): void
    {
        $text = "one\ntwo\nthree";
        $operation = new TextOperation()
            ->retain(1)
            ->delete(2)
            ->insert('NE')
            ->retain(3)
            ->delete(4)
            ->insert("\n2\n")
            ->retain(2)
            ->insert('!')
            ->retain(1);

        $this->assertSame(
            [
                ['line' => 0, 'column' => 1, 'endLine' => 0, 'endColumn' => 3, 'text' => 'NE'],
                ['line' => 1, 'column' => 2, 'endLine' => 2, 'endColumn' => 2, 'text' => "\n2\n"],
                ['line' => 2, 'column' => 4, 'endLine' => 2, 'endColumn' => 4, 'text' => '!'],
            ],
            $operation->toEdits($text),
        );
        $this->assertSame(
            [['line' => 2, 'column' => 5, 'endLine' => 2, 'endColumn' => 5, 'text' => '?']],
            new TextOperation()
                ->retain(\strlen($text))
                ->insert('?')
                ->toEdits($text),
            'at the very end',
        );
        $this->assertSame(
            [['line' => 0, 'column' => 0, 'endLine' => 1, 'endColumn' => 0, 'text' => '']],
            new TextOperation()
                ->delete(4)
                ->retain(9)
                ->toEdits($text),
        );
        $this->assertSame(
            [],
            new TextOperation()
                ->retain(\strlen($text))
                ->toEdits($text),
        );
    }
}
