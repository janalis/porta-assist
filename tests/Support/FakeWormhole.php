<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Support;

use Porta\AssistPlugin\Net\Rendezvous\Frames;
use Porta\Tests\Support\Budget;
use Symfony\Component\Process\Process;

/**
 * A magic-wormhole mailbox server and transit relay of a test's own
 * (`fake-wormhole.php`), in a process that lives as long as this object:
 * where a host and a guest under test meet.
 */
final readonly class FakeWormhole
{
    /** `ws://127.0.0.1:<port>/v1` */
    public string $mailbox;

    /** `127.0.0.1:<port>` */
    public string $transit;

    private Process $process;

    /** @param array<string, string> $env FAKE_WORMHOLE_REFUSE, say */
    public function __construct(array $env = [])
    {
        $this->process = new Process([\PHP_BINARY, __DIR__.'/fake-wormhole.php'], env: $env);
        $this->process->start();
        $line = '';
        $deadline = \microtime(true) + Budget::seconds(10.0);
        while (!\str_contains($line, "\n") && \microtime(true) < $deadline && $this->process->isRunning()) {
            \usleep(5_000);
            $line .= $this->process->getIncrementalOutput();
        }
        $parts = \explode(' ', \trim($line));
        \assert(2 === \count($parts), description: 'the fake wormhole says where it is: '.$line.$this->process->getErrorOutput());
        [$this->mailbox, $this->transit] = $parts;
    }

    public function __destruct()
    {
        $this->process->stop(0);
    }

    /** Hang up on every mailbox socket: clients come back to the nameplates they claimed. */
    public function drop(): void
    {
        $this->tell('{"type":"x-drop"}');
    }

    /** Hang up on every mailbox socket, and let strangers take every nameplate meanwhile. */
    public function crowd(): void
    {
        $this->tell('{"type":"x-crowd"}');
    }

    /** Say $message to the mailbox server, as a client would, and wait for it to hang up on everyone. */
    private function tell(string $message): void
    {
        $port = (int) \substr($this->mailbox, offset: (int) \strrpos($this->mailbox, needle: ':') + 1);
        $socket = \stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 2.0);
        \assert(\is_resource($socket), description: 'the fake wormhole listens');
        \fwrite($socket, data: "GET /v1 HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: eA==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        \fwrite($socket, Frames::encode(Frames::TEXT, $message, 'mask'));
        \stream_set_timeout($socket, seconds: 2);
        do {
            $read = (string) \fread($socket, length: 8_192);
        } while (!\feof($socket) && '' !== $read);
        \fclose($socket);
    }
}
