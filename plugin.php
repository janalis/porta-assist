<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Session\Follow;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Session\HostSession;
use Porta\AssistPlugin\Session\Role;
use Porta\AssistPlugin\Workspace\Mirror;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Event\CommandRunRequested;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\PluginObservable;
use Porta\Event\WorkingTreeChanged;
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
 * A host shares the project it has open and hands out the invite; a guest
 * runs `porta assist <invite>`, the host lets them in, and from then on
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
        $api->registerSetting('displayName', 'Your name in a session', 'text', '', hint: 'what the others see beside your caret; empty uses your login name');
        $api->registerSetting(
            'bind',
            'Accept guests from',
            'choice',
            '127.0.0.1',
            ['127.0.0.1', '0.0.0.0'],
            hint: '127.0.0.1: this machine only, reached through an ssh tunnel or a VPN; 0.0.0.0: the whole network — still encrypted, and only those holding the invite are asked in',
        );
        $api->registerSetting('port', 'Port to share on', 'int', 0, hint: '0 picks a free one each time', min: 0, max: 65_535);
        $api->registerSetting('maxFileSize', 'Largest file to share (MB)', 'int', 4, hint: 'a bigger one stays on the host', min: 1, max: 64);

        $api->registerCommand('share', 'Assist: Share This Project', $this->share(...));
        $api->registerCommand('stop', 'Assist: Stop Sharing', $this->stop(...));
        $api->registerCommand('join', 'Assist: Join a Session…', $this->join(...));
        $api->registerCommand('follow', 'Assist: Follow Someone', $this->pickToFollow(...));
        $api->registerCommand('participants', 'Assist: Participants', $this->participants(...));
        $api->registerCommand('leave', 'Assist: Leave the Session', $this->leave(...));
        $api->addMenuItem('share', fn (): bool => null === $this->guest);
        $api->addMenuItem('join', fn (): bool => null === $this->host && null === $this->guest);
        $api->addMenuItem('follow', fn (): bool => [] !== $this->peers());
        $api->addMenuItem('participants', fn (): bool => null !== $this->host || null !== $this->guest);
        $api->addMenuItem('stop', fn (): bool => null !== $this->host);
        $api->addMenuItem('leave', fn (): bool => null !== $this->guest);
        $api->registerStatusSegment($this->status(...));

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
        $this->host ??= new HostSession($api, $this->displayName(), (int) $api->setting('maxFileSize') * 1_048_576, $this->follow());
        $started = $this->host->start((string) $api->setting('bind'), (int) $api->setting('port'));
        if (\is_string($started)) {
            $this->host = null;
            $api->notify($started, ToastSeverity::Error, 8.0, slot: 'assist');

            return;
        }
        $api->copyToClipboard('porta assist '.$started->invite());
        $api->notify($api->trans('Sharing. Invite copied: porta assist %invite%', ['%invite%' => $started->invite()]), ToastSeverity::Success, 15.0, slot: 'assist');
        $api->refreshStatusSegments();
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
        $api->prompt($api->trans('Invite (host:port#token.fingerprint)'), initial: '', onSubmit: static function (string $invite) use ($api): void {
            try {
                $address = Address::parse(\preg_replace('/^\s*porta\s+assist\s+/', replacement: '', subject: $invite) ?? $invite);
            } catch (InvalidArgumentException $e) {
                $api->notify($e->getMessage(), ToastSeverity::Error, slot: 'assist');

                return;
            }
            $api->copyToClipboard('porta assist '.$address->invite());
            $api->notify($api->trans('Copied — run it in a terminal: porta assist %invite%', ['%invite%' => $address->invite()]), seconds: 15.0, slot: 'assist');
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
                'remove' => $api->trans('Remove from the session'),
            ];
            $api->pick($participant['name'], $actions, function (string $action) use ($host, $peer, $participant): void {
                $role = Role::tryFrom($action);
                match (true) {
                    null !== $role => $host?->setRole($peer, $role),
                    'remove' === $action => $host?->remove($peer),
                    default => $this->follow()->start($peer, $participant['name']),
                };
            });
        });
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

    private function displayName(): string
    {
        $name = \trim((string) $this->api()->setting('displayName'));
        $user = \getenv('USER');
        if ('' !== $name) {
            return $name;
        }

        return \is_string($user) && '' !== $user ? $user : 'host';
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
        if (null !== $this->host) {
            $count = \count($this->host->participants());

            return new StatusCell(self::GLYPH.' '.$count.$suffix, StatusTone::Accent, $this->menu(...), $api->trans('Sharing this project — %count% in', ['%count%' => $count]));
        }
        if (null === $this->guest) {
            return null;
        }
        $host = (string) $this->guest->hostName();
        if (!$this->guest->isConnected()) {
            return new StatusCell(self::GLYPH.' …', StatusTone::Subtle, $this->menu(...), $api->trans('Reconnecting to the host…'));
        }
        $tooltip = Role::Edit === $this->guest->role()
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
        $items[] = MenuItem::separator();
        $address = $this->host?->address();
        if (null !== $address) {
            $items[] = new MenuItem($api->trans('Copy the invite'), '', static fn () => $api->copyToClipboard('porta assist '.$address->invite()));
        }
        $items[] = new MenuItem($api->trans('Participants'), '', static fn () => $api->emit(new CommandRunRequested('assist:participants')));
        $items[] = null !== $address
            ? new MenuItem($api->trans('Stop sharing'), '', static fn () => $api->emit(new CommandRunRequested('assist:stop')))
            : new MenuItem($api->trans('Leave the session'), '', static fn () => $api->emit(new CommandRunRequested('assist:leave')));

        return $items;
    }
};
