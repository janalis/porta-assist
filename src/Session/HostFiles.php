<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Workspace\Paths;
use Porta\AssistPlugin\Workspace\ProjectFiles;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\NonBlockingWriter;
use Revolt\EventLoop;

/**
 * The host's files, as guests reach them outside the shared buffers: the
 * list of the project and the files in it, what a guest writes, moves or
 * deletes — the host does it, through the editor so open buffers follow —
 * and what changed under the editor, sent to everyone once it settles.
 */
final class HostFiles
{
    /** Bytes queued for a guest past which a batch waits for it to catch up. */
    public const int HIGH_WATER = 1_048_576;

    /** Why a guest may not touch a file under the root the listing leaves out. */
    private const string NOT_SHARED = 'not a file the host shares';

    /** The largest file whose encoding fits the queue a connection keeps, with room to spare. */
    private const int LARGEST = ((NonBlockingWriter::DEFAULT_LIMIT - self::HIGH_WATER) / 4) * 3;

    /** @var array<string, array{size: int, hash: string}>|null the project as last listed, null before anyone asked */
    private ?array $listed = null;

    private ?string $rescan = null;

    /** @var array<string, true> files guests wrote this session, fetchable though no listing has them yet */
    private array $written = [];

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
            // A path in digits keys as an int: sent as one, the guest drops it.
            // @mago-expect analysis:redundant-cast -- a key in digits is an int at runtime, whatever its docblock says
            $files[] = [(string) $path, $file['size'], $file['hash']];
        }
        $guest->send(['t' => 'manifest', 'files' => $files]);
    }

    /**
     * The files a guest asked for, as fast as it takes them: a batch is sent
     * a file at a time, and waits whenever what is queued for the guest
     * passes {@see HIGH_WATER} — all at once, a batch of large files would
     * overflow the queue, and cost the guest its connection.
     *
     * @param list<string> $paths
     */
    public function fetch(Guest $guest, array $paths): void
    {
        while (null !== ($relative = \array_shift($paths))) {
            $path = $this->offered($relative) ? $this->paths->absolute($relative) : null;
            $size = null === $path || !\is_file($path) ? null : (int) \filesize($path);
            // A file whose encoding could never be queued at all is sent as
            // not there, not as the end of the connection.
            $content = null === $size || $size > $this->maxFileSize || $size > self::LARGEST ? false : \file_get_contents((string) $path);
            $guest->send(false === $content ? ['t' => 'file', 'path' => $relative, 'missing' => true] : ['t' => 'file', 'path' => $relative, 'data' => \base64_encode($content)]);
            if ($guest->connection->backlog() > self::HIGH_WATER) {
                $guest->connection->afterDrain(fn () => $this->fetch($guest, $paths));

                return;
            }
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
        // A new file, or one the host offered: overwriting an existing file it
        // never listed (a gitignored `.env`) is not a guest's to do blind.
        if (Role::Edit !== $guest->role || null === $path || false === $text || $shared) {
            return;
        }
        if (\file_exists($path) && !$this->offered($relative)) {
            $this->refused($guest, $relative, self::NOT_SHARED);

            return;
        }
        $refused = $this->writeFile($path, $text);
        if (null !== $refused) {
            $guest->send(['t' => 'error', 'path' => $relative, 'message' => $refused]);

            return;
        }
        // Told to the others as changed: theirs to fetch before a rescan lists it.
        $this->written[$relative] = true;
        ($this->broadcast)(['t' => 'changed', 'files' => [[$relative, \strlen($text), \hash('xxh128', $text)]], 'gone' => []], $guest->id);
    }

    public function move(Guest $guest, string $from, string $to): void
    {
        $source = $this->paths->absolute($from);
        $target = $this->paths->absolute($to);
        // Only what was offered: renaming an unlisted `.env` to a listed name
        // would be fetching it with one more step.
        if (Role::Edit !== $guest->role || null === $source || null === $target) {
            return;
        }
        $why = $this->offered($from) ? $this->api->openBuffers()->move($source, $target) : self::NOT_SHARED;
        if (null === $why) {
            $this->written[$to] = true; // the guest's own doing, under its new name
        }
        $this->refused($guest, $from, $why);
    }

    public function delete(Guest $guest, string $relative): void
    {
        $path = $this->paths->absolute($relative);
        if (Role::Edit !== $guest->role || null === $path) {
            return;
        }
        $this->refused($guest, $relative, $this->offered($relative) ? $this->api->openBuffers()->delete($path) : self::NOT_SHARED);
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
        $this->written = [];
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
            // @mago-expect analysis:redundant-cast -- a key in digits is an int at runtime, whatever its docblock says
            $changed[] = [(string) $path, $file['size'], $file['hash']];
        }
        $gone = \array_map(\strval(...), \array_values(\array_diff(\array_keys($before), \array_keys($now))));
        if ([] !== $changed || [] !== $gone) {
            ($this->broadcast)(['t' => 'changed', 'files' => $changed, 'gone' => $gone]);
        }
    }

    /**
     * Whether the host shares $relative: listed (the project as git shows it,
     * plus vendor), or written by a guest this session. A path under the root
     * that the listing leaves out — a gitignored `.env` — is not a guest's to
     * read, move, overwrite or delete.
     */
    private function offered(string $relative): bool
    {
        $this->listed ??= $this->files->scan();

        return \array_key_exists($relative, $this->listed) || \array_key_exists($relative, $this->written);
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
