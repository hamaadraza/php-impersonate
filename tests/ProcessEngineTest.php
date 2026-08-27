<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;

/**
 * Live tests pinned to the executable engine, so the process path stays covered
 * end-to-end even in environments where 'auto' would pick the FFI engine.
 */
class ProcessEngineTest extends TestCase
{
    private const API = 'https://httpbun.com';

    /**
     * @param array<string,mixed> $curlOptions
     */
    private function process(string $browser, int $timeout = 30, array $curlOptions = []): PHPImpersonate
    {
        return new PHPImpersonate($browser, $timeout, $curlOptions, PHPImpersonate::ENGINE_PROCESS);
    }

    public function testEngineIsProcess(): void
    {
        $this->assertSame(PHPImpersonate::ENGINE_PROCESS, $this->process('firefox147')->engine());
    }

    public function testGetReturnsImpersonatedUserAgent(): void
    {
        $response = $this->process('firefox147')->sendGet(self::API . '/get');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Firefox/147', $response->json()['headers']['User-Agent'] ?? '');
    }

    public function testPostJsonBody(): void
    {
        $response = $this->process('chrome146')->sendPost(self::API . '/post', ['name' => 'x', 'n' => 2], [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertEquals(['name' => 'x', 'n' => 2], $response->json()['json']);
    }

    public function testCustomHeaderIsSent(): void
    {
        $response = $this->process('chrome146')->sendGet(self::API . '/headers', ['X-Custom' => 'abc']);
        $this->assertSame('abc', $response->json()['headers']['X-Custom'] ?? null);
    }

    public function testHeadHasEmptyBody(): void
    {
        $response = $this->process('chrome146')->sendHead(self::API . '/any');
        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    public function testBinaryBodyIsSentVerbatim(): void
    {
        // A body with an embedded NUL byte must not be truncated (regression: B1).
        $body = "AB\0CDEFGH"; // 9 bytes, NUL at index 2
        $response = $this->process('chrome146')->send(new Request(
            'POST',
            self::API . '/anything',
            ['Content-Type' => 'application/octet-stream'],
            $body
        ));

        $this->assertSame(200, $response->status());
        $this->assertSame('9', $response->json()['headers']['Content-Length'] ?? null);
    }
}
