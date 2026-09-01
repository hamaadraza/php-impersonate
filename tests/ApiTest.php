<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Response;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Exception\RequestException;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        TestServer::requireHttpbin($this);
    }

    /**
     * Add a small delay between requests to avoid rate limiting
     */
    private function waitBetweenRequests(): void
    {
        usleep(500000); // 0.5 seconds
    }

    /**
     * Test GET request with static method
     */
    public function testGet()
    {
        $response = PHPImpersonate::get(TestServer::httpbin('/get'), [
            'X-Test-Header' => 'test-value',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);
    }

    /**
     * Test GET request with instance method
     */
    public function testClientGet()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();
        $response = $client->sendGet(TestServer::httpbin('/get'));

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test POST request with static method
     */
    public function testPost()
    {
        $this->waitBetweenRequests();

        $formData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post(TestServer::httpbin('/post'), $formData, [
            'X-Test-Header' => 'test-value',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);
        $this->assertEquals('John Doe', $responseData['form']['name']);
        $this->assertEquals('john.doe@example.com', $responseData['form']['email']);
    }

    /**
     * Test POST request with instance method
     */
    public function testClientPost()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();

        $formData = [
            'user' => 'testuser',
            'password' => 'password123',
        ];

        $response = $client->sendPost(TestServer::httpbin('/post'), $formData);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('testuser', $responseData['form']['user']);
        $this->assertEquals('password123', $responseData['form']['password']);
    }

    /**
     * Test HEAD request with static method
     */
    public function testHead()
    {
        // Use /any endpoint as it accepts any HTTP method including HEAD
        $response = PHPImpersonate::head(TestServer::httpbin('/anything'), [
            'X-Test-Header' => 'test-value',
        ]);

        $this->assertEquals(200, $response->status());
        // HEAD requests don't return body content
        $this->assertEquals('', $response->body());
    }

    /**
     * Test HEAD request with instance method
     */
    public function testClientHead()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();
        // Use /any endpoint as it accepts any HTTP method including HEAD
        $response = $client->sendHead(TestServer::httpbin('/anything'));

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test DELETE request with static method
     */
    public function testDelete()
    {
        $response = PHPImpersonate::delete(TestServer::httpbin('/delete'), [
            'X-Test-Header' => 'test-value',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);
    }

    /**
     * Test DELETE request with instance method
     */
    public function testClientDelete()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();
        $response = $client->sendDelete(TestServer::httpbin('/delete'));

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test PATCH request with static method
     */
    public function testPatch()
    {
        $this->waitBetweenRequests();

        $data = [
            'name' => 'Updated Name',
            'job' => 'Developer',
        ];

        $response = PHPImpersonate::patch(TestServer::httpbin('/patch'), $data, [
            'X-Test-Header' => 'test-value',
            'Content-Type' => 'application/json',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);

        // Data is in json field, not form
        $this->assertNotNull($responseData['json']);
        $this->assertEquals('Updated Name', $responseData['json']['name']);
        $this->assertEquals('Developer', $responseData['json']['job']);
    }

    /**
     * Test PATCH request with instance method
     */
    public function testClientPatch()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();

        $data = [
            'name' => 'John Smith',
            'status' => 'Active',
        ];

        $response = $client->sendPatch(TestServer::httpbin('/patch'), $data);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Data is in json field with default Content-Type
        $this->assertNotNull($responseData['json']);
        $this->assertEquals('John Smith', $responseData['json']['name']);
        $this->assertEquals('Active', $responseData['json']['status']);
    }

    /**
     * Test PUT request with static method
     */
    public function testPut()
    {
        $this->waitBetweenRequests();

        $data = [
            'id' => 123,
            'title' => 'New Resource',
            'body' => 'Resource content',
        ];

        $response = PHPImpersonate::put(TestServer::httpbin('/put'), $data, [
            'X-Test-Header' => 'test-value',
            'Content-Type' => 'application/json',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);

        // Check for data in various possible locations
        if (isset($responseData['json'])) {
            $dataSection = $responseData['json'];
        } elseif (isset($responseData['data'])) {
            $dataSection = json_decode($responseData['data'], true);
        } else {
            $dataSection = [];
            echo "Warning: No JSON data found in response. Available keys: " . implode(', ', array_keys($responseData));
        }

        $this->assertEquals(123, $dataSection['id'] ?? null);
        $this->assertEquals('New Resource', $dataSection['title'] ?? null);
        $this->assertEquals('Resource content', $dataSection['body'] ?? null);
    }

    /**
     * Test PUT request with instance method
     */
    public function testClientPut()
    {
        $this->waitBetweenRequests();

        $client = new PHPImpersonate();

        $data = [
            'id' => 456,
            'name' => 'Updated Resource',
            'description' => 'Updated content',
        ];

        $response = $client->sendPut(TestServer::httpbin('/put'), $data);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Data is in json field with default Content-Type
        $this->assertNotNull($responseData['json']);
        $this->assertEquals(456, $responseData['json']['id']);
        $this->assertEquals('Updated Resource', $responseData['json']['name']);
        $this->assertEquals('Updated content', $responseData['json']['description']);
    }

    /**
     * Test response status code validation
     */
    public function testResponseStatus()
    {
        $response = PHPImpersonate::get(TestServer::httpbin('/status/201'));
        $this->assertEquals(201, $response->status());

        $response = PHPImpersonate::get(TestServer::httpbin('/status/404'));
        $this->assertEquals(404, $response->status());
        $this->assertFalse($response->isSuccess());

        $response = PHPImpersonate::get(TestServer::httpbin('/status/500'));
        $this->assertEquals(500, $response->status());
        $this->assertFalse($response->isSuccess());
    }

    /**
     * Test request with headers
     */
    public function testRequestWithHeaders()
    {
        $this->waitBetweenRequests();

        $headers = [
            'X-Custom-Header' => 'CustomValue',
            'User-Agent' => 'PHPImpersonate Test',
        ];

        $response = PHPImpersonate::get(TestServer::httpbin('/headers'), $headers);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Check that our custom header is present
        $this->assertEquals('CustomValue', $responseData['headers']['X-Custom-Header']);

        // Check that User-Agent contains our string (might be modified by browser impersonation)
        $this->assertStringContainsString('PHPImpersonate Test', $responseData['headers']['User-Agent']);
    }

    /**
     * Test request timeout handling
     */
    public function testRequestTimeout()
    {
        $this->waitBetweenRequests();

        $this->expectException(RequestException::class);

        // Set a very short timeout (1 second) to trigger a timeout exception
        PHPImpersonate::get(
            TestServer::httpbin('/delay/3'), // This endpoint delays response by 3 seconds
            [],
            1 // 1 second timeout
        );
    }

    /**
     * Test response debug methods
     */
    public function testResponseDebugMethods()
    {
        $response = PHPImpersonate::get(TestServer::httpbin('/get'));

        // Test dump() method returns a string
        $dump = $response->dump();
        $this->assertIsString($dump);
        $this->assertStringContainsString('HTTP Status:', $dump);

        // Instead of actually calling debug() which outputs to console:
        // Capture output to avoid "risky" test
        ob_start();
        $result = $response->debug();
        $output = ob_get_clean();

        // Check output contains expected content
        $this->assertStringContainsString('HTTP Status:', $output);
        // Check method returns self for chaining
        $this->assertSame($response, $result);
    }

    /**
     * Test response headers
     */
    public function testResponseHeaders()
    {
        $response = PHPImpersonate::get(TestServer::httpbin('/response-headers?X-Test-Header=test-value'));

        $this->assertEquals('test-value', $response->header('X-Test-Header'));
        $this->assertNull($response->header('Non-Existent-Header'));
        $this->assertEquals('default', $response->header('Non-Existent-Header', 'default'));

        $headers = $response->headers();
        $this->assertIsArray($headers);
        // Check that we have at least one header
        $this->assertNotEmpty($headers);

        // Alternative test: check for "content-type" which should be present
        // (using lowercase key as header names can be case-inconsistent)
        $headersLowercase = array_change_key_case($headers, CASE_LOWER);
        $this->assertArrayHasKey('content-type', $headersLowercase);
    }

    /**
     * Test POST request with form data
     */
    public function testPostWithFormData()
    {
        $this->waitBetweenRequests();

        $formData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post(TestServer::httpbin('/post'), $formData, [
            'X-Test-Header' => 'test-value',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);
        $this->assertEquals('John Doe', $responseData['form']['name']);
        $this->assertEquals('john.doe@example.com', $responseData['form']['email']);
    }

    /**
     * Test POST request with JSON data
     */
    public function testPostWithJsonData()
    {
        $this->waitBetweenRequests();

        $jsonData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post(TestServer::httpbin('/post'), $jsonData, [
            'X-Test-Header' => 'test-value',
            'Content-Type' => 'application/json',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();
        $this->assertEquals('test-value', $responseData['headers']['X-Test-Header']);

        // Data should be in the json field when Content-Type is application/json
        $this->assertEquals('John Doe', $responseData['json']['name']);
        $this->assertEquals('john.doe@example.com', $responseData['json']['email']);
    }

    /**
     * Test request with large headers that exceed command line limit
     * This triggers the temp file header mechanism
     */
    public function testRequestWithLargeHeaders()
    {
        $this->waitBetweenRequests();

        // Create a large cookie value (>7000 chars to trigger temp file usage)
        $largeValue = str_repeat('x', 7500);

        $headers = [
            'Cookie' => 'session=' . $largeValue,
            'X-Test-Header' => 'large-header-test',
        ];

        $response = PHPImpersonate::get(TestServer::httpbin('/headers'), $headers);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Verify both headers were sent correctly
        $this->assertStringContainsString('session=' . $largeValue, $responseData['headers']['Cookie']);
        $this->assertEquals('large-header-test', $responseData['headers']['X-Test-Header']);
    }

    /**
     * Test request with multiple large headers
     */
    public function testRequestWithMultipleLargeHeaders()
    {
        $this->waitBetweenRequests();

        // Create multiple headers that together exceed the limit
        $headers = [
            'X-Large-Header-1' => str_repeat('a', 3000),
            'X-Large-Header-2' => str_repeat('b', 3000),
            'X-Large-Header-3' => str_repeat('c', 3000),
            'X-Test-Header' => 'multiple-large-headers',
        ];

        $response = PHPImpersonate::get(TestServer::httpbin('/headers'), $headers);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Verify all headers were sent correctly
        $this->assertEquals(str_repeat('a', 3000), $responseData['headers']['X-Large-Header-1']);
        $this->assertEquals(str_repeat('b', 3000), $responseData['headers']['X-Large-Header-2']);
        $this->assertEquals(str_repeat('c', 3000), $responseData['headers']['X-Large-Header-3']);
        $this->assertEquals('multiple-large-headers', $responseData['headers']['X-Test-Header']);
    }

    /**
     * Test POST request with large cookie header
     */
    public function testPostWithLargeCookieHeader()
    {
        $this->waitBetweenRequests();

        $largeCookie = str_repeat('data', 2000); // 8000 chars

        $formData = ['field' => 'value'];

        $response = PHPImpersonate::post(TestServer::httpbin('/post'), $formData, [
            'Cookie' => 'large_cookie=' . $largeCookie,
            'X-Test-Header' => 'post-large-cookie',
        ]);

        $this->assertEquals(200, $response->status());
        $responseData = $response->json();

        // Verify cookie was sent
        $this->assertStringContainsString('large_cookie=' . $largeCookie, $responseData['headers']['Cookie']);
        $this->assertEquals('post-large-cookie', $responseData['headers']['X-Test-Header']);
        $this->assertEquals('value', $responseData['form']['field']);
    }
}
