<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Frames;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Net\Rendezvous\Spake2;
use Porta\AssistPlugin\Net\Rendezvous\Transit;

/**
 * What a host and a guest meet with: the code read out, the key both sides
 * get from it and only they do, what that key seals, and the frames the
 * mailbox's WebSocket carries it all in.
 */
final class RendezvousTest extends TestCase
{
    public function testACodeIsReadBackHoweverItIsTyped(): void
    {
        $code = Code::at('42');
        $this->assertMatchesRegularExpression('/^42-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code->text());
        $this->assertNotSame($code->secret, Code::at('42')->secret, 'a fresh secret each time');

        $typed = Code::parse("  007 k7qm x2pa\n");
        $this->assertSame(['7', 'K7QMX2PA', '7-K7QM-X2PA'], [$typed->nameplate, $typed->secret, $typed->text()]);
        $this->assertSame('42-2K7Q-MX2P', Code::parse('422K7QMX2P')->text(), 'the secret is the last eight: the rest is the number');
        $drawn = \count_chars(\implode('', \array_map(static fn (): string => Code::at('1')->secret, \range(1, end: 400))), mode: 3);
        $this->assertSame(\count_chars(Code::ALPHABET, mode: 3), $drawn, 'every letter of the alphabet is drawn, the first and the last too');
        try {
            Code::parse("  nonsense \n");
            $this->fail('nonsense is refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('"nonsense" is not a code like 42-K7QM-X2PA', $e->getMessage(), 'said as typed, without the spaces around it');
        }

        foreach (['nonsense', '42-K7QM-X2P', '42-K7QM-X2PAA', '42-I7QM-X2PA', '42-K0QM-X2PA', 'K7QM-X2PA', '1234567-K7QM-X2PA'] as $typo) {
            try {
                Code::parse($typo);
                $this->fail($typo.' is refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(\sprintf('"%s" is not a code like 42-K7QM-X2PA', $typo), $e->getMessage());
            }
        }
    }

    public function testOnlyTheSameCodeGivesTheSameKey(): void
    {
        $host = new Spake2('42-K7QM-X2PA', host: true);
        $guest = new Spake2('42-K7QM-X2PA', host: false);
        $this->assertSame(32, \strlen((string) $host->key($guest->message)));
        $this->assertSame($host->key($guest->message), $guest->key($host->message));

        $wrong = new Spake2('42-K7QM-X2PB', host: false);
        $this->assertNotSame($host->key($wrong->message), $wrong->key($host->message));
        $this->assertNotSame($host->key($guest->message), $host->key(new Spake2('42-K7QM-X2PA', host: false)->message), 'fresh on each side every time');
        $this->assertNull($host->key('not a point'), 'too short');
        $this->assertNull($host->key(\str_repeat("\xff", times: 32)), 'no point on the curve');

        // A message made so the shared point is the identity — a key known
        // in advance — is no message.
        $password = \sodium_crypto_core_ristretto255_scalar_reduce(\hash('sha512', data: 'porta assist SPAKE2 password 42-K7QM-X2PA', binary: true));
        $blind = \sodium_crypto_core_ristretto255_from_hash(\hash('sha512', data: 'porta assist SPAKE2 N', binary: true));
        $this->assertNull($host->key(\sodium_crypto_scalarmult_ristretto255($password, $blind)));
    }

    public function testWhatIsSealedOpensOnlyWithTheKeyAndForThePurpose(): void
    {
        $key = new SessionKey(\random_bytes(32));
        $sealed = $key->seal('invite', 'the host');
        $this->assertSame('the host', $key->open('invite', $sealed));
        $this->assertNotSame($sealed, $key->seal('invite', 'the host'), 'a nonce of its own each time');
        $this->assertNull($key->open('ack', $sealed), 'another purpose');
        $this->assertNull(new SessionKey(\random_bytes(32))->open('invite', $sealed), 'another key');
        $this->assertNull($key->open('invite', \substr($sealed, offset: 0, length: -1).'x'), 'tampered with');
        $this->assertNull($key->open('invite', 'short'));
        $this->assertNull($key->open('invite', \str_repeat('x', times: 20)), 'a nonce and less than a seal: nothing, not an error');
        $this->assertSame('', $key->open('invite', $key->seal('invite', '')), 'nothing sealed is the shortest seal, and opens');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->channel(0));
        $this->assertSame($key->channel(3), $key->channel(3));
        $this->assertNotSame($key->channel(3), $key->channel(4));
        $this->assertNotSame($key->channel(3), new SessionKey(\random_bytes(32))->channel(3));
    }

    public function testAnInviteIsReadBackOnlyWhole(): void
    {
        $invite = new Invite(new SessionKey(\random_bytes(32)), 'relay.example:4001', \str_repeat('ab', times: 32), \str_repeat('cd', times: 16));
        $back = Invite::fromArray($invite->toArray());
        $this->assertInstanceOf(Invite::class, $back);
        $this->assertSame($invite->toArray(), $back->toArray());

        // @mago-expect lint:no-literal-password -- not a token: a value no token can be
        foreach (['key' => 'zz', 'transit' => '', 'fingerprint' => 'AB', 'token' => 42] as $field => $bad) {
            $this->assertNull(Invite::fromArray([$field => $bad] + $invite->toArray()), $field);
        }
        $this->assertNull(Invite::fromArray([]));
    }

    public function testARelayIsHostAndPort(): void
    {
        // Taken without a word: an address that is not one throws, below.
        Transit::at(Transit::PUBLIC, 1.0);
        Transit::at(' [::1]:4001 ', 1.0);
        foreach (['nowhere', 'host:0', 'host:70000', ':4001'] as $bad) {
            try {
                Transit::at($bad, 1.0);
                $this->fail($bad.' is refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(\sprintf('the relay "%s" is not host:port', $bad), $e->getMessage());
            }
        }
        try {
            Transit::at("  nowhere\n", 1.0);
            $this->fail('nowhere is refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('the relay "nowhere" is not host:port', $e->getMessage(), 'said without the spaces around it');
        }
    }

    public function testFramesGoBothWaysWholeOrInPieces(): void
    {
        $errors = [];
        $frames = new Frames(static function (string $why) use (&$errors): void {
            $errors[] = $why;
        }, maxMessage: 100_000);
        $long = \str_repeat('x', times: 70_000);
        $wire = Frames::encode(Frames::TEXT, 'hi', 'mask').Frames::encode(Frames::TEXT, \str_repeat('y', times: 200)).Frames::encode(Frames::TEXT, $long, 'abcd');
        $messages = [];
        foreach (\str_split($wire, length: 7) as $piece) {
            $messages = [...$messages, ...$frames->feed($piece)];
        }
        $this->assertSame([[Frames::TEXT, 'hi'], [Frames::TEXT, \str_repeat('y', times: 200)], [Frames::TEXT, $long]], $messages, 'masked or not, any length, fed a few bytes at a time');
        $this->assertSame("\x81\x02hi", Frames::encode(Frames::TEXT, 'hi'), 'a server\'s: no mask');

        // A message in fragments, a ping between them.
        $fragments = "\x01\x03one".Frames::encode(Frames::PING, 'p')."\x00\x03two\x80\x05three";
        $this->assertSame(
            [
                [Frames::PING, 'p'],
                [Frames::TEXT, 'onetwothree'],
            ],
            $frames->feed($fragments),
        );
        $this->assertSame([], $errors);

        $this->assertSame([], $frames->feed("\x80\x01x"), 'a continuation of nothing');
        $this->assertSame([], new Frames(static function (string $why) use (&$errors): void {
            $errors[] = $why;
        }, maxMessage: 4)->feed(Frames::encode(Frames::TEXT, 'too long')));
        $this->assertSame([], new Frames(static function (string $why) use (&$errors): void {
            $errors[] = $why;
        }, maxMessage: 4)->feed("\x01\x03abc\x80\x03def"), 'too long once put together');
        $this->assertSame(['a continuation of nothing', 'a message too long', 'a message too long'], $errors);
    }

    /** A length is said in the fewest bytes that hold it: one up to 125, two more up to 65 535, eight past that. */
    public function testAFramesLengthTakesTheFewestBytes(): void
    {
        $head = static fn (int $length): string => \bin2hex(\substr(Frames::encode(Frames::TEXT, \str_repeat('x', $length)), offset: 0, length: 10));

        $this->assertSame('817d', \substr($head(125), offset: 0, length: 4));
        $this->assertSame('817e007e', \substr($head(126), offset: 0, length: 8));
        $this->assertSame('817effff', \substr($head(65_535), offset: 0, length: 8));
        $this->assertSame('817f0000000000010000', $head(65_536));
    }

    /** A message exactly as long as allowed is let through, whole or in fragments; the messages before a bad one are kept. */
    public function testTheLimitIsTheLongestMessageAllowed(): void
    {
        $errors = [];
        $onError = static function (string $why) use (&$errors): void {
            $errors[] = $why;
        };

        $this->assertSame([[Frames::TEXT, 'x']], new Frames($onError, maxMessage: 1)->feed(Frames::encode(Frames::TEXT, 'x')), 'one byte, under a limit of one');
        $this->assertSame([[Frames::TEXT, 'abcdef']], new Frames($onError, maxMessage: 6)->feed("\x01\x03abc\x80\x03def"));
        $this->assertSame([], $errors);
        $this->assertSame(
            [[Frames::TEXT, 'a'], [Frames::TEXT, 'b']],
            new Frames($onError, maxMessage: 4)->feed(Frames::encode(Frames::TEXT, 'a').Frames::encode(Frames::TEXT, 'b').Frames::encode(Frames::TEXT, 'too long')),
        );
        $this->assertSame(
            [[Frames::TEXT, 'a'], [Frames::TEXT, 'b']],
            new Frames($onError, maxMessage: 4)->feed(Frames::encode(Frames::TEXT, 'a').Frames::encode(Frames::TEXT, 'b')."\x01\x03abc\x80\x03def"),
            'and before one too long once put together',
        );
        $this->assertSame(['a message too long', 'a message too long'], $errors);

        $mebibyte = \str_repeat('m', times: 1_048_576);
        $this->assertSame([[Frames::TEXT, $mebibyte]], new Frames($onError)->feed(Frames::encode(Frames::TEXT, $mebibyte)), 'a mebibyte by default');
        $this->assertSame([], new Frames($onError)->feed(Frames::encode(Frames::TEXT, $mebibyte.'!')));
    }
}
