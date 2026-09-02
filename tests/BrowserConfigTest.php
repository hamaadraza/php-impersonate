<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\BrowserName;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Browser\BrowserConfig;

class BrowserConfigTest extends TestCase
{
    /**
     * BrowserName::getAll() and BrowserConfig must list exactly the same set of
     * browsers — they are two hand/generator-maintained lists and can drift (T3).
     */
    public function testBrowserNameAndConfigListsAreInParity(): void
    {
        $configs = array_keys(BrowserConfig::getAllConfigs());
        $names = BrowserName::getAll();

        sort($configs);
        sort($names);

        $this->assertSame(
            $configs,
            $names,
            'BrowserName::getAll() and BrowserConfig::getAllConfigs() must match'
        );
    }

    /**
     * Modern Chrome/Firefox negotiate ECH; every one of these profiles must carry
     * the `ech` option, or the executable engine sends a fingerprint no real
     * browser sends (regression guard for the missing-ECH bug).
     */
    public function testModernChromeAndFirefoxDeclareEch(): void
    {
        $mustHaveEch = ['chrome142', 'chrome145', 'chrome146', 'chrome150', 'firefox144', 'firefox147'];

        foreach ($mustHaveEch as $browser) {
            $options = BrowserConfig::getConfig($browser)['options'];
            $this->assertArrayHasKey('ech', $options, "$browser must declare the ech option");
            $this->assertSame('grease', $options['ech'], "$browser ech should be 'grease'");
        }
    }

    /**
     * Guard actual config values, not just structure: a non-empty cipher list and
     * a User-Agent that matches the browser it claims to be (T2).
     */
    public function testConfigValuesAreConsistent(): void
    {
        $expectedUa = [
            'firefox147' => 'Firefox/147',
            'chrome146' => 'Chrome/146',
            'safari184' => 'Version/18.4',
        ];

        foreach (BrowserConfig::getAllConfigs() as $name => $config) {
            $this->assertArrayHasKey('ciphers', $config, "$name missing ciphers");
            $this->assertNotEmpty($config['ciphers'], "$name has empty ciphers");
            $this->assertNotEmpty($config['headers']['User-Agent'] ?? '', "$name missing User-Agent");
        }

        foreach ($expectedUa as $browser => $token) {
            $this->assertStringContainsString(
                $token,
                BrowserConfig::getConfig($browser)['headers']['User-Agent'],
                "$browser User-Agent should contain '$token'"
            );
        }
    }

    /**
     * Test getting all configurations
     */
    public function testGetAllConfigs(): void
    {
        $configs = BrowserConfig::getAllConfigs();

        $this->assertIsArray($configs);
        $this->assertNotEmpty($configs);

        // Check that each browser has the required structure
        foreach ($configs as $browserName => $config) {
            $this->assertIsString($browserName);
            $this->assertIsArray($config);

            // Check for required sections
            $this->assertArrayHasKey('headers', $config);
            $this->assertArrayHasKey('options', $config);
            $this->assertIsArray($config['headers']);
            $this->assertIsArray($config['options']);
        }
    }

    /**
     * Test getting specific browser configuration
     */
    public function testGetConfig(): void
    {
        $config = BrowserConfig::getConfig('chrome99');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('ciphers', $config);
        $this->assertArrayHasKey('headers', $config);
        $this->assertArrayHasKey('options', $config);
        $this->assertArrayHasKey('User-Agent', $config['headers']);
        $this->assertStringContainsString('Chrome/99', $config['headers']['User-Agent']);
    }

    /**
     * Test getting non-existent browser configuration
     */
    public function testGetConfigNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Browser configuration not found: nonexistent');

        BrowserConfig::getConfig('nonexistent');
    }

    /**
     * Test getting available browsers
     */
    public function testGetAvailableBrowsers(): void
    {
        $browsers = BrowserConfig::getAvailableBrowsers();

        $this->assertIsArray($browsers);
        $this->assertNotEmpty($browsers);
        $this->assertContains('chrome99', $browsers);
        $this->assertContains('firefox133', $browsers);
        $this->assertContains('safari260', $browsers);
    }

    /**
     * Test checking if browser configuration exists
     */
    public function testHasConfig(): void
    {
        $this->assertTrue(BrowserConfig::hasConfig('chrome99'));
        $this->assertTrue(BrowserConfig::hasConfig('firefox133'));
        $this->assertFalse(BrowserConfig::hasConfig('nonexistent'));
    }

    /**
     * Test browser configurations have required fields
     */
    public function testBrowserConfigStructure(): void
    {
        $configs = BrowserConfig::getAllConfigs();

        foreach ($configs as $browserName => $config) {
            // All browsers should have headers and options
            $this->assertArrayHasKey('headers', $config, "Browser {$browserName} missing headers");
            $this->assertArrayHasKey('options', $config, "Browser {$browserName} missing options");

            // All browsers should have User-Agent header
            $this->assertArrayHasKey('User-Agent', $config['headers'], "Browser {$browserName} missing User-Agent");

            // All browsers should have http2 option
            $this->assertArrayHasKey('http2', $config['options'], "Browser {$browserName} missing http2 option");
            $this->assertTrue($config['options']['http2'], "Browser {$browserName} http2 should be true");
        }
    }

    /**
     * Cross-checks the client hints, the platform hint and the User-Agent
     * against each other for EVERY profile.
     *
     * Nothing did this, which is how two incoherent profiles shipped:
     * chrome131_android declares `sec-ch-ua-mobile: ?0` beside an "Android"
     * platform and a Mobile UA (no real mobile Chrome sends ?0), and
     * okhttp4_android carries a desktop macOS Safari UA over an okhttp TLS
     * profile. Both are inherited verbatim from upstream's own generated data,
     * so they are recorded as known-bad rather than asserted away — the point of
     * the test is that NOTHING NEW joins them, and that these two turn up here
     * the moment an upstream sync fixes them.
     *
     * @return array<string, array{0: string, 1: array<string,mixed>}>
     */
    public static function profileProvider(): array
    {
        $out = [];
        foreach (BrowserConfig::getAllConfigs() as $name => $config) {
            $out[$name] = [$name, $config];
        }

        return $out;
    }

    /** Profiles whose incoherence comes from upstream; see the docblock above. */
    private const KNOWN_INCOHERENT = ['chrome131_android', 'okhttp4_android'];

    #[DataProvider('profileProvider')]
    public function testClientHintsAgreeWithTheUserAgent(string $name, array $config): void
    {
        /** @var array<string,string> $headers */
        $headers = $config['headers'] ?? [];

        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower((string) $k)] = (string) $v;
        }

        $ua = $lower['user-agent'] ?? '';
        $this->assertNotSame('', $ua, "$name has no User-Agent");

        $problems = [];

        // A mobile UA and the mobile client hint must agree.
        if (isset($lower['sec-ch-ua-mobile'])) {
            $uaSaysMobile = str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone');
            $hintSaysMobile = $lower['sec-ch-ua-mobile'] === '?1';
            if ($uaSaysMobile !== $hintSaysMobile) {
                $problems[] = sprintf(
                    'sec-ch-ua-mobile is %s but the User-Agent says %s',
                    $lower['sec-ch-ua-mobile'],
                    $uaSaysMobile ? 'mobile' : 'desktop'
                );
            }
        }

        // The platform hint must name the OS the UA names.
        if (isset($lower['sec-ch-ua-platform'])) {
            $platform = strtolower(trim($lower['sec-ch-ua-platform'], '"'));
            $expected = [
                'android' => ['Android'],
                'windows' => ['Windows'],
                'macos' => ['Mac OS X', 'Macintosh'],
                'linux' => ['Linux'],
                'ios' => ['iPhone', 'iPad', 'CPU OS'],
            ];
            if (isset($expected[$platform])) {
                $matched = false;
                foreach ($expected[$platform] as $token) {
                    if (str_contains($ua, $token)) {
                        $matched = true;

                        break;
                    }
                }
                if (! $matched) {
                    $problems[] = sprintf(
                        'sec-ch-ua-platform is "%s" but the User-Agent names none of: %s',
                        $platform,
                        implode(', ', $expected[$platform])
                    );
                }
            }
        }

        // An _android profile must not ship a desktop browser UA.
        if (str_ends_with($name, '_android') && str_contains($ua, 'Macintosh')) {
            $problems[] = 'an _android profile carries a macOS desktop User-Agent';
        }

        if (in_array($name, self::KNOWN_INCOHERENT, true)) {
            $this->assertNotSame(
                [],
                $problems,
                "$name is recorded as known-bad but now looks coherent — "
                . 'upstream has evidently fixed it, so drop it from KNOWN_INCOHERENT.'
            );

            return;
        }

        $this->assertSame([], $problems, "$name: " . implode('; ', $problems));
    }
}
