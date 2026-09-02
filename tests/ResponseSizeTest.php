<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Support\CurlOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * The response-size cap, on both engines.
 *
 * Both engines buffer the whole body, and the FFI engine's buffer is C memory
 * outside PHP's memory_limit: against a server that streams indefinitely it
 * reached 568 MB of RSS in three seconds while PHP reported a 4 MB peak. A
 * finite cap turns that into an error the caller can handle.
 */
class ResponseSizeTest extends TestCase
{
    /** curl's CURLE_FILESIZE_EXCEEDED. */
    private const CURLE_FILESIZE_EXCEEDED = 63;

    protected function setUp(): void
    {
        TestServer::requireHttpbin($this);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engineProvider(): array
    {
        $engines = ['process' => [PHPImpersonate::ENGINE_PROCESS]];

        if (PHPImpersonate::ffiAvailable()) {
            $engines['ffi'] = [PHPImpersonate::ENGINE_FFI];
        }

        return $engines;
    }

    #[DataProvider('engineProvider')]
    public function testABodyOverTheCapIsRejectedWithCurlsFilesizeError(string $engine): void
    {
        $client = new PHPImpersonate('chrome146', 30, ['max-filesize' => 1000], $engine);

        try {
            $client->sendGet(TestServer::httpbin('/bytes/5000'));
            $this->fail("$engine returned a body larger than max-filesize");
        } catch (RequestException $e) {
            $this->assertSame(self::CURLE_FILESIZE_EXCEEDED, $e->getCode(), "$engine: " . $e->getMessage());
        }
    }

    #[DataProvider('engineProvider')]
    public function testABodyUnderTheCapIsReturnedWhole(string $engine): void
    {
        $response = (new PHPImpersonate('chrome146', 30, ['max-filesize' => 10000], $engine))
            ->sendGet(TestServer::httpbin('/bytes/5000'));

        $this->assertSame(200, $response->status());
        $this->assertSame(5000, strlen($response->body()));
    }

    public function testTheDefaultCapIsFiniteAndGenerous(): void
    {
        $this->assertGreaterThanOrEqual(64 * 1024 * 1024, CurlOptions::DEFAULT_MAX_FILESIZE);
        $this->assertLessThanOrEqual(1024 * 1024 * 1024, CurlOptions::DEFAULT_MAX_FILESIZE);
        $this->assertTrue(CurlOptions::isAllowed('max-filesize'));
        $this->assertSame(CurlOptions::TYPE_LONG, CurlOptions::type('max-filesize'));
    }
}
