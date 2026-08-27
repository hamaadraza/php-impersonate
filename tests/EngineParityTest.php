<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
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
    private const TLS_API = 'https://tls.peet.ws/api/all';

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

    private function ja4(string $browser, string $engine): string
    {
        try {
            $ja4 = (new PHPImpersonate($browser, 30, [], $engine))
                ->sendGet(self::TLS_API)->json()['tls']['ja4'] ?? null;
        } catch (\Throwable $e) {
            $this->markTestSkipped('tls.peet.ws unreachable: ' . $e->getMessage());
        }
        if (! is_string($ja4) || $ja4 === '') {
            $this->markTestSkipped('tls.peet.ws returned no JA4');
        }

        return $ja4;
    }
}
