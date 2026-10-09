<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Mailbox;
use Porta\AssistPlugin\Net\Rendezvous\Transit;
use Porta\AssistPlugin\Session\Follow;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Session\HostSession;
use Porta\AssistPlugin\Session\Role;
use Porta\AssistPlugin\Workspace\Mirror;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Event\CommandRunRequested;
use Porta\Event\DebugStatusChanged;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\PluginObservable;
use Porta\Event\WorkingTreeChanged;
use Porta\Install\PortaBinary;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\DisposablePlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;

/**
 * Working on one project together, live — what VS Code calls Live Share and
 * PhpStorm Code With Me.
 *
 * A host shares the project it has open and reads out the code it gets; a
 * guest runs `porta assist <code>`, the host lets them in, and from then on
 * everyone edits the same files at once, sees each other's carets and
 * selections, and may follow one another around. The host's copy is the one
 * that counts: a guest's saves and file operations are asked of the host.
 */
return new class('assist') extends AbstractPlugin implements DisposablePlugin {
    private const string GLYPH = '⇄';

    private ?PluginApi $api = null;

    private ?HostSession $host = null;

    private ?GuestSession $guest = null;

    private ?Follow $follow = null;

    public function activate(PluginApi $api): void
    {
        $this->api = $api;
        $api->registerSetting(
            'mailbox',
            'Mailbox server',
            'text',
            Mailbox::PUBLIC,
            hint: 'where a host and a guest with the same code meet — magic-wormhole\'s public one, or one of your own; guests name the same with --mailbox',
        );
        $api->registerSetting(
            'transit',
            'Transit relay',
            'text',
            Transit::PUBLIC,
            hint: 'host:port of the relay every connection goes through, encrypted end to end — magic-wormhole\'s public one, or one of your own',
        );
        $api->registerSetting('maxGuests', 'Most guests at once', 'int', 8, hint: 'past eight, the colours that tell carets apart repeat — the names beside them do not', min: 1, max: 32);
        $api->registerSetting('maxFileSize', 'Largest file to share (MB)', 'int', 4, hint: 'a bigger one stays on the host', min: 1, max: 64);

        $api->registerCommand('share', 'Assist: Share This Project', $this->share(...));
        $api->registerCommand('stop', 'Assist: Stop Sharing', $this->stop(...));
        $api->registerCommand('join', 'Assist: Join a Session…', $this->join(...));
        $api->registerCommand('follow', 'Assist: Follow Someone', $this->pickToFollow(...));
        $api->registerCommand('participants', 'Assist: Participants', $this->participants(...));
        $api->registerCommand('leave', 'Assist: Leave the Session', $this->leave(...));
        $api->registerCommand('share_terminal', 'Assist: Share a Terminal', $this->shareTerminal(...));
        $api->registerCommand('share_server', 'Assist: Share a Server', $this->shareServer(...));
        $api->registerCommand('servers', 'Assist: Shared Servers', $this->servers(...));
        $api->registerCommand('run', 'Assist: Run on the Host…', $this->runOnHost(...));
        $api->registerCommand('focus', 'Assist: Ask Everyone to Follow Me', $this->focus(...));
        $api->registerCommand('debugger', 'Assist: Host\'s Debugger', $this->hostDebugger(...));
        $api->addMenuItem('share', fn (): bool => null === $this->guest);
        $api->addMenuItem('join', fn (): bool => null === $this->host && null === $this->guest);
        $api->addMenuItem('follow', fn (): bool => [] !== $this->peers());
        $api->addMenuItem('participants', fn (): bool => null !== $this->host || null !== $this->guest);
        $api->addMenuItem('stop', fn (): bool => null !== $this->host);
        $api->addMenuItem('leave', fn (): bool => null !== $this->guest);
        $api->addMenuItem('share_terminal', fn (): bool => null !== $this->host);
        $api->addMenuItem('share_server', fn (): bool => null !== $this->host);
        $api->addMenuItem('servers', fn (): bool => [] !== ($this->guest?->servers() ?? []));
        $api->addMenuItem('run', fn (): bool => true === $this->guest?->isConnected());
        $api->addMenuItem('focus', fn (): bool => [] !== $this->peers());
        $api->addMenuItem('debugger', fn (): bool => true === $this->guest?->isConnected());
        $api->registerStatusSegment($this->status(...));

        // In a guest's copy, the host's language servers are this one's: each
        // is reached through `porta assist-lsp`, which the host answers.
        $session = GuestSession::load($api->projectRoot());
        foreach (null === $session ? [] : $session['languages'] as $language) {
            $api->registerLanguageServer($language, 'assist', static fn (): array => [\PHP_BINARY, PortaBinary::path(), 'assist-lsp', $language]);
        }

        $api->onReady($this->rejoin(...));
        $api->onFileOpened(function (string $path): void {
            $this->guest?->opened($path);
        });
        $api->onFileSaved(function (string $path): void {
            $this->host?->saved($path);
            $this->guest?->saved($path);
        });
        $api->on(FileRenamed::class, function (PluginObservable $event): void {
            // Widened then narrowed, as every plugin does: on() hands each
            // listener the marker type.
            \assert($event instanceof FileRenamed, description: 'on() delivers the class it was registered for');
            $this->host?->moved($event->from, $event->to);
            $this->guest?->moved($event->from, $event->to);
        });
        $api->on(FileDeleted::class, function (PluginObservable $event): void {
            \assert($event instanceof FileDeleted, description: 'on() delivers the class it was registered for');
            $this->host?->deleted($event->path);
            $this->guest?->deleted($event->path);
        });
        // An agent at work on either side is a participant like any other.
        $api->on(DebugStatusChanged::class, function (): void {
            $this->host?->debugChanged();
        });
        $api->on(AgentActivity::class, function (PluginObservable $event): void {
            \assert($event instanceof AgentActivity, description: 'on() delivers the class it was registered for');
            $this->host?->agentActivity($event);
            $this->guest?->agentActivity($event);
        });
        $api->on(AgentLeft::class, function (PluginObservable $event): void {
            \assert($event instanceof AgentLeft, description: 'on() delivers the class it was registered for');
            $this->host?->agentLeft($event->agent);
            $this->guest?->agentLeft($event->agent);
        });
        $api->on(WorkingTreeChanged::class, function (): void {
            $this->host?->treeChanged();
        });
        $api->onShutdown($this->deactivate(...));
    }

    public function deactivate(): void
    {
        $this->host?->stop();
        $this->guest?->disconnect();
        $this->host = null;
        $this->guest = null;
    }

    private function api(): PluginApi
    {
        \assert(null !== $this->api, description: 'set by activate(), before anything here can run');

        return $this->api;
    }

    private function share(): void
    {
        $api = $this->api();
        if (null !== $this->guest) {
            $api->flash($api->trans('This is a copy of someone else\'s project — it is theirs to share'));

            return;
        }
        $this->host ??= new HostSession($api, $api->participants()->yourName(), (int) $api->setting('maxFileSize') * 1_048_576, $this->follow());
        // Stopping before the mailbox answered calls the start off: whatever
        // ends here ends the session being started.
        $this->host->start((string) $api->setting('mailbox'), (string) $api->setting('transit'), function (Code|string $started) use ($api): void {
            if (\is_string($started)) {
                $this->host = null;
                $api->notify($api->trans('Cannot share: %why%', ['%why%' => $started]), ToastSeverity::Error, 8.0, slot: 'assist');

                return;
            }
            $api->copyToClipboard('porta assist '.$started->text());
            $api->notify($api->trans('Sharing. The code to read out: %code% (copied: porta assist %code%)', ['%code%' => $started->text()]), ToastSeverity::Success, 15.0, slot: 'assist');
            $api->refreshStatusSegments();
        });
    }

    private function stop(): void
    {
        $api = $this->api();
        if (null === $this->host) {
            return;
        }
        $this->host->stop();
        $this->host = null;
        $api->notify($api->trans('Stopped sharing'), slot: 'assist');
        $api->refreshStatusSegments();
    }

    /** A session is its own project, opened by its own Porta: the command to run is what this hands over. */
    private function join(): void
    {
        $api = $this->api();
        $api->prompt($api->trans('Code from the host (42-K7QM-X2PA)'), initial: '', onSubmit: static function (string $typed) use ($api): void {
            try {
                $code = Code::parse(\preg_replace('/^\s*porta\s+assist\s+/', replacement: '', subject: $typed) ?? $typed);
            } catch (InvalidArgumentException $e) {
                $api->notify($e->getMessage(), ToastSeverity::Error, slot: 'assist');

                return;
            }
            $api->copyToClipboard('porta assist '.$code->text());
            $api->notify($api->trans('Copied — run it in a terminal: porta assist %code%', ['%code%' => $code->text()]), seconds: 15.0, slot: 'assist');
        });
    }

    private function pickToFollow(): void
    {
        $api = $this->api();
        $peers = $this->peers();
        if ([] === $peers) {
            $api->flash($api->trans('Nobody else is in the session'));

            return;
        }
        $follow = $this->follow();
        $api->pick($api->trans('Follow'), $peers, static function (string $peer) use ($peers, $follow): void {
            $follow->start($peer, $peers[$peer]);
        });
    }

    /**
     * Everyone else in the session — guests, the host, and every agent at
     * work on either side — to follow, or, for the host, to say what a
     * guest may do.
     */
    private function participants(): void
    {
        $api = $this->api();
        $participants = $this->host?->participants() ?? $this->guest?->participants() ?? [];
        if ([] === $participants) {
            $api->flash($api->trans('Nobody else is in the session'));

            return;
        }
        $kinds = [
            'host' => $api->trans('host'),
            Role::Edit->value => $api->trans('editing'),
            Role::ReadOnly->value => $api->trans('reading along'),
            'agent' => $api->trans('agent'),
        ];
        $rows = [];
        $byId = [];
        foreach ($participants as $participant) {
            $byId[$participant['id']] = $participant;
            $rows[] = ['label' => $participant['name'], 'hint' => $kinds[$participant['kind']] ?? '', 'value' => $participant['id']];
        }
        $api->pick($api->trans('Participants'), $rows, function (string $peer) use ($api, $byId): void {
            $participant = $byId[$peer];
            $host = $this->host;
            $guest = null !== $host && \in_array($participant['kind'], [Role::Edit->value, Role::ReadOnly->value], strict: true);
            if (!$guest) {
                $this->follow()->start($peer, $participant['name']);

                return;
            }
            $actions = [
                'follow' => $api->trans('Follow %name%', ['%name%' => $participant['name']]),
                Role::Edit->value => $api->trans('Let them edit'),
                Role::ReadOnly->value => $api->trans('Let them read along'),
                'typing' => $api->trans('Let them type in shared terminals'),
                'no-typing' => $api->trans('Stop them typing in shared terminals'),
                'running' => $api->trans('Let them run the run configurations'),
                'no-running' => $api->trans('Stop them running the run configurations'),
                'debugging' => $api->trans('Let them step the debugger'),
                'no-debugging' => $api->trans('Stop them stepping the debugger'),
                'remove' => $api->trans('Remove from the session'),
            ];
            $api->pick($participant['name'], $actions, function (string $action) use ($host, $peer, $participant): void {
                $role = Role::tryFrom($action);
                match (true) {
                    null !== $role => $host?->setRole($peer, $role),
                    \in_array($action, ['typing', 'running', 'debugging'], strict: true) => $host?->permit($peer, $action, true),
                    \in_array($action, ['no-typing', 'no-running', 'no-debugging'], strict: true) => $host?->permit($peer, \substr($action, offset: 3), false),
                    'remove' === $action => $host?->remove($peer),
                    default => $this->follow()->start($peer, $participant['name']),
                };
            });
        });
    }

    /** Show one of the host's terminals to everyone — or stop showing it, when it is shown. */
    private function shareTerminal(): void
    {
        $api = $this->api();
        $host = $this->host;
        $terminals = $api->runs()->terminals();
        if (null === $host || [] === $terminals) {
            $api->flash($api->trans('No terminal to share — open one first'));

            return;
        }
        $shared = $host->terminals()->shared();
        $rows = [];
        $titles = [];
        foreach ($terminals as $terminal) {
            $titles[$terminal['id']] = $terminal['title'];
            $rows[] = ['label' => $terminal['title'], 'hint' => \array_key_exists($terminal['id'], $shared) ? $api->trans('shared — pick to stop') : '', 'value' => $terminal['id']];
        }
        $api->pick($api->trans('Share which terminal?'), $rows, static fn (string $id) => $host->terminals()->toggle($id, $titles[$id]));
    }

    /** Let everyone reach a port of this machine — a dev server — or stop, when it is shared. */
    private function shareServer(): void
    {
        $api = $this->api();
        $host = $this->host;
        if (null === $host) {
            $api->flash($api->trans('Share this project first'));

            return;
        }
        $ask = static fn () => $api->prompt($api->trans('Share which local port? (a dev server: 8000, say)'), initial: '', onSubmit: static function (string $typed) use ($api, $host): void {
            $port = \trim($typed);
            // @noinspection NotOptimalIfConditionsInspection -- the digits are checked before the number they make
            if (!\ctype_digit($port) || (int) $port < 1 || (int) $port > 65_535) {
                $api->flash($api->trans('Not a port: %port%', ['%port%' => $port]));

                return;
            }
            $host->servers()->toggle((int) $port);
        });
        $shared = $host->servers()->shared();
        if ([] === $shared) {
            $ask();

            return;
        }
        $rows = \array_map(static fn (int $port): array => ['label' => 'localhost:'.$port, 'hint' => $api->trans('shared — pick to stop'), 'value' => (string) $port], $shared);
        $rows[] = ['label' => $api->trans('Another port…'), 'hint' => '', 'value' => ''];
        $api->pick($api->trans('Share which server?'), $rows, static fn (string $port) => '' === $port ? $ask() : $host->servers()->toggle((int) $port));
    }

    /** The host's servers, as they are reached here: the address of one, copied. */
    private function servers(): void
    {
        $api = $this->api();
        $servers = $this->guest?->servers() ?? [];
        if ([] === $servers) {
            $api->flash($api->trans('The host shares no server'));

            return;
        }
        $rows = [];
        foreach ($servers as $port => $local) {
            $rows[] = ['label' => 'localhost:'.$port, 'hint' => 'http://127.0.0.1:'.$local, 'value' => 'http://127.0.0.1:'.$local];
        }
        $api->pick($api->trans('Shared servers'), $rows, static function (string $url) use ($api): void {
            $api->copyToClipboard($url);
            $api->notify($api->trans('Address copied: %url%', ['%url%' => $url]), slot: 'assist');
        });
    }

    /** Everyone else follows this side now. */
    private function focus(): void
    {
        $api = $this->api();
        if ([] === $this->peers()) {
            $api->flash($api->trans('Nobody else is in the session'));

            return;
        }
        $this->host?->focus();
        $this->guest?->focus();
        $api->notify($api->trans('Asked everyone to follow you'), slot: 'assist');
    }

    /** The host's debugger: where it stopped, its stack and the step verbs. */
    private function hostDebugger(): void
    {
        $api = $this->api();
        if (true !== $this->guest?->isConnected()) {
            $api->flash($api->trans('Not in a session'));

            return;
        }
        $this->guest->debug->show();
    }

    /** Start one of the host's run configurations, when the host lets this guest. */
    private function runOnHost(): void
    {
        $api = $this->api();
        if (true !== $this->guest?->isConnected()) {
            $api->flash($api->trans('Not in a session'));

            return;
        }
        $this->guest->runs->ask();
    }

    private function leave(): void
    {
        $api = $this->api();
        if (null === $this->guest) {
            return;
        }
        $this->guest->leave();
        $this->guest = null;
        $api->notify($api->trans('Left the session — this copy is yours to keep'), slot: 'assist');
    }

    /** The IDE opened on a guest's copy: back into the session it was made for. */
    private function rejoin(string $root): void
    {
        $session = GuestSession::load($root);
        if (null !== $session) {
            $this->guest = new GuestSession($this->api(), new Mirror($root), $session, $this->follow());
            $this->guest->connect();
        }
    }

    private function follow(): Follow
    {
        return $this->follow ??= new Follow($this->api());
    }

    /** @return array<string, string> peer => name, everyone else in the session */
    private function peers(): array
    {
        $peers = [];
        foreach ($this->host?->participants() ?? $this->guest?->participants() ?? [] as $participant) {
            $peers[$participant['id']] = $participant['name'];
        }

        return $peers;
    }

    private function status(): ?StatusCell
    {
        $api = $this->api();
        $following = $this->follow?->target;
        $suffix = null === $following ? '' : ' → '.$following[1];

        return match (true) {
            null !== $this->host => $this->hostStatus($api, $this->host, $suffix),
            null !== $this->guest => $this->guestStatus($api, $this->guest, $suffix),
            default => null,
        };
    }

    private function hostStatus(PluginApi $api, HostSession $host, string $suffix): StatusCell
    {
        $count = \count($host->participants());

        return new StatusCell(self::GLYPH.' '.$count.$suffix, StatusTone::Accent, $this->menu(...), $api->trans('Sharing this project — %count% in', ['%count%' => $count]));
    }

    private function guestStatus(PluginApi $api, GuestSession $guest, string $suffix): StatusCell
    {
        $host = (string) $guest->hostName();
        if (!$guest->isConnected()) {
            return new StatusCell(self::GLYPH.' …', StatusTone::Subtle, $this->menu(...), $api->trans('Reconnecting to the host…'));
        }
        $tooltip = Role::Edit === $guest->role()
            ? $api->trans('In %name%\'s session, editing', ['%name%' => $host])
            : $api->trans('In %name%\'s session, reading along', ['%name%' => $host]);

        return new StatusCell(self::GLYPH.' '.$host.$suffix, StatusTone::Accent, $this->menu(...), $tooltip);
    }

    /** @return list<MenuItem> */
    private function menu(): array
    {
        $api = $this->api();
        $items = [];
        foreach ($this->peers() as $peer => $name) {
            $items[] = new MenuItem($api->trans('Follow %name%', ['%name%' => $name]), '', fn () => $this->follow()->start($peer, $name));
        }
        if (null !== $this->follow?->target) {
            $items[] = new MenuItem($api->trans('Stop following'), '', fn () => $this->follow()->stop());
        }
        if ([] !== $items) {
            $items[] = new MenuItem($api->trans('Ask everyone to follow me'), '', static fn () => $api->emit(new CommandRunRequested('assist:focus')));
        }
        $items[] = MenuItem::separator();
        $code = $this->host?->code();
        if (null !== $code) {
            $items[] = new MenuItem($api->trans('Copy the code: %code%', ['%code%' => $code->text()]), '', static fn () => $api->copyToClipboard('porta assist '.$code->text()));
        }
        $items[] = new MenuItem($api->trans('Participants'), '', static fn () => $api->emit(new CommandRunRequested('assist:participants')));
        $items[] = null !== $this->host
            ? new MenuItem($api->trans('Stop sharing'), '', static fn () => $api->emit(new CommandRunRequested('assist:stop')))
            : new MenuItem($api->trans('Leave the session'), '', static fn () => $api->emit(new CommandRunRequested('assist:leave')));

        return $items;
    }
};
