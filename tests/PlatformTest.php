<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Platform\PlatformDetector;

class PlatformTest extends TestCase
{
    /**
     * Test platform detection
     */
    public function testPlatformDetection()
    {
        $platform = PlatformDetector::getPlatform();

        $this->assertIsString($platform);
        $this->assertContains($platform, [
            PlatformDetector::PLATFORM_LINUX,
            PlatformDetector::PLATFORM_WINDOWS,
            PlatformDetector::PLATFORM_MACOS,
        ]);
    }

    /**
     * Test architecture detection
     */
    public function testArchitectureDetection()
    {
        $arch = PlatformDetector::getArchitecture();

        $this->assertIsString($arch);
        $this->assertContains($arch, [
            PlatformDetector::ARCH_X86_64,
            PlatformDetector::ARCH_AARCH64,
            PlatformDetector::ARCH_UNKNOWN,
        ]);
    }

    /**
     * Test platform support check
     */
    public function testPlatformSupport()
    {
        $isSupported = PlatformDetector::isSupported();

        $this->assertIsBool($isSupported);

        $platform = PlatformDetector::getPlatform();
        $arch = PlatformDetector::getArchitecture();

        // Should be true for supported platforms with known architecture
        $supportedPlatforms = [
            PlatformDetector::PLATFORM_LINUX,
            PlatformDetector::PLATFORM_WINDOWS,
            PlatformDetector::PLATFORM_MACOS,
        ];

        if (in_array($platform, $supportedPlatforms, true) && $arch !== PlatformDetector::ARCH_UNKNOWN) {
            $this->assertTrue($isSupported);
        } else {
            $this->assertFalse($isSupported);
        }
    }

    /**
     * Test binary directory path includes platform and architecture
     */
    public function testBinaryDirectory()
    {
        $binaryDir = PlatformDetector::getBinaryDir();

        $this->assertIsString($binaryDir);
        $this->assertStringStartsWith('bin/', $binaryDir);

        $platform = PlatformDetector::getPlatform();
        $arch = PlatformDetector::getArchitecture();

        // Binary dir should contain both platform and architecture
        $this->assertStringContainsString($platform, $binaryDir);

        if ($arch !== PlatformDetector::ARCH_UNKNOWN) {
            $this->assertStringContainsString($arch, $binaryDir);
        }

        // Verify format: bin/{platform}-{arch}[-musl]
        $suffix = PlatformDetector::getBinaryDirSuffix();
        $this->assertEquals("bin/{$suffix}", $binaryDir);
    }

    /**
     * Test binary directory fallbacks
     */
    public function testBinaryDirFallbacks()
    {
        $fallbacks = PlatformDetector::getBinaryDirFallbacks();

        $this->assertIsArray($fallbacks);
        $this->assertNotEmpty($fallbacks);

        // First fallback should be the primary (platform-arch[-musl])
        $this->assertEquals(PlatformDetector::getBinaryDirSuffix(), $fallbacks[0]);

        // Last fallback should be just the platform name (legacy)
        $platform = PlatformDetector::getPlatform();
        $this->assertEquals($platform, end($fallbacks));
    }

    /**
     * The libc is read from what the running process has MAPPED, not from
     * which loader files exist on disk: a glibc host with the `musl` package
     * installed (musl-tools, common on CI images) has /lib/ld-musl-x86_64.so.1
     * and used to be detected as musl, so the musl build of the shared library
     * was dlopen'ed into a glibc process.
     */
    public function testLibcIsReadFromTheProcessMemoryMap(): void
    {
        $probe = new \ReflectionMethod(PlatformDetector::class, 'libcFromMaps');

        $glibc = "7f1a0000-7f1a1000 r--p 00000000 fd:01 1234 /usr/lib/x86_64-linux-gnu/libc.so.6\n"
            . "7f1b0000-7f1b1000 r--p 00000000 fd:01 1235 /usr/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2\n";
        $musl = "7f1a0000-7f1a1000 r--p 00000000 fd:01 1234 /lib/ld-musl-x86_64.so.1\n";
        $muslViaSymlink = "7f1a0000-7f1a1000 r--p 00000000 fd:01 1234 /lib/libc.musl-aarch64.so.1\n";
        $static = "00400000-00401000 r--p 00000000 fd:01 1234 /usr/local/bin/php\n";

        $this->assertSame(PlatformDetector::LIBC_GNU, $probe->invoke(null, $glibc));
        $this->assertSame(PlatformDetector::LIBC_MUSL, $probe->invoke(null, $musl));
        $this->assertSame(PlatformDetector::LIBC_MUSL, $probe->invoke(null, $muslViaSymlink));
        $this->assertNull($probe->invoke(null, $static), 'a statically linked php names no libc');
        $this->assertNull($probe->invoke(null, ''));

        // A musl loader merely PRESENT on disk is not what the process runs on.
        $glibcWithMuslInstalled = $glibc . "7f1c0000-7f1c1000 r--p 00000000 fd:01 1236 /usr/lib/x86_64-linux-gnu/libm.so.6\n";
        $this->assertSame(PlatformDetector::LIBC_GNU, $probe->invoke(null, $glibcWithMuslInstalled));
    }

    /**
     * On a real Linux the memory-map answer and the public answer agree.
     */
    public function testTheMemoryMapAgreesWithTheDetectedLibc(): void
    {
        if (! PlatformDetector::isLinux() || ! is_readable('/proc/self/maps')) {
            $this->markTestSkipped('needs a Linux /proc');
        }

        $probe = new \ReflectionMethod(PlatformDetector::class, 'libcFromMaps');
        $fromMaps = $probe->invoke(null, (string) file_get_contents('/proc/self/maps'));

        if ($fromMaps === null) {
            $this->markTestSkipped('this php maps no libc (static build)');
        }

        $this->assertSame($fromMaps, PlatformDetector::getLibcType());
    }

    /**
     * Test libc type detection on Linux
     */
    public function testLibcTypeDetection()
    {
        $libcType = PlatformDetector::getLibcType();

        $this->assertIsString($libcType);
        $this->assertContains($libcType, [
            PlatformDetector::LIBC_GNU,
            PlatformDetector::LIBC_MUSL,
        ]);
    }

    /**
     * Test platform description
     */
    public function testPlatformDescription()
    {
        $description = PlatformDetector::getPlatformDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);

        $platform = PlatformDetector::getPlatform();
        $arch = PlatformDetector::getArchitecture();

        $this->assertStringContainsString($platform, $description);
        $this->assertStringContainsString($arch, $description);
    }

    /**
     * Test supported architectures list
     */
    public function testSupportedArchitectures()
    {
        $architectures = PlatformDetector::getSupportedArchitectures();

        $this->assertIsArray($architectures);
        $this->assertContains(PlatformDetector::ARCH_X86_64, $architectures);
        $this->assertContains(PlatformDetector::ARCH_AARCH64, $architectures);
    }

    /**
     * Test helper methods
     */
    public function testHelperMethods()
    {
        $platform = PlatformDetector::getPlatform();

        $this->assertEquals($platform === PlatformDetector::PLATFORM_WINDOWS, PlatformDetector::isWindows());
        $this->assertEquals($platform === PlatformDetector::PLATFORM_LINUX, PlatformDetector::isLinux());
        $this->assertEquals($platform === PlatformDetector::PLATFORM_MACOS, PlatformDetector::isMacOS());
    }
}
