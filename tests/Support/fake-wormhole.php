<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Rendezvous\Frames;
use Revolt\EventLoop;

/*
 * A magic-wormhole mailbox server and transit relay, as a separate process —
 * as much of each as assist uses, on ports of their own on loopback. Prints
 * `ws://127.0.0.1:<port>/v1 127.0.0.1:<port>` on the first line, then serves
 * until it is stopped.
 *
 * Like the real one, a nameplate holds two sides, a third is told
 * "crowded", and a side's claim outlives its socket. Two messages no real
 * server knows let a test pull the rug:
 *
 *   {"type": "x-drop"}    hang up on every mailbox socket
 *   {"type": "x-crowd"}   that, and crowd every nameplate with strangers
 *
 * With FAKE_WORMHOLE_REFUSE set, every welcome carries that as its error.
 */

require __DIR__.'/../../../../vendor/autoload.php';
require __DIR__.'/../../../../tests/plugin-autoload.php';

/** @var array<string, array{sides: list<string>, mailbox: string, messages: list<array<string, string>>}> $nameplates */
$nameplates = [];
/** @var array<int, array{stream: resource, side: string, open: string, claimed: string, watcher: string}> $sockets */
$sockets = [];

$send = static function ($stream, array $message): void {
    if (is_resource($stream)) {
        // @mago-expect lint:no-error-control-operator -- a client that just went away warns; this server shrugs
        @fwrite($stream, Frames::encode(Frames::TEXT, (string) json_encode($message, \JSON_THROW_ON_ERROR)));
    }
};
$hangUp = static function (int $id) use (&$sockets): void {
    if (!array_key_exists($id, $sockets)) {
        return;
    }
    EventLoop::cancel($sockets[$id]['watcher']);
    fclose($sockets[$id]['stream']);
    unset($sockets[$id]);
};
$plate = static fn (): array => ['sides' => [], 'mailbox' => bin2hex(random_bytes(6)), 'messages' => []];
$handle = static function (int $id, array $message) use (&$sockets, &$nameplates, $send, $hangUp, $plate): void {
    $socket = &$sockets[$id];
    $stream = $socket['stream'];
    if (array_key_exists('id', $message)) {
        $send($stream, ['type' => 'ack', 'id' => $message['id']]);
    }
    switch ((string) ($message['type'] ?? '')) {
        case 'bind':
            $socket['side'] = (string) ($message['side'] ?? '');
            break;
        case 'allocate':
            $n = 1;
            while (array_key_exists((string) $n, $nameplates)) {
                ++$n;
            }
            $nameplates[(string) $n] = $plate();
            $send($stream, ['type' => 'allocated', 'nameplate' => (string) $n]);
            break;
        case 'claim':
            $name = (string) ($message['nameplate'] ?? '');
            $nameplates[$name] ??= $plate();
            $mine = in_array($socket['side'], $nameplates[$name]['sides'], strict: true);
            if (!$mine && count($nameplates[$name]['sides']) >= 2) {
                $send($stream, ['type' => 'error', 'error' => 'crowded', 'orig' => $message]);
                break;
            }
            if (!$mine) {
                $nameplates[$name]['sides'][] = $socket['side'];
            }
            $socket['claimed'] = $name;
            $send($stream, ['type' => 'claimed', 'mailbox' => $nameplates[$name]['mailbox']]);
            break;
        case 'open':
            $socket['open'] = (string) ($message['mailbox'] ?? '');
            foreach ($nameplates as $each) {
                if ($each['mailbox'] !== $socket['open']) {
                    continue;
                }
                foreach ($each['messages'] as $stored) {
                    $send($stream, ['type' => 'message'] + $stored);
                }
            }
            break;
        case 'add':
            $stored = ['side' => $socket['side'], 'phase' => (string) ($message['phase'] ?? ''), 'body' => (string) ($message['body'] ?? '')];
            foreach ($nameplates as $name => $each) {
                if ($each['mailbox'] !== $socket['open']) {
                    continue;
                }
                $nameplates[$name]['messages'][] = $stored;
            }
            foreach ($sockets as $other) {
                if ($other['open'] !== $socket['open']) {
                    continue;
                }
                $send($other['stream'], ['type' => 'message'] + $stored);
            }
            break;
        case 'release':
            $name = $socket['claimed'];
            if (array_key_exists($name, $nameplates)) {
                $nameplates[$name]['sides'] = array_values(array_diff($nameplates[$name]['sides'], [$socket['side']]));
            }
            if ([] === ($nameplates[$name]['sides'] ?? null)) {
                unset($nameplates[$name]);
            }
            $send($stream, ['type' => 'released']);
            break;
        case 'close':
            $socket['open'] = '';
            $send($stream, ['type' => 'closed']);
            break;
        case 'ping':
            $send($stream, ['type' => 'pong', 'pong' => $message['ping'] ?? 0]);
            break;
        case 'x-crowd':
            foreach (array_keys($nameplates) as $name) {
                $nameplates[$name]['sides'] = ['stranger1', 'stranger2'];
            }
            // no break: and hang up on everyone
        case 'x-drop':
            foreach (array_keys($sockets) as $other) {
                $hangUp($other);
            }
            break;
        default:
            $send($stream, ['type' => 'error', 'error' => 'unknown type', 'orig' => $message]);
    }
};
// Each client, as it connects, to $serve.
$accept = static function ($server, Closure $serve): void {
    stream_set_blocking($server, enable: false);
    EventLoop::onReadable($server, static function () use ($server, $serve): void {
        // @mago-expect lint:no-error-control-operator -- a client that gave up before it was accepted warns; there is nobody to serve
        $stream = @stream_socket_accept($server, timeout: 0);
        if (false !== $stream) {
            stream_set_blocking($stream, enable: false);
            $serve($stream);
        }
    });
};

$mailbox = stream_socket_server('tcp://127.0.0.1:0');
$accept($mailbox, static function ($stream) use (&$sockets, $handle, $hangUp, $send): void {
    $id = (int) $stream;
    $request = '';
    $frames = new Frames(static fn () => $hangUp($id));
    $sockets[$id] = ['stream' => $stream, 'side' => '', 'open' => '', 'claimed' => '', 'watcher' => ''];
    $sockets[$id]['watcher'] = EventLoop::onReadable($stream, static function () use ($stream, $id, &$request, $frames, $handle, $hangUp, $send): void {
        $bytes = fread($stream, length: 65_536);
        if (false === $bytes || ('' === $bytes && feof($stream))) {
            $hangUp($id);

            return;
        }
        if (null !== $request) {
            $request .= $bytes;
            $end = strpos($request, needle: "\r\n\r\n");
            if (false === $end) {
                return;
            }
            $key = [];
            preg_match('/^sec-websocket-key:\s*(\S+)/im', $request, $key);
            fwrite(
                $stream,
                "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: "
                .base64_encode(sha1(($key[1] ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', binary: true))
                ."\r\n\r\n",
            );
            $refuse = getenv('FAKE_WORMHOLE_REFUSE');
            $send($stream, ['type' => 'welcome', 'welcome' => false === $refuse ? [] : ['error' => $refuse]]);
            $bytes = substr($request, $end + 4);
            $request = null;
        }
        foreach ($frames->feed($bytes) as [$opcode, $payload]) {
            if (Frames::CLOSE === $opcode) {
                $hangUp($id);

                return;
            }
            if (Frames::PING === $opcode) {
                fwrite($stream, Frames::encode(Frames::PONG, $payload));
            }
            $message = Frames::TEXT === $opcode ? json_decode($payload, associative: true, flags: \JSON_THROW_ON_ERROR) : null;
            if (is_array($message)) {
                $handle($id, $message);
            }
        }
    });
});

/** @var array<string, array{stream: resource, side: string}> $waiting by channel */
$waiting = [];
// Join $one and $other: each one's bytes to the other, until either hangs up.
$join = static function ($one, $other): void {
    $pipes = [];
    $end = static function () use (&$pipes, $one, $other): void {
        foreach ($pipes as $pipe) {
            EventLoop::cancel($pipe);
        }
        foreach ([$one, $other] as $stream) {
            if (!is_resource($stream)) {
                continue;
            }
            fclose($stream);
        }
    };
    foreach ([[$one, $other], [$other, $one]] as [$from, $to]) {
        fwrite($from, data: "ok\n");
        $pipes[] = EventLoop::onReadable($from, static function () use ($from, $to, $end): void {
            $bytes = fread($from, length: 65_536);
            if (false === $bytes || ('' === $bytes && feof($from))) {
                $end();

                return;
            }
            stream_set_blocking($to, enable: true);
            // @mago-expect lint:no-error-control-operator -- the other end just went away warns; its own read sees the end
            @fwrite($to, $bytes);
            stream_set_blocking($to, enable: false);
        });
    }
};
$transit = stream_socket_server('tcp://127.0.0.1:0');
$accept($transit, static function ($stream) use (&$waiting, $join): void {
    $line = '';
    EventLoop::onReadable($stream, static function (string $watcher) use ($stream, &$line, &$waiting, $join): void {
        $byte = fread($stream, length: 1);
        if (false === $byte || ('' === $byte && feof($stream))) {
            EventLoop::cancel($watcher);
            $waiting = array_filter($waiting, static fn (array $other): bool => $other['stream'] !== $stream);
            fclose($stream);

            return;
        }
        if ("\n" !== $byte) {
            $line .= $byte;

            return;
        }
        EventLoop::cancel($watcher);
        $match = [];
        if (1 !== preg_match('/^please relay ([0-9a-f]+) for side ([0-9a-f]+)$/', $line, $match)) {
            fwrite($stream, data: "bad handshake\n");
            fclose($stream);

            return;
        }
        $other = $waiting[$match[1]] ?? null;
        if (null === $other || $other['side'] === $match[2]) {
            $waiting[$match[1]] = ['stream' => $stream, 'side' => $match[2]];

            return;
        }
        unset($waiting[$match[1]]);
        $join($stream, $other['stream']);
    });
});

$name = static fn ($server): string => (string) stream_socket_get_name($server, remote: false);
fwrite(STDOUT, sprintf("ws://%s/v1 %s\n", $name($mailbox), $name($transit)));
fflush(STDOUT);
EventLoop::run();
