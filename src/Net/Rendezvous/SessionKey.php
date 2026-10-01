<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

/**
 * The key a guest and the host agreed on through {@see Spake2}, and what it
 * is used for: sealing what they say through the mailbox, and naming the
 * relay channels only the two of them can meet on.
 */
final readonly class SessionKey
{
    public function __construct(
        #[\SensitiveParameter]
        public string $bytes,
    ) {}

    /** $text, readable only by the holder of this key, for $purpose only. */
    public function seal(string $purpose, string $text): string
    {
        $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce.\sodium_crypto_secretbox($text, $nonce, $this->derive('seal '.$purpose));
    }

    /** What $sealed holds — null when it was sealed under another key, for another purpose, or tampered with. */
    public function open(string $purpose, string $sealed): ?string
    {
        if (\strlen($sealed) < (\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES)) {
            return null;
        }
        $text = \sodium_crypto_secretbox_open(
            \substr($sealed, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            \substr($sealed, offset: 0, length: \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->derive('seal '.$purpose),
        );

        return false === $text ? null : $text;
    }

    /** The relay channel of the guest's $n-th connection. */
    public function channel(int $n): string
    {
        return \bin2hex($this->derive('channel '.$n));
    }

    private function derive(string $purpose): string
    {
        return \hash_hmac('sha256', $purpose, $this->bytes, binary: true);
    }
}
