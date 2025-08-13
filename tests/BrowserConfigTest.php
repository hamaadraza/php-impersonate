<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\BrowserConfig;

class BrowserConfigTest extends TestCase
{
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
}
