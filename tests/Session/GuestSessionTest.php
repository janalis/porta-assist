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

    /** Made for this user only, and written as it reads: slashes as slashes. */
    public function testTheSessionIsKeptForThisUserOnly(): void
    {
        $project = new TempProject([]);

        GuestSession::store($project->dir, ['invite' => self::invite(), 'ticket' => \str_repeat('ab', times: 16), 'name' => 'Bo/Ana', 'languages' => []]);

        $this->assertSame(0o700, \fileperms(\dirname($project->path(GuestSession::FILE))) & 0o777);
        $this->assertStringContainsString('"Bo/Ana"', (string) \file_get_contents($project->path(GuestSession::FILE)));
        $this->assertSame('Bo/Ana', GuestSession::load($project->dir)['name'] ?? null);
    }

    /** An invite, a ticket and a name — any one missing, there is no session to rejoin. */
    public function testASessionWithoutItsInviteTicketOrNameIsNone(): void
    {
        $project = new TempProject([]);
        $session = ['ticket' => \str_repeat('ab', times: 16), 'name' => 'Bo'];
        $write = static function (array $fields) use ($project): void {
            \file_put_contents($project->path(GuestSession::FILE), \json_encode($fields, \JSON_THROW_ON_ERROR));
        };
        GuestSession::store($project->dir, ['invite' => self::invite(), ...$session, 'languages' => []]);
        $this->assertNotNull(GuestSession::load($project->dir), 'the control: whole, it is one');

        foreach (['invite' => $session, 'ticket' => ['name' => 'Bo'], 'name' => ['ticket' => $session['ticket']]] as $missing => $fields) {
            $write([...('invite' === $missing ? [] : self::invite()->toArray()), ...$fields]);
            $this->assertNull(GuestSession::load($project->dir), 'no '.$missing);
        }
    }

    private static function invite(): Invite
    {
        return new Invite(new SessionKey(\str_repeat('k', times: 32)), '127.0.0.1:1', \str_repeat('0', times: 64), \str_repeat('ab', times: 16));
    }
}
