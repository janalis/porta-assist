<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

/**
 * The session's messages on the wire: one JSON object per line, each with a
 * type under `t`. Reading one hands back the object or nothing — a line that
 * is not one is a peer's mistake, never this side's crash.
 */
final class Wire
{
    /** The protocol a peer must speak to be let in. */
    public const int VERSION = 2;

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        return \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    /** @return array<string, mixed>|null the message, or null when the line is not one */
    public static function decode(string $line): ?array
    {
        try {
            // @mago-expect analysis:mixed-assignment -- narrowed below
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($message) || !\is_string($message['t'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $message */
        return $message;
    }

    /** @param array<array-key, mixed> $message */
    public static function string(array $message, string $key): string
    {
        return \is_string($message[$key] ?? null) ? $message[$key] : '';
    }

    /**
     * A string the other end sent to be SHOWN — a name, a title, a message:
     * without control or format characters, so it cannot reach the terminal
     * as an escape sequence or turn the text around it, and no longer than
     * $max characters.
     *
     * @param array<array-key, mixed> $message
     */
    public static function text(array $message, string $key, int $max = 200): string
    {
        return self::clean(self::string($message, $key), $max);
    }

    /** {@see text()}, for a string already in hand. */
    public static function clean(string $text, int $max = 200): string
    {
        return \mb_substr(\trim((string) \preg_replace('/[\p{Cc}\p{Cf}]+/u', replacement: '', subject: $text)), start: 0, length: $max);
    }

    /** @param array<array-key, mixed> $message */
    public static function int(array $message, string $key, int $default = 0): int
    {
        return \is_int($message[$key] ?? null) ? $message[$key] : $default;
    }

    /**
     * Where someone's caret is, as a presence message says it: the file
     * relative to the project, a 0-based line (negative: in the file, no
     * caret) and byte column, the anchor of their selection, if any, and
     * the first line they see, when they said.
     *
     * @param array<string, mixed> $message
     *
     * @return array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}
     */
    public static function caret(array $message): array
    {
        $anchored = \is_int($message['anchorLine'] ?? null) && $message['anchorLine'] >= 0;

        return [
            'path' => self::string($message, 'path'),
            'line' => self::int($message, 'line'),
            'column' => self::int($message, 'column'),
            'anchorLine' => $anchored ? self::int($message, 'anchorLine') : null,
            'anchorColumn' => $anchored ? self::int($message, 'anchorColumn') : null,
            'top' => \is_int($message['top'] ?? null) ? $message['top'] : null,
        ];
    }

    /**
     * The strings of a list — paths, most often — anything else left out.
     *
     * @param array<string, mixed> $message
     *
     * @return list<string>
     */
    public static function strings(array $message, string $key): array
    {
        $strings = [];
        // @mago-expect analysis:mixed-assignment -- narrowed below
        foreach (self::list($message, $key) as $value) {
            if (!\is_string($value)) {
                continue;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * A list of files as the host sends them — `[path, size, digest]` each —
     * keyed by path; an entry of any other shape is left out. A path in
     * digits keys as an int: whoever reads the keys casts them back.
     *
     * @param array<string, mixed> $message
     *
     * @return array<array-key, array{size: int, hash: string}>
     */
    public static function files(array $message, string $key): array
    {
        $files = [];
        // @mago-expect analysis:mixed-assignment -- narrowed below
        foreach (self::list($message, $key) as $file) {
            if (!\is_array($file) || !\is_string($file[0] ?? null) || !\is_int($file[1] ?? null) || !\is_string($file[2] ?? null)) {
                continue;
            }
            $files[$file[0]] = ['size' => $file[1], 'hash' => $file[2]];
        }

        return $files;
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return list<mixed>
     */
    public static function list(array $message, string $key): array
    {
        return \is_array($message[$key] ?? null) && \array_is_list($message[$key]) ? $message[$key] : [];
    }
}
