<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

/**
 * What a host reads out to a guest: `42-K7QM-X2PA`. The number is the
 * nameplate the {@see Mailbox} meets the two sides at — public, the server
 * sees it; the eight letters are the secret, which never leaves either
 * machine: both sides prove they hold it through {@see Spake2}.
 *
 * The letters are from an alphabet without the ones mistaken for each other
 * — no I, O, 0 or 1 — and a code typed back is read case-blind, with spaces
 * and dashes wherever they fall after the number.
 */
final readonly class Code
{
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Letters in the secret: forty bits, each guess at them one try the host sees. */
    public const int LENGTH = 8;

    private function __construct(
        public string $nameplate,
        #[\SensitiveParameter]
        public string $secret,
    ) {}

    /** A fresh code at $nameplate, the one the mailbox handed out. */
    public static function at(string $nameplate): self
    {
        $secret = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $secret .= self::ALPHABET[\random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return new self($nameplate, $secret);
    }

    /** @throws \InvalidArgumentException naming what is wrong with $typed */
    public static function parse(#[\SensitiveParameter] string $typed): self
    {
        $upper = \strtoupper(\trim($typed));
        $match = [];
        // The number ends at the first space or dash — or, typed without
        // any, where the last eight letters begin.
        if (1 !== \preg_match('/^(\d{1,6})[\s-]+(.+)$/', $upper, $match) && 1 !== \preg_match('/^(\d{1,6})(\w{'.self::LENGTH.'})$/', $upper, $match)) {
            $match = ['', '', ''];
        }
        $secret = (string) \preg_replace('/[\s-]+/', replacement: '', subject: $match[2]);
        if (1 !== \preg_match('/^[A-HJ-NP-Z2-9]{'.self::LENGTH.'}$/', $secret)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a code like 42-K7QM-X2PA', \trim($typed)));
        }

        return new self((string) (int) $match[1], $secret);
    }

    /** The code as it is read out. */
    public function text(): string
    {
        return $this->nameplate.'-'.\implode('-', \str_split($this->secret, length: 4));
    }
}
