<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

/**
 * A blocking connection to a host, for the commands that run outside the IDE
 * (`porta assist`, the language-server bridge): connected, encrypted, and
 * checked to be the host whose certificate fingerprint it was given.
 */
final class SecureClient
{
    /**
     * The encrypted stream, or why there is none — said for a person.
     *
     * @return resource|string
     */
    public static function connect(string $host, int $port, string $fingerprint, float $timeout)
    {
        $errno = 0;
        $error = '';
        $endpoint = \sprintf('tcp://%s:%d', \str_contains($host, ':') ? '['.$host.']' : $host, $port);
        // @mago-expect lint:no-error-control-operator -- an unreachable host warns as well as returning false; the message is carried in $error
        $socket = @\stream_socket_client($endpoint, $errno, $error, $timeout, context: \stream_context_create(Certificate::clientContext()));
        if (false === $socket) {
            return \sprintf('Cannot reach %s:%d: %s', $host, $port, $error);
        }
        // @mago-expect lint:no-error-control-operator -- a refused handshake warns as well as returning false; false is the answer
        $refused = match (true) {
            true !== @\stream_socket_enable_crypto($socket, enable: true, crypto_method: \STREAM_CRYPTO_METHOD_TLS_CLIENT) => 'The secure connection to the host failed',
            !Certificate::presentedBy($socket, $fingerprint) => 'The host is not the one the invite names — ask the host for the invite again',
            default => null,
        };
        if (null !== $refused) {
            \fclose($socket);

            return $refused;
        }

        return $socket;
    }
}
