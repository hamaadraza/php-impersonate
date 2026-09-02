<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Ffi\LibResolver;
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

    /**
     * The reason is the diagnostic that turns "auto silently used the
     * executable" into something an operator can act on.
     */
    public function testTheReasonIsNullExactlyWhenTheEngineIsAvailable(): void
    {
        $reason = PHPImpersonate::ffiUnavailableReason();

        if (PHPImpersonate::ffiAvailable()) {
            $this->assertNull($reason);
        } else {
            $this->assertIsString($reason);
            $this->assertNotSame('', $reason);
        }
    }

    public function testABrokenLibraryIsReportedWithItsPath(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('Needs a working FFI engine to break.');
        }

        $saved = getenv(LibResolver::ENV_VAR);

        try {
            $bogus = (string) tempnam(sys_get_temp_dir(), 'not_a_library');
            file_put_contents($bogus, "definitely not an ELF object\n");
            putenv(LibResolver::ENV_VAR . '=' . $bogus);
            LibResolver::clearCache();

            $this->assertFalse(PHPImpersonate::ffiAvailable());
            $this->assertStringContainsString($bogus, (string) PHPImpersonate::ffiUnavailableReason());
            $this->assertStringContainsString('could not be loaded', (string) PHPImpersonate::ffiUnavailableReason());
        } finally {
            $saved === false ? putenv(LibResolver::ENV_VAR) : putenv(LibResolver::ENV_VAR . "=$saved");
            LibResolver::clearCache();
            if (isset($bogus)) {
                @unlink($bogus);
            }
        }
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

    /**
     * A negative probe must not outlive the library resolution it was based on.
     * The probe used to be cached unconditionally, so once ffiAvailable() had
     * returned false it kept returning false for the life of the process — even
     * after LibResolver::clearCache(), which exists precisely so a library
     * installed mid-process gets picked up.
     */
    public function testProbeIsReevaluatedWhenTheResolvedLibraryChanges(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('Needs a working FFI engine to invalidate.');
        }

        $saved = getenv(LibResolver::ENV_VAR);

        try {
            // Point at a library that cannot load, and re-resolve.
            $bogus = (string) tempnam(sys_get_temp_dir(), 'not_a_library');
            file_put_contents($bogus, "definitely not an ELF object\n");
            putenv(LibResolver::ENV_VAR . '=' . $bogus);
            LibResolver::clearCache();

            $this->assertFalse(PHPImpersonate::ffiAvailable(), 'a broken library must probe as unavailable');

            // Restore, and the probe must recover rather than stay stuck on false.
            $saved === false ? putenv(LibResolver::ENV_VAR) : putenv(LibResolver::ENV_VAR . "=$saved");
            LibResolver::clearCache();
            @unlink($bogus);

            $this->assertTrue(PHPImpersonate::ffiAvailable(), 'the probe must re-run once the library resolves again');
        } finally {
            $saved === false ? putenv(LibResolver::ENV_VAR) : putenv(LibResolver::ENV_VAR . "=$saved");
            LibResolver::clearCache();
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
