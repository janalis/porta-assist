<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Net;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Resolver;
use Porta\Tests\Support\Probe;
use Revolt\EventLoop;

/** A name looked up off the loop: the loop keeps turning while it is. */
final class ResolverTest extends TestCase
{
    public function testAnAddressIsItsOwnAnswer(): void
    {
        $this->assertSame('127.0.0.1', GuestRendezvous::blocking(static fn (): ?string => Resolver::resolve('127.0.0.1', 1.0)));
        $this->assertSame('[::1]', GuestRendezvous::blocking(static fn (): ?string => Resolver::resolve('[::1]', 1.0)));
    }

    public function testANameIsLookedUpWhileTheLoopTurnsAndKept(): void
    {
        Probe::setStatic(Resolver::class, 'known', []);
        $turns = 0;
        $ticker = EventLoop::repeat(0.001, static function () use (&$turns): void {
            ++$turns;
        });
        try {
            $address = GuestRendezvous::blocking(static fn (): ?string => Resolver::resolve('localhost', 5.0));
        } finally {
            EventLoop::cancel($ticker);
        }

        $this->assertSame('127.0.0.1', $address);
        $this->assertGreaterThan(0, $turns, 'the loop was not held while it looked');
        $this->assertSame('127.0.0.1', Probe::getStatic(Resolver::class, 'known')['localhost']['address'] ?? null, 'and it is kept');
        Probe::setStatic(Resolver::class, 'known', ['localhost' => ['address' => '10.9.8.7', 'until' => \PHP_FLOAT_MAX]]);
        $this->assertSame('10.9.8.7', GuestRendezvous::blocking(static fn (): ?string => Resolver::resolve('localhost', 5.0)), 'not asked again');
        Probe::setStatic(Resolver::class, 'known', []);
    }

    public function testANameNobodyKnowsHasNoAddress(): void
    {
        $this->assertNull(GuestRendezvous::blocking(static fn (): ?string => Resolver::resolve('no-such-host.invalid', 5.0)));
    }
}
