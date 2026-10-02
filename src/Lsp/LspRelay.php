<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Lsp;

use Porta\AssistPlugin\Net\Connection;
use Porta\Support\JsonRpcTransport;

/**
 * A guest's language server, run by the host: the server the host itself
 * would start for the language, on the host's files, with every message
 * relayed to and from the guest's editor — file names moved between the
 * guest's copy and the host's project on the way.
 *
 * The guest's editor starts the session, so its `initialize` is the one the
 * server answers; it is pointed at the host's project, and given the host's
 * initialization options when it brings none. A guest who may only read
 * along is not let run the server's commands, which may change files.
 */
final readonly class LspRelay
{
    /** Requests a guest reading along may not make: what they do, they do to the host's files. */
    private const array WRITES = ['workspace/executeCommand'];

    private Roots $toHost;

    /**
     * @param array<string, mixed>|null $initializationOptions the host's, for the language
     * @param \Closure(): bool          $readOnly              whether the guest only reads along — asked each time, as the host may change it
     */
    public function __construct(
        private Connection $guest,
        private JsonRpcTransport $server,
        string $guestRoot,
        string $hostRoot,
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
            fn (array $message) => $this->guest->send(['t' => 'lsp', 'm' => $toGuest->rewrite($message)]),
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

    /** @param array<string, mixed> $message */
    private function fromGuest(array $message): void
    {
        if ('lsp' !== $message['t'] || !\is_array($message['m'] ?? null)) {
            return;
        }
        $lsp = $message['m'];
        $method = \is_string($lsp['method'] ?? null) ? $lsp['method'] : null;
        if (($this->readOnly)() && \in_array($method, self::WRITES, strict: true)) {
            if (\array_key_exists('id', $lsp)) {
                $this->guest->send([
                    't' => 'lsp',
                    'm' => ['jsonrpc' => '2.0', 'id' => $lsp['id'], 'error' => ['code' => -32_600, 'message' => 'reading along: the host\'s files are not yours to change']],
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
     * process of the guest's to watch, the host's project as the workspace.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function initialize(array $params): array
    {
        $params['processId'] = null;
        $params['initializationOptions'] ??= $this->initializationOptions;

        return $params;
    }
}
