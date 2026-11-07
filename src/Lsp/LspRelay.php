<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Lsp;

use Porta\AssistPlugin\Net\Connection;
use Porta\Lsp\Uri;
use Porta\Support\JsonRpcTransport;

/**
 * A guest's language server, run by the host: the server the host itself
 * would start for the language, on the host's files, with every message
 * relayed to and from the guest's editor — file names moved between the
 * guest's copy and the host's project on the way.
 *
 * The guest's editor starts the session, so its `initialize` is the one the
 * server answers; it is pointed at the host's project, and given the host's
 * initialization options in place of its own. A guest who may only read
 * along is not let run the server's commands, which may change files.
 */
final readonly class LspRelay
{
    /** Requests a guest reading along may not make: what they do, they do to the host's files. */
    private const array WRITES = ['workspace/executeCommand'];

    /**
     * What no guest may send, whatever the role: how the host's server is
     * configured is the host's — symfony-lsp's options name a PHP command
     * it runs — and so is what it reads: a folder added past the share is
     * the host's disk, read back through hover and symbols.
     */
    private const array CONFIGURES = ['workspace/didChangeConfiguration', 'workspace/didChangeWorkspaceFolders'];

    private Roots $toHost;

    /**
     * @param array<string, mixed>|null $initializationOptions the host's, for the language
     * @param \Closure(): bool          $readOnly              whether the guest only reads along — asked each time, as the host may change it
     */
    public function __construct(
        private Connection $guest,
        private JsonRpcTransport $server,
        string $guestRoot,
        private string $hostRoot,
        private ?array $initializationOptions,
        private \Closure $readOnly,
    ) {
        $this->toHost = new Roots($guestRoot, $hostRoot);
    }

    /** Start the server and relay; false when it does not start. */
    public function start(): bool
    {
        $toGuest = $this->toHost->reversed();
        $started = $this->server->start(
            fn (array $message) => $this->fromServer($message, $toGuest),
            fn () => $this->guest->close('the language server stopped'),
        );
        if (!$started) {
            return false;
        }
        $this->guest->listen($this->fromGuest(...), function (): void {
            $this->server->stop();
        });

        return true;
    }

    public function stop(): void
    {
        $this->guest->close('the session ended');
    }

    /**
     * The server's message, on to the guest — except its question of how it is
     * configured, which is the host's to answer and not the guest's: the guest's
     * answer would be its own settings, a PHP command among them. Answered here
     * with nothing per item, so the server keeps the host's options.
     *
     * @param array<array-key, mixed> $message
     */
    private function fromServer(array $message, Roots $toGuest): void
    {
        if ('workspace/configuration' === ($message['method'] ?? null) && \array_key_exists('id', $message)) {
            $items = \is_array($message['params'] ?? null) && \is_array($message['params']['items'] ?? null) ? $message['params']['items'] : [];
            $this->server->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => \array_fill(0, \count($items), value: null)]);

            return;
        }
        $this->guest->send(['t' => 'lsp', 'm' => $toGuest->rewrite($message)]);
    }

    /** @param array<string, mixed> $message */
    private function fromGuest(array $message): void
    {
        if ('lsp' !== $message['t'] || !\is_array($message['m'] ?? null)) {
            return;
        }
        $lsp = $message['m'];
        $method = \is_string($lsp['method'] ?? null) ? $lsp['method'] : null;
        $refused = \in_array($method, self::CONFIGURES, strict: true) || (($this->readOnly)() && \in_array($method, self::WRITES, strict: true));
        if ($refused) {
            if (\array_key_exists('id', $lsp)) {
                $this->guest->send([
                    't' => 'lsp',
                    'm' => ['jsonrpc' => '2.0', 'id' => $lsp['id'], 'error' => ['code' => -32_600, 'message' => 'the host\'s server, and the host\'s files, are not yours to change']],
                ]);
            }

            return;
        }
        $lsp = $this->toHost->rewrite($lsp);
        if ('initialize' === $method && \is_array($lsp['params'] ?? null)) {
            $lsp['params'] = $this->initialize($lsp['params']);
        }
        $this->server->send($lsp);
    }

    /**
     * The guest's `initialize`, as the host's server must hear it: no
     * process of the guest's to watch, the host's project as the workspace,
     * the host's options — never the guest's.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function initialize(array $params): array
    {
        $params['processId'] = null;
        $params['initializationOptions'] = $this->initializationOptions;
        // Set, not rewritten: a root the guest named outside its copy passed
        // through as it was, and the host's server indexed that folder — the
        // host's disk past the share, read back through hover and symbols.
        $params['rootUri'] = Uri::fromPath($this->hostRoot);
        $params['rootPath'] = $this->hostRoot;
        $params['workspaceFolders'] = [['uri' => Uri::fromPath($this->hostRoot), 'name' => \basename($this->hostRoot)]];

        return $params;
    }
}
