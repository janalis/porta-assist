<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Workspace;

use Porta\Support\GitIgnored;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * What a guest gets of the host's project: every file git would show (tracked,
 * and untracked but not ignored) plus `vendor/`, which the guest's own
 * language server needs to know the dependencies — or, outside a repository,
 * every file but the private folders and `node_modules`. Each with its size
 * and a digest, so a guest that joined before fetches only what changed.
 *
 * The digests are cached by size and mtime: a rescan after a checkout reads
 * only the files the checkout touched.
 */
final class ProjectFiles
{
    private const array SKIPPED_DIRECTORIES = ['.git', '.porta', 'node_modules'];

    /** @var array<string, array{size: int, mtime: int, hash: string}> */
    private array $cache = [];

    public function __construct(
        private readonly string $root,
        private readonly int $maxFileSize,
    ) {}

    /** @return array<array-key, array{size: int, hash: string}> relative path (an int when it is digits) => size and digest, sorted */
    public function scan(): array
    {
        $paths = new Paths($this->root);
        $files = [];
        foreach ($this->candidates() as $relative) {
            $absolute = $paths->absolute($relative);
            if (null === $absolute || !\is_file($absolute) || \is_link($absolute)) {
                continue;
            }
            $stat = \stat($absolute);
            if (false === $stat || $stat['size'] > $this->maxFileSize) {
                continue;
            }
            $cached = $this->cache[$relative] ?? null;
            if (null === $cached || $cached['size'] !== $stat['size'] || $cached['mtime'] !== $stat['mtime']) {
                $cached = ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'hash' => (string) \hash_file('xxh128', $absolute)];
                $this->cache[$relative] = $cached;
            }
            $files[$relative] = ['size' => $cached['size'], 'hash' => $cached['hash']];
        }
        \ksort($files, \SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $files = $this->tracked();
        if (null === $files) {
            return new Paths($this->root)->walk('', self::SKIPPED_DIRECTORIES);
        }

        return \is_dir($this->root.'/vendor') ? [...$files, ...new Paths($this->root)->walk('vendor', self::SKIPPED_DIRECTORIES)] : $files;
    }

    /** @return list<string>|null what git lists — or, where it cannot, what the .gitignore files leave — or null outside a repository */
    private function tracked(): ?array
    {
        if (!\file_exists($this->root.'/.git')) {
            return null;
        }
        // The project's own config can name an fsmonitor — a program git runs
        // to list what changed. A listing is no reason to run it.
        $process = new Process(['git', '-c', 'core.fsmonitor=false', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $this->root);
        $process->run();

        return $process->isSuccessful()
            ? \array_values(\array_filter(\explode("\0", $process->getOutput()), static fn (string $file): bool => '' !== $file))
            : $this->unignored();
    }

    /**
     * A repository git cannot list — no git on the PATH, one a dubious owner
     * makes it refuse, a `.git` naming a gitdir that is gone — read by its
     * .gitignore files alone. Walked whole, it handed a guest the gitignored
     * `.env`. What only `.git/info/exclude` or the user's own excludes file
     * ignore, nothing here knows of. A folder it cannot read — a database
     * volume a container owns — is passed over, as a listing would, and an
     * ignored one is not walked at all.
     *
     * @return list<string>
     */
    private function unignored(): array
    {
        $files = [];
        foreach (new Finder()
            ->files()
            ->in($this->root)
            ->exclude(self::SKIPPED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCSIgnored(true)
            ->ignoreUnreadableDirs()
            ->filter(GitIgnored::pruner($this->root), prune: true) as $file) {
            $files[] = $file->getRelativePathname();
        }

        return $files;
    }
}
