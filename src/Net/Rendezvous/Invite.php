<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

/**
 * What a guest who held the right {@see Code} gets from the host, sealed
 * through the mailbox: the relay to meet the host at, the key that names
 * their channels there, the host's certificate to expect, and the token
 * that gets a guest ASKED about.
 */
final readonly class Invite
{
    public function __construct(
        public SessionKey $key,
        public string $transit,
        public string $fingerprint,
        #[\SensitiveParameter]
        public string $token,
    ) {}

    /**
     * Read back from {@see toArray()}, or from what the host sealed — null
     * when a field is missing or not what it should be.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function fromArray(array $fields): ?self
    {
        $key = \is_string($fields['key'] ?? null) ? $fields['key'] : '';
        $transit = \is_string($fields['transit'] ?? null) ? $fields['transit'] : '';
        $fingerprint = \is_string($fields['fingerprint'] ?? null) ? $fields['fingerprint'] : '';
        $token = \is_string($fields['token'] ?? null) ? $fields['token'] : '';
        if ('' === $transit || 1 !== \preg_match('/^[0-9a-f]{64}$/', $key) || 1 !== \preg_match('/^[0-9a-f]{64}$/', $fingerprint) || 1 !== \preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return new self(new SessionKey((string) \hex2bin($key)), $transit, $fingerprint, $token);
    }

    /** @return array{key: string, transit: string, fingerprint: string, token: string} */
    public function toArray(): array
    {
        return ['key' => \bin2hex($this->key->bytes), 'transit' => $this->transit, 'fingerprint' => $this->fingerprint, 'token' => $this->token];
    }
}
