<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Both engines must be interchangeable: the same browser must produce the same
 * TLS fingerprint whether it went through the executable (our BrowserConfig) or
 * FFI (the library's built-in profiles). Divergence here means one of them is
 * sending a fingerprint no real browser sends.
 *
 * Requires the FFI engine (parity is meaningless with only one engine), so it
 * is skipped where FFI is unavailable.
 */
class EngineParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('Cross-engine parity requires the FFI engine.');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function browserProvider(): array
    {
        return [
            'firefox147 (default)' => ['firefox147'],
            'firefox144' => ['firefox144'],
            'chrome146' => ['chrome146'],
            'chrome150' => ['chrome150'],
            'safari184' => ['safari184'],
            'okhttp4_android' => ['okhttp4_android'],
            // Hand-written rather than generated from upstream, so they are the
            // ones most likely to drift out of step with the shared library.
            'firefox133 (hand-written)' => ['firefox133'],
            'firefox135 (hand-written)' => ['firefox135'],
            'tor145 (hand-written)' => ['tor145'],
        ];
    }

    #[DataProvider('browserProvider')]
    public function testBothEnginesProduceIdenticalJa4(string $browser): void
    {
        // Handle a service outage ONCE, here, the way every other TLS test does
        // — rather than excusing each individual request inside ja4().
        TestServer::requireTls($this);

        $ffi = $this->ja4($browser, PHPImpersonate::ENGINE_FFI);
        $process = $this->ja4($browser, PHPImpersonate::ENGINE_PROCESS);

        $this->assertSame(
            $ffi,
            $process,
            "$browser: the executable and FFI engines produced different JA4 fingerprints"
        );
    }

    /**
     * The two engines must put headers on the wire in the SAME order.
     *
     * Nothing asserted this, which is how F-02 survived: the executable engine
     * emitted the caller's headers ahead of the whole browser profile, so an
     * Authorization line preceded sec-ch-ua while the FFI engine put it last.
     * Both engines returned identical JA4, so the parity suite stayed green —
     * TLS parity says nothing about HTTP.
     *
     * The service reports the real HTTP/2 HEADERS frame, pseudo-headers
     * included, so this compares what was actually framed rather than what a
     * server chose to echo back.
     */
    public function testBothEnginesSendHeadersInTheSameOrder(): void
    {
        TestServer::requireTls($this);

        $ffi = $this->headerOrder(PHPImpersonate::ENGINE_FFI);
        $process = $this->headerOrder(PHPImpersonate::ENGINE_PROCESS);

        $this->assertNotSame([], $ffi, 'no HEADERS frame was reported for the FFI engine');

        $this->assertSame(
            $ffi,
            $process,
            "the engines framed headers in different orders\nffi:     " . implode(', ', $ffi)
                . "\nprocess: " . implode(', ', $process)
        );

        // Agreement alone would be self-referential — two engines can agree on
        // an order no browser produces, which is precisely what F-02 was. So
        // also pin the shape absolutely.
        foreach (['ffi' => $ffi, 'process' => $process] as $engine => $order) {
            // Pseudo-headers lead, as HTTP/2 requires.
            $this->assertStringStartsWith(':', $order[0], "$engine: pseudo-headers must come first");

            // A caller header the profile has no counterpart for goes last...
            $this->assertSame('authorization', end($order), "$engine: a caller-only header must come last");

            // ...while one that overrides a profile header keeps the profile's
            // slot instead of being hoisted to the front.
            $language = array_search('accept-language', $order, true);
            $agent = array_search('user-agent', $order, true);
            $this->assertIsInt($language);
            $this->assertIsInt($agent);
            $this->assertGreaterThan(
                $agent,
                $language,
                "$engine: an overridden header jumped out of its profile slot"
            );
        }
    }

    /**
     * The lower-cased header names of the request's HTTP/2 HEADERS frame, in
     * the order they were framed.
     *
     * @return list<string>
     */
    private function headerOrder(string $engine): array
    {
        $body = (new PHPImpersonate('chrome146', 30, [], $engine))->sendGet(
            TestServer::tls(),
            // One header that overrides the profile's, one the profile lacks.
            ['Accept-Language' => 'de-DE', 'Authorization' => 'Bearer parity']
        )->json();

        foreach ($body['http2']['sent_frames'] ?? [] as $frame) {
            if (($frame['frame_type'] ?? '') !== 'HEADERS') {
                continue;
            }

            $names = [];
            foreach ($frame['headers'] ?? [] as $header) {
                $separator = strpos((string) $header, ': ');
                $names[] = strtolower($separator === false ? (string) $header : substr((string) $header, 0, $separator));
            }

            return $names;
        }

        return [];
    }

    public function testCustomOptionsBehaveIdenticallyOnBothEngines(): void
    {
        TestServer::requireHttpbin($this);

        // referer is sent verbatim, and max-redirs caps the redirect chain — the
        // same way on both engines (the shared, typed option map).
        $referer = fn (string $engine): string => (new PHPImpersonate('chrome146', 30, ['referer' => 'https://ref.test/x'], $engine))
            ->sendGet(TestServer::httpbin('/headers'))->json()['headers']['Referer'] ?? '';

        $this->assertSame('https://ref.test/x', $referer(PHPImpersonate::ENGINE_FFI));
        $this->assertSame('https://ref.test/x', $referer(PHPImpersonate::ENGINE_PROCESS));

        $capped = fn (string $engine): int => (new PHPImpersonate('chrome146', 15, ['max-redirs' => 1], $engine))
            ->sendGet(TestServer::httpbin('/redirect/3'))->status();

        $ffi = $capped(PHPImpersonate::ENGINE_FFI);
        $this->assertGreaterThanOrEqual(300, $ffi);
        $this->assertLessThan(400, $ffi);
        $this->assertSame($ffi, $capped(PHPImpersonate::ENGINE_PROCESS));
    }

    /**
     * A caller header must REPLACE the profile's, not be appended alongside it.
     * curl sends every -H it is handed, so the executable engine used to emit
     * two User-Agent lines where libcurl replaces by name — a divergence, and a
     * bot signal in its own right.
     */
    public function testCallerHeaderReplacesProfileHeaderOnBothEngines(): void
    {
        TestServer::requireHttpbin($this);

        $sent = function (string $engine): array {
            return (new PHPImpersonate('chrome146', 30, [], $engine))
                ->sendGet(TestServer::httpbin('/headers'), [
                    'User-Agent' => 'MyCustomAgent/1.0',
                    'Accept-Language' => 'de-DE',
                ])->json()['headers'] ?? [];
        };

        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            $headers = $sent($engine);

            // httpbin folds repeated headers into one comma-separated value, so
            // a duplicate shows up as the profile value trailing the caller's.
            $this->assertSame('MyCustomAgent/1.0', $headers['User-Agent'] ?? null, "$engine duplicated User-Agent");
            $this->assertSame('de-DE', $headers['Accept-Language'] ?? null, "$engine duplicated Accept-Language");
        }
    }

    public function testProfileHeadersSurviveWhenNotOverridden(): void
    {
        TestServer::requireHttpbin($this);

        // The replace rule must not swallow the profile when nothing overrides it.
        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            $headers = (new PHPImpersonate('chrome146', 30, [], $engine))
                ->sendGet(TestServer::httpbin('/headers'))->json()['headers'] ?? [];

            $this->assertStringContainsString('Chrome/146', $headers['User-Agent'] ?? '', "$engine lost the profile UA");
        }
    }

    /**
     * A loose value for a bool option must mean the same thing on both engines.
     * Rendered naively the executable engine produced `--insecure no`, where
     * curl reads `no` as an extra URL — corrupting the body with a second
     * response's write-out while also enabling the flag the caller turned off.
     */
    public function testLooseBooleanOptionBehavesIdenticallyOnBothEngines(): void
    {
        TestServer::requireHttpbin($this);

        $bodies = [];
        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            $response = (new PHPImpersonate('chrome146', 30, ['insecure' => 'no'], $engine))
                ->sendGet(TestServer::httpbin('/headers'));

            $this->assertSame(200, $response->status(), "$engine did not return a clean 200");
            $this->assertIsArray($response->json(), "$engine returned a corrupted body");
            $bodies[$engine] = $response->json()['headers']['User-Agent'] ?? null;
        }

        $this->assertSame(
            $bodies[PHPImpersonate::ENGINE_FFI],
            $bodies[PHPImpersonate::ENGINE_PROCESS],
            'engines disagreed on a loose bool option'
        );
    }

    /**
     * A multipart body has to be accepted by a real parser, on both engines.
     *
     * Requesting multipart used to yield an http_build_query() string under a
     * boundary-less multipart Content-Type; httpbin answered 200 and threw the
     * body away, so the caller saw success and the server saw an empty form.
     */
    public function testMultipartFormDataIsParsedByTheServerOnBothEngines(): void
    {
        TestServer::requireHttpbin($this);

        $forms = [];
        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            $response = (new PHPImpersonate('chrome146', 30, [], $engine))->sendPost(
                TestServer::httpbin('/post'),
                ['name' => 'Ada', 'role' => 'eng'],
                ['Content-Type' => 'multipart/form-data']
            );

            $this->assertSame(200, $response->status(), "$engine did not return 200");

            $forms[$engine] = $response->json()['form'] ?? [];
            $this->assertSame(
                ['name' => 'Ada', 'role' => 'eng'],
                $forms[$engine],
                "$engine sent a multipart body the server could not parse"
            );
        }

        $this->assertSame(
            $forms[PHPImpersonate::ENGINE_FFI],
            $forms[PHPImpersonate::ENGINE_PROCESS],
            'engines disagreed on the multipart body'
        );
    }

    /**
     * A redirected POST must behave the way curl and browsers do, on both engines.
     *
     * 301/302/303 switch to GET and drop the body; 307/308 preserve both. The
     * engines used to pin the verb — `-X POST` and CURLOPT_CUSTOMREQUEST — which
     * survives the redirect, while libcurl still applied its own rule and dropped
     * the body. The result was neither behaviour: a POST carrying nothing.
     *
     * @param int $status
     * @param string $expectedMethod
     * @param bool $expectBody
     */
    #[DataProvider('redirectProvider')]
    public function testRedirectedPostFollowsBrowserSemanticsOnBothEngines(
        int $status,
        string $expectedMethod,
        bool $expectBody
    ): void {
        TestServer::requireHttpbin($this);

        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            $body = (new PHPImpersonate('chrome146', 30, [], $engine))->sendPost(
                TestServer::httpbin('/redirect-to?url=%2Fanything&status_code=' . $status),
                ['a' => '1']
            )->json();

            $this->assertSame(
                $expectedMethod,
                $body['method'] ?? null,
                "$engine used the wrong method after a $status"
            );

            $this->assertSame(
                $expectBody ? ['a' => '1'] : [],
                $body['form'] ?? [],
                $expectBody
                    ? "$engine dropped the body a $status must preserve"
                    : "$engine resent a body a $status must drop"
            );
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: bool}>
     */
    public static function redirectProvider(): array
    {
        return [
            '301 becomes GET' => [301, 'GET', false],
            '302 becomes GET' => [302, 'GET', false],
            '303 becomes GET' => [303, 'GET', false],
            '307 keeps POST' => [307, 'POST', true],
            '308 keeps POST' => [308, 'POST', true],
        ];
    }

    public function testGetWithBodyKeepsMethodOnBothEngines(): void
    {
        TestServer::requireHttpbin($this);

        // A body must not silently promote GET to POST on either engine (B5).
        $method = function (string $engine): string {
            $r = (new PHPImpersonate('chrome146', 30, [], $engine))
                ->send(new Request('GET', TestServer::httpbin('/anything'), [], 'q=1'));

            return $r->json()['method'] ?? '';
        };

        $this->assertSame('GET', $method(PHPImpersonate::ENGINE_FFI), 'FFI turned GET+body into another method');
        $this->assertSame('GET', $method(PHPImpersonate::ENGINE_PROCESS), 'process turned GET+body into another method');
    }

    /**
     * The JA4 one engine reports for a browser.
     *
     * Deliberately without a try/catch. This used to turn every Throwable into
     * markTestSkipped(), which made the entire parity suite disappear on exactly
     * the failure it exists to catch: when the bundled shared library is older
     * than this package's browser list, the FFI engine throws "does not support
     * target '<browser>'" — and that was reported as an unreachable service and
     * skipped, leaving CI green. A missing JA4 was excused the same way, which
     * also hid a body corrupted by an engine bug.
     *
     * Outages are handled once by TestServer::requireTls() in the test above;
     * anything that goes wrong after that is ours, and must fail.
     */
    private function ja4(string $browser, string $engine): string
    {
        $response = (new PHPImpersonate($browser, 30, [], $engine))->sendGet(TestServer::tls());

        $this->assertSame(200, $response->status(), "$browser/$engine: the TLS service did not return 200");

        $ja4 = $response->json()['tls']['ja4'] ?? null;

        $this->assertIsString($ja4, "$browser/$engine: the response carried no JA4 fingerprint");
        $this->assertNotSame('', $ja4, "$browser/$engine: the response carried an empty JA4 fingerprint");

        return $ja4;
    }
}
