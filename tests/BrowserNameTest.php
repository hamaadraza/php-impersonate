<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\BrowserName;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Exception\InvalidArgumentException;

/**
 * Old browser releases are a detection signal in their own right, so the
 * pre-2024 profiles are deprecated and BrowserName::latest() names a current
 * one per family.
 */
class BrowserNameTest extends TestCase
{
    public function testEveryDeprecatedNameIsARealProfile(): void
    {
        foreach (BrowserName::DEPRECATED as $name) {
            $this->assertContains($name, BrowserName::getAll(), "$name is deprecated but not a known profile");
            $this->assertTrue(BrowserConfig::hasConfig($name));
            $this->assertTrue(BrowserName::isDeprecated($name));
        }
    }

    public function testTheDefaultBrowserIsNotDeprecated(): void
    {
        $this->assertFalse(BrowserName::isDeprecated(\Raza\PHPImpersonate\PHPImpersonate::DEFAULT_BROWSER));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function latestProvider(): array
    {
        return [
            'chrome desktop' => ['chrome', 'chrome150'],
            'chrome android' => ['chrome_android', 'chrome131_android'],
            'firefox' => ['firefox', 'firefox147'],
            'safari desktop, 26.0.1 sorts after 26.0' => ['safari', 'safari2601'],
            'safari ios' => ['safari_ios', 'safari260_ios'],
            'tor' => ['tor', 'tor145'],
            'okhttp' => ['okhttp_android', 'okhttp4_android'],
            'case-insensitive' => ['Firefox', 'firefox147'],
        ];
    }

    #[DataProvider('latestProvider')]
    public function testLatestNamesTheNewestCurrentProfile(string $family, string $expected): void
    {
        $this->assertSame($expected, BrowserName::latest($family));
        $this->assertFalse(BrowserName::isDeprecated(BrowserName::latest($family)));
    }

    public function testADesktopFamilyNeverAnswersWithAMobileProfile(): void
    {
        $this->assertStringNotContainsString('_', BrowserName::latest('chrome'));
        $this->assertStringNotContainsString('_', BrowserName::latest('safari'));
    }

    public function testAFamilyWithOnlyDeprecatedProfilesThrows(): void
    {
        // Both Edge profiles are 2022 releases.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No current profile for browser family 'edge'");

        BrowserName::latest('edge');
    }

    public function testAnUnknownFamilyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BrowserName::latest('netscape');
    }
}
