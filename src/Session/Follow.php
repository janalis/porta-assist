<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\Plugin\Api\PluginApi;

/**
 * Following someone: wherever their caret goes — another file, another line —
 * this side's editor goes too, until the user moves on their own.
 */
final class Follow
{
    /**
     * Who is followed, by peer id and name.
     *
     * @var array{string, string}|null
     */
    public private(set) ?array $target = null;

    /**
     * Where the last jump left the caret: a caret found anywhere else was
     * moved by the user.
     *
     * @var array{string, int, int}|null
     */
    private ?array $expected = null;

    public function __construct(
        private readonly PluginApi $api,
    ) {}

    /** Follow $peer — because $why, when they asked everyone to; else because this side chose to. */
    public function start(string $peer, string $name, ?string $why = null): void
    {
        $this->target = [$peer, $name];
        $this->expected = null;
        $this->api->flash($why ?? $this->api->trans('Following %name%', ['%name%' => $name]));
        $this->api->refreshStatusSegments();
    }

    /** $name asked everyone to follow them: followed, saying so. */
    public function asked(string $peer, string $name): void
    {
        $this->start($peer, $name, $this->api->trans('%name% asked everyone to follow them', ['%name%' => $name]));
    }

    public function stop(): void
    {
        if (null === $this->target) {
            return;
        }
        $this->api->flash($this->api->trans('Stopped following %name%', ['%name%' => $this->target[1]]));
        $this->target = null;
        $this->expected = null;
        $this->api->refreshStatusSegments();
    }

    /** $peer left: following them ends. */
    public function gone(string $peer): void
    {
        if ($peer === ($this->target[0] ?? null)) {
            $this->stop();
        }
    }

    /**
     * $peer's caret moved to $path, at a 0-based line and byte column, with
     * $top the first line they see when they said — followed there, scroll
     * and all, when $peer is the one followed.
     */
    public function moved(string $peer, string $path, int $line, int $column, ?int $top = null): void
    {
        if ($peer !== ($this->target[0] ?? null)) {
            return;
        }
        if ($this->api->activeFile() !== $path) {
            $this->api->openFile($path);
        }
        // The editor is told UTF-16 columns; the wire says bytes.
        $text = \explode("\n", (string) $this->api->openBuffers()->text($path))[$line] ?? '';
        $before = \substr($text, offset: 0, length: $column);
        $utf16 = \intdiv(\strlen((string) \mb_convert_encoding($before, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8')), num2: 2);
        $this->api->jumpTo($line, $utf16, $top);
        $this->expected = [$path, $line, $column];
    }

    /**
     * This side's caret moved: away from where following put it, it was the
     * user — and following stops.
     */
    public function check(?string $path, int $line, int $column): void
    {
        if (null !== $this->expected && [$path, $line, $column] !== $this->expected) {
            $this->stop();
        }
    }
}
