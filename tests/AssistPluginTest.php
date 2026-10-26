<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests;

use PHPUnit\Framework\TestCase;
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
        $plugin = require \dirname(__DIR__).'/plugin.php';
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
}
