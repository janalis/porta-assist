<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsRow;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The knobs the plugin offers, as the Settings page shows them — asked of the plugin itself, no IDE booted. */
final class AssistPluginTest extends TestCase
{
    /** Eight guests by default — as many as there are caret colours — and a share of files up to four megabytes. */
    public function testTheLimitsStartAtWhatTheSessionCanShowAndCarry(): void
    {
        $project = new TempProject([]);
        $settings = new PluginSettings($project->dir, ['assist' => []]);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $manager->wireRuntime(TestPluginRuntime::create(new CodeEditorWidget(), new StatusBarWidget()));
        $api = $manager->api('assist', $project->dir, $settings);
        $plugin = require \dirname(__DIR__).'/porta.php';
        $plugin->activate($api);
        $manager->commit($api, $plugin);

        $rows = [];
        foreach ($settings->rows('assist') as $row) {
            $rows[$row->key] = $row;
        }
        $limits = static fn (?SettingsRow $row): array => [$row?->default, $row?->min, $row?->max];

        $this->assertSame([8, 1, 32], $limits($rows['pluginSetting:maxGuests'] ?? null));
        $this->assertSame([4, 1, 64], $limits($rows['pluginSetting:maxFileSize'] ?? null));
    }

    /**
     * A guest's copy reaches the host's language servers through `porta
     * assist-lsp`, which runs nothing of the copy — so they are relayed, the
     * one kind a copy opened restricted still starts.
     */
    public function testTheHostsLanguageServersAreRelayed(): void
    {
        $project = new TempProject([]);
        $ticket = \str_repeat('a', times: 32);
        GuestSession::store($project->dir, [
            'invite' => new Invite(new SessionKey(\str_repeat('k', times: 32)), '127.0.0.1:9', \str_repeat('0', times: 64), $ticket),
            'ticket' => $ticket,
            'name' => 'Bo',
            'languages' => ['php'],
        ]);
        $settings = new PluginSettings($project->dir, ['assist' => []]);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $manager->wireRuntime(TestPluginRuntime::create(new CodeEditorWidget(), new StatusBarWidget()));
        $api = $manager->api('assist', $project->dir, $settings);
        $plugin = require \dirname(__DIR__).'/porta.php';
        $plugin->activate($api);
        $manager->commit($api, $plugin);

        $this->assertSame(['assist-lsp', 'php'], \array_slice($manager->relayedServers()['php'][0]['command'] ?? [], offset: -2));
    }
}
