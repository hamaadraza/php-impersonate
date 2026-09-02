<?php

declare(strict_types=1);

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
 * the test when the service is unreachable OR answers a non-2xx status — a
 * rate-limited service is not a healthy one — so an outage never fails CI.
 */
final class TestServer
{
    /**
     * Cached probes, keyed by URL: the HTTP status the service answered with,
     * or null when the connection could not be made at all.
     *
     * @var array<string, int|null>
     */
    private static array $probed = [];

    /**
     * The decoded JSON of an httpbin response, with header/query/form values
     * normalised to strings.
     *
     * The two httpbin implementations disagree on shape: the Python original
     * renders each request header, query argument and form field as a string
     * (repeats joined with ", "), while go-httpbin — what CI runs — renders
     * them as LISTS of strings, straight from Go's http.Header/url.Values.
     * Every assertion in this suite was written against the Python shape and
     * only met the Go one once httpbin became reachable in CI. Both are valid
     * httpbin; the tests should not care which one answered.
     *
     * @return array<string,mixed>
     */
    public static function json(\Raza\PHPImpersonate\Response $response): array
    {
        $json = $response->json();

        foreach (['headers', 'args', 'form'] as $section) {
            if (! isset($json[$section]) || ! is_array($json[$section])) {
                continue;
            }
            foreach ($json[$section] as $name => $value) {
                if (is_array($value)) {
                    $json[$section][$name] = implode(', ', $value);
                }
            }
        }

        return $json;
    }

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

    /**
     * Whether an unavailable service must FAIL the run rather than skip it.
     *
     * Skipping is right for a contributor with no network. It is wrong for the
     * run that guards releases: the fingerprint suites — the only tests that
     * observe what actually goes on the wire — gate on a public service that
     * rate-limits, so one 429 marked every one of them skipped and the run went
     * green having verified nothing. Set REQUIRE_LIVE_SERVICES=1 wherever that
     * silence would be mistaken for a pass.
     */
    private static function mustHaveLiveServices(): bool
    {
        $flag = getenv('REQUIRE_LIVE_SERVICES');

        return is_string($flag) && filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    private static function unavailable(TestCase $test, string $message): void
    {
        if (self::mustHaveLiveServices()) {
            $test->fail($message . ' (REQUIRE_LIVE_SERVICES=1, so this is a failure rather than a skip.)');
        }

        $test->markTestSkipped($message);
    }

    private static function require(TestCase $test, string $url, string $name, string $envVar): void
    {
        if (! array_key_exists($url, self::$probed)) {
            self::$probed[$url] = self::probe($url);
        }

        $status = self::$probed[$url];

        if ($status === null) {
            self::unavailable($test, sprintf(
                '%s is unreachable at %s. Start a local instance (see docker-compose.yml) '
                . 'and set %s, or check your network.',
                ucfirst($name),
                $url,
                $envVar
            ));
        }

        // A reachable service is not automatically a usable one. The probe sets
        // ignore_errors, so a 429 or a 503 comes back as an ordinary body and
        // used to read as healthy — after which every test that trusted the
        // probe failed on a rate-limit page instead of skipping. Now that the
        // fingerprint suites assert hard rather than swallowing exceptions, that
        // turned one 429 into a screenful of unrelated failures.
        if ($status < 200 || $status >= 300) {
            self::unavailable($test, sprintf(
                '%s answered HTTP %d at %s, so it cannot serve this test.%s Run a local '
                . 'instance (see docker-compose.yml) and set %s to stop depending on it.',
                ucfirst($name),
                $status,
                $url,
                $status === 429 ? ' That is a rate limit, not a fault in this library.' : '',
                $envVar
            ));
        }
    }

    /**
     * The status code of the final response in a `$http_response_header` array.
     *
     * The last status line wins: the stream wrapper follows redirects by default,
     * so the array can hold one line per hop.
     *
     * @param array<int, string> $responseHeaders
     */
    public static function statusFromHeaders(array $responseHeaders): ?int
    {
        $status = null;

        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    /**
     * @return int|null The HTTP status answered, or null if nothing answered.
     */
    private static function probe(string $url): ?int
    {
        // ignore_errors keeps the response (and its status line) instead of
        // collapsing a 4xx/5xx into `false`, which is what lets the caller tell
        // "rate limited" apart from "down".
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        // `@` is not enough here. PHPUnit installs its own error handler, which
        // promotes the connection warning to a TEST warning regardless of the
        // suppression operator — and phpunit.xml.dist sets failOnWarning="true",
        // so an unreachable service FAILED the run instead of skipping it, which
        // is the exact opposite of what this class exists to do. Swap in a
        // handler that swallows for the duration of the probe.
        set_error_handler(static fn (): bool => true);

        try {
            // $http_response_header is populated in this scope by the call.
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            return null;
        }

        return self::statusFromHeaders($http_response_header ?? []);
    }
}
