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

    /**
     * Asking for multipart used to return an http_build_query() string with a
     * boundary-less multipart Content-Type on it. Against httpbin that body was
     * discarded outright — the server answered 200 with an empty form — so the
     * request silently did nothing.
     */
    public function testPrepareBodyEncodesMultipartAndAddsABoundary(): void
    {
        $headers = ['Content-Type' => 'multipart/form-data'];
        $body = RequestPreparer::prepareBody(['name' => 'Ada', 'role' => 'eng'], $headers);

        // The header must now carry the boundary the body actually uses;
        // without one, no conforming parser can read a single field.
        $this->assertMatchesRegularExpression(
            '#^multipart/form-data; boundary=\S+$#',
            $headers['Content-Type']
        );

        preg_match('/boundary=(\S+)/', $headers['Content-Type'], $m);
        $boundary = $m[1];

        $this->assertSame(
            "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Ada\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"role\"\r\n\r\n"
            . "eng\r\n"
            . "--$boundary--\r\n",
            $body
        );
    }

    public function testPrepareBodyKeepsACallerSuppliedBoundary(): void
    {
        // Rewriting the caller's boundary would contradict the header they set.
        $headers = ['content-type' => 'multipart/form-data; boundary=XYZ'];
        $body = RequestPreparer::prepareBody(['a' => '1'], $headers);

        $this->assertSame('multipart/form-data; boundary=XYZ', $headers['content-type']);
        $this->assertSame(
            "--XYZ\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--XYZ--\r\n",
            $body
        );
    }

    public function testPrepareBodyDetectsMultipartRegardlessOfCase(): void
    {
        // Media types are case-insensitive (RFC 9110 6.4.1).
        $headers = ['Content-Type' => 'Multipart/Form-Data; boundary=B'];
        $body = RequestPreparer::prepareBody(['a' => '1'], $headers);

        $this->assertStringStartsWith('--B', $body);
    }

    /**
     * The multipart and urlencoded paths must carry the same data — only the
     * framing differs — so field names are flattened the same way, nulls are
     * dropped and bools render as 1/0.
     */
    public function testPrepareBodyFlattensFieldsLikeHttpBuildQuery(): void
    {
        $data = ['u' => ['n' => 'x', 'tags' => ['a', 'b']], 'ok' => true, 'skip' => null];

        $headers = ['Content-Type' => 'multipart/form-data; boundary=B'];
        $body = RequestPreparer::prepareBody($data, $headers);

        preg_match_all('/name="([^"]*)"/', $body, $m);

        // The same field names http_build_query() would have produced.
        $this->assertSame(['u[n]', 'u[tags][0]', 'u[tags][1]', 'ok'], $m[1]);
        $this->assertStringNotContainsString('skip', $body);
        $this->assertStringContainsString("\r\n\r\n1\r\n", $body);
    }

    /**
     * A field name is written into a quoted part header, so a quote or a CRLF in
     * it could forge an extra part — the multipart equivalent of header
     * injection. Browsers percent-encode exactly these; so do we.
     */
    public function testPrepareBodyNeutralisesFieldNameInjection(): void
    {
        $headers = ['Content-Type' => 'multipart/form-data; boundary=B'];
        $body = RequestPreparer::prepareBody(["ev\"il\r\nX-Injected: 1" => 'v'], $headers);

        $this->assertStringContainsString('name="ev%22il%0D%0AX-Injected: 1"', $body);
        $this->assertSame(1, substr_count($body, 'Content-Disposition'));
    }

    public function testPrepareBodyRejectsAValueContainingTheBoundary(): void
    {
        // Would terminate the body early and truncate the form.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains the boundary');

        $headers = ['Content-Type' => 'multipart/form-data; boundary=SECRET'];
        RequestPreparer::prepareBody(['a' => 'xx--SECRET--yy'], $headers);
    }

    public function testPrepareBodyRejectsNonScalarMultipartValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a scalar or array');

        $headers = ['Content-Type' => 'multipart/form-data; boundary=B'];
        RequestPreparer::prepareBody(['a' => new \stdClass()], $headers);
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
     * URLs curl and every browser accept, which filter_var(FILTER_VALIDATE_URL)
     * refused — so the library rejected them before a request was ever made.
     *
     * Verified against the bundled binary rather than assumed: it reports IDN
     * support (libidn2) and fetches `https://münchen.de/` successfully, and
     * unicode paths and queries round-trip through both engines.
     *
     * @param string $url
     */
    #[DataProvider('acceptableUrlProvider')]
    public function testValidateRequestAcceptsWhatCurlAccepts(string $url): void
    {
        RequestPreparer::validateRequest(Request::get($url));
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptableUrlProvider(): array
    {
        return [
            'IDN host' => ['https://münchen.de/'],
            'IDN host, non-latin' => ['https://例え.jp/'],
            'punycode host' => ['https://xn--mnchen-3ya.de/'],
            // Legal in DNS and common on internal networks (service discovery).
            'underscore in host' => ['https://my_host.example.com/'],
            'unicode path' => ['https://example.com/café'],
            'unicode query' => ['https://example.com/a?q=hé'],
            'userinfo' => ['https://user:pass@example.com/'],
            'IPv4 literal' => ['https://192.168.1.1/'],
            'IPv6 literal' => ['https://[::1]:8080/'],
            'port and path' => ['http://localhost:8080/path'],
            'no trailing slash' => ['http://example.com'],
        ];
    }

    /**
     * Loosening the format check must not loosen the guards that matter: the
     * scheme allow-list (an HTTP client handing file:// or ftp:// to curl is an
     * SSRF surprise) and control characters, where a CR or LF in the request
     * target could split it into a second request.
     *
     * @param string $url
     * @param string $expectedMessage
     */
    #[DataProvider('rejectableUrlProvider')]
    public function testValidateRequestStillRejectsWhatItMust(string $url, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        RequestPreparer::validateRequest(Request::get($url));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectableUrlProvider(): array
    {
        return [
            'ftp' => ['ftp://example.com/x', 'Unsupported URL scheme'],
            // Parses to no host at all, so it must still name the scheme it
            // was rejected for rather than blaming the host.
            'file' => ['file:///etc/passwd', 'Unsupported URL scheme'],
            'javascript' => ['javascript:alert(1)', 'Unsupported URL scheme'],
            'CRLF injection' => ["https://exa\r\nmple.com/", 'must be percent-encoded'],
            'raw space' => ['https://example.com/a b', 'must be percent-encoded'],
            'tab' => ["https://example.com/\tx", 'must be percent-encoded'],
            'no host' => ['https://', 'a scheme and a host are required'],
            'protocol-relative' => ['//example.com/path', 'a scheme and a host are required'],
        ];
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
