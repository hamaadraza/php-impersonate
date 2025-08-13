<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;

class TlsApiConnectionTest extends TestCase
{
    private const TLS_FINGERPRINT_API = 'https://tls.peet.ws/api/all';

    /**
     * Test basic connection to TLS fingerprinting API
     */
    public function testBasicConnection()
    {
        $client = new PHPImpersonate();
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());
        $this->assertNotEmpty($response->body());

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('user_agent', $data);
        $this->assertArrayHasKey('http_version', $data);
    }

    /**
     * Test that the API returns expected structure
     */
    public function testApiResponseStructure()
    {
        $client = new PHPImpersonate('chrome110');
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());

        $data = $response->json();

        // Verify top-level keys (based on actual API response)
        $expectedKeys = [
            'donate',
            'ip',
            'http_version',
            'method',
            'user_agent',
            'tls',
            'http2',
            'tcpip',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Response should contain key: $key");
        }

        // Verify TLS structure
        $tls = $data['tls'];
        $expectedTlsKeys = [
            'ciphers',
            'extensions',
            'tls_version_record',
            'tls_version_negotiated',
            'ja3',
            'ja3_hash',
            'ja4',
            'ja4_r',
            'peetprint',
            'peetprint_hash',
            'client_random',
            'session_id',
        ];

        foreach ($expectedTlsKeys as $key) {
            $this->assertArrayHasKey($key, $tls, "TLS data should contain key: $key");
        }

        // Verify HTTP/2 structure
        $http2 = $data['http2'];
        $expectedHttp2Keys = [
            'akamai_fingerprint',
            'akamai_fingerprint_hash',
            'sent_frames',
        ];

        foreach ($expectedHttp2Keys as $key) {
            $this->assertArrayHasKey($key, $http2, "HTTP/2 data should contain key: $key");
        }
    }

    /**
     * Test that different HTTP methods work
     */
    public function testDifferentHttpMethods()
    {
        $client = new PHPImpersonate('chrome110');

        // Test GET
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('GET', $data['method']);

        // Test POST
        $response = $client->sendPost(self::TLS_FINGERPRINT_API, ['test' => 'data']);
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('POST', $data['method']);

        // Test HEAD
        $response = $client->sendHead(self::TLS_FINGERPRINT_API);
        $this->assertEquals(200, $response->status());
    }

    /**
     * Test that custom headers don't interfere with TLS fingerprinting
     */
    public function testCustomHeadersDontInterfereWithTlsFingerprinting()
    {
        $client = new PHPImpersonate('chrome110');
        $customHeaders = [
            'X-Test-Header' => 'test-value',
            'X-Another-Header' => 'another-value',
        ];

        $response = $client->sendGet(self::TLS_FINGERPRINT_API, $customHeaders);
        $this->assertEquals(200, $response->status());

        $data = $response->json();

        // Verify that TLS fingerprinting still works with custom headers
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);

        // Verify that the request was successful despite custom headers
        $this->assertArrayHasKey('user_agent', $data);
        $this->assertArrayHasKey('http_version', $data);
        $this->assertEquals('h2', $data['http_version']);
    }

    /**
     * Test that the API is accessible and responsive
     */
    public function testApiAccessibility()
    {
        $client = new PHPImpersonate('chrome110');

        // Test multiple requests to ensure API is stable
        for ($i = 0; $i < 3; $i++) {
            $response = $client->sendGet(self::TLS_FINGERPRINT_API);
            $this->assertEquals(200, $response->status());

            $data = $response->json();
            $this->assertArrayHasKey('tls', $data);
            $this->assertArrayHasKey('ja3', $data['tls']);
            $this->assertNotEmpty($data['tls']['ja3']);

            // Small delay to avoid overwhelming the API
            usleep(100000); // 100ms
        }
    }

    /**
     * Test that error handling works properly
     */
    public function testErrorHandling()
    {
        $client = new PHPImpersonate('chrome110');

        // Test with invalid URL
        $this->expectException(\Exception::class);
        $client->sendGet('https://invalid-domain-that-does-not-exist-12345.com');
    }

    /**
     * Test that the default browser works
     */
    public function testDefaultBrowser()
    {
        $client = new PHPImpersonate(); // Uses default browser
        $response = $client->sendGet(self::TLS_FINGERPRINT_API);

        $this->assertEquals(200, $response->status());

        $data = $response->json();
        $this->assertArrayHasKey('user_agent', $data);
        $this->assertArrayHasKey('tls', $data);
        $this->assertArrayHasKey('ja3', $data['tls']);
        $this->assertNotEmpty($data['tls']['ja3']);
    }
}
