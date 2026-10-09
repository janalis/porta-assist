<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

/**
 * SPAKE2 over ristretto255 (RFC 9382's shape): two sides that hold the same
 * short {@see Code} end up with the same strong key, and anyone else — the
 * mailbox server in the middle included — learns nothing from watching, and
 * one wrong guess from trying.
 *
 * Each side sends {@see $message}; given the other's, {@see key()} is the
 * shared key — a different one on each side when the codes differ, which
 * the first sealed message then shows.
 */
final readonly class Spake2
{
    private const string LABEL = 'porta assist SPAKE2 ';

    /** What this side sends. */
    public string $message;

    private string $scalar;

    private string $password;

    public function __construct(
        #[\SensitiveParameter]
        string $password,
        private bool $host,
    ) {
        $this->password = \sodium_crypto_core_ristretto255_scalar_reduce(\hash('sha512', self::LABEL.'password '.$password, binary: true));
        $this->scalar = \sodium_crypto_core_ristretto255_scalar_random();
        $this->message = \sodium_crypto_core_ristretto255_add(
            \sodium_crypto_scalarmult_ristretto255_base($this->scalar),
            \sodium_crypto_scalarmult_ristretto255($this->password, self::blind($host ? 'M' : 'N')),
        );
    }

    /** The shared key, given the other side's message — null when it is no point on the curve. */
    public function key(string $theirs): ?string
    {
        return \SODIUM_CRYPTO_CORE_RISTRETTO255_BYTES === \strlen($theirs) && \sodium_crypto_core_ristretto255_is_valid_point($theirs)
            ? $this->shared($theirs)
            : null;
    }

    /** The shared key, given the other side's point — null when it is the identity. */
    private function shared(string $theirs): ?string
    {
        try {
            $shared = \sodium_crypto_scalarmult_ristretto255(
                $this->scalar,
                \sodium_crypto_core_ristretto255_sub($theirs, \sodium_crypto_scalarmult_ristretto255($this->password, self::blind($this->host ? 'N' : 'M'))),
            );
        } catch (\SodiumException) {
            // The identity: a message made to force a known key.
            return null;
        }
        [$hosts, $guests] = $this->host ? [$this->message, $theirs] : [$theirs, $this->message];

        return \hash('sha256', self::LABEL.'key'.$hosts.$guests.$shared.$this->password, binary: true);
    }

    /** M for the host, N for the guest: points nobody knows the logarithm of. */
    private static function blind(string $which): string
    {
        return \sodium_crypto_core_ristretto255_from_hash(\hash('sha512', self::LABEL.$which, binary: true));
    }
}
