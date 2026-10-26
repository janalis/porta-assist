<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\RendezvousFailed;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Guests meet the host with the code it reads out, through a mailbox server
 * and a relay neither side has to be reachable for: a code lets one guest
 * in and the next is ready, a wrong one is said on both sides, and a
 * mailbox server that hangs up or forgets is got over.
 */
final class HostRendezvousE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(['enabledPlugins' => ['assist']], \JSON_THROW_ON_ERROR),
        ]);
    }

    public function testACodeLetsOneGuestInAndTheNextIsReady(): void
    {
        $this->runIde(self::project(), null, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                // Stopped before the mailbox answered: nothing is shared.
                $api = $this->api($ide);
                $api->changeSetting('mailbox', $this->wormhole()->mailbox);
                $api->changeSetting('transit', $this->wormhole()->transit);
                $this->command($ide, 'assist:share');
                $this->command($ide, 'assist:stop');
                $this->await(static fn (): bool => self::toasted($ide, 'Stopped sharing'), what: 'the stop');
                $this->pump(0.2);
                $this->assertFalse(self::toasted($ide, 'Sharing.'), 'the start called off');

                // Asked twice before the first code came: one code.
                $this->command($ide, 'assist:share');
                $first = $this->share($ide);
                $this->assertSame($first->text(), $this->code($ide)->text());

                // A wrong code: said to the guest, and the host's is a new one.
                try {
                    $this->redeem(Code::parse($first->nameplate.'-AAAA-AAAA'));
                    $this->fail('a wrong code gets nothing');
                } catch (RendezvousFailed $e) {
                    $this->assertSame('That code is not right — check it with the host', $e->getMessage());
                }
                $second = $this->code($ide, not: $first);
                $this->await(static fn (): bool => self::toasted($ide, 'Someone tried the code and did not get in — the code is now '.$second->text()), what: 'the host told');

                // Ana gets in with it, Bo with the next: one code each.
                $ana = $this->redeem($second);
                $this->askIn($ana, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $this->assertSame('edit', $this->expect('welcome')['role']);
                $third = $this->code($ide, not: $second);
                $this->assertFalse(self::toasted($ide, 'the code is now '.$third->text()), 'a code used is replaced quietly');
                try {
                    $this->redeem(Code::parse('99-AAAA-AAAA'));
                    $this->fail('a code nobody waits with gets nothing');
                } catch (RendezvousFailed $e) {
                    $this->assertSame('No host is waiting with that code — check it, or ask the host for a new one', $e->getMessage());
                }
                $bo = $this->redeem($third);
                $this->askIn($bo, 'Bo');
                $this->awaitPainted($tui, $terminal, 'Bo asks to join');
                $terminal->simulateInput("\r");
                $this->assertSame('edit', $this->expect('welcome')['role']);

                // Each comes back through its own door, as often as it needs.
                $this->dial($ana);
                $this->say(['t' => 'hello', 'v' => 2, 'name' => 'Ana', 'token' => $ana->token]);
                $this->expect('pending');
                $this->hangUp();

                // Stopped: nobody gets in with the session's invites — not even on a channel Ana's door waited on.
                $this->command($ide, 'assist:stop');
                $this->assertIsString(GuestRendezvous::connect($ana, $this->nextChannel($ana), 0.5), 'the session\'s way in went with it');
            },
        ]);
    }

    public function testAMailboxServerThatHangsUpOrForgetsIsGotOver(): void
    {
        $this->runIde(self::project(), null, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                $code = $this->share($ide);

                // Hung up on: back at the same nameplate, the same code.
                $this->wormhole()->drop();
                $this->await(static fn (): bool => self::toasted($ide, 'Lost the mailbox server'), what: 'the host told');
                $this->assertFalse(self::toasted($ide, '%why%'), 'told why');
                $this->assertSame($code->text(), $this->code($ide)->text());
                $invite = $this->redeem($code);
                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\x1b[B\x1b[B\r");
                $this->assertSame('host', $this->expect('denied')['reason']);

                // Taken by strangers: a new code, said.
                $next = $this->code($ide, not: $code);
                $this->wormhole()->crowd();
                $taken = $this->code($ide, not: $next);
                $this->await(static fn (): bool => self::toasted($ide, 'The code was taken at the mailbox server — the code is now '.$taken->text()), what: 'the new code said');

                $this->command($ide, 'assist:stop');
            },
        ]);
    }
}
