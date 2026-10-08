<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\Role;
use Porta\AssistPlugin\Tests\Support\TalksAssist;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A guest's language server is the host's: run by the host on its own
 * files, every message relayed, file names moved between the guest's copy
 * and the host's project both ways — and nothing a guest reading along asks
 * changes the host's files.
 */
final class HostLanguageServerE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use RunsIde;
    use TalksAssist;
    use UsesBundledPlugins;

    private const string COPY = '/guest/copy';

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(
                [
                    'enabledPlugins' => ['assist'],
                    'lsp' => [
                        'php' => \PHP_BINARY.' '.__DIR__.'/Support/echo-lsp-server.php',
                        'broken' => '/nonexistent/language-server',
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    /** A second connection, as the guest's `porta assist-lsp` makes: the host's answer. @return array<string, mixed> */
    private function channel(Invite $invite, string $ticket, string $language, string $root = self::COPY): array
    {
        $this->dial($invite);
        $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'channel' => 'lsp', 'name' => 'Ana', 'ticket' => $ticket, 'language' => $language, 'root' => $root]);
        $this->await(fn (): bool => [] !== $this->inbox || $this->hungUp(), what: 'the host\'s answer');

        return $this->inbox[0] ?? ['t' => 'closed'];
    }

    /** @param array<string, mixed> $message @return array<string, mixed> the server's answer, relayed */
    private function ask(array $message): array
    {
        $this->say(['t' => 'lsp', 'm' => $message]);
        $answer = $this->expect('lsp')['m'];
        $this->assertIsArray($answer);

        return $answer;
    }

    public function testTheHostsServerAnswersAGuestAboutItsCopy(): void
    {
        $project = self::project();
        $root = (string) \realpath($project->dir);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($root): void {
                $invite = $this->invited($ide);
                $this->askIn($invite, 'Ana');
                $this->awaitPainted($tui, $terminal, 'Ana asks to join');
                $terminal->simulateInput("\r");
                $welcome = $this->expect('welcome');
                $this->assertContains('php', $welcome['languages']);
                $ticket = Wire::string($welcome, 'ticket');
                $main = $this->peer;

                // Refused: no ticket, a language the host has no server for, no copy, a server that does not start.
                foreach ([['', 'php', self::COPY, 'token'], [$ticket, 'cobol', self::COPY, 'language'], [$ticket, 'php', '', 'root'], [$ticket, 'broken', self::COPY, 'unavailable']] as [
                    $with,
                    $language,
                    $copy,
                    $reason,
                ]) {
                    $this->assertSame(['t' => 'denied', 'reason' => $reason], $this->channel($invite, $with, $language, $copy), $reason);
                }

                $this->assertSame(['t' => 'ready'], $this->channel($invite, $ticket, 'php'));
                $this->inbox = [];
                $initialize = $this->ask([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'processId' => 4_242,
                        'rootUri' => 'file://'.self::COPY,
                        'workspaceFolders' => [['uri' => 'file://'.self::COPY, 'name' => 'copy']],
                        'initializationOptions' => ['phpCommand' => '/bin/sh -c evil'],
                    ],
                ]);
                $heard = \json_decode((string) \base64_decode((string) ($initialize['result']['raw'] ?? ''), strict: true), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertIsArray($heard);
                $this->assertSame(
                    [null, Uri::fromPath($root), Uri::fromPath($root), null],
                    [$heard['processId'], $heard['rootUri'], $heard['workspaceFolders'][0]['uri'], $heard['initializationOptions']],
                    'the host\'s project and options, no process of the guest\'s',
                );
                // Nor may a guest reconfigure the host's server later, whatever its role.
                $reconfigure = $this->ask(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'workspace/didChangeConfiguration', 'params' => ['settings' => ['phpCommand' => 'evil']]]);
                $this->assertSame(5, $reconfigure['id']);
                $this->assertArrayHasKey('error', $reconfigure);
                $widen = $this->ask([
                    'jsonrpc' => '2.0',
                    'id' => 6,
                    'method' => 'workspace/didChangeWorkspaceFolders',
                    'params' => ['event' => ['added' => [['uri' => 'file:///', 'name' => 'all']], 'removed' => []]],
                ]);
                $this->assertArrayHasKey('error', $widen, 'nor read past the share');
                // The server asking how it is configured is answered by the host —
                // with nothing, so it keeps the host's options — never by the guest.
                $this->assertSame(7, $this->ask(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'test/askConfiguration'])['id']);
                $told = $this->expect('lsp')['m'];
                $this->assertSame(['test/configured', [null, null]], [$told['method'] ?? null, $told['params']['result'] ?? null]);

                // A question about the copy's file reaches the server about the host's — and the answer comes back about the copy's.
                $definition = $this->ask([
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'method' => 'textDocument/definition',
                    'params' => ['textDocument' => ['uri' => 'file://'.self::COPY.'/src/A.php'], 'position' => ['line' => 0, 'character' => 1]],
                ]);
                $this->assertSame('file://'.self::COPY.'/src/A.php', $definition['result']['heard']['params']['textDocument']['uri'] ?? null, 'there and back');
                $this->say(['t' => 'lsp', 'm' => ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]]);
                $this->say(['t' => 'nonsense']);

                // Reading along: the server's commands are not hers to run.
                $host = Probe::get($this->plugin($ide), 'host');
                $this->assertIsObject($host);
                $host->setRole(Wire::string($welcome, 'peer'), Role::ReadOnly);
                $this->say(['t' => 'lsp', 'm' => ['jsonrpc' => '2.0', 'method' => 'workspace/executeCommand', 'params' => ['command' => 'x']]]);
                $refused = $this->ask(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'workspace/executeCommand', 'params' => ['command' => 'x']]);
                $this->assertSame(3, $refused['id']);
                $this->assertStringContainsString('not yours to change', $refused['error']['message'] ?? '');
                $host->setRole(Wire::string($welcome, 'peer'), Role::Edit);
                $this->assertSame(
                    'workspace/executeCommand',
                    $this->ask(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'workspace/executeCommand', 'params' => ['command' => 'x']])['result']['heard']['method'] ?? null,
                );

                // The server exits: the channel closes.
                $this->say(['t' => 'lsp', 'm' => ['jsonrpc' => '2.0', 'method' => 'exit']]);
                $this->await($this->hungUp(...), what: 'the channel closed with the server');

                // Removed from the session: her language servers go with her.
                $this->dial($invite);
                $this->say(['t' => 'hello', 'v' => Wire::VERSION, 'channel' => 'lsp', 'name' => 'Ana', 'ticket' => $ticket, 'language' => 'php', 'root' => self::COPY]);
                $this->expect('ready');
                $channel = $this->peer;
                $this->peer = $main;
                $host->remove(Wire::string($welcome, 'peer'));
                $this->peer = $channel;
                $this->await($this->hungUp(...), what: 'her server stopped');

                // And stopping the session stops every one. Bo comes with a code
                // of his own: Ana's way in went with her.
                $invite = $this->redeem($this->code($ide));
                $this->askIn($invite, 'Bo');
                $this->awaitPainted($tui, $terminal, 'Bo asks to join');
                $terminal->simulateInput("\r");
                $bo = Wire::string($this->expect('welcome'), 'ticket');
                $this->assertSame(['t' => 'ready'], $this->channel($invite, $bo, 'php'));
                $this->command($ide, 'assist:stop');
                $this->await($this->hungUp(...), what: 'his server stopped with the session');
                $this->hangUp();
            },
        ]);
    }
}
