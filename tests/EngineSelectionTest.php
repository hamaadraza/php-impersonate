<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Covers how the single PHPImpersonate entry point selects its engine.
 */
class EngineSelectionTest extends TestCase
{
    public function testFfiAvailableReturnsBool(): void
    {
        $this->assertIsBool(PHPImpersonate::ffiAvailable());
    }

    public function testDefaultEngineMatchesAvailability(): void
    {
        $expected = PHPImpersonate::ffiAvailable()
            ? PHPImpersonate::ENGINE_FFI
            : PHPImpersonate::ENGINE_PROCESS;

        $this->assertSame($expected, (new PHPImpersonate('chrome136'))->engine());
    }

    public function testProcessEngineCanBeForced(): void
    {
        $client = new PHPImpersonate('chrome136', 30, [], PHPImpersonate::ENGINE_PROCESS);
        $this->assertSame(PHPImpersonate::ENGINE_PROCESS, $client->engine());
    }

    public function testUnknownEngineThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PHPImpersonate('chrome136', 30, [], 'bogus');
    }

    public function testAutoUsesFfiWhenAvailableRegardlessOfOptions(): void
    {
        // Every supported option works on both engines, so auto just prefers FFI.
        $expected = PHPImpersonate::ffiAvailable()
            ? PHPImpersonate::ENGINE_FFI
            : PHPImpersonate::ENGINE_PROCESS;

        foreach ([[], ['proxy' => 'http://127.0.0.1:8080'], ['insecure' => true]] as $options) {
            $client = new PHPImpersonate('chrome136', 30, $options);
            $this->assertSame($expected, $client->engine());
        }
    }

    /**
     * @return array<string, array{0: array<string,mixed>}>
     */
    public static function unsupportedOptionProvider(): array
    {
        return [
            'fingerprint (ciphers)' => [['ciphers' => 'x']],
            'fingerprint (user-agent)' => [['user-agent' => 'x']],
            'internal (output)' => [['output' => '/tmp/x']],
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedOptionProvider')]
    public function testUnsupportedOptionIsRejectedOnBothEngines(array $options): void
    {
        foreach ([PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS] as $engine) {
            if ($engine === PHPImpersonate::ENGINE_FFI && ! PHPImpersonate::ffiAvailable()) {
                continue;
            }

            try {
                new PHPImpersonate('chrome136', 30, $options, $engine);
                $this->fail("$engine accepted an unsupported option");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsupported curl option', $e->getMessage());
            }
        }
    }

    public function testForcedFfiThrowsWhenUnavailable(): void
    {
        if (PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine is available here; cannot test the unavailable path.');
        }

        $this->expectException(RequestException::class);
        new PHPImpersonate('chrome136', 30, [], PHPImpersonate::ENGINE_FFI);
    }
}
