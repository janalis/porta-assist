<?php

declare(strict_types=1);

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
 *   chatty       as welcome, with the session talking in between
 */

$mode = $argv[1] ?? 'welcome';
$dir = $argv[2] ?? '.';
$files = [];
if (is_dir($dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr((string) $file, strlen($dir) + 1);
        $files[$relative] = (string) file_get_contents((string) $file);
    }
    ksort($files);
}
$server = stream_socket_server('tcp://127.0.0.1:0');
$name = (string) stream_socket_get_name($server, remote: false);
fwrite(STDOUT, substr($name, offset: (int) strrpos($name, needle: ':') + 1)."\n");
fflush(STDOUT);
$client = stream_socket_accept($server, timeout: 20);
if (false === $client) {
    exit(1);
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
