<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Ot;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\ClientDocument;
use Porta\AssistPlugin\Ot\ServerDocument;
use Porta\AssistPlugin\Ot\TextDiff;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Ot\UndoStack;
use Porta\Tests\Support\Probe;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A host and three guests typing, undoing and receiving in a random order,
 * with every message delayed a random while: once everything is delivered,
 * every copy of the text is the same. Seeded, so a failure replays.
 */
final class ConvergenceTest extends TestCase
{
    private const array ALPHABET = ['a', 'b', "\n", 'é', ' ', 'Z'];

    /** @var list<array{text: string, doc: ClientDocument, undo: UndoStack, inbox: list<array{string, ?TextOperation}>}> */
    private array $guests = [];

    /** @var list<array{int, int, TextOperation}> guest, revision, change — in the order sent */
    private array $toHost = [];

    private ServerDocument $host;

    private UndoStack $hostUndo;

    private Randomizer $random;

    public function testEveryCopyEndsTheSame(): void
    {
        foreach ([1, 2, 3, 42, 1_234] as $seed) {
            $this->run_($seed);
        }
    }

    public function testAnUndoTakesBackOnlyItsOwnersChange(): void
    {
        $this->host = new ServerDocument('one two');
        $this->hostUndo = new UndoStack();
        $this->guests = [['text' => 'one two', 'doc' => new ClientDocument(0, 'one two'), 'undo' => new UndoStack(), 'inbox' => []]];

        $this->guestTypes(0, 'one! two');
        $this->hostTypes('ONE two');
        $this->deliverAll();
        $this->assertSame('ONE! two', $this->host->text);

        $this->guestUndoes(0);
        $this->deliverAll();
        $this->assertSame('ONE two', $this->host->text, 'the guest\'s "!" went, the host\'s capitals stayed');
        $this->assertSame('ONE two', $this->guests[0]['text']);

        $operation = $this->hostUndo->undo($this->host->text);
        $this->assertInstanceOf(TextOperation::class, $operation);
        $this->hostApplies($operation);
        $this->deliverAll();
        $this->assertSame('one two', $this->guests[0]['text'], 'and the host\'s undo took back only the host\'s');
        $this->assertNull(new UndoStack()->undo(''), 'nothing to undo');
    }

    private function run_(int $seed): void
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->host = new ServerDocument("start\ntext\n");
        $this->hostUndo = new UndoStack();
        $this->guests = [];
        $this->toHost = [];
        for ($g = 0; $g < 3; ++$g) {
            $this->guests[] = ['text' => $this->host->text, 'doc' => new ClientDocument(0, $this->host->text), 'undo' => new UndoStack(), 'inbox' => []];
        }
        for ($step = 0; $step < 400; ++$step) {
            $guest = $this->random->getInt(0, max: 2);
            match ($this->random->getInt(0, max: 9)) {
                0, 1, 2 => $this->guestTypes($guest, self::mutate($this->random, $this->guests[$guest]['text'])),
                3 => $this->guestUndoes($guest),
                4 => $this->hostTypes(self::mutate($this->random, $this->host->text)),
                5, 6 => $this->deliverToHost(),
                default => $this->deliverToGuest($guest),
            };
        }
        $this->deliverAll();
        foreach ($this->guests as $g => $guest) {
            $this->assertSame($this->host->text, $guest['text'], \sprintf('seed %d, guest %d', $seed, $g));
            $this->assertNull(Probe::get($guest['doc'], 'outstanding'));
        }
    }

    private function guestTypes(int $g, string $new): void
    {
        $old = $this->guests[$g]['text'];
        $operation = TextDiff::between($old, $new);
        $this->guests[$g]['undo']->did($operation, $old);
        $this->guestApplies($g, $operation);
    }

    private function guestUndoes(int $g): void
    {
        $operation = $this->guests[$g]['undo']->undo($this->guests[$g]['text']);
        if (null !== $operation) {
            $this->guestApplies($g, $operation);
        }
    }

    private function guestApplies(int $g, TextOperation $operation): void
    {
        $this->guests[$g]['text'] = $operation->apply($this->guests[$g]['text']);
        $send = $this->guests[$g]['doc']->local($operation);
        if (null !== $send) {
            $this->toHost[] = [$g, $this->guests[$g]['doc']->revision, $send];
        }
    }

    private function hostTypes(string $new): void
    {
        $operation = TextDiff::between($this->host->text, $new);
        $this->hostUndo->did($operation, $this->host->text);
        $this->hostApplies($operation);
    }

    private function hostApplies(TextOperation $operation): void
    {
        $applied = $this->host->receive($this->host->revision(), $operation);
        foreach (\array_keys($this->guests) as $g) {
            $this->guests[$g]['inbox'][] = ['op', $applied];
        }
    }

    private function deliverToHost(): void
    {
        $message = \array_shift($this->toHost);
        if (null === $message) {
            return;
        }
        [$from, $revision, $operation] = $message;
        $applied = $this->host->receive($revision, $operation);
        $this->hostUndo->transform($applied);
        foreach (\array_keys($this->guests) as $g) {
            $this->guests[$g]['inbox'][] = $g === $from ? ['ack', null] : ['op', $applied];
        }
    }

    private function deliverToGuest(int $g): void
    {
        $message = \array_shift($this->guests[$g]['inbox']);
        if (null === $message) {
            return;
        }
        [$kind, $operation] = $message;
        $doc = $this->guests[$g]['doc'];
        if (null === $operation) {
            $next = $doc->acknowledged();
            if (null !== $next) {
                $this->toHost[] = [$g, $doc->revision, $next];
            }

            return;
        }
        $this->assertSame($kind, 'op');
        $operation = $doc->remote($operation);
        $this->guests[$g]['undo']->transform($operation);
        $this->guests[$g]['text'] = $operation->apply($this->guests[$g]['text']);
    }

    private function deliverAll(): void
    {
        while ([] !== $this->toHost || [] !== \array_merge(...\array_column($this->guests, 'inbox'))) {
            $this->deliverToHost();
            foreach (\array_keys($this->guests) as $g) {
                $this->deliverToGuest($g);
            }
        }
    }

    private static function mutate(Randomizer $random, string $text): string
    {
        $chars = \mb_str_split($text);
        $at = $random->getInt(0, \count($chars));
        $insert = \array_fill(0, $random->getInt(0, max: 3), self::ALPHABET[$random->getInt(0, max: 5)]);
        \array_splice($chars, $at, $random->getInt(0, max: 2), $insert);

        return \implode('', $chars);
    }
}
