<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Session;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\Tests\Support\TempProject;

/**
 * Where a guest's session is kept for the IDE about to open the copy.
 */
final class GuestSessionTest extends TestCase
{
    public function testAFolderThatCannotBeMadeIsReportedNotWarnedAbout(): void
    {
        // A file holds the name the session's folder needs.
        $project = new TempProject(['.porta' => 'not a folder']);
        $invite = new Invite(new SessionKey(\str_repeat('k', times: 32)), '127.0.0.1:1', \str_repeat('0', times: 64), \str_repeat('ab', times: 16));

        $this->expectExceptionObject(new \RuntimeException('cannot create '.$project->dir.'/.porta'));
        GuestSession::store($project->dir, ['invite' => $invite, 'ticket' => \str_repeat('ab', times: 16), 'name' => 'Bo', 'languages' => []]);
    }

    public function testAChannelCounterThatCannotBeOpenedIsReportedNotWarnedAbout(): void
    {
        // The counter lives in .porta/, and a file holds that name.
        $project = new TempProject(['.porta' => 'not a folder']);

        $this->expectExceptionObject(new \RuntimeException('cannot open the channel counter in '.$project->dir));
        GuestSession::channel($project->dir);
    }
}
