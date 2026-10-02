<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Console;

use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\Plugin\Api\PluginCommand;
use Porta\Support\JsonRpcFramer;
use Porta\Support\LineFramer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta assist-lsp <language>` — a guest's language server, which is the
 * host's.
 *
 * The guest's IDE starts this as it would any language server, on stdin and
 * stdout; this carries every message to the host of the session the copy is
 * in, which runs its own server for the language on its own files and
 * answers. A plain blocking loop: it has nothing else to do, and ends when
 * either side does — or when the host has said nothing for
 * {@see Connection::SILENCE} seconds, which a connection dead with the
 * network says. Pings, when this side has said nothing for a while, keep a
 * live one talking. The editor sees the server exit, and starts it again.
 */
#[AsCommand(
    name: 'assist-lsp',
    description: 'A language server for a guest of a shared session — the host\'s, relayed',
    hidden: true,
)]
final class LanguageServerCommand extends PluginCommand
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param string|null   $root    the guest's copy; null finds it from the working directory
     * @param resource|null $input   the editor's requests; null is the real stdin
     * @param resource|null $output  where answers go; null is the real stdout
     */
    public function __construct(
        ?string $root = null,
        $input = null,
        $output = null,
        private readonly float $timeout = 10.0,
        private readonly float $ping = Connection::PING,
        private readonly float $silence = Connection::SILENCE,
    ) {
        parent::__construct($root);
        $this->input = $input ?? \STDIN;
        $this->output = $output ?? \STDOUT;
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('The language the server is for, as the host names it')]
        string $language,
    ): int {
        $errors = self::errors($output);
        $root = $this->projectRoot();
        $session = GuestSession::load($root);
        if (null === $session) {
            $errors->writeln(\sprintf('<error>%s is not a copy of a shared project.</error>', $root));

            return self::FAILURE;
        }
        $socket = GuestRendezvous::blocking(fn (): mixed => GuestRendezvous::connect($session['invite'], GuestSession::channel($root), $this->timeout));
        if (\is_string($socket)) {
            $errors->writeln('<error>'.OutputFormatter::escape($socket).'</error>');

            return self::FAILURE;
        }
        \stream_set_blocking($socket, enable: true);
        \fwrite($socket, Wire::encode([
            't' => 'hello',
            'v' => Wire::VERSION,
            'channel' => 'lsp',
            'name' => $session['name'],
            'ticket' => $session['ticket'],
            'language' => $language,
            'root' => $root,
        ]));
        $lines = new LineFramer(static function (string $why): void {}, Connection::MAX_LINE);
        // A host that never answers is no server: the wait for it ends.
        \stream_set_timeout($socket, (int) \ceil($this->timeout));
        $answer = $this->first($socket, $lines);
        if ('ready' !== ($answer['t'] ?? null)) {
            $errors->writeln(\sprintf('<error>The host runs no %s language server for this session (%s).</error>', $language, Wire::string($answer ?? [], 'reason')));
            \fclose($socket);

            return self::FAILURE;
        }
        $gone = $this->relay($socket, $lines);
        \fclose($socket);
        if ($gone) {
            $errors->writeln('<error>The host stopped answering.</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The host's first answer, or null when it hangs up first.
     *
     * @param resource $socket
     *
     * @return array<string, mixed>|null
     */
    private function first($socket, LineFramer $lines): ?array
    {
        $first = [];
        while ([] === $first && false !== ($bytes = \fgets($socket))) {
            $first = $lines->feed($bytes);
        }

        return [] === $first ? null : Wire::decode($first[0]);
    }

    /**
     * The editor's messages to the host, the host's to the editor, until
     * either side hangs up — false — or the host falls silent: true.
     *
     * @param resource $socket
     */
    private function relay($socket, LineFramer $lines): bool
    {
        $frames = new JsonRpcFramer(static function (string $why): void {});
        $heard = \hrtime(true) / 1e9;
        $said = $heard;
        while (true) {
            $now = \hrtime(true) / 1e9;
            if (($now - $heard) >= $this->silence) {
                return true;
            }
            if (($now - $said) >= $this->ping) {
                \stream_set_blocking($socket, enable: true);
                \fwrite($socket, Wire::encode(['t' => 'ping']));
                $said = $now;
            }
            $wait = \max(0.0, \min($this->ping - ($now - $said), $this->silence - ($now - $heard)));
            $read = [$this->input, $socket];
            $write = null;
            $except = null;
            if (false === \stream_select($read, $write, $except, (int) $wait, (int) (($wait - (int) $wait) * 1e6))) {
                // A select interrupted by a signal: not reproducible on demand.
                // @codeCoverageIgnoreStart
                return false;

                // @codeCoverageIgnoreEnd
            }
            foreach ($read as $stream) {
                // The socket is read without blocking: readable can be a TLS
                // record with nothing in it for this side — a session ticket —
                // which is no end of anything, and a blocking read would wait
                // out the socket's timeout on it and take it for one.
                \stream_set_blocking($stream, enable: $stream !== $socket);
                $bytes = \fread($stream, length: 262_144);
                if (false === $bytes || '' === $bytes && \feof($stream)) {
                    return false;
                }
                if ('' === $bytes) {
                    // A TLS record with nothing in it for this side: when the
                    // server sends one is OpenSSL's, not a test's, to choose.
                    // @codeCoverageIgnoreStart
                    continue;

                    // @codeCoverageIgnoreEnd
                }
                // Anything from the host says it is there; anything to it, that this side is.
                $at = \hrtime(true) / 1e9;
                $heard = $stream === $socket ? $at : $heard;
                $said = $stream === $socket ? $said : $at;
                $stream === $socket ? $this->toEditor($lines->feed($bytes)) : $this->toHost($socket, $frames->feed($bytes));
            }
        }
    }

    /** @param list<string> $lines */
    private function toEditor(array $lines): void
    {
        foreach ($lines as $line) {
            $message = Wire::decode($line);
            if (\is_array($message['m'] ?? null)) {
                \fwrite($this->output, JsonRpcFramer::encode($message['m']));
            }
        }
        \fflush($this->output);
    }

    /**
     * @param resource                          $socket
     * @param list<array<array-key, mixed>> $messages
     */
    private function toHost($socket, array $messages): void
    {
        foreach ($messages as $message) {
            \stream_set_blocking($socket, enable: true);
            \fwrite($socket, Wire::encode(['t' => 'lsp', 'm' => $message]));
        }
    }
}
