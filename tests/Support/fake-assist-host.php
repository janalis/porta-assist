<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Certificate;

/*
 * A host for `porta assist` to join, as a separate process — the command
 * blocks, so the host cannot share its loop. Prints its port, serves ONE
 * connection the way $argv[1] says, then exits:
 *
 *   welcome      let in, list and send the files of $argv[2]
 *   pending      say "pending" first, then as welcome
 *   deny:<why>   turn away with that reason
 *   silent       never answer
 *   garbled      a welcome without a ticket
 *   no-list      let in, then never list the files
 *   cut          let in, list, and hang up on the first fetch
 *   chatty       as welcome, with the session talking in between
 *   plain        no TLS: hang up on whatever comes
 *   lsp          a language-server channel: ready for php, each request
 *                answered with its own method, hung up on `exit`
 *
 * It speaks TLS with a certificate of its own, and prints its port and the
 * certificate's fingerprint on the first line. With PORTA_ASSIST_RELAY set
 * to a relay's port on loopback, it is reached through that relay instead:
 * the port printed is the relay's, followed by the session guests name.
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
$relay = getenv('PORTA_ASSIST_RELAY');
// One line from a relay, a byte at a time: what follows is TLS, not to be read here.
$relayed = static function ($stream, array $hello): array {
    fwrite($stream, json_encode($hello)."\n");
    $line = '';
    while (false !== ($byte = fgetc($stream)) && "\n" !== $byte) {
        $line .= $byte;
    }

    return (array) json_decode($line, associative: true);
};
$reached = static function (string $relay) use ($relayed, $certificate) {
    $control = stream_socket_client('tcp://127.0.0.1:'.$relay);
    $session = (string) ($relayed($control, ['relay' => 'host'])['session'] ?? '');
    fwrite(STDOUT, $relay.' '.$certificate->fingerprint.' '.$session."\n");
    fflush(STDOUT);
    $incoming = json_decode((string) fgets($control), associative: true);
    $client = stream_socket_client('tcp://127.0.0.1:'.$relay, context: stream_context_create($certificate->serverContext()));
    stream_set_read_buffer($client, size: 0);
    $relayed($client, ['relay' => 'accept', 'session' => $session, 'conn' => $incoming['incoming'] ?? -1]);

    return $client;
};
$listened = static function () use ($certificate) {
    $server = stream_socket_server('tcp://127.0.0.1:0', context: stream_context_create($certificate->serverContext()));
    $name = (string) stream_socket_get_name($server, remote: false);
    fwrite(STDOUT, substr($name, offset: (int) strrpos($name, needle: ':') + 1).' '.$certificate->fingerprint."\n");
    fflush(STDOUT);

    return stream_socket_accept($server, timeout: 20);
};
$client = false !== $relay ? $reached($relay) : $listened();
// @mago-expect lint:no-error-control-operator -- a client that gives up mid-handshake warns; this host just stops
if (false === $client || 'plain' === $mode || true !== @stream_socket_enable_crypto($client, enable: true, crypto_method: STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    exit(0);
}
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
