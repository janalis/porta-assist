<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Listener;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Ot\ServerDocument;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Workspace\Paths;
use Porta\Event\AgentActivity;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Revolt\EventLoop;

/**
 * Sharing this project: the host's side of a session.
 *
 * The host's copy is the one that counts. Every change to a shared file —
 * the host's own, read off its buffer, and each guest's — is put in one order
 * here ({@see ServerDocument}) and sent on to everyone else who has the file
 * open. Files, saves and file operations are the host's too: a guest asks,
 * the host does it, and everyone hears.
 *
 * Nobody is in until the host says so: a guest needs the invite's token to
 * be ASKED about, and the host answers each one — edit, read only, or no. A
 * guest let in gets a ticket, which lets it back in without asking again,
 * for as long as this session lasts.
 */
final class HostSession
{
    /** How often the shared buffers are read for local edits. */
    public const float READ_EVERY = 0.05;

    /** How often the host's own caret is looked at. */
    public const float CARET_EVERY = 0.1;

    /** As many guests as the editor has colours to tell apart. */
    private int $maxGuests = 4;

    /** Connections that have not said who they are yet. */
    private int $maxPending = 8;

    /** Seconds a connection has to say who it is. */
    private float $helloTimeout = 10.0;

    /** Seconds the host has to answer a guest who asked in. */
    private float $answerTimeout = 120.0;

    private const string HOST = 'host';

    private readonly Listener $listener;

    private readonly Paths $paths;

    private readonly HostFiles $files;

    private readonly Presence $presence;

    private ?Address $address = null;

    /** The session's TLS identity, while it lasts. */
    private ?Certificate $certificate = null;

    /** @var array<int, Connection> connections not let in yet */
    private array $pending = [];

    /** @var array<string, Guest> by peer id */
    private array $guests = [];

    /** @var array<string, array{name: string, role: Role, id: string}> ticket => who it lets back in */
    private array $tickets = [];

    /** @var array<string, array{text: SharedText, server: ServerDocument}> by relative path */
    private array $docs = [];

    /** @var array<string, array{name: string, owner: string, caret: array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null}}> agents at work, by peer id: the host's own and each guest's */
    private array $agents = [];

    /** @var list<string> */
    private array $timers = [];

    private int $next = 0;

    public function __construct(
        private readonly PluginApi $api,
        private readonly string $name,
        int $maxFileSize,
        public readonly Follow $follow,
    ) {
        $root = $api->projectRoot();
        $this->paths = new Paths($root);
        $this->files = new HostFiles($api, $this->paths, $maxFileSize, $this->broadcast(...));
        $this->presence = new Presence($api, $this->paths);
        $this->listener = new Listener($this->accept(...));
    }

    /**
     * Listen on $bind:$port (0 picks one): the invite to hand out, or why
     * there is none.
     */
    public function start(string $bind, int $port): Address|string
    {
        if (null !== $this->address) {
            return $this->address;
        }
        $certificate = Certificate::create();
        $listening = $this->listener->start($bind, $port, $certificate);
        if (\is_string($listening)) {
            $certificate->forget();

            return $listening;
        }
        $this->certificate = $certificate;
        $host = '0.0.0.0' === $bind ? \gethostbyname((string) \gethostname()) : $bind;
        $this->address = new Address($host, $listening, \bin2hex(\random_bytes(16)), $certificate->fingerprint);
        $this->timers = [
            EventLoop::unreference(EventLoop::repeat(self::READ_EVERY, $this->readAll(...))),
            EventLoop::unreference(EventLoop::repeat(self::CARET_EVERY, $this->caret(...))),
        ];

        return $this->address;
    }

    public function address(): ?Address
    {
        return $this->address;
    }

    /** Everyone is told the session ended and let go; the host's buffers keep what they have. */
    public function stop(): void
    {
        // First: a guest let go now has not LEFT, and is not announced as one.
        $this->address = null;
        foreach ($this->guests as $guest) {
            $guest->connection->close('the session ended', ['t' => 'bye', 'reason' => 'ended']);
        }
        foreach ($this->pending as $connection) {
            $connection->close('the session ended');
        }
        $this->listener->stop();
        $this->certificate?->forget();
        $this->certificate = null;
        $this->files->stop();
        foreach ($this->timers as $timer) {
            EventLoop::cancel($timer);
        }
        $buffers = $this->api->openBuffers();
        foreach ($this->docs as $doc) {
            $buffers->reclaimHistory($doc['text']->path);
        }
        $this->timers = [];
        $this->docs = [];
        $this->tickets = [];
    }

    /** A guest may now edit, or only read along. */
    public function setRole(string $peer, Role $role): void
    {
        $guest = $this->guests[$peer] ?? null;
        if (null === $guest) {
            return;
        }
        $guest->role = $role;
        $this->tickets[$guest->ticket]['role'] = $role;
        $guest->send(['t' => 'role', 'role' => $role->value]);
        $this->broadcast(['t' => 'peer', 'id' => $peer, 'name' => $guest->name, 'role' => $role->value], except: $peer);
        $this->api->refreshStatusSegments();
    }

    /** Send a guest away, without the way back in its ticket gave it. */
    public function remove(string $peer): void
    {
        $guest = $this->guests[$peer] ?? null;
        if (null !== $guest) {
            unset($this->tickets[$guest->ticket]);
            $guest->connection->close('removed by the host', ['t' => 'bye', 'reason' => 'removed']);
        }
    }

    // --- The host's own side --------------------------------------------------

    /** A file the host saved: everyone with it open can mark theirs saved too. */
    public function saved(string $path): void
    {
        $relative = $this->paths->relative($path);
        if (null === $relative) {
            return;
        }
        $this->readAll();
        $text = $this->api->openBuffers()->text($path);
        if (null !== $text) {
            $this->broadcast(['t' => 'saved', 'path' => $relative, 'hash' => \hash('xxh128', $text)]);
        }
    }

    /** A file moved on the host: everyone moves theirs, and opens it again under its new name. */
    public function moved(string $from, string $to): void
    {
        $old = $this->paths->relative($from);
        $new = $this->paths->relative($to);
        if (null === $old) {
            return;
        }
        $this->forget($old, $to);
        $this->broadcast(null === $new ? ['t' => 'deleted', 'path' => $old] : ['t' => 'moved', 'from' => $old, 'to' => $new]);
    }

    public function deleted(string $path): void
    {
        $relative = $this->paths->relative($path);
        if (null === $relative) {
            return;
        }
        $this->forget($relative, $path);
        $this->broadcast(['t' => 'deleted', 'path' => $relative]);
    }

    /** The working tree changed under the editor: see {@see HostFiles::treeChanged()}. */
    public function treeChanged(): void
    {
        $this->files->treeChanged();
    }

    /** Read every shared buffer for what the host typed, and send it on. */
    public function readAll(): void
    {
        foreach (\array_keys($this->docs) as $relative) {
            $this->read($relative);
        }
    }

    private function read(string $relative): void
    {
        $doc = $this->docs[$relative];
        $operation = $doc['text']->read();
        if (null !== $operation) {
            $this->publish($relative, $doc['server']->receive($doc['server']->revision(), $operation));
        }
    }

    /** A change of the host's, in the log: to everyone with the file open. */
    private function publish(string $relative, TextOperation $operation, ?string $author = null): void
    {
        foreach ($this->guests as $guest) {
            if ($guest->id === $author || !\array_key_exists($relative, $guest->docs)) {
                continue;
            }
            $guest->send(['t' => 'op', 'path' => $relative, 'ops' => $operation->toWire()]);
        }
        $this->presence->show($this->docs[$relative]['text'], $this->names());
    }

    private function caret(): void
    {
        $mine = $this->presence->moved();
        if (null === $mine) {
            return;
        }
        $this->follow->check($this->paths->absolute($mine['path']), $mine['line'], $mine['column']);
        $this->broadcast(['t' => 'presence', 'peer' => self::HOST, 'name' => $this->name, ...$mine]);
    }

    // --- Letting guests in ----------------------------------------------------

    private function accept(Connection $connection): void
    {
        if (\count($this->pending) >= $this->maxPending) {
            $connection->close('too many waiting', ['t' => 'denied', 'reason' => 'busy']);

            return;
        }
        $id = $this->next++;
        $this->pending[$id] = $connection;
        $timeout = EventLoop::unreference(EventLoop::delay($this->helloTimeout, static fn () => $connection->close('said nothing')));
        $connection->listen(
            function (array $message) use ($id, $connection, $timeout): void {
                EventLoop::cancel($timeout);
                $connection->listen(static function (array $message): void {}, fn () => $this->unpend($id));
                $this->hello($id, $connection, $message);
            },
            function () use ($id, $timeout): void {
                EventLoop::cancel($timeout);
                $this->unpend($id);
            },
        );
    }

    private function unpend(int $id): void
    {
        unset($this->pending[$id]);
    }

    /** @param array<string, mixed> $message the connection's first */
    private function hello(int $id, Connection $connection, array $message): void
    {
        $name = \mb_substr(\trim(Wire::string($message, 'name')), start: 0, length: 40);
        $ticket = $this->tickets[Wire::string($message, 'ticket')] ?? null;
        $token = Wire::string($message, 'token');
        $refusal = match (true) {
            'hello' !== $message['t'] || Wire::VERSION !== Wire::int($message, 'v') => 'version',
            null === $ticket && !\hash_equals((string) $this->address?->token, $token) => 'token',
            '' === $name => 'name',
            null === $ticket && \count($this->guests) >= $this->maxGuests => 'full',
            default => null,
        };
        if (null !== $refusal) {
            $connection->close('refused: '.$refusal, ['t' => 'denied', 'reason' => $refusal]);

            return;
        }
        if (null !== $ticket) {
            $this->admit($id, $connection, $ticket['id'], $ticket['name'], $ticket['role'], Wire::string($message, 'ticket'));

            return;
        }
        $connection->send(['t' => 'pending']);
        $answered = false;
        $timeout = EventLoop::unreference(EventLoop::delay($this->answerTimeout, static function () use ($connection, &$answered): void {
            $answered = true;
            $connection->close('not answered', ['t' => 'denied', 'reason' => 'unanswered']);
        }));
        $this->api->pick(
            $this->api->trans('%name% asks to join, from %from%', ['%name%' => $name, '%from%' => $connection->peer()]),
            [
                Role::Edit->value => $this->api->trans('Let them edit'),
                Role::ReadOnly->value => $this->api->trans('Let them read along'),
                'deny' => $this->api->trans('Turn them away'),
            ],
            function (string $answer) use ($id, $connection, $name, $timeout, &$answered): void {
                EventLoop::cancel($timeout);
                $role = Role::tryFrom($answer);
                if ($answered || $connection->isClosed()) {
                    return;
                }
                $answered = true;
                if (null === $role) {
                    $connection->close('turned away', ['t' => 'denied', 'reason' => 'host']);

                    return;
                }
                $this->admit($id, $connection, 'g'.$id, $name, $role, \bin2hex(\random_bytes(16)));
            },
        );
    }

    private function admit(int $id, Connection $connection, string $peer, string $name, Role $role, string $ticket): void
    {
        unset($this->pending[$id]);
        // The same guest back on a new connection: the old one is over —
        // replaced first, so its closing is not taken for the guest leaving.
        $previous = $this->guests[$peer] ?? null;
        $guest = new Guest($peer, $name, $role, $ticket, $connection);
        $this->guests[$peer] = $guest;
        $previous?->connection->close('replaced');
        $this->tickets[$ticket] = ['name' => $name, 'role' => $role, 'id' => $peer];
        $connection->listen(fn (array $message) => $this->receive($guest, $message), fn () => $this->gone($guest));
        $guest->send([
            't' => 'welcome',
            'peer' => $peer,
            'role' => $role->value,
            'ticket' => $ticket,
            'project' => ['id' => \substr(\hash('xxh128', (string) \gethostname().$this->paths->root), offset: 0, length: 16), 'name' => \basename($this->paths->root)],
            'peers' => [
                ['id' => self::HOST, 'name' => $this->name, 'role' => 'host'],
                ...\array_map(static fn (Guest $other): array => [
                    'id' => $other->id,
                    'name' => $other->name,
                    'role' => $other->role->value,
                ], \array_values(\array_filter($this->guests, static fn (Guest $other): bool => $other !== $guest))),
            ],
        ]);
        // Where everyone already is, so the newcomer sees them before they move.
        $mine = $this->presence->last();
        if (null !== $mine) {
            $guest->send(['t' => 'presence', 'peer' => self::HOST, 'name' => $this->name, ...$mine]);
        }
        foreach ($this->guests as $other) {
            if ($other === $guest || null === $other->caret) {
                continue;
            }
            $guest->send(['t' => 'presence', 'peer' => $other->id, 'name' => $other->name, ...$other->caret]);
        }
        foreach ($this->agents as $id => $agent) {
            if ($peer === $agent['owner']) {
                continue;
            }
            $guest->send(['t' => 'presence', 'peer' => $id, 'name' => $agent['name'], 'agent' => true, ...$agent['caret']]);
        }
        $this->broadcast(['t' => 'peer', 'id' => $peer, 'name' => $name, 'role' => $role->value], except: $peer);
        $this->api->notify(
            Role::Edit === $role
                ? $this->api->trans('%name% joined and can edit', ['%name%' => $name])
                : $this->api->trans('%name% joined, reading along', ['%name%' => $name]),
            ToastSeverity::Success,
            slot: 'assist',
        );
        $this->api->refreshStatusSegments();
    }

    private function gone(Guest $guest): void
    {
        if (($this->guests[$guest->id] ?? null) !== $guest) {
            return;
        }
        unset($this->guests[$guest->id]);
        foreach ($this->docs as $doc) {
            $doc['text']->dropCaret($guest->id);
        }
        $owned = \array_filter($this->agents, static fn (array $agent): bool => $guest->id === $agent['owner']);
        foreach (\array_keys($owned) as $peer) {
            $this->dropAgent($peer);
        }
        $this->presence->left($guest->id);
        $this->follow->gone($guest->id);
        $this->broadcast(['t' => 'left', 'peer' => $guest->id]);
        if (null !== $this->address) {
            $this->api->notify($this->api->trans('%name% left', ['%name%' => $guest->name]), slot: 'assist');
        }
        $this->api->refreshStatusSegments();
    }

    // --- What guests ask ------------------------------------------------------

    /** @param array<string, mixed> $message */
    private function receive(Guest $guest, array $message): void
    {
        match ($message['t']) {
            'manifest' => $this->files->manifest($guest),
            'fetch' => $this->files->fetch($guest, Wire::strings($message, 'paths')),
            'open' => $this->open($guest, Wire::string($message, 'path')),
            'close' => $this->close($guest, Wire::string($message, 'path')),
            'op' => $this->operation($guest, $message),
            'presence' => $this->guestCaret($guest, $message),
            'agent' => $this->guestAgent($guest, $message),
            'agentLeft' => $this->dropAgent($guest->id.'~'.Wire::string($message, 'id')),
            'save' => $this->save($guest, Wire::string($message, 'path')),
            'write' => $this->files->write($guest, Wire::string($message, 'path'), Wire::string($message, 'data'), \array_key_exists(Wire::string($message, 'path'), $this->docs)),
            'move' => $this->files->move($guest, Wire::string($message, 'from'), Wire::string($message, 'to')),
            'delete' => $this->files->delete($guest, Wire::string($message, 'path')),
            'bye' => $guest->connection->close('left'),
            default => null,
        };
    }

    private function open(Guest $guest, string $relative): void
    {
        $doc = $this->doc($relative);
        if (null === $doc) {
            $guest->send(['t' => 'error', 'path' => $relative, 'message' => 'not a file the host can share']);

            return;
        }
        $this->read($relative);
        $guest->docs[$relative] = true;
        $guest->send(['t' => 'opened', 'path' => $relative, 'rev' => $doc['server']->revision(), 'text' => $doc['server']->text]);
        // The others already in it, where they are now.
        foreach ($doc['text']->carets() as $peer => $caret) {
            if ($peer === $guest->id) {
                continue;
            }
            $guest->send(['t' => 'presence', 'peer' => $peer, 'name' => $this->names()[$peer] ?? $peer, 'path' => $relative, ...$caret]);
        }
    }

    private function close(Guest $guest, string $relative): void
    {
        unset($guest->docs[$relative]);
    }

    /**
     * The shared state of a file, opened on the host if it is not — in a tab
     * behind the one in front, which is where a guest's work shows.
     *
     * @return array{text: SharedText, server: ServerDocument}|null
     */
    private function doc(string $relative): ?array
    {
        $path = $this->paths->absolute($relative);
        if (null === $path || !\is_file($path)) {
            return null;
        }
        $buffers = $this->api->openBuffers();
        $doc = $this->docs[$relative] ?? null;
        if (null !== $doc) {
            if (!$doc['text']->isOpen() && null === $buffers->open($path)) {
                $doc['text']->restore();
                $this->delegate($relative, $path);
            }

            return $doc;
        }
        $opened = null === $buffers->open($path) ? $buffers->text($path) : null;
        if (null === $opened) {
            return null;
        }
        $text = SharedText::of($buffers, $path, $opened);
        $this->docs[$relative] = ['text' => $text, 'server' => new ServerDocument($text->shadow)];
        $this->delegate($relative, $path);

        return $this->docs[$relative];
    }

    /** The host's Ctrl+Z in a shared file takes back the host's own changes only. */
    private function delegate(string $relative, string $path): void
    {
        $this->api->openBuffers()->delegateHistory($path, fn (): bool => $this->replay($relative, undo: true), fn (): bool => $this->replay($relative, undo: false));
    }

    // @mago-expect lint:no-boolean-flag-parameter -- undo or redo is the one fact the call carries; both are the same replay
    private function replay(string $relative, bool $undo): bool
    {
        // Only a shared file's history is handed here, and taken back when it
        // stops being one.
        $doc = $this->docs[$relative];
        $operation = $undo ? $doc['text']->undo() : $doc['text']->redo();
        if (null === $operation) {
            return false;
        }
        $this->publish($relative, $doc['server']->receive($doc['server']->revision(), $operation));

        return true;
    }

    /** @param array<string, mixed> $message */
    private function operation(Guest $guest, array $message): void
    {
        $relative = Wire::string($message, 'path');
        $doc = Role::Edit === $guest->role && \array_key_exists($relative, $guest->docs) ? $this->doc($relative) : null;
        if (null === $doc) {
            // Out of step — a role taken away mid-change, a file gone: the
            // guest starts that file over from the host's copy.
            $guest->send(['t' => 'reopen', 'path' => $relative]);

            return;
        }
        $this->read($relative);
        try {
            $operation = $doc['server']->receive(Wire::int($message, 'rev', -1), TextOperation::fromWire($message['ops'] ?? null));
        } catch (\InvalidArgumentException) {
            $guest->send(['t' => 'reopen', 'path' => $relative]);

            return;
        }
        $doc['text']->apply($operation);
        $guest->send(['t' => 'ack', 'path' => $relative]);
        $this->publish($relative, $operation, author: $guest->id);
    }

    /** @param array<string, mixed> $message */
    private function guestCaret(Guest $guest, array $message): void
    {
        $caret = Wire::caret($message);
        $guest->caret = $caret;
        $this->place($guest->id, $guest->name, $caret);
        $this->broadcast(['t' => 'presence', 'peer' => $guest->id, 'name' => $guest->name, ...$caret], except: $guest->id);
    }

    /**
     * Someone else's caret, shown here: in the text when the file is shared,
     * on the file's tab and tree row when it is not — and followed, when
     * they are the one followed.
     *
     * @param array{path: string, line: int, column: int, anchorLine: int|null, anchorColumn: int|null, top: int|null} $caret
     */
    private function place(string $peer, string $name, array $caret): void
    {
        $doc = ($this->docs[$caret['path']] ?? null)['text'] ?? null;
        $path = $this->paths->absolute($caret['path']);
        // No line is no caret: in the file, nowhere in its text.
        $caret['line'] < 0 ? $doc?->dropCaret($peer) : $doc?->placeCaret($peer, $caret['line'], $caret['column'], $caret['anchorLine'], $caret['anchorColumn']);
        null === $doc || $caret['line'] < 0 ? $this->presence->inFile($peer, $name, $caret['path']) : $this->presence->show($doc, $this->names());
        if (null !== $path) {
            $this->follow->moved($peer, $path, \max(0, $caret['line']), $caret['column'], $caret['top']);
        }
    }

    // --- Agents at work ---------------------------------------------------------

    /**
     * An agent at work here — an MCP client, say — is a participant too: the
     * guests see its caret and its name, and may follow it.
     */
    public function agentActivity(AgentActivity $event): void
    {
        $relative = $this->paths->relative($event->path);
        if (null === $relative || \str_starts_with($event->agent, Presence::PREFIX)) {
            return;
        }
        $peer = self::HOST.'~'.$event->agent;
        $caret = ['path' => $relative, 'line' => $event->line, 'column' => $event->column, 'anchorLine' => null, 'anchorColumn' => null, 'top' => null];
        $this->agents[$peer] = ['name' => $event->name, 'owner' => self::HOST, 'caret' => $caret];
        if ($event->line >= 0) {
            $this->follow->moved($peer, $event->path, $event->line, $event->column);
        }
        $this->broadcast(['t' => 'presence', 'peer' => $peer, 'name' => $event->name, 'agent' => true, ...$caret]);
        $this->api->refreshStatusSegments();
    }

    public function agentLeft(string $agent): void
    {
        $this->dropAgent(self::HOST.'~'.$agent);
    }

    /** @param array<string, mixed> $message */
    private function guestAgent(Guest $guest, array $message): void
    {
        $peer = $guest->id.'~'.Wire::string($message, 'id');
        $name = Wire::string($message, 'name');
        $caret = Wire::caret($message);
        $this->agents[$peer] = ['name' => $name, 'owner' => $guest->id, 'caret' => $caret];
        $this->place($peer, $name, $caret);
        $this->broadcast(['t' => 'presence', 'peer' => $peer, 'name' => $name, 'agent' => true, ...$caret], except: $guest->id);
        $this->api->refreshStatusSegments();
    }

    private function dropAgent(string $peer): void
    {
        if (!\array_key_exists($peer, $this->agents)) {
            return;
        }
        unset($this->agents[$peer]);
        foreach ($this->docs as $doc) {
            $doc['text']->dropCaret($peer);
        }
        $this->presence->left($peer);
        $this->follow->gone($peer);
        $this->broadcast(['t' => 'left', 'peer' => $peer]);
        $this->api->refreshStatusSegments();
    }

    /**
     * Everyone in the session but the host: each guest with its right, and
     * every agent at work — the host's own, and the guests'.
     *
     * @return list<array{id: string, name: string, kind: string}> kind: edit, read or agent
     */
    public function participants(): array
    {
        $participants = [];
        foreach ($this->guests as $guest) {
            $participants[] = ['id' => $guest->id, 'name' => $guest->name, 'kind' => $guest->role->value];
        }
        foreach ($this->agents as $peer => $agent) {
            $participants[] = ['id' => $peer, 'name' => $agent['name'], 'kind' => 'agent'];
        }

        return $participants;
    }

    private function save(Guest $guest, string $relative): void
    {
        $doc = Role::Edit === $guest->role ? $this->doc($relative) : null;
        if (null === $doc) {
            return;
        }
        $this->read($relative);
        $this->files->refused($guest, $relative, $this->api->openBuffers()->save($doc['text']->path));
    }

    /** A shared file renamed or deleted: its session state goes; whoever needs it opens it again. */
    private function forget(string $relative, string $path): void
    {
        if (!\array_key_exists($relative, $this->docs)) {
            return;
        }
        unset($this->docs[$relative]);
        $this->api->openBuffers()->reclaimHistory($path);
        foreach ($this->guests as $guest) {
            unset($guest->docs[$relative]);
        }
    }

    // --- Helpers ---------------------------------------------------------------

    /** @param array<string, mixed> $message */
    private function broadcast(array $message, ?string $except = null): void
    {
        foreach ($this->guests as $guest) {
            if ($guest->id === $except) {
                continue;
            }
            $guest->send($message);
        }
    }

    /** @return array<string, string> peer => name */
    private function names(): array
    {
        return [...\array_map(static fn (Guest $guest): string => $guest->name, $this->guests), ...\array_map(static fn (array $agent): string => $agent['name'], $this->agents)];
    }
}
