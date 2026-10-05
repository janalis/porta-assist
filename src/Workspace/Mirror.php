<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Workspace;

/**
 * A guest's copy of the host's project: a real directory, so everything the
 * IDE does with files — the tree, search, a language server — works on it as
 * on any project. It is brought in line with the host's list file by file:
 * what is missing or differs is fetched, what the host no longer has goes.
 *
 * Each write is checked by {@see Paths}: the host names the files, and a host
 * is not trusted to name one outside this directory.
 */
final readonly class Mirror
{
    public Paths $paths;

    public function __construct(
        public string $root,
    ) {
        $this->paths = new Paths($root);
    }

    /**
     * The mirror for the host project $id under $home: one per host project,
     * kept between sessions so a guest that comes back fetches only changes.
     * Its folder carries the project's own $name, which is what the IDE
     * titles its tree with; a name that is not one folder's is not used.
     */
    public static function under(string $home, string $id, string $name): self
    {
        $folder = '' === $name || '.' === $name || '..' === $name || 1 === \preg_match('#[/\\\\\0]#', $name) ? 'project' : $name;
        $root = $home.'/.porta/assist/'.$id.'/'.$folder;
        if (!\is_dir($root)) {
            \mkdir($root, permissions: 0o700, recursive: true);
        }

        return new self((string) \realpath($root));
    }

    /**
     * Which of the host's files this copy lacks or has different.
     *
     * @param array<array-key, array{size: int, hash: string}> $host
     *
     * @return list<string>
     */
    public function missing(array $host): array
    {
        $missing = [];
        foreach ($host as $relative => $file) {
            $relative = (string) $relative; // a path in digits keys as an int
            $path = $this->paths->absolute($relative);
            if (null === $path) {
                continue;
            }
            if (!\is_file($path) || \filesize($path) !== $file['size'] || \hash_file('xxh128', $path) !== $file['hash']) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    /**
     * Remove the files the host does not have — never the private folders.
     *
     * @param array<array-key, array{size: int, hash: string}> $host
     *
     * @return int how many went
     */
    public function prune(array $host): int
    {
        $pruned = 0;
        foreach ($this->paths->walk('', Paths::PRIVATE) as $relative) {
            if (\array_key_exists($relative, $host)) {
                continue;
            }
            \unlink($this->root.'/'.$relative);
            ++$pruned;
        }

        return $pruned;
    }

    /** Write a file the host sent; false when the path is not one this mirror may hold. */
    public function write(string $relative, string $content): bool
    {
        $path = $this->paths->absolute($relative);
        if (null === $path) {
            return false;
        }
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), permissions: 0o755, recursive: true);
        }

        return false !== \file_put_contents($path, $content);
    }
}
