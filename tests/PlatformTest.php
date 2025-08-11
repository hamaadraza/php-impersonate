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
     * Test platform support check
     */
    public function testPlatformSupport()
    {
        $isSupported = PlatformDetector::isSupported();

        $this->assertIsBool($isSupported);

        // Should be true for Linux and Windows, false for macOS (currently)
        $platform = PlatformDetector::getPlatform();
        if (in_array($platform, [PlatformDetector::PLATFORM_LINUX, PlatformDetector::PLATFORM_WINDOWS])) {
            $this->assertTrue($isSupported);
        } else {
            $this->assertFalse($isSupported);
        }
    }

    /**
     * Test binary directory path
     */
    public function testBinaryDirectory()
    {
        $binaryDir = PlatformDetector::getBinaryDir();

        $this->assertIsString($binaryDir);
        $this->assertStringStartsWith('bin/', $binaryDir);

        $platform = PlatformDetector::getPlatform();
        $expectedDir = "bin/{$platform}";
        $this->assertEquals($expectedDir, $binaryDir);
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
            $this->assertEquals('.bat', $extension);
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
}
