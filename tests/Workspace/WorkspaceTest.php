<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Workspace\Mirror;
use Porta\AssistPlugin\Workspace\Paths;
use Porta\AssistPlugin\Workspace\ProjectFiles;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

/**
 * What a session shares of a project, and where it may write on the other
 * side: never outside the project, never in its private folders — whoever
 * names the path.
 */
final class WorkspaceTest extends TestCase
{
    public function testAPathCrossesTheWireOnlyInsideTheProject(): void
    {
        $outside = new TempProject(['secret.txt' => 'no']);
        $project = new TempProject(['src/A.php' => '<?php']);
        \symlink($outside->dir, $project->path('link'));
        \symlink($outside->path('planted.php'), $project->path('dangling.php')); // a target not there YET
        \symlink($outside->path('later'), $project->path('dangling-dir'));
        $paths = new Paths($project->dir);
        $root = (string) \realpath($project->dir);

        $this->assertSame($root, $paths->root, 'resolved, as buffers name files');
        $this->assertSame('src/A.php', $paths->relative($root.'/src/A.php'));
        $this->assertSame($root.'/src/New.php', $paths->absolute('src/New.php'), 'a file that is not there yet is fine');
        $this->assertSame($root.'/a/b/c.php', $paths->absolute('a/b/c.php'), 'in folders not there yet too');
        foreach ([
            '',
            '../x',
            'src/../../x',
            '/etc/passwd',
            'a//b',
            './a',
            "a\0b",
            'a\\b',
            '.git/config',
            '.porta/assist-session.json',
            '.porta.json',
            'link/secret.txt',
            'dangling.php',
            'dangling-dir/x.php',
            'sub/.git/config',
            'vendor/acme/.porta/x',
            'a/.porta.json',
        ] as $refused) {
            $this->assertNull($paths->absolute($refused), \json_encode($refused, \JSON_THROW_ON_ERROR));
        }
        $this->assertNull($paths->relative('/elsewhere/a.php'));
        $this->assertNull($paths->relative($root.'/.porta.json'));
        $this->assertSame($root.'/missing', new Paths($root.'/missing')->root, 'a root that is not there is kept as given');
        $this->assertNull(new Paths($root.'/missing')->absolute('a.php'), 'and shares nothing');
    }

    public function testAGitProjectSharesWhatGitShowsAndVendor(): void
    {
        $project = new TempProject([
            '.gitignore' => "vendor/\nignored.log\n",
            'src/A.php' => '<?php',
            'untracked.php' => '<?php',
            'ignored.log' => 'noise',
            'vendor/pkg/B.php' => '<?php',
            'vendor/pkg/.git/HEAD' => 'ref',
            'big.bin' => \str_repeat('x', times: 2_000),
            'node_modules/x.js' => 'x',
        ]);
        new Process(['git', 'init', '-q'], $project->dir)->mustRun();
        new Process(['git', 'add', 'src/A.php', '.gitignore', 'big.bin', 'node_modules/x.js'], $project->dir)->mustRun();
        $files = new ProjectFiles($project->dir, maxFileSize: 1_000);

        $listed = $files->scan();
        $this->assertSame(['.gitignore', 'node_modules/x.js', 'src/A.php', 'untracked.php', 'vendor/pkg/B.php'], \array_keys($listed), 'tracked, untracked, vendor — not ignored, not too big');
        $this->assertSame(['size' => 5, 'hash' => \hash('xxh128', data: '<?php')], $listed['src/A.php']);

        \file_put_contents($project->path('src/A.php'), data: '<?php // changed');
        \touch($project->path('src/A.php'), \time() + 5);
        $this->assertSame(\hash('xxh128', data: '<?php // changed'), $files->scan()['src/A.php']['hash'], 'a changed file is read again');
    }

    public function testOutsideARepositoryEveryFileButThePrivateOnesAndNodeModules(): void
    {
        $outside = new TempProject(['x.php' => '']);
        $project = new TempProject(['a.php' => '1', 'node_modules/x.js' => 'x', '.porta/state' => 's', 'sub/b.php' => '2']);
        \symlink($outside->path('x.php'), $project->path('linked.php'));
        \symlink($outside->dir, $project->path('linked-dir'));

        $this->assertSame(['a.php', 'sub/b.php'], \array_keys(new ProjectFiles($project->dir, 100)->scan()), 'no links followed or shared');

        // A `.git` that git does not read as a repository: listed by walking.
        \file_put_contents($project->path('.git'), data: 'gitdir: /nowhere');
        $this->assertSame(['a.php', 'sub/b.php'], \array_keys(new ProjectFiles($project->dir, 100)->scan()));
    }

    public function testTheMirrorFollowsTheHostsListAndWritesOnlyInsideItself(): void
    {
        $home = new TempProject([]);
        $mirror = Mirror::under($home->dir, 'abc', 'shop');
        $this->assertSame((string) \realpath($home->path('.porta/assist/abc/shop')), $mirror->root, 'named as the project, for the tree\'s title');
        $this->assertSame(0o700, \fileperms($mirror->root) & 0o777);
        $this->assertSame($mirror->root, Mirror::under($home->dir, 'abc', 'shop')->root, 'the same copy the next time');
        foreach (['', '.', '..', 'a/b', 'a\\b', "a\0b"] as $name) {
            $this->assertStringEndsWith('/abc/project', Mirror::under($home->dir, 'abc', $name)->root, \json_encode($name, \JSON_THROW_ON_ERROR));
        }

        $this->assertTrue($mirror->write('src/A.php', 'same'));
        $this->assertTrue($mirror->write('old.php', 'gone soon'));
        $this->assertTrue($mirror->write('stale.php', 'old'));
        $this->assertFalse($mirror->write('../escape.php', 'x'));
        $this->assertFalse($mirror->write('.porta.json', '{}'), 'the copy\'s own settings are not the host\'s to write');
        $this->assertFalse($mirror->write('old.php/inside.php', 'x'), 'a file holds the folder\'s name: nothing to write into');
        \mkdir($mirror->root.'/.porta');
        \file_put_contents($mirror->root.'/.porta/assist-session.json', data: '{}');

        $host = [
            'src/A.php' => ['size' => 4, 'hash' => \hash('xxh128', data: 'same')],
            'stale.php' => ['size' => 3, 'hash' => \hash('xxh128', data: 'new')],
            'resized.php' => ['size' => 9, 'hash' => 'h'],
            'new/B.php' => ['size' => 1, 'hash' => 'h'],
            '../escape.php' => ['size' => 1, 'hash' => 'h'],
        ];
        $this->assertSame(['stale.php', 'resized.php', 'new/B.php'], $mirror->missing($host), 'what differs or is not here, nothing outside');
        $this->assertSame(1, $mirror->prune($host), 'old.php went');
        $this->assertFileDoesNotExist($mirror->root.'/old.php');
        $this->assertFileExists($mirror->root.'/.porta/assist-session.json', 'the private folder is never pruned');
    }
}
