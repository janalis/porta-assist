<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Tls;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Ot\ClientDocument;
use Porta\AssistPlugin\Ot\TextDiff;
use Porta\AssistPlugin\Ot\TextOperation;
use Porta\AssistPlugin\Workspace\Mirror;
use Porta\Event\AgentActivity;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Backoff;
use Revolt\EventLoop;

/**
 * Being in someone else's session: the guest's side.
 *
 * The IDE is open on the guest's copy of the host's project
 * ({@see Mirror}), which `porta assist join` filled. Every file the guest
 * opens is asked of the host and replaced by the host's text, unsaved edits
 * included; from then on, what the guest types goes to the host one change at
 * a time ({@see ClientDocument}), and what the others type comes back.
 *
 * Saves and file operations are asked of the host, which does them and tells
 * everyone; the copy follows what the host says. A dropped connection makes
 * the shared files read-only and tries again, with the ticket the host gave,
 * until it is back or the guest leaves.
 */
final class GuestSession
{
    /** A host's terminal, as it is named here. */
    private const string TERMINAL = 'assist:';

    /** The mark on a tab that shows the host's terminal. */
    private const string GLYPH = '⇄';

    /** Where the IDE in a guest's copy finds how to reach the host. */
    public const string FILE = '.porta/assist-session.json';

    /**
     * The next channel a connection to the host takes — shared by the IDE
     * and every `porta assist-lsp` it starts, so each takes its own.
     */
    public const string CHANNEL = '.porta/assist-channel';

    /** @var array<string, array{text: SharedText, client: ClientDocument}> by relative path */
    private array $docs = [];

    /** @var array<string, true> files asked for, not answered yet */
    private array $opening = [];

    /** What was typed and never acknowledged when the connection dropped: carried onto the host's copy when it is shared again. */
    private readonly CarriedEdits $carried;

    /** Why the host was last lost, while it is: said once, not at every try. */
    private ?string $lostFor = null;

    /** @var array<string, string> peer => name */
    private array $peers = [];

    /** @var array<string, string> peer => host, edit, read or agent */
    private array $kinds = [];

    /** @var array<string, string> the agents at work HERE, by their own id => name */
    private array $agents = [];

    /** @var array<string, string> the host's terminals shown here, by the host's id => title */
    private array $terminals = [];

    /** Whether the host lets this guest type into its terminals. */
    private bool $typing = false;

    private ?Connection $connection = null;

    /** The host's run configurations, started from here. */
    public readonly GuestRuns $runs;

    /** The host's debugger, watched and stepped from here. */
    public readonly GuestDebug $debug;

    /** The host's servers, reached through the connection there is now. */
    private ?GuestServers $servers = null;

    private ?Role $role = null;

    private ?string $hostName = null;

    private int $tries = 0;

    /** @var list<string> */
    private array $timers = [];

    private ?string $retry = null;

    private bool $over = false;

    /** The channel the connection being made is on — kept for the next try until the relay joins it. */
    private ?int $channel = null;

    /** Set while the host's word is being carried out here, so it is not sent back as the guest's. */
    private bool $applying = false;

    private readonly Presence $presence;

    /**
     * @param array{invite: Invite, ticket: string, name: string, languages: list<string>} $session
     *
     * @throws \RuntimeException when the folder it goes in cannot be made
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly Mirror $mirror,
        private readonly array $session,
        public readonly Follow $follow,
        private readonly float $timeout = 10.0,
    ) {
        $this->presence = new Presence($api, $mirror->paths);
        $this->carried = new CarriedEdits();
        $this->runs = new GuestRuns($api, $this->send(...));
        $this->debug = new GuestDebug($api, $mirror->paths, $this->send(...));
    }

    /**
     * The session the IDE was opened for, when it was opened on a guest's
     * copy — read from {@see FILE} under $root — or null.
     *
     * @return array{invite: Invite, ticket: string, name: string, languages: list<string>}|null
     */
    public static function load(string $root): ?array
    {
        $file = $root.'/'.self::FILE;
        // @mago-expect analysis:mixed-assignment -- narrowed below
        // @noinspection JsonEncodingApiUsageInspection -- a file that is not JSON (hand-edited, half-written) reads as none, it does not throw
        $json = \is_file($file) ? \json_decode((string) \file_get_contents($file), associative: true) : null;
        $invite = \is_array($json) ? Invite::fromArray($json) : null;
        if (null === $invite || !\is_string($json['ticket'] ?? null) || !\is_string($json['name'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $json */
        return [
            'invite' => $invite,
            'ticket' => $json['ticket'],
            'name' => $json['name'],
            'languages' => Wire::strings($json, 'languages'),
        ];
    }

    /**
     * Write the session for the IDE about to open $root — readable by this
     * user only: the ticket lets its holder in — the next connection to the
     * host to take channel $channel.
     *
     * @param array{invite: Invite, ticket: string, name: string, languages: list<string>} $session
     */
    public static function store(string $root, array $session, int $channel = 0): void
    {
        $file = $root.'/'.self::FILE;
        $dir = \dirname($file);
        // @mago-expect lint:no-error-control-operator -- a folder that cannot be made is reported by the exception, not a warning
        if (!\is_dir($dir) && !@\mkdir($dir, permissions: 0o700, recursive: true) && !\is_dir($dir)) {
            throw new \RuntimeException('cannot create '.$dir);
        }
        \touch($file);
        \chmod($file, permissions: 0o600);
        $fields = [...$session['invite']->toArray(), 'ticket' => $session['ticket'], 'name' => $session['name'], 'languages' => $session['languages']];
        \file_put_contents($file, \json_encode($fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        \file_put_contents($root.'/'.self::CHANNEL, (string) $channel);
    }

    /** The next channel to the host, taken: no other process in this copy takes it too. */
    public static function channel(string $root): int
    {
        $handle = \fopen($root.'/'.self::CHANNEL, mode: 'cb+');
        \assert(false !== $handle, description: 'the copy\'s .porta/ is there: the session file is in it');
        \flock($handle, \LOCK_EX);
        $channel = (int) \stream_get_contents($handle);
        \ftruncate($handle, size: 0);
        \rewind($handle);
        \fwrite($handle, (string) ($channel + 1));
        \fclose($handle);

        return $channel;
    }

    public function connect(): void
    {
        EventLoop::queue($this->dial(...));
        if ([] === $this->timers) {
            $this->timers = [
                EventLoop::unreference(EventLoop::repeat(HostSession::READ_EVERY, $this->readAll(...))),
                EventLoop::unreference(EventLoop::repeat(HostSession::CARET_EVERY, $this->caret(...))),
            ];
        }
    }

    /** Reach the host through the relay, on a channel of this copy's own, and ask back in with the ticket. */
    private function dial(): void
    {
        $invite = $this->session['invite'];
        // The same channel again until the relay joins it: the host waits on
        // only a few past the last one joined.
        $this->channel ??= self::channel($this->mirror->root);
        $stream = GuestRendezvous::pair($invite, $this->channel, $this->timeout);
        if (!\is_string($stream)) {
            $this->channel = null;
            $stream = GuestRendezvous::secure($invite, $stream, $this->timeout);
        }
        if ($this->over) {
            if (!\is_string($stream)) {
                \fclose($stream);
            }

            return;
        }
        if (\is_string($stream)) {
            $this->lost($stream);

            return;
        }
        $connection = new Connection($stream);
        $connection->keepAlive();
        $this->connection = $connection;
        $this->servers = new GuestServers($this->api, $connection, fn (): string => (string) $this->hostName);
        $connection->listen($this->receive(...), $this->lost(...));
        $connection->send(['t' => 'hello', 'v' => Wire::VERSION, 'name' => $this->session['name'], 'ticket' => $this->session['ticket']]);
    }

    /** Whether the host is there now. */
    public function isConnected(): bool
    {
        return null !== $this->role && null !== $this->connection && !$this->connection->isClosed();
    }

    public function role(): ?Role
    {
        return $this->role;
    }

    /** Everyone follows this guest now — and is shown where it is, at once. */
    public function focus(): void
    {
        $this->send(['t' => 'focus']);
        $mine = $this->presence->last();
        if (null !== $mine) {
            $this->send(['t' => 'presence', ...$mine]);
        }
    }

    /** @return array<int, int> the host's shared servers: its port => the port they are reached at here */
    public function servers(): array
    {
        return $this->servers?->shared() ?? [];
    }

    public function hostName(): ?string
    {
        return $this->hostName;
    }

    /** Leave for good: the host is told, the files are the guest's own again, and the copy forgets the session. */
    public function leave(): void
    {
        $this->over = true;
        // For good: the host forgets the ticket, and stops waiting for it.
        $this->connection?->close('left', ['t' => 'bye', 'for' => 'good']);
        $this->end();
        $file = $this->mirror->root.'/'.self::FILE;
        if (\is_file($file)) {
            \unlink($file);
        }
    }

    /** The IDE is closing: the host is told, and the session stays — opening this copy again rejoins. */
    public function disconnect(): void
    {
        $this->over = true;
        $this->connection?->close('closed', ['t' => 'bye']);
        $this->end();
    }

    // --- The guest's own side -------------------------------------------------

    /** A file the guest opened: asked of the host, when the session shares it. */
    public function opened(string $path): void
    {
        $relative = $this->mirror->paths->relative($path);
        if (null !== $relative && $this->isConnected()) {
            $this->open($relative);
        }
    }

    /** A save here is the host's to make — or, for a file the session does not share, the content goes to it. */
    public function saved(string $path): void
    {
        $relative = $this->mirror->paths->relative($path);
        if ($this->applying || null === $relative || !$this->isConnected()) {
            return;
        }
        if (\array_key_exists($relative, $this->docs)) {
            $this->read($relative);
            $this->send(['t' => 'save', 'path' => $relative]);

            return;
        }
        if (Role::Edit === $this->role && \is_file($path)) {
            $this->send(['t' => 'write', 'path' => $relative, 'data' => \base64_encode((string) \file_get_contents($path))]);
        }
    }

    public function moved(string $from, string $to): void
    {
        $old = $this->mirror->paths->relative($from);
        $new = $this->mirror->paths->relative($to);
        if ($this->applying || null === $old || null === $new || !$this->isConnected()) {
            return;
        }
        $this->send(['t' => 'move', 'from' => $old, 'to' => $new]);
        $this->forget($old, $to);
        $this->open($new);
    }

    public function deleted(string $path): void
    {
        $relative = $this->mirror->paths->relative($path);
        if ($this->applying || null === $relative || !$this->isConnected()) {
            return;
        }
        $this->send(['t' => 'delete', 'path' => $relative]);
        $this->forget($relative, $path);
    }

    /** Read every shared buffer for what the guest typed, and send it on. */
    public function readAll(): void
    {
        foreach (\array_keys($this->docs) as $relative) {
            // @mago-expect analysis:redundant-cast -- a key in digits is an int at runtime, whatever its docblock says
            $this->read((string) $relative); // a path in digits keys as an int
        }
    }

    private function read(string $relative): void
    {
        $doc = $this->docs[$relative];
        if (Role::Edit !== $this->role || !$this->isConnected()) {
            // Nothing typed here may stand: it would never reach the host.
            $doc['text']->revert();

            return;
        }
        $operation = $doc['text']->read();
        if (null !== $operation) {
            $this->local($relative, $operation);
        }
    }

    private function local(string $relative, TextOperation $operation): void
    {
        $doc = $this->docs[$relative];
        $send = $doc['client']->local($operation);
        if (null !== $send) {
            $this->send(['t' => 'op', 'path' => $relative, 'rev' => $doc['client']->revision, 'ops' => $send->toWire()]);
        }
        $this->presence->show($doc['text'], $this->peers);
    }

    // @mago-expect lint:no-boolean-flag-parameter -- undo or redo is the one fact the call carries; both are the same replay
    private function replay(string $relative, bool $undo): bool
    {
        // Only a shared file's history is handed here, and taken back when it
        // stops being one.
        $doc = $this->docs[$relative];
        if (Role::Edit !== $this->role || !$this->isConnected()) {
            return false;
        }
        $operation = $undo ? $doc['text']->undo() : $doc['text']->redo();
        if (null === $operation) {
            return false;
        }
        $this->local($relative, $operation);

        return true;
    }

    private function caret(): void
    {
        $mine = $this->presence->moved();
        if (null === $mine) {
            return;
        }
        $this->follow->check($this->mirror->paths->absolute($mine['path']), $mine['line'], $mine['column']);
        $this->send(['t' => 'presence', ...$mine]);
    }

    /**
     * An agent at work here — an MCP client, say — is a participant too: the
     * host and the other guests see its caret and its name.
     */
    public function agentActivity(AgentActivity $event): void
    {
        $relative = $this->mirror->paths->relative($event->path);
        if (null === $relative || \str_starts_with($event->agent, Presence::PREFIX)) {
            return;
        }
        $this->agents[$event->agent] = $event->name;
        $this->send(['t' => 'agent', 'id' => $event->agent, 'name' => $event->name, 'path' => $relative, 'line' => $event->line, 'column' => $event->column]);
        $this->api->refreshStatusSegments();
    }

    public function agentLeft(string $agent): void
    {
        if (!\array_key_exists($agent, $this->agents)) {
            return;
        }
        unset($this->agents[$agent]);
        $this->send(['t' => 'agentLeft', 'id' => $agent]);
        $this->api->refreshStatusSegments();
    }

    /**
     * Everyone in the session but this guest: the host, the other guests
     * with their right, and every agent at work — the others', and this
     * side's own.
     *
     * @return list<array{id: string, name: string, kind: string}> kind: host, edit, read or agent
     */
    public function participants(): array
    {
        $participants = [];
        foreach ($this->peers as $peer => $name) {
            $participants[] = ['id' => $peer, 'name' => $name, 'kind' => $this->kinds[$peer] ?? 'edit'];
        }
        foreach ($this->agents as $agent => $name) {
            $participants[] = ['id' => 'me~'.$agent, 'name' => $name, 'kind' => 'agent'];
        }

        return $participants;
    }

    // --- What the host says ---------------------------------------------------

    /** @param array<string, mixed> $message */
    private function receive(array $message): void
    {
        match ($message['t']) {
            'pending' => $this->api->notify($this->api->trans('Waiting for the host to let you in…'), slot: 'assist'),
            'welcome' => $this->welcome($message),
            'denied' => $this->denied(Wire::string($message, 'reason')),
            'opened' => $this->share(Wire::string($message, 'path'), Wire::int($message, 'rev'), Wire::string($message, 'text')),
            'reopen' => $this->reopen(Wire::string($message, 'path')),
            'op' => $this->remote(Wire::string($message, 'path'), $message['ops'] ?? null),
            'ack' => $this->acknowledged(Wire::string($message, 'path')),
            'presence' => $this->peerCaret($message),
            'peer' => $this->peer(Wire::string($message, 'id'), Wire::text($message, 'name'), Wire::string($message, 'role')),
            'left' => $this->peerLeft(Wire::string($message, 'peer')),
            'role' => $this->roleChanged(Role::tryFrom(Wire::string($message, 'role')) ?? Role::ReadOnly),
            'saved' => $this->hostSaved(Wire::string($message, 'path'), Wire::string($message, 'hash')),
            'moved' => $this->hostMoved(Wire::string($message, 'from'), Wire::string($message, 'to')),
            'deleted' => $this->hostDeleted(Wire::string($message, 'path')),
            'changed' => $this->hostChanged(Wire::files($message, 'files'), Wire::strings($message, 'gone')),
            'file' => $this->file(Wire::string($message, 'path'), $message),
            'error' => $this->refused(Wire::string($message, 'path'), Wire::text($message, 'path').': '.Wire::text($message, 'message')),
            'bye' => $this->bye(Wire::string($message, 'reason')),
            'terminal' => $this->terminal(Wire::string($message, 'id'), Wire::text($message, 'title'), Wire::string($message, 'data')),
            'terminal-out' => $this->api->runs()->feed(self::TERMINAL.Wire::string($message, 'id'), (string) \base64_decode(Wire::string($message, 'data'), strict: true)),
            'terminal-end' => $this->terminalEnded(Wire::string($message, 'id')),
            'typing' => $this->typingAllowed(true === ($message['allowed'] ?? null)),
            'server', 'server-end', 'tunnel-data', 'tunnel-close' => $this->servers?->receive($message),
            'runs', 'running', 'run-refused' => $this->runs->receive($message),
            'debug', 'debug-vars', 'debug-children', 'debugging', 'debug-refused' => $this->debug->receive($message),
            'focus' => $this->follow->asked(Wire::string($message, 'peer'), Wire::text($message, 'name')),
            default => null,
        };
    }

    /** @param array<string, mixed> $message */
    private function welcome(array $message): void
    {
        $this->tries = 0;
        $this->lostFor = null;
        $this->role = Role::tryFrom(Wire::string($message, 'role')) ?? Role::ReadOnly;
        $before = $this->peers;
        $this->peers = [];
        $this->kinds = [];
        // @mago-expect analysis:mixed-assignment -- narrowed below
        foreach (Wire::list($message, 'peers') as $peer) {
            if (!\is_array($peer) || !\is_string($peer['id'] ?? null) || !\is_string($peer['name'] ?? null)) {
                continue;
            }
            $this->peers[$peer['id']] = Wire::clean($peer['name']);
            $this->kinds[$peer['id']] = \is_string($peer['role'] ?? null) ? $peer['role'] : 'edit';
        }
        $this->hostName = $this->peers['host'] ?? null;
        $this->api->notify($this->api->trans('Connected to %name%\'s session', ['%name%' => (string) $this->hostName]), ToastSeverity::Success, slot: 'assist');
        // Whoever left while this side was away: their marks go — and the
        // terminals no longer shared, theirs.
        \array_map($this->presence->left(...), \array_keys(\array_diff_key($before, $this->peers)));
        \array_map($this->terminalEnded(...), \array_keys(\array_diff_key($this->terminals, \array_flip(Wire::strings($message, 'terminals')))));
        // What is open here is asked again: after a drop, the host's copy is
        // the one to carry on from — and what was typed here and never
        // reached it is carried onto that ({@see share()}). Undo is the
        // editor's own until then.
        $buffers = $this->api->openBuffers();
        \array_map(static fn (array $doc) => $buffers->reclaimHistory($doc['text']->path), $this->docs);
        $this->docs = [];
        $this->opening = [];
        foreach ($this->api->openBuffers()->files() as $file) {
            $this->opened($file['path']);
        }
        $this->roster();
    }

    private function denied(string $reason): void
    {
        $this->api->notify(
            match ($reason) {
                'host' => $this->api->trans('The host turned you away'),
                'full' => $this->api->trans('The session is full'),
                'unanswered' => $this->api->trans('The host did not answer'),
                default => $this->api->trans('The session is over — ask the host for a new code'),
            },
            ToastSeverity::Warn,
            8.0,
            slot: 'assist',
        );
        $this->over = true;
        $this->end();
    }

    private function bye(string $reason): void
    {
        $this->api->notify(
            'removed' === $reason ? $this->api->trans('The host removed you from the session') : $this->api->trans('The host ended the session'),
            ToastSeverity::Warn,
            8.0,
            slot: 'assist',
        );
        $this->over = true;
        $this->end();
    }

    /** The connection is gone without a goodbye: read-only until it is back. */
    private function lost(string $reason): void
    {
        // What was typed and not read yet is read now — it is carried over
        // with what was sent and never acknowledged.
        foreach ($this->docs as $relative => $doc) {
            // @mago-expect analysis:redundant-cast -- a key in digits is an int at runtime, whatever its docblock says
            $this->keep((string) $relative, $doc); // a path in digits keys as an int
        }
        $this->connection = null;
        $this->debug->reset();
        $this->servers?->stop();
        $this->servers = null;
        $this->role = null;
        if ($this->over) {
            return;
        }
        if (Tls::IMPOSTOR === $reason) {
            // Not the link: someone else answers at the host's address, and
            // will at the next try too.
            $this->api->notify($this->api->trans('Not rejoining: %why%', ['%why%' => $reason]), ToastSeverity::Error, 8.0, slot: 'assist');
            $this->over = true;
            $this->end();

            return;
        }
        $buffers = $this->api->openBuffers();
        foreach ($this->docs as $doc) {
            $buffers->makeReadOnly($doc['text']->path);
        }
        $delay = Backoff::after($this->tries);
        ++$this->tries;
        // Said when it is news — every thirty seconds of the same reason is not.
        if ($reason !== $this->lostFor) {
            $this->api->notify($this->api->trans('Lost the host (%why%) — trying again', ['%why%' => $reason]), ToastSeverity::Warn, slot: 'assist');
        }
        $this->lostFor = $reason;
        // Everyone stays in the roster as last seen until the host is back —
        // or, for as long as it is not, until core takes them out.
        $this->api->participants()->outOfTouch();
        $this->retry = EventLoop::unreference(EventLoop::delay($delay, function (): void {
            $this->retry = null;
            $this->connect();
        }));
        $this->api->refreshStatusSegments();
    }

    /** A terminal of the host's, shown here: what it shows now, and what it prints after. */
    private function terminal(string $id, string $title, string $data): void
    {
        $again = \array_key_exists($id, $this->terminals);
        $this->terminals[$id] = $title;
        $runs = $this->api->runs();
        if ($again) {
            // Back after a drop: the host sends all of it again, from the top.
            $runs->feed(self::TERMINAL.$id, "\x1b[3J");
        }
        $runs->mirror(self::TERMINAL.$id, self::GLYPH.' '.$title, function (string $bytes) use ($id): void {
            if (!$this->typing) {
                $this->api->flash($this->api->trans('This terminal is the host\'s — ask them to let you type'));

                return;
            }
            $this->send(['t' => 'terminal-in', 'id' => $id, 'data' => \base64_encode($bytes)]);
        });
        $runs->feed(self::TERMINAL.$id, (string) \base64_decode($data, strict: true));
        if (!$again) {
            $this->api->notify($this->api->trans('%name% shared a terminal: %title%', ['%name%' => (string) $this->hostName, '%title%' => $title]), slot: 'assist');
        }
    }

    /** No longer shared: the tab stays, saying so, and takes no keys. */
    private function terminalEnded(string $id): void
    {
        $title = $this->terminals[$id] ?? null;
        if (null === $title) {
            return;
        }
        unset($this->terminals[$id]);
        $runs = $this->api->runs();
        $runs->feed(self::TERMINAL.$id, "\r\n".$this->api->trans('[no longer shared]')."\r\n");
        $runs->mirror(self::TERMINAL.$id, self::GLYPH.' '.$title.' '.$this->api->trans('(ended)'), null);
    }

    // @mago-expect lint:no-boolean-flag-parameter -- whether it may type IS the one fact the call carries
    private function typingAllowed(bool $allowed): void
    {
        $this->typing = $allowed;
        $this->api->notify($allowed ? $this->api->trans('You can type in the shared terminals') : $this->api->trans('The shared terminals are read-only for you again'), slot: 'assist');
    }

    /** The session is over here: the files are the guest's again, everyone else's marks go. */
    private function end(): void
    {
        $this->servers?->stop();
        $this->servers = null;
        foreach ([...$this->timers, ...(null === $this->retry ? [] : [$this->retry])] as $timer) {
            EventLoop::cancel($timer);
        }
        $this->timers = [];
        $this->retry = null;
        $this->over = true;
        $buffers = $this->api->openBuffers();
        foreach ($this->docs as $doc) {
            $buffers->reclaimHistory($doc['text']->path);
            $buffers->makeWritable($doc['text']->path);
        }
        foreach (\array_keys($this->peers) as $peer) {
            $this->presence->left($peer);
        }
        $this->follow->stop();
        foreach (\array_keys($this->terminals) as $id) {
            $this->terminalEnded($id);
        }
        $this->docs = [];
        $this->carried->clear();
        $this->peers = [];
        $this->role = null;
        $this->api->participants()->set([]);
        $this->api->refreshStatusSegments();
    }

    private function open(string $relative): void
    {
        if (!\array_key_exists($relative, $this->docs) && !\array_key_exists($relative, $this->opening)) {
            $this->opening[$relative] = true;
            $this->send(['t' => 'open', 'path' => $relative]);
        }
    }

    /** The host's copy of a file, to share from now on — what the buffer here holds is replaced by it. */
    private function share(string $relative, int $revision, string $text): void
    {
        $path = $this->mirror->paths->absolute($relative);
        if (null === $path) {
            return;
        }
        $buffers = $this->api->openBuffers();
        if (null === $buffers->text($path)) {
            if (!\is_file($path)) {
                $this->mirror->write($relative, $text);
            }
            // Marked as asked for while it opens: opening it here is not the
            // user opening it, to be asked of the host again.
            $this->opening[$relative] = true;
            $buffers->open($path);
        }
        unset($this->opening[$relative]);
        $local = $buffers->text($path);
        if (null === $local) {
            return;
        }
        $buffers->edits($path, TextDiff::between($local, $text)->toEdits($local));
        $shared = SharedText::of($buffers, $path, $text);
        $this->docs[$relative] = ['text' => $shared, 'client' => new ClientDocument($revision, $text)];
        $buffers->delegateHistory($path, fn (): bool => $this->replay($relative, undo: true), fn (): bool => $this->replay($relative, undo: false));
        Role::Edit === $this->role ? $buffers->makeWritable($path) : $buffers->makeReadOnly($path);
        $this->carry($relative, $path, $text);
    }

    /**
     * The connection dropped: what was typed into $relative and not read yet
     * is read now, and kept with what was sent and never acknowledged.
     *
     * @param array{text: SharedText, client: ClientDocument} $doc
     */
    private function keep(string $relative, array $doc): void
    {
        $operations = Role::Edit === $this->role ? \array_filter([$doc['text']->read()]) : [];
        \array_map($doc['client']->local(...), $operations);
        $this->carried->keep($relative, $doc['client']);
    }

    /**
     * Back after a drop: what was typed into $relative and never reached the
     * host goes onto its copy now, as an edit made here — the next read
     * sends it. One that can no longer be made here is said, not dropped
     * without a word.
     */
    private function carry(string $relative, string $path, string $host): void
    {
        $operation = $this->carried->take($relative, $host);
        if (null === $operation) {
            return;
        }
        Role::Edit === $this->role
            ? $this->api->openBuffers()->edits($path, $operation->toEdits($host))
            : $this->api->notify($this->api->trans('What you typed in %file% while away was not sent: you can only read along now', ['%file%' => $relative]), ToastSeverity::Warn, slot: 'assist');
        $this->read($relative);
    }

    /**
     * The host would not share $relative: said — $said, already made safe
     * to show — and a file asked for and not shared is this side's own
     * again, undo and all.
     */
    private function refused(string $relative, string $said): void
    {
        $this->api->flash($said);
        unset($this->opening[$relative]);
        $this->carried->drop($relative);
        $path = $this->mirror->paths->absolute($relative);
        if (null === $path || \array_key_exists($relative, $this->docs)) {
            return;
        }
        $buffers = $this->api->openBuffers();
        $buffers->reclaimHistory($path);
        $buffers->makeWritable($path);
    }

    private function reopen(string $relative): void
    {
        $doc = $this->docs[$relative] ?? null;
        if (null !== $doc) {
            $this->forget($relative, $doc['text']->path);
        }
        $this->open($relative);
    }

    private function remote(string $relative, mixed $ops): void
    {
        $doc = $this->docs[$relative] ?? null;
        if (null === $doc) {
            return;
        }
        try {
            $operation = TextOperation::fromWire($ops);
            // What was typed here goes first — or, where nothing typed here
            // may stand, is put back first.
            Role::Edit === $this->role ? $this->read($relative) : $doc['text']->revert();
            $doc['text']->apply($doc['client']->remote($operation));
        } catch (\InvalidArgumentException) {
            // Out of step with the host: start this file over from its copy.
            $this->reopen($relative);

            return;
        }
        $this->presence->show($doc['text'], $this->peers);
    }

    private function acknowledged(string $relative): void
    {
        $doc = $this->docs[$relative] ?? null;
        $next = null === $doc ? null : $doc['client']->acknowledged();
        if (null !== $doc && null !== $next) {
            $this->send(['t' => 'op', 'path' => $relative, 'rev' => $doc['client']->revision, 'ops' => $next->toWire()]);
        }
    }

    /** @param array<string, mixed> $message */
    private function peerCaret(array $message): void
    {
        $peer = Wire::string($message, 'peer');
        $name = Wire::text($message, 'name');
        $caret = Wire::caret($message);
        $isNew = !\array_key_exists($peer, $this->peers);
        $this->peers[$peer] = $name;
        if (true === ($message['agent'] ?? null)) {
            $this->kinds[$peer] = 'agent';
        }
        if ($isNew) {
            $this->api->refreshStatusSegments();
        }
        $path = $this->mirror->paths->absolute($caret['path']);
        if (null === $path) {
            return;
        }
        $doc = ($this->docs[$caret['path']] ?? null)['text'] ?? null;
        // No line is no caret: in the file, nowhere in its text.
        $caret['line'] < 0 ? $doc?->dropCaret($peer) : $doc?->placeCaret($peer, $caret['line'], $caret['column'], $caret['anchorLine'], $caret['anchorColumn']);
        null === $doc || $caret['line'] < 0 ? $this->presence->inFile($peer, $name, $caret['path']) : $this->presence->show($doc, $this->peers);
        $this->follow->moved($peer, $path, \max(0, $caret['line']), $caret['column'], $caret['top']);
    }

    private function peer(string $peer, string $name, string $kind): void
    {
        $this->peers[$peer] = $name;
        $this->kinds[$peer] = $kind;
        $this->roster();
    }

    private function peerLeft(string $peer): void
    {
        unset($this->peers[$peer], $this->kinds[$peer]);
        foreach ($this->docs as $doc) {
            $doc['text']->dropCaret($peer);
        }
        $this->presence->left($peer);
        $this->follow->gone($peer);
        $this->roster();
    }

    /** Who is in the session now, in core's roster and on the status bar. */
    private function roster(): void
    {
        $this->api->participants()->set(Presence::people($this->participants()));
        $this->api->refreshStatusSegments();
    }

    private function roleChanged(Role $role): void
    {
        $this->role = $role;
        $buffers = $this->api->openBuffers();
        foreach ($this->docs as $doc) {
            Role::Edit === $role ? $buffers->makeWritable($doc['text']->path) : $buffers->makeReadOnly($doc['text']->path);
        }
        $this->api->notify(Role::Edit === $role ? $this->api->trans('You can edit now') : $this->api->trans('You can read along, not edit'), slot: 'assist');
        $this->api->refreshStatusSegments();
    }

    /** The host saved a file: the copy here is saved too when it holds the same text, else fetched. */
    private function hostSaved(string $relative, string $hash): void
    {
        $doc = $this->docs[$relative] ?? null;
        if (null === $doc) {
            $this->send(['t' => 'fetch', 'paths' => [$relative]]);

            return;
        }
        $this->read($relative);
        if (\hash('xxh128', $doc['text']->shadow) === $hash) {
            $this->carryOut(fn (): ?string => $this->api->openBuffers()->save($doc['text']->path));
        }
    }

    private function hostMoved(string $from, string $to): void
    {
        $source = $this->mirror->paths->absolute($from);
        $target = $this->mirror->paths->absolute($to);
        if (null === $source || null === $target) {
            return;
        }
        $this->forget($from, $target);
        if (\file_exists($source)) {
            $this->carryOut(fn (): ?string => $this->api->openBuffers()->move($source, $target));
        }
        if (null !== $this->api->openBuffers()->text($target)) {
            $this->open($to);
        }
    }

    private function hostDeleted(string $relative): void
    {
        $path = $this->mirror->paths->absolute($relative);
        if (null === $path) {
            return;
        }
        $this->forget($relative, $path);
        if (\is_file($path)) {
            $this->carryOut(fn (): ?string => $this->api->openBuffers()->delete($path));
        }
    }

    /**
     * @param array<array-key, array{size: int, hash: string}> $files
     * @param list<string>                                   $gone
     */
    private function hostChanged(array $files, array $gone): void
    {
        $missing = $this->mirror->missing(\array_diff_key($files, $this->docs));
        if ([] !== $missing) {
            $this->send(['t' => 'fetch', 'paths' => $missing]);
        }
        foreach (\array_diff($gone, \array_keys($this->docs)) as $relative) {
            $this->hostDeleted($relative);
        }
    }

    /** @param array<string, mixed> $message */
    private function file(string $relative, array $message): void
    {
        $content = \base64_decode(Wire::string($message, 'data'), strict: true);
        if (false !== $content && !\array_key_exists($relative, $this->docs)) {
            $this->mirror->write($relative, $content);
        }
    }

    private function forget(string $relative, string $path): void
    {
        if (!\array_key_exists($relative, $this->docs)) {
            return;
        }
        unset($this->docs[$relative]);
        $buffers = $this->api->openBuffers();
        $buffers->reclaimHistory($path);
        $buffers->makeWritable($path);
    }

    /** @param \Closure(): mixed $action the host's word, carried out here */
    private function carryOut(\Closure $action): void
    {
        $this->applying = true;
        try {
            $action();
        } finally {
            $this->applying = false;
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $this->connection?->send($message);
    }
}
