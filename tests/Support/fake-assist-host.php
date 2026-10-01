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
 *   no-list      let in, then never list the files
 *   cut          let in, list, and hang up on the first fetch
 *   chatty       as welcome, with the session talking in between
 *   lsp          a language-server channel: ready for php, each request
 *                answered with its own method, hung up on `exit`
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
    $say((string) json_encode($invite->toArray()));
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
stream_set_blocking($client, enable: true);
$send = static function (array $message) use ($client): void {
    fwrite($client, json_encode($message, JSON_UNESCAPED_SLASHES)."\n");
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
        'garbled' === $mode
            ? ['t' => 'welcome', 'project' => ['id' => 'not-hex']]
            : [
                't' => 'welcome',
                'peer' => 'g0',
                'role' => 'pending' === $mode ? 'read' : 'edit',
                'ticket' => 'feedfacefeedfacefeedfacefeedface',
                'project' => ['id' => '0123456789abcdef', 'name' => 'demo'],
                'languages' => ['php', 'twig'],
            ],
    );
    if ('chatty' === $mode) {
        // What a real host says right away: where its caret is.
        $send(['t' => 'presence', 'peer' => 'host', 'name' => 'Hal', 'path' => 'a.txt', 'line' => 0, 'column' => 0]);
    }
};
$fetch = static function (array $paths) use ($send, $files): void {
    foreach ($paths as $path) {
        $send(['t' => 'file', 'path' => $path, 'data' => base64_encode($files[$path])]);
    }
    $send(['t' => 'fetched']);
};
while (false !== ($line = fgets($client))) {
    $message = json_decode($line, associative: true);
    $type = $message['t'] ?? '';
    if ('lsp' === $mode) {
        $lsp = $message['m'] ?? [];
        if ('exit' === ($lsp['method'] ?? null) || 'hello' === $type && 'php' !== ($message['language'] ?? null)) {
            if ('hello' === $type) {
                $send(['t' => 'denied', 'reason' => 'language']);
            }
            break;
        }
        match (true) {
            'hello' === $type => $send(['t' => 'ready']),
            \array_key_exists('id', $lsp) => $send(['t' => 'lsp', 'm' => ['jsonrpc' => '2.0', 'id' => $lsp['id'], 'result' => $lsp['method']]]),
            default => null,
        };

        continue;
    }
    // A denial, a cut, a goodbye: this host is done.
    if ('bye' === $type || 'hello' === $type && str_starts_with($mode, 'deny:') || 'fetch' === $type && 'cut' === $mode) {
        if ('hello' === $type) {
            $welcome();
        }
        break;
    }
    match (true) {
        'hello' === $type => $welcome(),
        'manifest' === $type && 'no-list' !== $mode => $send([
            't' => 'manifest',
            'files' => array_map(static fn (string $path, string $content): array => [$path, strlen($content), hash('xxh128', $content)], array_keys($files), array_values($files)),
        ]),
        'fetch' === $type => $fetch($message['paths']),
        default => null,
    };
}
fclose($client);
