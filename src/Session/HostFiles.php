<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Workspace\Paths;
use Porta\AssistPlugin\Workspace\ProjectFiles;
use Porta\Plugin\Api\PluginApi;
use Revolt\EventLoop;

/**
 * The host's files, as guests reach them outside the shared buffers: the
 * list of the project and the files in it, what a guest writes, moves or
 * deletes — the host does it, through the editor so open buffers follow —
 * and what changed under the editor, sent to everyone once it settles.
 */
final class HostFiles
{
    /** @var array<string, array{size: int, hash: string}>|null the project as last listed, null before anyone asked */
    private ?array $listed = null;

    private ?string $rescan = null;

    private readonly ProjectFiles $files;

    /**
     * @param \Closure(array<string, mixed>, ?string=): void $broadcast to every guest, or every one but the one named
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly Paths $paths,
        private readonly int $maxFileSize,
        private readonly \Closure $broadcast,
    ) {
        $this->files = new ProjectFiles($paths->root, $maxFileSize);
    }

    public function manifest(Guest $guest): void
    {
        $this->listed = $this->files->scan();
        $files = [];
        foreach ($this->listed as $path => $file) {
            $files[] = [$path, $file['size'], $file['hash']];
        }
        $guest->send(['t' => 'manifest', 'files' => $files]);
    }

    /** @param list<string> $paths */
    public function fetch(Guest $guest, array $paths): void
    {
        foreach ($paths as $relative) {
            $path = $this->paths->absolute($relative);
            $content = null === $path || !\is_file($path) || (int) \filesize($path) > $this->maxFileSize ? false : \file_get_contents($path);
            $guest->send(false === $content ? ['t' => 'file', 'path' => $relative, 'missing' => true] : ['t' => 'file', 'path' => $relative, 'data' => \base64_encode($content)]);
        }
        $guest->send(['t' => 'fetched']);
    }

    /**
     * A file the guest saved that is not shared — a new one, most often. A
     * shared one is never written this way: its text is the session's.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the file is shared is the session's to know, and the one fact this needs of it
    public function write(Guest $guest, string $relative, string $data, bool $shared): void
    {
        $path = $this->paths->absolute($relative);
        $text = \base64_decode($data, strict: true);
        if (Role::Edit !== $guest->role || null === $path || false === $text || $shared) {
            return;
        }
        $refused = $this->writeFile($path, $text);
        if (null !== $refused) {
            $guest->send(['t' => 'error', 'path' => $relative, 'message' => $refused]);

            return;
        }
        ($this->broadcast)(['t' => 'changed', 'files' => [[$relative, \strlen($text), \hash('xxh128', $text)]], 'gone' => []], $guest->id);
    }

    public function move(Guest $guest, string $from, string $to): void
    {
        $source = $this->paths->absolute($from);
        $target = $this->paths->absolute($to);
        if (Role::Edit !== $guest->role || null === $source || null === $target) {
            return;
        }
        $this->refused($guest, $from, $this->api->openBuffers()->move($source, $target));
    }

    public function delete(Guest $guest, string $relative): void
    {
        $path = $this->paths->absolute($relative);
        if (Role::Edit !== $guest->role || null === $path) {
            return;
        }
        $this->refused($guest, $relative, $this->api->openBuffers()->delete($path));
    }

    /**
     * The working tree changed under the editor — a checkout, a pull: once
     * it settles, the project is listed again and everyone fetches what
     * changed. Nothing to tell before anyone has asked for the list.
     */
    public function treeChanged(): void
    {
        if (null === $this->listed) {
            return;
        }
        if (null !== $this->rescan) {
            EventLoop::cancel($this->rescan);
        }
        $this->rescan = EventLoop::unreference(EventLoop::delay(0.5, $this->rescan(...)));
    }

    public function stop(): void
    {
        if (null !== $this->rescan) {
            EventLoop::cancel($this->rescan);
        }
        $this->rescan = null;
        $this->listed = null;
    }

    private function rescan(): void
    {
        $this->rescan = null;
        $before = $this->listed ?? [];
        $now = $this->files->scan();
        $this->listed = $now;
        $changed = [];
        foreach ($now as $path => $file) {
            if (($before[$path] ?? null) === $file) {
                continue;
            }
            $changed[] = [$path, $file['size'], $file['hash']];
        }
        $gone = \array_values(\array_diff(\array_keys($before), \array_keys($now)));
        if ([] !== $changed || [] !== $gone) {
            ($this->broadcast)(['t' => 'changed', 'files' => $changed, 'gone' => $gone]);
        }
    }

    /** Write a guest's file to disk: null once written, else why not — an open one is the host's to save. */
    private function writeFile(string $path, string $text): ?string
    {
        $buffers = $this->api->openBuffers();
        if (!\is_file($path)) {
            return $buffers->create($path, $text);
        }
        if (null !== $buffers->text($path)) {
            return 'the host has it open';
        }

        return false === \file_put_contents($path, $text) ? 'cannot write it' : null;
    }

    /** What the host could not do, told to the guest who asked — nothing when it was done. */
    public function refused(Guest $guest, string $relative, ?string $why): void
    {
        if (null !== $why) {
            $guest->send(['t' => 'error', 'path' => $relative, 'message' => $why]);
        }
    }
}
