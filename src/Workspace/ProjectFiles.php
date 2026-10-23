<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Workspace;

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

    /** @return list<string>|null what git lists, or null when the project is not a repository git can list */
    private function tracked(): ?array
    {
        if (!\file_exists($this->root.'/.git')) {
            return null;
        }
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $this->root);
        $process->run();

        return $process->isSuccessful()
            ? \array_values(\array_filter(\explode("\0", $process->getOutput()), static fn (string $file): bool => '' !== $file))
            : null;
    }
}
