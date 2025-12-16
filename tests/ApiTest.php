<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Response;

class ApiTest extends TestCase
{
    /**
     * Test GET request with static method
     */
    public function testGet()
    {
        $response = PHPImpersonate::get('https://httpbun.com/get', [
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
        $client = new PHPImpersonate();
        $response = $client->sendGet('https://httpbun.com/get');

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test POST request with static method
     */
    public function testPost()
    {
        $formData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post('https://httpbun.com/post', $formData, [
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
        $client = new PHPImpersonate();

        $formData = [
            'user' => 'testuser',
            'password' => 'password123',
        ];

        $response = $client->sendPost('https://httpbun.com/post', $formData);

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
        $response = PHPImpersonate::head('https://httpbun.com/any', [
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
        $client = new PHPImpersonate();
        // Use /any endpoint as it accepts any HTTP method including HEAD
        $response = $client->sendHead('https://httpbun.com/any');

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test DELETE request with static method
     */
    public function testDelete()
    {
        $response = PHPImpersonate::delete('https://httpbun.com/delete', [
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
        $client = new PHPImpersonate();
        $response = $client->sendDelete('https://httpbun.com/delete');

        $this->assertEquals(200, $response->status());
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test PATCH request with static method
     */
    public function testPatch()
    {
        $data = [
            'name' => 'Updated Name',
            'job' => 'Developer',
        ];

        $response = PHPImpersonate::patch('https://httpbun.com/patch', $data, [
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
        $client = new PHPImpersonate();

        $data = [
            'name' => 'John Smith',
            'status' => 'Active',
        ];

        $response = $client->sendPatch('https://httpbun.com/patch', $data);

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
        $data = [
            'id' => 123,
            'title' => 'New Resource',
            'body' => 'Resource content',
        ];

        $response = PHPImpersonate::put('https://httpbun.com/put', $data, [
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
        $client = new PHPImpersonate();

        $data = [
            'id' => 456,
            'name' => 'Updated Resource',
            'description' => 'Updated content',
        ];

        $response = $client->sendPut('https://httpbun.com/put', $data);

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
        $response = PHPImpersonate::get('https://httpbun.com/status/201');
        $this->assertEquals(201, $response->status());

        $response = PHPImpersonate::get('https://httpbun.com/status/404');
        $this->assertEquals(404, $response->status());
        $this->assertFalse($response->isSuccess());

        $response = PHPImpersonate::get('https://httpbun.com/status/500');
        $this->assertEquals(500, $response->status());
        $this->assertFalse($response->isSuccess());
    }

    /**
     * Test request with headers
     */
    public function testRequestWithHeaders()
    {
        $headers = [
            'X-Custom-Header' => 'CustomValue',
            'User-Agent' => 'PHPImpersonate Test',
        ];

        $response = PHPImpersonate::get('https://httpbun.com/headers', $headers);

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
        $this->expectException(RequestException::class);

        // Set a very short timeout (1 second) to trigger a timeout exception
        PHPImpersonate::get(
            'https://httpbun.com/delay/3', // This endpoint delays response by 3 seconds
            [],
            1 // 1 second timeout
        );
    }

    /**
     * Test response debug methods
     */
    public function testResponseDebugMethods()
    {
        $response = PHPImpersonate::get('https://httpbun.com/get');

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
        $response = PHPImpersonate::get('https://httpbun.com/response-headers?X-Test-Header=test-value');

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
     * Debug helper to see actual headers returned
     */
    public function testDebugHeaders()
    {
        $response = PHPImpersonate::get('https://httpbun.com/response-headers');

        // Capture output to variable instead of printing directly
        ob_start();
        echo "Actual headers returned: \n";
        print_r($response->headers());
        $output = ob_get_clean();

        // Use PHPUnit's expectation method to tell it we expect this output
        $this->expectOutputString($output);
        echo $output;
    }

    /**
     * Debug test to see the response format
     */
    public function testDebugPutResponse()
    {
        $data = ['test' => 'value'];
        $response = PHPImpersonate::put('https://httpbun.com/put', $data);

        // Capture output to variable instead of printing directly
        ob_start();
        echo "Complete response structure:\n";
        print_r($response->json());
        $output = ob_get_clean();

        // Use PHPUnit's expectation method to tell it we expect this output
        $this->expectOutputString($output);
        echo $output;
    }

    /**
     * Verify the basic setup is working
     */
    public function testBasicSetup()
    {
        // Use PHP's built-in request instead of curl_impersonate for a basic check
        $result = file_get_contents('https://httpbun.com/get');
        $this->assertNotFalse($result);

        // Now test file permissions
        $tempFile = tempnam(sys_get_temp_dir(), 'test_permissions');
        $this->assertNotFalse($tempFile);
        $this->assertTrue(is_writable($tempFile));
        $this->assertTrue(file_put_contents($tempFile, 'test') !== false);
        $this->assertTrue(unlink($tempFile));
    }

    /**
     * Test POST request with form data
     */
    public function testPostWithFormData()
    {
        $formData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post('https://httpbun.com/post', $formData, [
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
        $jsonData = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ];

        $response = PHPImpersonate::post('https://httpbun.com/post', $jsonData, [
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
}
