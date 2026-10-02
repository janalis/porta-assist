<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Ot;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Ot\ClientDocument;
use Porta\AssistPlugin\Ot\TextDiff;
use Porta\AssistPlugin\Ot\TextOperation;

/** What a guest typed and never got acknowledged, carried onto the host's copy after a dropped connection. */
final class ClientDocumentTest extends TestCase
{
    public function testNothingTypedIsNothingToCarry(): void
    {
        $this->assertNull(new ClientDocument(0, 'one')->carry('one, and theirs'));
    }

    public function testWhatNeverReachedTheHostIsMadeAgainOnItsText(): void
    {
        $client = new ClientDocument(0, "one\n");
        $client->local(TextDiff::between("one\n", "one\nmine\n"));

        $carried = $client->carry("one\n");

        $this->assertInstanceOf(TextOperation::class, $carried);
        $this->assertSame("one\nmine\n", $carried->apply("one\n"));
    }

    public function testWhatTheHostChangedMeanwhileStays(): void
    {
        $client = new ClientDocument(0, "one\n");
        $client->local(TextDiff::between("one\n", "one\nmine\n"));
        $client->local(TextDiff::between("one\nmine\n", "one\nmine\nmore\n")); // buffered behind it

        $carried = $client->carry("theirs\none\n");

        $this->assertInstanceOf(TextOperation::class, $carried);
        $this->assertSame("theirs\none\nmine\nmore\n", $carried->apply("theirs\none\n"));
    }

    public function testWhatLandedAndLostOnlyItsAcknowledgementIsNotMadeTwice(): void
    {
        $client = new ClientDocument(0, "one\n");
        $client->local(TextDiff::between("one\n", "one\nmine\n"));

        $this->assertNull($client->carry("one\nmine\n"), 'the host has it');

        $client = new ClientDocument(0, "one\n");
        $client->local(TextDiff::between("one\n", "one\nmine\n"));
        $client->local(TextDiff::between("one\nmine\n", "one\nmine\nmore\n"));
        $carried = $client->carry("one\nmine\n");
        $this->assertInstanceOf(TextOperation::class, $carried);
        $this->assertSame("one\nmine\nmore\n", $carried->apply("one\nmine\n"), 'only what was buffered behind it');
    }

    public function testTheHostsTextIsFollowedThroughAcknowledgementsAndItsOwnChanges(): void
    {
        $client = new ClientDocument(0, 'a');
        $client->local(TextDiff::between('a', 'ab'));
        $client->acknowledged();
        $client->remote(TextDiff::between('ab', 'xab'));
        $client->local(TextDiff::between('xab', 'xabc'));

        $carried = $client->carry('xab');

        $this->assertInstanceOf(TextOperation::class, $carried);
        $this->assertSame('xabc', $carried->apply('xab'), 'the base it carries from is the host\'s text as last known');
    }
}
