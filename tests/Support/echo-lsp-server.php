<?php

declare(strict_types=1);

/*
 * A language server for the tests: it answers every request with what it
 * was asked — `{"heard": {method, params}}`, and the same base64-encoded
 * as `raw`, which nothing on the way back rewrites — so a test can read
 * what reached the server, and exits on `exit`.
 */

$buffer = '';
while (false !== ($chunk = fread(STDIN, length: 65_536)) && '' !== $chunk) {
    $buffer .= $chunk;
    while (false !== ($end = strpos($buffer, needle: "\r\n\r\n"))) {
        $length = (int) substr($buffer, offset: 16, length: $end - 16);
        if (strlen($buffer) < ($end + 4 + $length)) {
            break;
        }
        $message = json_decode(substr($buffer, $end + 4, $length), associative: true);
        $buffer = substr($buffer, $end + 4 + $length);
        if ('exit' === ($message['method'] ?? null)) {
            exit(0);
        }
        if (!array_key_exists('id', $message)) {
            continue;
        }
        $answer = json_encode([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => ['heard' => ['method' => $message['method'] ?? null, 'params' => $message['params'] ?? null], 'raw' => base64_encode(json_encode($message['params'] ?? null))],
        ], JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, 'Content-Length: '.strlen($answer)."\r\n\r\n".$answer);
        fflush(STDOUT);
    }
}
