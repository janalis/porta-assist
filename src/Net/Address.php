<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

/**
 * Where a shared session is and the secret that lets one in, in the one line
 * a host copies to a guest: `host:port#token` — `[::1]:port#token` for IPv6.
 */
final readonly class Address
{
    public function __construct(
        public string $host,
        public int $port,
        #[\SensitiveParameter]
        public string $token,
    ) {}

    /** @throws \InvalidArgumentException naming what is wrong with $line */
    public static function parse(string $line): self
    {
        $match = [];
        if (1 !== \preg_match('/^\s*(\[[0-9a-f:.]+\]|[^\s:#\[\]]+):(\d{1,5})#([0-9a-f]{32})\s*$/i', $line, $match)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not host:port#token', \trim($line)));
        }
        $port = (int) $match[2];
        if ($port < 1 || $port > 65_535) {
            throw new \InvalidArgumentException(\sprintf('%d is not a port', $port));
        }

        return new self(\trim($match[1], characters: '[]'), $port, \strtolower($match[3]));
    }

    /** The invite as it is handed out. */
    public function invite(): string
    {
        return \sprintf('%s:%d#%s', \str_contains($this->host, ':') ? '['.$this->host.']' : $this->host, $this->port, $this->token);
    }
}
