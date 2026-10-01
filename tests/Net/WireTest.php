<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Net\Wire;

/** The invite a host hands out, and the messages on the wire: whatever a peer sends is read safely. */
final class WireTest extends TestCase
{
    private const string KEY = '00112233445566778899aabbccddeeff';

    private const string PRINT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testAnInviteIsReadBackAsItWasHandedOut(): void
    {
        $address = Address::parse('  192.168.1.20:52814#'.\strtoupper(self::KEY.'.'.self::PRINT).' ');
        $this->assertSame(['192.168.1.20', 52_814, self::KEY, self::PRINT], [$address->host, $address->port, $address->token, $address->fingerprint], 'trimmed, lower-cased');
        $this->assertSame('192.168.1.20:52814#'.self::KEY.'.'.self::PRINT, $address->invite());

        $v6 = Address::parse('[::1]:9000#'.self::KEY.'.'.self::PRINT);
        $this->assertSame('::1', $v6->host);
        $this->assertSame('[::1]:9000#'.self::KEY.'.'.self::PRINT, $v6->invite(), 'the brackets come back');
    }

    public function testAnythingElseIsRefusedSayingWhat(): void
    {
        foreach ([
            'host:1#short.'.self::PRINT => 'is not host:port#token.fingerprint',
            'host#'.self::KEY.'.'.self::PRINT => 'is not host:port#token.fingerprint',
            'host:1#'.self::KEY => 'is not host:port#token.fingerprint',
            'host:0#'.self::KEY.'.'.self::PRINT => '0 is not a port',
            'host:70000#'.self::KEY.'.'.self::PRINT => '70000 is not a port',
        ] as $invite => $says) {
            try {
                Address::parse($invite);
                $this->fail($invite.' is refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    public function testALineIsAMessageOnlyWithAType(): void
    {
        $this->assertSame(['t' => 'x', 'n' => 1], Wire::decode(\trim(Wire::encode(['t' => 'x', 'n' => 1]))));
        $this->assertSame("{\"t\":\"é/\"}\n", Wire::encode(['t' => 'é/']), 'one line, readable');
        $this->assertNull(Wire::decode('not json'));
        $this->assertNull(Wire::decode('[1, 2]'));
        $this->assertNull(Wire::decode('{"t": 3}'));
        $this->assertNull(Wire::decode('"t"'));
    }

    public function testFieldsAreReadAsTheTypeAskedForOrNotAtAll(): void
    {
        $message = [
            't' => 'm',
            's' => 'text',
            'i' => 4,
            'l' => ['a', 2, 'b'],
            'map' => ['a' => 1],
            'files' => [['a.php', 3, 'h'], ['bad', 'size', 'h'], 'nope', ['b.php', 1, 'g']],
        ];

        $this->assertSame('text', Wire::string($message, 's'));
        $this->assertSame('', Wire::string($message, 'i'));
        $this->assertSame(4, Wire::int($message, 'i'));
        $this->assertSame(7, Wire::int($message, 's', default: 7));
        $this->assertSame(['a', 2, 'b'], Wire::list($message, 'l'));
        $this->assertSame([], Wire::list($message, 'map'), 'a map is not a list');
        $this->assertSame(['a', 'b'], Wire::strings($message, 'l'));
        $this->assertSame(['a.php' => ['size' => 3, 'hash' => 'h'], 'b.php' => ['size' => 1, 'hash' => 'g']], Wire::files($message, 'files'));
    }

    public function testACaretHasAnAnchorOnlyWhenItSaysOne(): void
    {
        $this->assertSame(
            ['path' => 'a.php', 'line' => 2, 'column' => 3, 'anchorLine' => 1, 'anchorColumn' => 0, 'top' => 1],
            Wire::caret(['t' => 'presence', 'path' => 'a.php', 'line' => 2, 'column' => 3, 'anchorLine' => 1, 'anchorColumn' => 0, 'top' => 1]),
        );
        $this->assertSame(['path' => '', 'line' => 0, 'column' => 0, 'anchorLine' => null, 'anchorColumn' => null, 'top' => null], Wire::caret(['t' => 'presence', 'anchorLine' => -1, 'top' => 'x']));
    }

    public function testWhatTheOtherEndSendsToBeShownCannotReachTheTerminal(): void
    {
        // An OSC 52 clipboard write, a clear screen, a C1 CSI, a bidi override.
        $name = "  Ev\x1b]52;c;aGFja2Vk\x07il\x1b[2J\u{009B}31m\u{202E}gnp.exe  ";
        $this->assertSame('Ev]52;c;aGFja2Vkil[2J31mgnp.exe', Wire::text(['name' => $name], 'name'));
        $this->assertSame('Ev]52', Wire::text(['name' => $name], 'name', max: 5));
        $this->assertSame('', Wire::text([], 'name'));
        $this->assertSame('Ana Ünal — שלום', Wire::clean("Ana Ünal — שלום\n"), 'letters of every script stay; a line break does not');
    }
}
