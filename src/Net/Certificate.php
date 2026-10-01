<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net;

/**
 * The host's TLS identity for one session: a key and a self-signed
 * certificate made when sharing starts, gone when it stops. Nobody vouches
 * for it — the invite carries its fingerprint instead, so a guest talks to
 * this host and no other, and nothing on the way reads the session.
 */
final readonly class Certificate
{
    /**
     * @param string $file        the certificate and its key, as PEM, readable by this user only
     * @param string $fingerprint its SHA-256, lower-case hex
     */
    private function __construct(
        public string $file,
        public string $fingerprint,
    ) {}

    public static function create(): self
    {
        $key = \openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert(false !== $key, description: 'an EC key is always available to OpenSSL');
        $request = \openssl_csr_new(['commonName' => 'porta-assist'], $key, ['digest_alg' => 'sha256']);
        \assert($request instanceof \OpenSSLCertificateSigningRequest, description: 'a request for a fresh key is always made');
        $certificate = \openssl_csr_sign($request, ca_certificate: null, private_key: $key, days: 30, options: ['digest_alg' => 'sha256']);
        \assert(false !== $certificate, description: 'self-signing a fresh request does not fail');
        $pem = '';
        $keyPem = '';
        \openssl_x509_export($certificate, $pem);
        \openssl_pkey_export($key, $keyPem);
        $file = (string) \tempnam(\sys_get_temp_dir(), prefix: 'porta-assist-');
        \chmod($file, permissions: 0o600);
        \file_put_contents($file, $pem.$keyPem);

        return new self($file, (string) \openssl_x509_fingerprint($certificate, digest_algo: 'sha256'));
    }

    /** The ssl context a server presents it with. @return array{ssl: array<string, mixed>} */
    public function serverContext(): array
    {
        return ['ssl' => ['local_cert' => $this->file, 'verify_peer' => false]];
    }

    /**
     * The ssl context a client connects with: the host's certificate is
     * self-signed and names no host, so nothing is checked during the
     * handshake — it is kept, for {@see presentedBy()} to check after.
     *
     * @return array{ssl: array<string, mixed>}
     */
    public static function clientContext(): array
    {
        return ['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    }

    /**
     * Whether the encrypted $stream's peer presented the certificate with
     * $fingerprint.
     *
     * @param resource $stream
     */
    public static function presentedBy($stream, string $fingerprint): bool
    {
        $options = \stream_context_get_options($stream);
        // @mago-expect analysis:mixed-assignment -- narrowed below
        $presented = $options['ssl']['peer_certificate'] ?? null;

        return $presented instanceof \OpenSSLCertificate && \hash_equals($fingerprint, (string) \openssl_x509_fingerprint($presented, digest_algo: 'sha256'));
    }

    /** The session is over: the key goes with it. */
    public function forget(): void
    {
        if (\is_file($this->file)) {
            \unlink($this->file);
        }
    }
}
