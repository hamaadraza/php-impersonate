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

    /**
     * Malformed entries are rejected, not dropped: silently discarding them
     * meant a typo'd header simply never reached the wire.
     *
     * @return array<string, array{0: array<int|string,mixed>, 1: string}>
     */
    public static function malformedHeaderProvider(): array
    {
        return [
            'list entry without colon' => [['no colon here'], 'list-form entries must be'],
            'list entry with empty name' => [[': empty name'], 'non-empty name'],
            'non-string list entry' => [[123], 'list-form entries must be'],
            'array value' => [['X-Arr' => ['a', 'b']], 'expected a string or number'],
            'null value' => [['X-Null' => null], 'expected a string or number'],
            'bool value' => [['X-Bool' => true], 'expected a string or number'],
        ];
    }

    /**
     * @param array<int|string,mixed> $headers
     */
    #[DataProvider('malformedHeaderProvider')]
    public function testNormalizeHeadersRejectsMalformedEntries(array $headers, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessage, '/') . '/');

        RequestPreparer::normalizeHeaders($headers);
    }

    public function testNormalizeHeadersIsIdempotent(): void
    {
        $once = RequestPreparer::normalizeHeaders(['X-List: b', 'X-Assoc' => 'a']);

        $this->assertSame($once, RequestPreparer::normalizeHeaders($once));
    }

    /**
     * Header names are case-insensitive (RFC 9110 §5.1), so two spellings are
     * one header. Left unfolded they both reached the wire, and
     * ['User-Agent' => …, 'user-agent' => …] went out as two User-Agent lines —
     * a bot signal in its own right.
     *
     * @param array<int|string,mixed> $headers
     * @param array<string,string> $expected
     */
    #[DataProvider('caseVariantHeaderProvider')]
    public function testNormalizeHeadersFoldsNamesDifferingOnlyInCase(array $headers, array $expected): void
    {
        // assertSame compares order as well as content: which slot the surviving
        // header occupies decides where it lands on the wire, and header order
        // is part of the fingerprint.
        $this->assertSame($expected, RequestPreparer::normalizeHeaders($headers));
    }

    /**
     * @return array<string, array{0: array<int|string,mixed>, 1: array<string,string>}>
     */
    public static function caseVariantHeaderProvider(): array
    {
        return [
            // The last value wins and the first spelling keeps its slot —
            // exactly what PHP's own array assignment does for identical keys.
            'assoc form' => [
                ['User-Agent' => 'First/1.0', 'user-agent' => 'Second/2.0'],
                ['User-Agent' => 'Second/2.0'],
            ],
            'list form' => [
                ['User-Agent: First/1.0', 'user-agent: Second/2.0'],
                ['User-Agent' => 'Second/2.0'],
            ],
            'mixed forms' => [
                ['User-Agent' => 'First/1.0', 'user-agent: Second/2.0'],
                ['User-Agent' => 'Second/2.0'],
            ],
            'three spellings' => [
                ['x-foo' => '1', 'X-Foo' => '2', 'X-FOO' => '3'],
                ['x-foo' => '3'],
            ],
            'position is the first occurrence\'s' => [
                ['A' => '1', 'User-Agent' => 'first', 'B' => '2', 'user-agent' => 'last'],
                ['A' => '1', 'User-Agent' => 'last', 'B' => '2'],
            ],
            'distinct names are untouched' => [
                ['Accept' => 'x', 'Accept-Language' => 'y'],
                ['Accept' => 'x', 'Accept-Language' => 'y'],
            ],
        ];
    }

    /**
     * The method is normalised at construction so neither engine has to. The FFI
     * engine uppercased before CURLOPT_CUSTOMREQUEST while the executable engine
     * passed -X through verbatim, so `new Request('get', …)` used to reach the
     * server as GET on one engine and as `get` — a 400 — on the other.
     */
    #[DataProvider('methodCasingProvider')]
    public function testRequestMethodIsNormalisedToUpperCase(string $given, string $expected): void
    {
        $this->assertSame($expected, (new Request($given, 'https://example.com'))->getMethod());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function methodCasingProvider(): array
    {
        return [
            'lowercase' => ['get', 'GET'],
            'mixed case' => ['Post', 'POST'],
            'already upper' => ['DELETE', 'DELETE'],
            'lowercase head' => ['head', 'HEAD'],
        ];
    }

    public function testNamedConstructorsKeepUpperCaseMethods(): void
    {
        $this->assertSame('GET', Request::get('https://example.com')->getMethod());
        $this->assertSame('PATCH', Request::patch('https://example.com')->getMethod());
    }

    public function testMethodNormalisationSurvivesWithers(): void
    {
        $request = (new Request('put', 'https://example.com'))->withBody('x')->withHeaders(['A' => 'b']);

        $this->assertSame('PUT', $request->getMethod());
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
