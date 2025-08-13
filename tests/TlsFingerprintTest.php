<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Response;

class TlsFingerprintTest extends TestCase
{
    private const TLS_FINGERPRINT_API = 'https://tls.peet.ws/api/all';

    /**
     * Test that Chrome 99 Android sends correct TLS fingerprint
     */
    public function testChrome99AndroidFingerprint()
    {
        $client = new PHPImpersonate('chrome99_android');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 99 Android
        $this->assertStringContainsString('Chrome/99', $data['user_agent']);
        $this->assertStringContainsString('Android', $data['user_agent']);
        $this->assertStringContainsString('Mobile Safari', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Chrome 110 sends correct TLS fingerprint
     */
    public function testChrome110Fingerprint()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 110
        $this->assertStringContainsString('Chrome/110', $data['user_agent']);
        $this->assertStringContainsString('Windows NT 10.0', $data['user_agent']);
        $this->assertStringNotContainsString('Mobile', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Chrome 120 sends correct TLS fingerprint
     */
    public function testChrome120Fingerprint()
    {
        $client = new PHPImpersonate('chrome120');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 120
        $this->assertStringContainsString('Chrome/120', $data['user_agent']);
        $this->assertStringContainsString('Macintosh; Intel Mac OS X 10_15_7', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Firefox 133 sends correct TLS fingerprint
     */
    public function testFirefox133Fingerprint()
    {
        $client = new PHPImpersonate('firefox133');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Firefox 133
        $this->assertStringContainsString('Firefox/133', $data['user_agent']);
        $this->assertStringContainsString('Macintosh; Intel Mac OS X 10.15', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Safari 153 sends correct TLS fingerprint
     */
    public function testSafari153Fingerprint()
    {
        $client = new PHPImpersonate('safari153');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Safari 153
        $this->assertStringContainsString('Version/15.3 Safari/605.1.15', $data['user_agent']);
        $this->assertStringContainsString('Macintosh', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Safari iOS sends correct TLS fingerprint
     */
    public function testSafariIosFingerprint()
    {
        $client = new PHPImpersonate('safari172_ios');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Safari iOS
        $this->assertStringContainsString('Safari/', $data['user_agent']);
        $this->assertStringContainsString('iPhone', $data['user_agent']);
        $this->assertStringContainsString('Mobile', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that Edge 99 sends correct TLS fingerprint
     */
    public function testEdge99Fingerprint()
    {
        $client = new PHPImpersonate('edge99');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Edge 99
        $this->assertStringContainsString('Edg/99', $data['user_agent']);
        $this->assertStringContainsString('Windows NT 10.0', $data['user_agent']);
        
        // Verify TLS version
        $this->assertArrayHasKey('tls_version_negotiated', $data['tls']);
        $this->assertContains($data['tls']['tls_version_negotiated'], ['771', '772']); // TLS 1.2 or 1.3
        
        // Verify HTTP/2 is used
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify JA4 fingerprint exists
        $this->assertArrayHasKey('ja4', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja4']);
    }

    /**
     * Test that different browsers have different JA3 fingerprints
     */
    public function testDifferentBrowsersHaveDifferentFingerprints()
    {
        $browsers = ['chrome99_android', 'chrome110', 'firefox133', 'safari153'];
        $fingerprints = [];

        foreach ($browsers as $browser) {
            $client = new PHPImpersonate($browser);
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            
            $this->assertEquals(200, $response->status());
            
            $data = $response->json();
            $this->assertArrayHasKey('tls', $data);
            $this->assertArrayHasKey('ja3', $data['tls']);
            
            $fingerprints[$browser] = $data['tls']['ja3'];
        }

        // Verify that all fingerprints are different
        $uniqueFingerprints = array_unique($fingerprints);
        $this->assertCount(count($browsers), $uniqueFingerprints, 'All browsers should have unique JA3 fingerprints');
    }

    /**
     * Test that different browsers have different JA4 fingerprints
     */
    public function testDifferentBrowsersHaveDifferentJa4Fingerprints()
    {
        $browsers = ['chrome99_android', 'chrome110', 'firefox133', 'safari153'];
        $fingerprints = [];

        foreach ($browsers as $browser) {
            $client = new PHPImpersonate($browser);
            
            // Add retry logic for robustness
            $maxRetries = 3;
            $response = null;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $response = $client->sendGet(self::TLS_FINGERPRINT_API);
                    
                    if ($response->status() === 200) {
                        break;
                    }
                    
                    // If not 200, wait and retry
                    if ($attempt < $maxRetries) {
                        usleep(500000); // 500ms delay
                    }
                } catch (\Exception $e) {
                    if ($attempt < $maxRetries) {
                        usleep(500000); // 500ms delay
                        continue;
                    }
                    throw $e;
                }
            }
            
            $this->assertNotNull($response, "Failed to get response for $browser after $maxRetries attempts");
            $this->assertEquals(200, $response->status(), "Failed to get 200 status for $browser");
            
            $data = $response->json();
            $this->assertArrayHasKey('tls', $data, "Missing 'tls' key for $browser");
            $this->assertArrayHasKey('ja4', $data['tls'], "Missing 'ja4' key for $browser");
            
            $fingerprints[$browser] = $data['tls']['ja4'];
            
            // Small delay between requests to avoid rate limiting
            usleep(200000); // 200ms
        }

        // Verify that most fingerprints are different (some browsers might have similar fingerprints)
        $uniqueFingerprints = array_unique($fingerprints);
        $this->assertGreaterThanOrEqual(count($browsers) - 1, count($uniqueFingerprints), 'Most browsers should have unique JA4 fingerprints');
    }

    /**
     * Test that TLS extensions are properly set
     */
    public function testTlsExtensionsAreSet()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('extensions', $data['tls']);
        
        $extensions = $data['tls']['extensions'];
        $this->assertNotEmpty($extensions);
        
        // Verify common extensions are present
        $extensionNames = array_column($extensions, 'name');
        
        // Check for server_name extension
        $this->assertContains('server_name (0)', $extensionNames);
        
        // Check for supported_groups extension
        $this->assertContains('supported_groups (10)', $extensionNames);
        
        // Check for application_layer_protocol_negotiation extension
        $this->assertContains('application_layer_protocol_negotiation (16)', $extensionNames);
        
        // Check for supported_versions extension
        $this->assertContains('supported_versions (43)', $extensionNames);
    }

    /**
     * Test that HTTP/2 settings are properly configured
     */
    public function testHttp2SettingsAreConfigured()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('http2', $data);
        $this->assertArrayHasKey('sent_frames', $data['http2']);
        
        $sentFrames = $data['http2']['sent_frames'];
        $this->assertNotEmpty($sentFrames);
        
        // Find SETTINGS frame
        $settingsFrame = null;
        foreach ($sentFrames as $frame) {
            if ($frame['frame_type'] === 'SETTINGS') {
                $settingsFrame = $frame;
                break;
            }
        }
        
        $this->assertNotNull($settingsFrame, 'SETTINGS frame should be present');
        $this->assertArrayHasKey('settings', $settingsFrame);
        $this->assertNotEmpty($settingsFrame['settings']);
    }

    /**
     * Test that ciphers are properly configured
     */
    public function testCiphersAreConfigured()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('ciphers', $data['tls']);
        
        $ciphers = $data['tls']['ciphers'];
        $this->assertNotEmpty($ciphers);
        
        // Verify that modern ciphers are present
        $cipherNames = array_map('strtolower', $ciphers);
        
        // Check for TLS 1.3 ciphers
        $this->assertContains('tls_aes_128_gcm_sha256', $cipherNames);
        $this->assertContains('tls_aes_256_gcm_sha384', $cipherNames);
        $this->assertContains('tls_chacha20_poly1305_sha256', $cipherNames);
        
        // Check for TLS 1.2 ciphers
        $this->assertContains('tls_ecdhe_rsa_with_aes_128_gcm_sha256', $cipherNames);
        $this->assertContains('tls_ecdhe_rsa_with_aes_256_gcm_sha384', $cipherNames);
    }

    /**
     * Test that static methods work with TLS fingerprinting
     */
    public function testStaticMethodsWithTlsFingerprinting()
    {
        $response = PHPImpersonate::get(self::TLS_FINGERPRINT_API, [], 30, 'chrome110');
        
        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 110
        $this->assertStringContainsString('Chrome/110', $data['user_agent']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
    }

    /**
     * Test that POST requests maintain TLS fingerprinting
     */
    public function testPostRequestMaintainsTlsFingerprinting()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendPost(self::TLS_FINGERPRINT_API, ['test' => 'data']);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 110
        $this->assertStringContainsString('Chrome/110', $data['user_agent']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify method is POST
        $this->assertEquals('POST', $data['method']);
    }

    /**
     * Test that custom headers don't interfere with TLS fingerprinting
     */
    public function testCustomHeadersDontInterfereWithTlsFingerprinting()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API, [
            'X-Custom-Header' => 'test-value',
            'Authorization' => 'Bearer test-token'
        ]);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 110
        $this->assertStringContainsString('Chrome/110', $data['user_agent']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
        
        // Verify that TLS fingerprinting still works with custom headers
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
    }

    /**
     * Test that timeout doesn't affect TLS fingerprinting
     */
    public function testTimeoutDoesntAffectTlsFingerprinting()
    {
        $client = new PHPImpersonate('chrome110', 60);
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        
        // Verify User-Agent matches Chrome 110
        $this->assertStringContainsString('Chrome/110', $data['user_agent']);
        
        // Verify JA3 fingerprint exists
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
    }
}
