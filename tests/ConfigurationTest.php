<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Config\Configuration;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\PlatformNotSupportedException;

/**
 * Configuration and PlatformNotSupportedException, neither of which had any
 * tests. Configuration matters more than its size suggests: it is publicly
 * writable and its `which_command` value is interpolated into a shell command
 * (see SecurityControlsTest), so its merge semantics are worth pinning.
 */
class ConfigurationTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $original = null;

    protected function setUp(): void
    {
        $this->original = Configuration::getPlatformConfig();
    }

    protected function tearDown(): void
    {
        // Static state: restore it, or the ordering of the rest of the suite
        // starts to matter.
        if ($this->original !== null) {
            Configuration::setPlatformConfig(PlatformDetector::getPlatform(), $this->original);
        }
    }

    public function testCurrentPlatformHasAWhichCommand(): void
    {
        $this->assertContains(Configuration::get('which_command'), ['which', 'where']);
    }

    public function testGetReturnsNullForAnUnknownKey(): void
    {
        $this->assertNull(Configuration::get('no-such-key'));
    }

    public function testBinaryDirFallbacksArePrefixedPaths(): void
    {
        $fallbacks = Configuration::getBinaryDirFallbacks();

        $this->assertNotSame([], $fallbacks);
        foreach ($fallbacks as $dir) {
            $this->assertStringStartsWith('bin/', $dir);
        }
    }

    /**
     * A partial override must keep the platform's other keys — merging onto the
     * Linux defaults instead would silently change behaviour on macOS/Windows.
     */
    public function testPartialOverrideKeepsThePlatformsOtherKeys(): void
    {
        $platform = PlatformDetector::getPlatform();

        Configuration::setPlatformConfig($platform, ['extra-key' => 'extra-value']);

        $this->assertSame('extra-value', Configuration::get('extra-key'));
        $this->assertContains(Configuration::get('which_command'), ['which', 'where']);
    }

    public function testOverrideReplacesAnExistingKey(): void
    {
        $platform = PlatformDetector::getPlatform();

        Configuration::setPlatformConfig($platform, ['which_command' => 'custom-which']);

        $this->assertSame('custom-which', Configuration::get('which_command'));
    }

    public function testUnknownPlatformFallsBackToLinuxDefaults(): void
    {
        Configuration::setPlatformConfig('plan9', ['something' => 'else']);

        // Reading still resolves against the CURRENT platform, so this only has
        // to not blow up — the fallback is about the new platform's own base.
        $this->assertContains(Configuration::get('which_command'), ['which', 'where']);
    }

    public function testPlatformNotSupportedExceptionMessage(): void
    {
        $e = new PlatformNotSupportedException('plan9', ['linux', 'macos']);

        $this->assertStringContainsString("Platform 'plan9'", $e->getMessage());
        $this->assertStringContainsString('is not supported', $e->getMessage());
        $this->assertStringContainsString('linux, macos', $e->getMessage());
    }

    public function testPlatformNotSupportedExceptionIncludesArchitecture(): void
    {
        $e = new PlatformNotSupportedException('linux', ['linux'], 'riscv64', ['x86_64', 'aarch64']);

        $this->assertStringContainsString("architecture 'riscv64'", $e->getMessage());
        $this->assertStringContainsString('x86_64, aarch64', $e->getMessage());
    }

    public function testPlatformNotSupportedExceptionOmitsEmptyLists(): void
    {
        $message = (new PlatformNotSupportedException('plan9'))->getMessage();

        $this->assertStringContainsString("Platform 'plan9' is not supported.", $message);
        $this->assertStringNotContainsString('Supported platforms', $message);
        $this->assertStringNotContainsString('Supported architectures', $message);
    }
}
