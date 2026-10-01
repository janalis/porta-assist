<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Lsp\Roots;

/** A language server's messages, moved between the guest's copy and the host's project — and nothing else touched. */
final class RootsTest extends TestCase
{
    public function testEveryNameUnderOneRootMovesUnderTheOther(): void
    {
        $roots = new Roots('/guest/copy', '/host/proj');

        $this->assertSame(
            [
                'rootUri' => 'file:///host/proj',
                'rootPath' => '/host/proj',
                'textDocument' => ['uri' => 'file:///host/proj/src/A.php'],
                'changes' => ['file:///host/proj/b.php' => [['newText' => 'file:///guest/copy is text, inside']]],
                'list' => ['file:///guest/copyist/x.php', 'file:///elsewhere/y.php', 3, null, true],
            ],
            $roots->rewrite([
                'rootUri' => 'file:///guest/copy',
                'rootPath' => '/guest/copy',
                'textDocument' => ['uri' => 'file:///guest/copy/src/A.php'],
                'changes' => ['file:///guest/copy/b.php' => [['newText' => 'file:///guest/copy is text, inside']]],
                'list' => ['file:///guest/copyist/x.php', 'file:///elsewhere/y.php', 3, null, true],
            ]),
            'URIs and paths, keys too; a longer name or text that merely mentions one is not a file under it',
        );
        $this->assertSame(['uri' => 'file:///guest/copy/a%20b.php'], $roots->reversed()->rewrite(['uri' => 'file:///host/proj/a%20b.php']));
    }
}
