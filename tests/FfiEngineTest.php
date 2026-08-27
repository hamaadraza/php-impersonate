<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Live tests for the FFI engine, driven through the PHPImpersonate entry point
 * with engine: ENGINE_FFI. Skipped automatically when FFI or the shared library
 * is unavailable (e.g. CI without libs), or non-functional on this platform.
 */
class FfiEngineTest extends TestCase
{
    private const API = 'https://httpbun.com';

    /** One-time functional probe result. */
    private static ?bool $functional = null;

    protected function setUp(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }

        if (self::$functional === null) {
            try {
                self::$functional = $this->ffi('chrome146')->sendGet(self::API . '/get')->status() === 200;
            } catch (\Throwable $e) {
                self::$functional = false;
            }
        }
        if (! self::$functional) {
            $this->markTestSkipped('FFI engine is not functional in this environment.');
        }
    }

    /**
     * @param array<string,mixed> $curlOptions
     */
    private function ffi(string $browser, int $timeout = 30, array $curlOptions = []): PHPImpersonate
    {
        return new PHPImpersonate($browser, $timeout, $curlOptions, PHPImpersonate::ENGINE_FFI);
    }

    public function testEngineIsFfi(): void
    {
        $this->assertSame(PHPImpersonate::ENGINE_FFI, $this->ffi('firefox147')->engine());
    }

    public function testGetReturnsImpersonatedUserAgent(): void
    {
        $response = $this->ffi('firefox147')->sendGet(self::API . '/get');

        $this->assertSame(200, $response->status());
        $ua = $response->json()['headers']['User-Agent'] ?? '';
        $this->assertStringContainsString('Firefox/147', $ua);
    }

    public function testPostJsonBody(): void
    {
        $response = $this->ffi('chrome146')->sendPost(self::API . '/post', ['name' => 'x', 'n' => 2], [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertEquals(['name' => 'x', 'n' => 2], $response->json()['json']);
    }

    public function testCustomHeaderIsSent(): void
    {
        $response = $this->ffi('chrome146')->sendGet(self::API . '/headers', ['X-Custom' => 'abc']);
        $this->assertSame('abc', $response->json()['headers']['X-Custom'] ?? null);
    }

    public function testHeadHasEmptyBody(): void
    {
        $response = $this->ffi('chrome146')->sendHead(self::API . '/any');
        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    public function testCompressedResponseIsDecoded(): void
    {
        try {
            $response = $this->ffi('chrome146')->sendGet('https://httpbin.org/gzip');
        } catch (\Throwable $e) {
            $this->markTestSkipped('httpbin.org unreachable: ' . $e->getMessage());
        }
        if ($response->status() !== 200) {
            $this->markTestSkipped('httpbin.org returned ' . $response->status());
        }
        $this->assertIsArray($response->json()); // json() would throw if still gzip
    }

    public function testConnectionIsReusedAcrossRequests(): void
    {
        $client = $this->ffi('chrome146');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $client->sendGet(self::API . '/get')->status());
        }
    }

    public function testBinaryBodyIsSentVerbatim(): void
    {
        // A body with an embedded NUL byte must not be truncated (regression: B1).
        $body = "AB\0CDEFGH"; // 9 bytes, NUL at index 2
        $response = $this->ffi('chrome146')->send(new Request(
            'POST',
            self::API . '/anything',
            ['Content-Type' => 'application/octet-stream'],
            $body
        ));

        $this->assertSame(200, $response->status());
        $this->assertSame('9', $response->json()['headers']['Content-Length'] ?? null);
    }

    public function testProxyOptionIsApplied(): void
    {
        // An unreachable proxy must fail — proving the proxy option takes effect.
        $client = $this->ffi('chrome146', 5, ['proxy' => 'http://127.0.0.1:9']);

        $this->expectException(RequestException::class);
        $client->sendGet(self::API . '/get');
    }
}
