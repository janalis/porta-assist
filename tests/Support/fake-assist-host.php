<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Certificate;
use Porta\AssistPlugin\Net\Rendezvous\Code;
use Porta\AssistPlugin\Net\Rendezvous\Door;
use Porta\AssistPlugin\Net\Rendezvous\GuestRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\HostRendezvous;
use Porta\AssistPlugin\Net\Rendezvous\Invite;
use Porta\AssistPlugin\Net\Rendezvous\SessionKey;
use Porta\Support\FiberWait;
use Revolt\EventLoop;

/*
 * A host for `porta assist` to join, as a separate process — the command
 * blocks, so the host cannot share its loop. Meets its guest through the
 * mailbox server and relay named by PORTA_FAKE_MAILBOX and PORTA_FAKE_TRANSIT,
 * prints the code to join with on its first line, serves ONE connection the
 * way $argv[1] says, then exits:
 *
 *   welcome      let in, list and send the files of $argv[2]
 *   pending      say "pending" first, then as welcome
 *   deny:<why>   turn away with that reason
 *   silent       never answer
 *   garbled      a welcome without a ticket
 *   garbled:<id> a welcome with a ticket, for the project <id>
 *   no-ticket    a welcome for a project, without a ticket
 *   no-list      let in, then never list the files
 *   cut          let in, list, and hang up on the first fetch
 *   chatty       as welcome, with the session talking in between
 *   lsp          a language-server channel: ready for php, each request
 *                answered with its own method, hung up on `exit`
 *   lsp-mute     ready, then never another word — a connection dead with
 *                the network — printing the type of each message it gets
 *
 * A file named vanished.txt is listed, and answered as missing when fetched:
 * gone between the two.
 *
 * With PORTA_FAKE_DOOR set, it skips the mailbox: it prints the invite a
 * guest holds already, as JSON, and waits at the relay for that guest.
 */

$mode = $argv[1] ?? 'welcome';
// No directory, no files: never the working directory, which is the whole repository.
$dir = $argv[2] ?? null;
$files = [];
if (null !== $dir && is_dir($dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr((string) $file, strlen($dir) + 1);
        $files[$relative] = (string) file_get_contents((string) $file);
    }
    ksort($files);
}
require __DIR__.'/../../../../vendor/autoload.php';
require __DIR__.'/../../../../tests/plugin-autoload.php';
$certificate = Certificate::create();
register_shutdown_function($certificate->forget(...));
// A test stops this host with SIGTERM, which ends PHP without its shutdown
// functions: the key above stayed in the temp dir, one per test, for good.
// Exiting on it runs them.
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static fn () => exit(0));
$transit = (string) getenv('PORTA_FAKE_TRANSIT');
// @mago-expect lint:no-literal-password -- the fake host's token: the tests know it, and it guards nothing
$token = '00112233445566778899aabbccddeeff';
$client = null;
$take = static function ($stream) use (&$client): void {
    $client ??= $stream;
};
$say = static function (string $line): void {
    fwrite(STDOUT, $line."\n");
    fflush(STDOUT);
};
$door = static function () use ($transit, $certificate, $token, $take, $say): void {
    $invite = new Invite(new SessionKey(random_bytes(32)), $transit, $certificate->fingerprint, $token);
    new Door($invite->key, $transit, $certificate, $take)->open();
    $say((string) json_encode($invite->toArray(), \JSON_THROW_ON_ERROR));
};
$code = static fn () => new HostRendezvous(
    (string) getenv('PORTA_FAKE_MAILBOX'),
    $transit,
    $certificate,
    $token,
    static fn (Code $code, string $why) => '' === $why ? $say($code->text()) : null,
    static fn (string $why) => $say('failed: '.$why),
    static function (): void {},
    $take,
)->start();
false !== getenv('PORTA_FAKE_DOOR') ? $door() : $code();
// Not arrow functions: those would capture $client as it is now — null.
GuestRendezvous::blocking(static function () use (&$client): bool {
    return FiberWait::within(
        20.0,
        static function (Closure $done) use (&$client): void {
            EventLoop::repeat(0.01, static function () use (&$client, $done): void {
                if (null !== $client) {
                    $done(true);
                }
            });
        },
        false,
    );
});
if (null === $client) {
    exit(0);
}
// The handshake is done, and with it the key's use. Gone now, it is not left
// by a host stopped while it blocks in fgets() below: a SIGTERM there does not
// reach the handler above before the SIGKILL that follows it.
$certificate->forget();
stream_set_blocking($client, enable: true);
$send = static function (array $message) use ($client): void {
    fwrite($client, json_encode($message, \JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
};
$welcome = static function () use ($mode, $send): void {
    if ('silent' === $mode) {
        return;
    }
    if (str_starts_with($mode, 'deny:')) {
        $send(['t' => 'denied', 'reason' => substr($mode, offset: 5)]);

        return;
    }
    if ('pending' === $mode) {
        $send(['t' => 'pending']);
    }
    $send(
        match (true) {
            'garbled' === $mode => ['t' => 'welcome', 'project' => ['id' => 'not-hex']],
            str_starts_with($mode, 'garbled:') => ['t' => 'welcome', 'ticket' => 'feedfacefeedfacefeedfacefeedface', 'project' => ['id' => substr($mode, offset: 8), 'name' => 'demo']],
            'no-ticket' === $mode => ['t' => 'welcome', 'project' => ['id' => '0123456789abcdef', 'name' => 'demo']],
            default => [
                't' => 'welcome',
                'peer' => 'g0',
                'role' => 'pending' === $mode ? 'read' : 'edit',
                'ticket' => 'feedfacefeedfacefeedfacefeedface',
                'project' => ['id' => '0123456789abcdef', 'name' => 'demo'],
                'languages' => ['php', 'twig'],
            ],
        },
    );
    if ('chatty' === $mode) {
        // What a real host says right away: where its caret is.
        $send(['t' => 'presence', 'peer' => 'host', 'name' => 'Hal', 'path' => 'a.txt', 'line' => 0, 'column' => 0]);
    }
};
$fetch = static function (array $paths) use ($send, $files): void {
    foreach ($paths as $path) {
        $send(str_ends_with($path, 'vanished.txt') ? ['t' => 'file', 'path' => $path, 'missing' => true] : ['t' => 'file', 'path' => $path, 'data' => base64_encode($files[$path])]);
    }
    $send(['t' => 'fetched']);
};
while (false !== ($line = fgets($client))) {
    $message = json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
    $type = $message['t'] ?? '';
    if ('lsp-mute' === $mode) {
        'hello' === $type ? $send(['t' => 'ready']) : (print $type."\n");

        continue;
    }
    if ('lsp' === $mode) {
        $lsp = $message['m'] ?? [];
        if ('exit' === ($lsp['method'] ?? null) || ('hello' === $type && 'php' !== ($message['language'] ?? null))) {
            if ('hello' === $type) {
                $send(['t' => 'denied', 'reason' => 'language']);
            }
            break;
        }
        match (true) {
            'hello' === $type => $send(['t' => 'ready']),
            'lsp' === $type && \array_key_exists('id', $lsp) => $send(['t' => 'lsp', 'm' => ['jsonrpc' => '2.0', 'id' => $lsp['id'], 'result' => $lsp['method']]]),
            default => null,
        };

        continue;
    }
    // A first hello without the invite's token is a stranger's — one back on a ticket is not: turned away, as a real host does.
    if ('hello' === $type && !array_key_exists('ticket', $message) && !hash_equals($token, (string) ($message['token'] ?? ''))) {
        $send(['t' => 'denied', 'reason' => 'host']);

        break;
    }
    // A denial, a cut, a goodbye: this host is done.
    if (('fetch' === $type && 'cut' === $mode) || 'bye' === $type || ('hello' === $type && str_starts_with($mode, 'deny:'))) {
        if ('hello' === $type) {
            $welcome();
        }
        break;
    }
    match (true) {
        'hello' === $type => $welcome(),
        'manifest' === $type && 'no-list' !== $mode => $send([
            't' => 'manifest',
            'files' => array_map(static fn (int|string $path, string $content): array => [(string) $path, strlen($content), hash('xxh128', $content)], array_keys($files), array_values($files)),
        ]),
        'fetch' === $type => $fetch($message['paths']),
        default => null,
    };
}
fclose($client);
