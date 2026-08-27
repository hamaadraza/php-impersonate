<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Endpoints for the live test services, with graceful skipping.
 *
 * The HTTP-behaviour tests talk to an httpbin instance. Point them at a local
 * container (recommended — reliable, offline-capable) by setting HTTPBIN_URL,
 * e.g. `HTTPBIN_URL=http://localhost:8080` after `docker compose up -d httpbin`.
 * When unset it falls back to the public https://httpbin.org.
 *
 * The TLS-fingerprint tests need a service that reports the negotiated JA3/JA4,
 * which httpbin cannot do; they use https://tls.peet.ws (override with
 * TLS_FINGERPRINT_URL). Both `require*` helpers probe once per process and skip
 * the test when the service is unreachable, so an outage never fails CI.
 */
final class TestServer
{
    /** @var array<string, bool> cached reachability probes, keyed by URL */
    private static array $reachable = [];

    public static function httpbin(string $path = ''): string
    {
        $base = getenv('HTTPBIN_URL') ?: 'https://httpbin.org';

        return rtrim($base, '/') . $path;
    }

    public static function tls(): string
    {
        return getenv('TLS_FINGERPRINT_URL') ?: 'https://tls.peet.ws/api/all';
    }

    /**
     * Skip the current test if the httpbin service is unreachable.
     */
    public static function requireHttpbin(TestCase $test): void
    {
        self::require($test, self::httpbin('/get'), 'httpbin', 'HTTPBIN_URL');
    }

    /**
     * Skip the current test if the TLS-fingerprint service is unreachable.
     */
    public static function requireTls(TestCase $test): void
    {
        self::require($test, self::tls(), 'the TLS-fingerprint service', 'TLS_FINGERPRINT_URL');
    }

    private static function require(TestCase $test, string $url, string $name, string $envVar): void
    {
        if (! array_key_exists($url, self::$reachable)) {
            self::$reachable[$url] = self::probe($url);
        }

        if (! self::$reachable[$url]) {
            $test->markTestSkipped(sprintf(
                '%s is unreachable at %s. Start a local instance (see docker-compose.yml) '
                . 'and set %s, or check your network.',
                ucfirst($name),
                $url,
                $envVar
            ));
        }
    }

    private static function probe(string $url): bool
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        return @file_get_contents($url, false, $context) !== false;
    }
}
