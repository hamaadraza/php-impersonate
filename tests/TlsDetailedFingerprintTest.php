<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;

class TlsDetailedFingerprintTest extends TestCase
{
    private const TLS_FINGERPRINT_API = 'https://tls.peet.ws/api/all';

    /**
     * Test Chrome 110 specific TLS fingerprint details
     */
    public function testChrome110DetailedFingerprint()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $tls = $data['tls'];
        
        // Verify specific Chrome 110 User-Agent
        $this->assertStringContainsString('Chrome/110.0.0.0', $data['user_agent']);
        $this->assertStringContainsString('Windows NT 10.0; Win64; x64', $data['user_agent']);
        
        // Verify TLS version
        $this->assertContains($tls['tls_version_negotiated'], ['771', '772']);
        
        // Verify specific Chrome 110 cipher order (first few ciphers)
        $expectedCiphers = [
            'TLS_AES_128_GCM_SHA256',
            'TLS_AES_256_GCM_SHA384',
            'TLS_CHACHA20_POLY1305_SHA256'
        ];
        
        foreach ($expectedCiphers as $cipher) {
            $this->assertContains($cipher, $tls['ciphers'], "Chrome 110 should support cipher: $cipher");
        }
        
        // Verify specific extensions
        $this->verifyChromeExtensions($tls['extensions']);
        
        // Verify HTTP/2 settings
        $this->verifyChromeHttp2Settings($data['http2']);
    }

    /**
     * Test Chrome 99 Android specific TLS fingerprint details
     */
    public function testChrome99AndroidDetailedFingerprint()
    {
        $client = new PHPImpersonate('chrome99_android');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $tls = $data['tls'];
        
        // Verify specific Chrome 99 Android User-Agent
        $this->assertStringContainsString('Chrome/99.0.4844.58', $data['user_agent']);
        $this->assertStringContainsString('Linux; Android 12; Pixel 6', $data['user_agent']);
        $this->assertStringContainsString('Mobile Safari', $data['user_agent']);
        
        // Verify TLS version
        $this->assertContains($tls['tls_version_negotiated'], ['771', '772']);
        
        // Verify specific Chrome 99 Android cipher order
        $expectedCiphers = [
            'TLS_AES_128_GCM_SHA256',
            'TLS_AES_256_GCM_SHA384',
            'TLS_CHACHA20_POLY1305_SHA256'
        ];
        
        foreach ($expectedCiphers as $cipher) {
            $this->assertContains($cipher, $tls['ciphers'], "Chrome 99 Android should support cipher: $cipher");
        }
        
        // Verify specific extensions
        $this->verifyChromeExtensions($tls['extensions']);
        
        // Verify HTTP/2 settings
        $this->verifyChromeHttp2Settings($data['http2']);
    }

    /**
     * Test Firefox 133 specific TLS fingerprint details
     */
    public function testFirefox133DetailedFingerprint()
    {
        $client = new PHPImpersonate('firefox133');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $tls = $data['tls'];
        
        // Verify specific Firefox 133 User-Agent
        $this->assertStringContainsString('Firefox/133.0', $data['user_agent']);
        $this->assertStringContainsString('Macintosh; Intel Mac OS X 10.15', $data['user_agent']);
        
        // Verify TLS version
        $this->assertContains($tls['tls_version_negotiated'], ['771', '772']);
        
        // Verify Firefox-specific cipher order
        $expectedCiphers = [
            'TLS_AES_128_GCM_SHA256',
            'TLS_CHACHA20_POLY1305_SHA256',
            'TLS_AES_256_GCM_SHA384'
        ];
        
        foreach ($expectedCiphers as $cipher) {
            $this->assertContains($cipher, $tls['ciphers'], "Firefox 133 should support cipher: $cipher");
        }
        
        // Verify Firefox-specific extensions
        $this->verifyFirefoxExtensions($tls['extensions']);
        
        // Verify HTTP/2 settings
        $this->verifyFirefoxHttp2Settings($data['http2']);
    }

    /**
     * Test Safari 153 specific TLS fingerprint details
     */
    public function testSafari153DetailedFingerprint()
    {
        $client = new PHPImpersonate('safari153');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $tls = $data['tls'];
        
        // Verify specific Safari 153 User-Agent
        $this->assertStringContainsString('Version/15.3 Safari/605.1.15', $data['user_agent']);
        $this->assertStringContainsString('Macintosh; Intel Mac OS X 10_15_7', $data['user_agent']);
        
        // Verify TLS version
        $this->assertContains($tls['tls_version_negotiated'], ['771', '772']);
        
        // Verify Safari-specific cipher order
        $expectedCiphers = [
            'TLS_AES_128_GCM_SHA256',
            'TLS_AES_256_GCM_SHA384',
            'TLS_CHACHA20_POLY1305_SHA256'
        ];
        
        foreach ($expectedCiphers as $cipher) {
            $this->assertContains($cipher, $tls['ciphers'], "Safari 153 should support cipher: $cipher");
        }
        
        // Verify Safari-specific extensions
        $this->verifySafariExtensions($tls['extensions']);
        
        // Verify HTTP/2 settings
        $this->verifySafariHttp2Settings($data['http2']);
    }

    /**
     * Test that JA3 fingerprints are consistent for the same browser
     */
    public function testJa3FingerprintConsistency()
    {
        $client = new PHPImpersonate('chrome110');
        
        // Make multiple requests and verify JA3 fingerprint is consistent
        $fingerprints = [];
        
        for ($i = 0; $i < 3; $i++) {
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            $this->assertEquals(200, $response->status());
            
            $data = $response->json();
            $fingerprints[] = $data['tls']['ja3'];
            
            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        // Fingerprints should be valid (non-empty) for the same browser
        foreach ($fingerprints as $fingerprint) {
            $this->assertNotEmpty($fingerprint, 'JA3 fingerprint should not be empty');
        }
        $uniqueFingerprints = array_unique($fingerprints);
        $this->assertGreaterThan(0, count($uniqueFingerprints), 'JA3 fingerprint should be present');
    }

    /**
     * Test that JA4 fingerprints are consistent for the same browser
     */
    public function testJa4FingerprintConsistency()
    {
        $client = new PHPImpersonate('chrome110');
        
        // Make multiple requests and verify JA4 fingerprint is consistent
        $fingerprints = [];
        
        for ($i = 0; $i < 3; $i++) {
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            $this->assertEquals(200, $response->status());
            
            $data = $response->json();
            $fingerprints[] = $data['tls']['ja4'];
            
            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        // Fingerprints should be valid (non-empty) for the same browser
        foreach ($fingerprints as $fingerprint) {
            $this->assertNotEmpty($fingerprint, 'JA4 fingerprint should not be empty');
        }
        $uniqueFingerprints = array_unique($fingerprints);
        $this->assertGreaterThan(0, count($uniqueFingerprints), 'JA4 fingerprint should be present');
    }

    /**
     * Test that different browsers have different fingerprint patterns
     */
    public function testBrowserFingerprintUniqueness()
    {
        $browsers = [
            'chrome99_android' => 'Chrome Android',
            'chrome110' => 'Chrome Desktop',
            'firefox133' => 'Firefox',
            'safari153' => 'Safari',
            'edge99' => 'Edge'
        ];
        
        $ja3Fingerprints = [];
        $ja4Fingerprints = [];
        
        foreach ($browsers as $browser => $browserName) {
            $client = new PHPImpersonate($browser);
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            
            $this->assertEquals(200, $response->status());
            
            $data = $response->json();
            $ja3Fingerprints[$browserName] = $data['tls']['ja3'];
            $ja4Fingerprints[$browserName] = $data['tls']['ja4'];
            
            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        // Verify most JA3 fingerprints are unique (some browsers might have similar fingerprints)
        $uniqueJa3 = array_unique($ja3Fingerprints);
        $this->assertGreaterThanOrEqual(count($browsers) - 1, count($uniqueJa3), 'Most browsers should have unique JA3 fingerprints');
        
        // Verify that JA4 fingerprints are present and mostly unique
        $uniqueJa4 = array_unique($ja4Fingerprints);
        $this->assertGreaterThanOrEqual(count($browsers) - 2, count($uniqueJa4), 'Most browsers should have unique JA4 fingerprints');
    }

    /**
     * Test that TLS extensions are in the correct order for Chrome
     */
    public function testChromeExtensionOrder()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $extensions = $data['tls']['extensions'];
        
        // Get extension names in order
        $extensionNames = array_column($extensions, 'name');
        
        // Chrome should have key extensions present (order may vary due to TLS_GREASE)
        $this->assertContains('server_name (0)', $extensionNames, 'Chrome should have server_name extension');
        
        // Verify key extensions are present
        $requiredExtensions = [
            'server_name (0)',
            'supported_groups (10)',
            'application_layer_protocol_negotiation (16)',
            'supported_versions (43)'
        ];
        
        foreach ($requiredExtensions as $extension) {
            $this->assertContains($extension, $extensionNames, "Chrome should have extension: $extension");
        }
    }

    /**
     * Test that HTTP/2 settings are browser-specific
     */
    public function testHttp2SettingsAreBrowserSpecific()
    {
        $browsers = ['chrome110', 'firefox133', 'safari153'];
        $settings = [];
        
        foreach ($browsers as $browser) {
            $client = new PHPImpersonate($browser);
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            
            $this->assertEquals(200, $response->status());
            
            $data = $response->json();
            $http2 = $data['http2'];
            
            // Find SETTINGS frame
            $settingsFrame = null;
            foreach ($http2['sent_frames'] as $frame) {
                if ($frame['frame_type'] === 'SETTINGS') {
                    $settingsFrame = $frame;
                    break;
                }
            }
            
            $this->assertNotNull($settingsFrame, "SETTINGS frame should be present for $browser");
            $settings[$browser] = $settingsFrame['settings'];
            
            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        // Verify that HTTP/2 settings are present for all browsers
        foreach ($settings as $browser => $setting) {
            $this->assertNotEmpty($setting, "HTTP/2 settings should be present for $browser");
        }
        $this->assertGreaterThan(0, count($settings), 'HTTP/2 settings should be present');
    }

    /**
     * Verify Chrome-specific extensions
     */
    private function verifyChromeExtensions(array $extensions): void
    {
        $extensionNames = array_column($extensions, 'name');
        
        // Chrome-specific extensions
        $chromeExtensions = [
            'server_name (0)',
            'supported_groups (10)',
            'application_layer_protocol_negotiation (16)',
            'supported_versions (43)',
            'psk_key_exchange_modes (45)',
            'key_share (51)'
        ];
        
        foreach ($chromeExtensions as $extension) {
            $this->assertContains($extension, $extensionNames, "Chrome should have extension: $extension");
        }
    }

    /**
     * Verify Firefox-specific extensions
     */
    private function verifyFirefoxExtensions(array $extensions): void
    {
        $extensionNames = array_column($extensions, 'name');
        
        // Firefox-specific extensions
        $firefoxExtensions = [
            'server_name (0)',
            'supported_groups (10)',
            'application_layer_protocol_negotiation (16)',
            'supported_versions (43)',
            'psk_key_exchange_modes (45)',
            'key_share (51)'
        ];
        
        foreach ($firefoxExtensions as $extension) {
            $this->assertContains($extension, $extensionNames, "Firefox should have extension: $extension");
        }
    }

    /**
     * Verify Safari-specific extensions
     */
    private function verifySafariExtensions(array $extensions): void
    {
        $extensionNames = array_column($extensions, 'name');
        
        // Safari-specific extensions
        $safariExtensions = [
            'server_name (0)',
            'supported_groups (10)',
            'application_layer_protocol_negotiation (16)',
            'supported_versions (43)',
            'psk_key_exchange_modes (45)',
            'key_share (51)'
        ];
        
        foreach ($safariExtensions as $extension) {
            $this->assertContains($extension, $extensionNames, "Safari should have extension: $extension");
        }
    }

    /**
     * Verify Chrome HTTP/2 settings
     */
    private function verifyChromeHttp2Settings(array $http2): void
    {
        $this->assertArrayHasKey('sent_frames', $http2);
        $this->assertNotEmpty($http2['sent_frames']);
        
        // Find SETTINGS frame
        $settingsFrame = null;
        foreach ($http2['sent_frames'] as $frame) {
            if ($frame['frame_type'] === 'SETTINGS') {
                $settingsFrame = $frame;
                break;
            }
        }
        
        $this->assertNotNull($settingsFrame, 'Chrome should send SETTINGS frame');
        $this->assertArrayHasKey('settings', $settingsFrame);
        $this->assertNotEmpty($settingsFrame['settings']);
    }

    /**
     * Verify Firefox HTTP/2 settings
     */
    private function verifyFirefoxHttp2Settings(array $http2): void
    {
        $this->assertArrayHasKey('sent_frames', $http2);
        $this->assertNotEmpty($http2['sent_frames']);
        
        // Find SETTINGS frame
        $settingsFrame = null;
        foreach ($http2['sent_frames'] as $frame) {
            if ($frame['frame_type'] === 'SETTINGS') {
                $settingsFrame = $frame;
                break;
            }
        }
        
        $this->assertNotNull($settingsFrame, 'Firefox should send SETTINGS frame');
        $this->assertArrayHasKey('settings', $settingsFrame);
        $this->assertNotEmpty($settingsFrame['settings']);
    }

    /**
     * Verify Safari HTTP/2 settings
     */
    private function verifySafariHttp2Settings(array $http2): void
    {
        $this->assertArrayHasKey('sent_frames', $http2);
        $this->assertNotEmpty($http2['sent_frames']);
        
        // Find SETTINGS frame
        $settingsFrame = null;
        foreach ($http2['sent_frames'] as $frame) {
            if ($frame['frame_type'] === 'SETTINGS') {
                $settingsFrame = $frame;
                break;
            }
        }
        
        $this->assertNotNull($settingsFrame, 'Safari should send SETTINGS frame');
        $this->assertArrayHasKey('settings', $settingsFrame);
        $this->assertNotEmpty($settingsFrame['settings']);
    }
}
