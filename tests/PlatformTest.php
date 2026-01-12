<?php

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
     * Test file extension
     */
    public function testFileExtension()
    {
        $extension = PlatformDetector::getFileExtension();

        $this->assertIsString($extension);

        $platform = PlatformDetector::getPlatform();
        if ($platform === PlatformDetector::PLATFORM_WINDOWS) {
            $this->assertEquals('.exe', $extension);
        } else {
            $this->assertEquals('', $extension);
        }
    }

    /**
     * Test command separator
     */
    public function testCommandSeparator()
    {
        $separator = PlatformDetector::getCommandSeparator();

        $this->assertIsString($separator);

        $platform = PlatformDetector::getPlatform();
        if ($platform === PlatformDetector::PLATFORM_WINDOWS) {
            $this->assertEquals('^', $separator);
        } else {
            $this->assertEquals('\\', $separator);
        }
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
