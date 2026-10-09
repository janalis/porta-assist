<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Workspace;

/**
 * Paths as they cross the wire: relative to the project, `/`-separated — and
 * CHECKED on the way in, both ways. A guest names files on the host's disk and
 * a host names files on the guest's; neither may name one outside the
 * project, or inside the two folders that are not the project's to share.
 */
final readonly class Paths
{
    /**
     * Never shared: git's own store, Porta's per-project state (the session
     * file is there) and its project settings — the host's plugins and keys
     * are the host's, and a guest's copy keeps settings of its own.
     */
    public const array PRIVATE = ['.git', '.porta', '.porta.json'];

    /** The project's root, resolved: buffers and file events name files by their real path. */
    public string $root;

    public function __construct(string $root)
    {
        $real = \realpath($root);
        $this->root = false === $real ? $root : $real;
    }

    /** $path relative to the root, or null when it is not a shareable file under it. */
    public function relative(string $path): ?string
    {
        $prefix = \rtrim($this->root, characters: '/').'/';
        if (!\str_starts_with($path, $prefix)) {
            return null;
        }
        $relative = \substr($path, \strlen($prefix));

        return self::valid($relative) ? $relative : null;
    }

    /**
     * The absolute path a relative one names, or null when it names nothing
     * shareable: `..` anywhere, an absolute path, a private folder — or a
     * symlink on the way that leads out of the project.
     */
    public function absolute(string $relative): ?string
    {
        if (!self::valid($relative)) {
            return null;
        }
        $path = \rtrim($this->root, characters: '/').'/'.$relative;
        // The deepest part that exists, resolved: a link in it that points
        // out of the project is as good as `..`. A dangling link counts as
        // existing — skipping past it would let a write create its target,
        // wherever that is — and realpath() refuses it below.
        $existing = $path;
        while (!\file_exists($existing) && !\is_link($existing) && \dirname($existing) !== $existing) {
            $existing = \dirname($existing);
        }
        $real = \realpath($existing);
        $root = \realpath($this->root);

        return false !== $real && false !== $root && ($real === $root || \str_starts_with($real, $root.'/')) ? $path : null;
    }

    /**
     * Every file under $relative (the root itself for ''), by relative path:
     * folders named in $skip are left out wherever they are, and a symlinked
     * folder is not followed — it may lead anywhere.
     *
     * @param list<string> $skip
     *
     * @return list<string>
     */
    public function walk(string $relative, array $skip): array
    {
        $directory = '' === $relative ? $this->root : $this->root.'/'.$relative;
        $entries = \scandir($directory);
        $files = [];
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, $skip, strict: true)) {
                continue;
            }
            $path = '' === $relative ? $entry : $relative.'/'.$entry;
            if (\is_dir($this->root.'/'.$path) && !\is_link($this->root.'/'.$path)) {
                \array_push($files, ...$this->walk($path, $skip));

                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    private static function valid(string $relative): bool
    {
        if ('' === $relative || \str_contains($relative, "\0") || \str_contains($relative, '\\')) {
            return false;
        }
        $segments = \explode('/', $relative);

        // At any depth: a submodule's `.git`, a vendored project's `.porta`.
        return !\array_any($segments, static fn (string $segment): bool => '' === $segment || '.' === $segment || '..' === $segment) && [] === \array_intersect($segments, self::PRIVATE);
    }
}
