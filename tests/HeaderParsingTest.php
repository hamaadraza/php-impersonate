<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Support\ResponseHeaderParser;

/**
 * Unit tests for response header parsing (ResponseHeaderParser::parse), the
 * shared implementation both engines use. No binary required.
 */
class HeaderParsingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string[]>
     */
    private function parseHeaders(string $content): array
    {
        return ResponseHeaderParser::parse($content);
    }

    // -------------------------------------------------------------------------
    // Empty / blank input
    // -------------------------------------------------------------------------

    public function testEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->parseHeaders(''));
    }

    public function testWhitespaceOnlyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->parseHeaders("   \n  \r\n  "));
    }

    // -------------------------------------------------------------------------
    // Single headers — stored as single-element string[]
    // -------------------------------------------------------------------------

    public function testSingleHeaderIsStoredAsArray(): void
    {
        $result = $this->parseHeaders("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        $this->assertArrayHasKey('Content-Type', $result);
        $this->assertIsArray($result['Content-Type']);
        $this->assertSame(['application/json'], $result['Content-Type']);
    }

    public function testMultipleSingleValueHeadersAreEachStoredAsArray(): void
    {
        $raw = implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Content-Type: text/html',
            'Content-Length: 1024',
            'Cache-Control: no-cache',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertSame(['text/html'], $result['Content-Type']);
        $this->assertSame(['1024'], $result['Content-Length']);
        $this->assertSame(['no-cache'], $result['Cache-Control']);
    }

    // -------------------------------------------------------------------------
    // HTTP status line
    // -------------------------------------------------------------------------

    public function testHttpStatusLineIsCapturedUnderHttpStatusKey(): void
    {
        $result = $this->parseHeaders("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n");

        $this->assertArrayHasKey('HTTP_STATUS', $result);
        $this->assertSame(['HTTP/1.1 200 OK'], $result['HTTP_STATUS']);
    }

    public function testHttp2StatusLineIsCaptured(): void
    {
        $result = $this->parseHeaders("HTTP/2 200\r\nContent-Type: application/json\r\n");

        $this->assertArrayHasKey('HTTP_STATUS', $result);
        $this->assertSame(['HTTP/2 200'], $result['HTTP_STATUS']);
    }

    public function testHttpStatusLineIsStoredAsSingleElementArray(): void
    {
        $result = $this->parseHeaders("HTTP/1.1 404 Not Found\r\n");

        $this->assertCount(1, $result['HTTP_STATUS']);
    }

    // -------------------------------------------------------------------------
    // Duplicate Set-Cookie headers — the primary motivation for the fix
    // -------------------------------------------------------------------------

    public function testMultipleSetCookieHeadersAreAllPreserved(): void
    {
        $raw = implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Set-Cookie: sessionid=abc; Path=/; HttpOnly',
            'Set-Cookie: csrftoken=xyz; Path=/; SameSite=Lax',
            'Set-Cookie: theme=dark; Path=/',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertArrayHasKey('Set-Cookie', $result);
        $this->assertCount(3, $result['Set-Cookie']);
        $this->assertSame('sessionid=abc; Path=/; HttpOnly', $result['Set-Cookie'][0]);
        $this->assertSame('csrftoken=xyz; Path=/; SameSite=Lax', $result['Set-Cookie'][1]);
        $this->assertSame('theme=dark; Path=/', $result['Set-Cookie'][2]);
    }

    public function testOnlyTheLastSetCookieValueIsNotKept(): void
    {
        // This is the regression test: the old code did $headers[$name] = $value
        // and would have returned only 'theme=dark; Path=/'
        $raw = implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Set-Cookie: sessionid=abc',
            'Set-Cookie: csrftoken=xyz',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertNotSame('csrftoken=xyz', $result['Set-Cookie'][0]);
        $this->assertSame('sessionid=abc', $result['Set-Cookie'][0]);
    }

    // -------------------------------------------------------------------------
    // Other repeatable headers
    // -------------------------------------------------------------------------

    public function testMultipleLinkHeadersAreAllPreserved(): void
    {
        $raw = implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Link: <https://api.example.com/users?page=2>; rel="next"',
            'Link: <https://api.example.com/users?page=1>; rel="prev"',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertCount(2, $result['Link']);
        $this->assertStringContainsString('rel="next"', $result['Link'][0]);
        $this->assertStringContainsString('rel="prev"', $result['Link'][1]);
    }

    public function testMultipleWwwAuthenticateHeadersAreAllPreserved(): void
    {
        $raw = implode("\r\n", [
            'HTTP/1.1 401 Unauthorized',
            'WWW-Authenticate: Basic realm="example"',
            'WWW-Authenticate: Bearer realm="api"',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertCount(2, $result['WWW-Authenticate']);
    }

    // -------------------------------------------------------------------------
    // Redirect handling — only the final HTTP section is kept
    // -------------------------------------------------------------------------

    public function testRedirectHeadersAreDiscardedAndOnlyFinalSectionIsKept(): void
    {
        // curl -D outputs all sections separated by blank lines when following redirects
        $raw = implode("\r\n", [
            'HTTP/1.1 302 Found',
            'Location: https://example.com/',
            'Set-Cookie: redirect_cookie=old; Path=/',
            '',
            'HTTP/1.1 200 OK',
            'Content-Type: text/html',
            'Set-Cookie: final_cookie=new; Path=/',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        // Status from final section only
        $this->assertSame(['HTTP/1.1 200 OK'], $result['HTTP_STATUS']);

        // Cookies from final section only
        $this->assertCount(1, $result['Set-Cookie']);
        $this->assertSame('final_cookie=new; Path=/', $result['Set-Cookie'][0]);

        // Location header belongs to redirect section — should not appear
        $this->assertArrayNotHasKey('Location', $result);
    }

    public function testMultipleRedirectsKeepOnlyLastSection(): void
    {
        $raw = implode("\r\n", [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://www.example.com/',
            '',
            'HTTP/1.1 302 Found',
            'Location: https://www.example.com/home',
            '',
            'HTTP/1.1 200 OK',
            'Content-Type: text/html',
            '',
        ]);

        $result = $this->parseHeaders($raw);

        $this->assertSame(['HTTP/1.1 200 OK'], $result['HTTP_STATUS']);
        $this->assertArrayNotHasKey('Location', $result);
    }

    // -------------------------------------------------------------------------
    // Whitespace handling
    // -------------------------------------------------------------------------

    public function testHeaderValuesAreWhitespaceTrimmed(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type:   application/json   \r\n";

        $result = $this->parseHeaders($raw);

        $this->assertSame('application/json', $result['Content-Type'][0]);
    }

    public function testHeaderNamesAreWhitespaceTrimmed(): void
    {
        $raw = "HTTP/1.1 200 OK\r\n  X-Custom  : value\r\n";

        $result = $this->parseHeaders($raw);

        $this->assertArrayHasKey('X-Custom', $result);
    }

    public function testEmptyLinesAreSkipped(): void
    {
        $raw = "HTTP/1.1 200 OK\r\n\r\nContent-Type: text/plain\r\n\r\n";

        $result = $this->parseHeaders($raw);

        $this->assertArrayHasKey('Content-Type', $result);
        $this->assertArrayNotHasKey('', $result);
    }

    // -------------------------------------------------------------------------
    // Line ending variants (LF vs CRLF)
    // -------------------------------------------------------------------------

    public function testLfLineEndingsAreHandled(): void
    {
        $raw = "HTTP/1.1 200 OK\nContent-Type: application/json\nX-Custom: value\n";

        $result = $this->parseHeaders($raw);

        $this->assertSame(['application/json'], $result['Content-Type']);
        $this->assertSame(['value'], $result['X-Custom']);
    }

    public function testCrlfLineEndingsAreHandled(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Custom: value\r\n";

        $result = $this->parseHeaders($raw);

        $this->assertSame(['application/json'], $result['Content-Type']);
        $this->assertSame(['value'], $result['X-Custom']);
    }

    // -------------------------------------------------------------------------
    // Header name case preservation
    // -------------------------------------------------------------------------

    public function testHeaderNamesPreserveOriginalCase(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-type: text/plain\r\nX-Custom-Header: value\r\n";

        $result = $this->parseHeaders($raw);

        // Keys are stored as-is from the wire — lookups are case-insensitive in Response
        $this->assertArrayHasKey('content-type', $result);
        $this->assertArrayHasKey('X-Custom-Header', $result);
    }

    // -------------------------------------------------------------------------
    // Header values containing colons
    // -------------------------------------------------------------------------

    public function testHeaderValueContainingColonIsParsedCorrectly(): void
    {
        // The colon in the value must not be treated as a name/value separator
        $raw = "HTTP/1.1 200 OK\r\nDate: Mon, 01 Jan 2026 12:00:00 GMT\r\n";

        $result = $this->parseHeaders($raw);

        $this->assertSame(['Mon, 01 Jan 2026 12:00:00 GMT'], $result['Date']);
    }

    public function testSetCookieValueContainingCommaIsStoredIntact(): void
    {
        // Cookie values can contain commas — they must never be folded/split
        $raw = "HTTP/1.1 200 OK\r\nSet-Cookie: expires=Thu, 01 Jan 2026 00:00:00 GMT; Path=/\r\n";

        $result = $this->parseHeaders($raw);

        $this->assertSame(
            ['expires=Thu, 01 Jan 2026 00:00:00 GMT; Path=/'],
            $result['Set-Cookie']
        );
    }
}
