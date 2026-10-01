<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Ot;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\TextDiff;

final class TextDiffTest extends TestCase
{
    public function testOneCaretTypingIsOneSmallChange(): void
    {
        $this->assertSame([5, 'X', 6], TextDiff::between('hello world', 'helloX world')->toWire());
        $this->assertSame([11], TextDiff::between('hello world', 'hello world')->toWire(), 'nothing changed');
        $this->assertSame(['abc'], TextDiff::between('', 'abc')->toWire());
        $this->assertSame([-3], TextDiff::between('abc', '')->toWire());
        $this->assertSame([2, -1], TextDiff::between('aaa', 'aa')->toWire(), 'the common start and end do not overlap');
    }

    public function testCaretsFarApartAreSeveralChangesNotOne(): void
    {
        $old = "a\nb\nc\nd\ne\nf\n";
        $new = "A\nb\nc\nd\ne\nF\n";

        $this->assertSame(['A', -1, 9, 'F', -1, 1], TextDiff::between($old, $new)->toWire(), 'the last newline is the common end');
    }

    public function testLinesAddedAndRemovedBetweenKeptOnesAreTheirOwnChanges(): void
    {
        $old = "keep\ndrop\nkeep\nedit me\nkeep\nlast";
        $new = "keep\nkeep\nedit you\nnew\nkeep\nlast!";
        $operation = TextDiff::between($old, $new);

        $this->assertSame($new, $operation->apply($old));
        $this->assertSame([5, -5, 10, "you\nnew", -2, 10, '!'], $operation->toWire());
        $this->assertSame("\n\nx", TextDiff::between("\n", "\n\nx")->apply("\n"));
        $this->assertSame("b\n", TextDiff::between("a\nb\n", "b\n")->apply("a\nb\n"), 'a line gone at the start');
    }

    public function testACutNeverFallsInsideACharacter(): void
    {
        // é is C3 A9, è is C3 A8: they share a first byte, and a cut after
        // it would leave the caret mid-character.
        $this->assertSame([1, 'è', -2, 1], TextDiff::between('aéb', 'aèb')->toWire());
        $this->assertSame([1, 'è', -2], TextDiff::between('aé', 'aè')->toWire(), 'at the end too');
        $this->assertSame(['ü', -2, 1], TextDiff::between('éa', 'üa')->toWire(), 'and at the start');
        // é is C3 A9 and ة is D8 A9: their common END is a continuation byte.
        $this->assertSame([1, 'ة', -2], TextDiff::between('aé', 'aة')->toWire(), 'nor inside one at the end');
    }

    public function testPastTheEditDistanceTheMiddleIsOneReplacement(): void
    {
        $old = \implode("\n", \range(1, end: 2_100))."\n";
        $new = \implode("\n", \array_map(static fn (int $n): string => 'x'.$n, \range(1, end: 2_100)))."\n";

        $operation = TextDiff::between($old, $new);
        $this->assertSame($new, $operation->apply($old));
        $this->assertCount(3, $operation->toWire(), 'one insert, one delete, and the last newline kept');
    }

    /** Random edits at random places: the diff always rebuilds the new text. */
    public function testRandomEditsAlwaysRoundTrip(): void
    {
        \mt_srand(7);
        $alphabet = ['a', 'b', "\n", 'é', ' '];
        for ($round = 0; $round < 300; ++$round) {
            $old = '';
            for ($i = \mt_rand(0, max: 30); $i > 0; --$i) {
                $old .= $alphabet[\mt_rand(0, max: 4)];
            }
            $new = $old;
            for ($edits = \mt_rand(1, max: 4); $edits > 0; --$edits) {
                $chars = \mb_str_split($new);
                $at = \mt_rand(0, \count($chars));
                \array_splice($chars, $at, \mt_rand(0, max: 3), \array_fill(0, \mt_rand(0, max: 3), $alphabet[\mt_rand(0, max: 4)]));
                $new = \implode('', $chars);
            }
            $this->assertSame($new, TextDiff::between($old, $new)->apply($old), \json_encode([$old, $new], \JSON_THROW_ON_ERROR));
        }
    }
}
