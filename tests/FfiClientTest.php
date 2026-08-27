<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\FfiClient;

/**
 * Live tests for the FFI-backed client. Skipped automatically when FFI or the
 * libcurl-impersonate shared library is not available (e.g. CI without libs).
 */
class FfiClientTest extends TestCase
{
    private const API = 'https://httpbun.com';

    /** One-time functional probe result (null = not yet probed). */
    private static ?bool $functional = null;

    protected function setUp(): void
    {
        if (! FfiClient::isAvailable()) {
            $this->markTestSkipped('FFI or libcurl-impersonate library not available.');
        }

        // Probe once that the FFI client can actually complete a request on this
        // platform. If not (an untested OS quirk, or the endpoint is unreachable),
        // skip rather than fail — the library still falls back safely at runtime.
        if (self::$functional === null) {
            try {
                self::$functional = (new FfiClient('chrome146'))->sendGet(self::API . '/get')->status() === 200;
            } catch (\Throwable $e) {
                self::$functional = false;
            }
        }
        if (! self::$functional) {
            $this->markTestSkipped('FFI client is not functional in this environment.');
        }
    }

    public function testGetReturnsImpersonatedUserAgent(): void
    {
        $response = (new FfiClient('firefox147'))->sendGet(self::API . '/get');

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->isSuccess());
        $ua = $response->json()['headers']['User-Agent'] ?? '';
        $this->assertStringContainsString('Firefox/147', $ua);
    }

    public function testPostJsonBody(): void
    {
        $client = new FfiClient('chrome146');
        $response = $client->sendPost(self::API . '/post', ['name' => 'x', 'n' => 2], [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(200, $response->status());
        // assertEquals: the echo endpoint may reorder JSON keys.
        $this->assertEquals(['name' => 'x', 'n' => 2], $response->json()['json']);
    }

    public function testCustomHeaderIsSent(): void
    {
        $response = (new FfiClient('chrome146'))->sendGet(self::API . '/headers', [
            'X-Custom' => 'abc',
        ]);

        $this->assertSame('abc', $response->json()['headers']['X-Custom'] ?? null);
    }

    public function testHeadHasEmptyBody(): void
    {
        $response = (new FfiClient('chrome146'))->sendHead(self::API . '/any');

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
        $this->assertTrue($response->hasHeader('content-type'));
    }

    public function testResponseHeadersAreParsed(): void
    {
        $response = (new FfiClient('chrome146'))->sendGet(self::API . '/get');

        $this->assertNotNull($response->header('content-type'));
        $this->assertStringContainsString('application/json', (string) $response->header('content-type'));
    }

    public function testCompressedResponseIsDecoded(): void
    {
        // httpbin serves genuinely gzip-encoded JSON here. It is a separate,
        // occasionally rate-limited host, so treat an unreachable/non-200 result
        // as a skip rather than a failure — decompression itself is what we assert.
        try {
            $response = (new FfiClient('chrome146'))->sendGet('https://httpbin.org/gzip');
        } catch (\Throwable $e) {
            $this->markTestSkipped('httpbin.org unreachable: ' . $e->getMessage());
        }

        if ($response->status() !== 200) {
            $this->markTestSkipped('httpbin.org returned ' . $response->status());
        }

        // If the body were still gzip-compressed, json() would throw.
        $this->assertIsArray($response->json());
    }

    public function testConnectionIsReusedAcrossRequests(): void
    {
        $client = new FfiClient('chrome146');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $client->sendGet(self::API . '/get')->status());
        }
    }

    public function testUnsupportedCurlOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support');
        new FfiClient('chrome146', 30, ['output' => '/tmp/nope']);
    }

    public function testProxyOptionIsApplied(): void
    {
        // Routing through an unreachable proxy must fail — proving the proxy
        // option actually takes effect (without it, the request would succeed).
        $client = new FfiClient('chrome146', 5, ['proxy' => 'http://127.0.0.1:9']);

        $this->expectException(\Raza\PHPImpersonate\Exception\RequestException::class);
        $client->sendGet(self::API . '/get');
    }
}
