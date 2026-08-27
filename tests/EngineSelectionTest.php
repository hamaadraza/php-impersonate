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

    public function testAutoUsesProcessForFfiUnsupportedOptions(): void
    {
        // 'insecure' is a valid executable option the FFI engine can't map,
        // so auto must stay on the process engine.
        $client = new PHPImpersonate('chrome136', 30, ['insecure' => true]);
        $this->assertSame(PHPImpersonate::ENGINE_PROCESS, $client->engine());
    }

    public function testAutoUsesFfiForProxyWhenAvailable(): void
    {
        // Proxy options ARE supported by the FFI engine.
        $expected = PHPImpersonate::ffiAvailable()
            ? PHPImpersonate::ENGINE_FFI
            : PHPImpersonate::ENGINE_PROCESS;

        $client = new PHPImpersonate('chrome136', 30, ['proxy' => 'http://127.0.0.1:8080']);
        $this->assertSame($expected, $client->engine());
    }

    public function testForcedFfiRejectsUnsupportedOption(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support');
        new PHPImpersonate('chrome136', 30, ['insecure' => true], PHPImpersonate::ENGINE_FFI);
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
