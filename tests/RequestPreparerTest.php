<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Support\RequestPreparer;

class RequestPreparerTest extends TestCase
{
    public function testNormalizeHeadersAcceptsAssocAndListForms(): void
    {
        $result = RequestPreparer::normalizeHeaders([
            'X-Assoc' => 'a',
            'X-List: b',
            'X-Num' => 5,
        ]);

        $this->assertSame('a', $result['X-Assoc']);
        $this->assertSame('b', $result['X-List']);
        $this->assertSame('5', $result['X-Num']);
    }

    public function testNormalizeHeadersDropsMalformedEntries(): void
    {
        $result = RequestPreparer::normalizeHeaders([
            'no colon here',   // int key, no colon -> dropped
            ': empty name',    // empty name -> dropped
        ]);

        $this->assertSame([], $result);
    }

    public function testFindHeaderValueIsCaseInsensitive(): void
    {
        $headers = ['content-type' => 'application/json'];

        $this->assertSame('application/json', RequestPreparer::findHeaderValue($headers, 'Content-Type'));
        $this->assertNull(RequestPreparer::findHeaderValue($headers, 'Accept'));
    }

    public function testPrepareBodyReturnsNullForNullData(): void
    {
        $headers = [];
        $this->assertNull(RequestPreparer::prepareBody(null, $headers));
    }

    public function testPrepareBodyUsesJsonWhenContentTypeJsonAnyCase(): void
    {
        $headers = ['content-type' => 'application/json'];
        $body = RequestPreparer::prepareBody(['a' => 1], $headers);

        $this->assertSame('{"a":1}', $body);
    }

    public function testPrepareBodyDefaultsToFormEncoding(): void
    {
        $headers = [];
        $body = RequestPreparer::prepareBody(['a' => 'b c'], $headers);

        $this->assertSame('a=b+c', $body);
        $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
    }

    public function testPrepareBodyHonoursJsonDefaultContentType(): void
    {
        $headers = [];
        $body = RequestPreparer::prepareBody(['a' => 1], $headers, 'application/json');

        $this->assertSame('{"a":1}', $body);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function testValidateRequestRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestPreparer::validateRequest(Request::get('   '));
    }

    public function testValidateRequestRejectsNonHttpScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported URL scheme');
        RequestPreparer::validateRequest(Request::get('ftp://example.com/x'));
    }

    public function testValidateRequestAcceptsHttpAndHttps(): void
    {
        RequestPreparer::validateRequest(Request::get('http://example.com'));
        RequestPreparer::validateRequest(Request::get('https://example.com'));
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeHeaderProvider(): array
    {
        return [
            'CR in value' => ['X-Foo', "bar\r"],
            'LF in value' => ['X-Foo', "bar\nInjected: 1"],
            'NUL in value' => ['X-Foo', "bar\0"],
            'colon in name' => ['X:Foo', 'bar'],
            'empty name' => ['', 'bar'],
        ];
    }

    #[DataProvider('unsafeHeaderProvider')]
    public function testAssertHeaderIsSafeRejectsInjection(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestPreparer::assertHeaderIsSafe($name, $value);
    }

    public function testAssertHeaderIsSafeAllowsNormalHeader(): void
    {
        RequestPreparer::assertHeaderIsSafe('X-Custom', 'some value; q=0.9');
        $this->addToAssertionCount(1);
    }
}
