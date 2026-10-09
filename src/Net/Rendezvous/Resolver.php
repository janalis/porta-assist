<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

use Porta\Support\AsyncProcess;
use Porta\Support\FiberWait;

/**
 * A host name looked up without blocking the loop. PHP has no asynchronous
 * lookup: `stream_socket_client` resolves first, synchronously, even when
 * asked to connect asynchronously — and with the network just changed, or a
 * captive portal in the way, that is the whole IDE frozen for the resolver's
 * timeout, at every retry. So the lookup runs in a child process, waited for
 * by the fiber that asked, and is kept for a minute.
 */
final class Resolver
{
    /** Seconds a lookup is kept. */
    private const float KEEP = 60.0;

    /** @var array<string, array{address: string, until: float}> */
    private static array $known = [];

    /**
     * The address $host is at — $host itself when it is one — or null when
     * it cannot be found in $timeout seconds.
     */
    public static function resolve(string $host, float $timeout): ?string
    {
        if (false !== \filter_var(\trim($host, characters: '[]'), \FILTER_VALIDATE_IP)) {
            return $host;
        }
        $now = \hrtime(true) / 1e9;
        $known = self::$known[$host] ?? null;

        return null !== $known && $known['until'] > $now ? $known['address'] : self::lookup($host, $timeout, $now);
    }

    /** What the system resolver makes of $host, kept for a while once found: null when nothing, in $timeout seconds. */
    private static function lookup(string $host, float $timeout, float $now): ?string
    {
        $lookup = new AsyncProcess([\PHP_BINARY, '-r', 'echo gethostbyname($argv[1]);', $host], \sys_get_temp_dir(), timeout: $timeout);
        /** @var string|null $address */
        $address = FiberWait::for(static function (\Closure $done) use ($lookup): void {
            if (!$lookup->start(static fn (int $code, string $out) => $done(0 === $code ? \trim($out) : null))) {
                // PHP's own binary, gone from under it: nothing a test can arrange.
                // @codeCoverageIgnoreStart
                $done(null);

                // @codeCoverageIgnoreEnd
            }
        });
        // gethostbyname answers the name itself when it finds nothing.
        $address = null !== $address && false !== \filter_var($address, \FILTER_VALIDATE_IP) ? $address : null;
        if (null !== $address) {
            self::$known[$host] = ['address' => $address, 'until' => $now + self::KEEP];
        }

        return $address;
    }
}
