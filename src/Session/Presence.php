<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Workspace\Paths;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\AgentActivityKind;

/**
 * The others, as this side's editor shows them — through core's agent
 * presence: a caret in each one's colour with their name, their selection
 * tinted, a mark on the tabs and tree rows of the files they are in — and
 * where this side's own caret is, for the others.
 */
final class Presence
{
    /** What this plugin's own agent ids start with — so it never takes a peer it shows for an agent at work here. */
    public const string PREFIX = 'assist:';

    /** Never faded by time: a peer is shown until it leaves. */
    private const float FADE = 86_400.0;

    /** @var array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}|null */
    private ?array $mine = null;

    public function __construct(
        private readonly PluginApi $api,
        private readonly Paths $paths,
    ) {}

    /**
     * Show every peer's caret in $text, by their names.
     *
     * @param array<string, string> $names peer => name
     */
    public function show(SharedText $text, array $names): void
    {
        foreach ($text->carets() as $peer => $caret) {
            $this->api->emit(new AgentActivity(
                self::agent($peer),
                $names[$peer] ?? $peer,
                $text->path,
                AgentActivityKind::Editing,
                $caret['line'],
                $caret['column'],
                self::FADE,
                $caret['anchorLine'] ?? -1,
                $caret['anchorColumn'] ?? 0,
            ));
        }
    }

    /** $peer is in a file this side has not shared: on its tab and tree row only. */
    public function inFile(string $peer, string $name, string $relative): void
    {
        $path = $this->paths->absolute($relative);
        if (null !== $path) {
            $this->api->emit(new AgentActivity(self::agent($peer), $name, $path, AgentActivityKind::Reading, -1, 0, self::FADE));
        }
    }

    public function left(string $peer): void
    {
        $this->api->emit(new AgentLeft(self::agent($peer)));
    }

    /**
     * Where this side's caret is, when it moved since the last ask: the file
     * relative to the project, the caret and its selection's anchor. Null
     * when it did not move, or is in no file the session shares.
     *
     * @return array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}|null
     */
    public function moved(): ?array
    {
        $path = $this->api->activeFile();
        $relative = null === $path ? null : $this->paths->relative($path);
        if (null === $path || null === $relative) {
            return null;
        }
        // A file is active, so there is a caret in it.
        $caret = $this->api->caretPosition() ?? ['line' => 0, 'column' => 0, 'top' => 0];
        $selection = $this->api->openBuffers()->selection($path);
        $anchorLine = null;
        $anchorColumn = null;
        if (null !== $selection) {
            // The selection says which end comes first, not where it began:
            // the end the caret is not at is where it was started.
            $atStart = [$selection['line'], $selection['column']] === [$caret['line'], $caret['column']];
            $anchorLine = $atStart ? $selection['endLine'] : $selection['line'];
            $anchorColumn = $atStart ? $selection['endColumn'] : $selection['column'];
        }
        $mine = ['path' => $relative, 'line' => $caret['line'], 'column' => $caret['column'], 'anchorLine' => $anchorLine, 'anchorColumn' => $anchorColumn, 'top' => $caret['top']];
        $moved = $mine !== $this->mine;
        $this->mine = $mine;

        return $moved ? $mine : null;
    }

    /**
     * Where this side's caret was last said to be — for a peer who arrives
     * after it stopped moving.
     *
     * @return array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}|null
     */
    public function last(): ?array
    {
        return $this->mine;
    }

    /**
     * The people among a session's $participants, for core's roster: an
     * agent is not one — it keeps its caret, and core lists who is at work.
     *
     * @param list<array{id: string, name: string, kind: string}> $participants
     *
     * @return array<string, string> id => name
     */
    public static function people(array $participants): array
    {
        $people = [];
        foreach ($participants as $participant) {
            if ('agent' === $participant['kind']) {
                continue;
            }
            $people[$participant['id']] = $participant['name'];
        }

        return $people;
    }

    private static function agent(string $peer): string
    {
        return self::PREFIX.$peer;
    }
}
