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
        ];
    }

    #[DataProvider('browserProvider')]
    public function testBothEnginesProduceIdenticalJa4(string $browser): void
    {
        $ffi = $this->ja4($browser, PHPImpersonate::ENGINE_FFI);
        $process = $this->ja4($browser, PHPImpersonate::ENGINE_PROCESS);

        $this->assertSame(
            $ffi,
            $process,
            "$browser: the executable and FFI engines produced different JA4 fingerprints"
        );
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

    private function ja4(string $browser, string $engine): string
    {
        try {
            $ja4 = (new PHPImpersonate($browser, 30, [], $engine))
                ->sendGet(TestServer::tls())->json()['tls']['ja4'] ?? null;
        } catch (\Throwable $e) {
            $this->markTestSkipped('TLS-fingerprint service unreachable: ' . $e->getMessage());
        }
        if (! is_string($ja4) || $ja4 === '') {
            $this->markTestSkipped('TLS-fingerprint service returned no JA4');
        }

        return $ja4;
    }
}
