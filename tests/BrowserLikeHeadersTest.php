<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Support\RequestPreparer;

/**
 * Headers curl adds on its own that no browser sends. Both engines must
 * suppress them, and must not suppress a caller's own value.
 */
class BrowserLikeHeadersTest extends TestCase
{
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

    // -------------------------------------------------------------------------
    // Offline: the suppression lines themselves
    // -------------------------------------------------------------------------

    public function testABodylessPostSuppressesCurlsDefaultContentType(): void
    {
        $this->assertSame(['Expect:', 'Content-Type:'], RequestPreparer::implicitHeaderSuppressions('POST', null, []));
    }

    public function testAPostWithABodyKeepsItsContentType(): void
    {
        $this->assertSame(['Expect:'], RequestPreparer::implicitHeaderSuppressions('POST', 'a=1', []));
    }

    public function testACallersOwnHeadersAreNeverSuppressed(): void
    {
        $this->assertSame([], RequestPreparer::implicitHeaderSuppressions('POST', null, [
            'content-type' => 'text/plain',
            'EXPECT' => '100-continue',
        ]));
    }

    public function testOtherMethodsOnlyDropExpect(): void
    {
        $this->assertSame(['Expect:'], RequestPreparer::implicitHeaderSuppressions('GET', null, []));
        $this->assertSame(['Expect:'], RequestPreparer::implicitHeaderSuppressions('PUT', null, []));
    }

    // -------------------------------------------------------------------------
    // Live: what actually reaches the server
    // -------------------------------------------------------------------------

    #[DataProvider('engineProvider')]
    public function testABodylessPostCarriesNoContentTypeLikeABrowser(string $engine): void
    {
        TestServer::requireHttpbin($this);

        $headers = TestServer::json((new PHPImpersonate('chrome146', 30, [], $engine))
            ->sendPost(TestServer::httpbin('/post')))['headers'] ?? [];

        $lower = array_change_key_case($headers, CASE_LOWER);
        $this->assertArrayNotHasKey('content-type', $lower, "$engine sent curl's default Content-Type on an empty POST");
        $this->assertSame('0', $lower['content-length'] ?? null, "$engine must still declare an empty body");
    }

    #[DataProvider('engineProvider')]
    public function testALargeBodyIsSentWithoutExpect100Continue(string $engine): void
    {
        TestServer::requireHttpbin($this);

        // Over a megabyte is where curl adds Expect: 100-continue on HTTP/1.1.
        $body = str_repeat('x', 1500000);
        $headers = TestServer::json((new PHPImpersonate('chrome146', 60, [], $engine))
            ->send(new Request('POST', TestServer::httpbin('/post'), ['Content-Type' => 'text/plain'], $body)))['headers'] ?? [];

        $lower = array_change_key_case($headers, CASE_LOWER);
        $this->assertArrayNotHasKey('expect', $lower, "$engine sent Expect: 100-continue, which no browser does");
        $this->assertSame('text/plain', $lower['content-type'] ?? null, "$engine must keep the caller's Content-Type");
    }
}
