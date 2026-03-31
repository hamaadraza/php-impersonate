<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Response;
use PHPUnit\Framework\Attributes\DataProvider;

class ResponseTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string[]> $headers
     */
    private function makeResponse(
        array $headers = [],
        int $status = 200,
        string $body = ''
    ): Response {
        return new Response($body, $status, $headers);
    }

    // -------------------------------------------------------------------------
    // headers()
    // -------------------------------------------------------------------------

    public function testHeadersReturnsMultiValueMap(): void
    {
        $response = $this->makeResponse([
            'Content-Type' => ['application/json'],
            'Set-Cookie' => ['sessionid=abc; Path=/; HttpOnly', 'csrftoken=xyz; Path=/'],
        ]);

        $headers = $response->headers();

        $this->assertIsArray($headers);
        $this->assertSame(['application/json'], $headers['Content-Type']);
        $this->assertSame(
            ['sessionid=abc; Path=/; HttpOnly', 'csrftoken=xyz; Path=/'],
            $headers['Set-Cookie']
        );
    }

    public function testHeadersReturnsEmptyArrayWhenNoHeaders(): void
    {
        $this->assertSame([], $this->makeResponse()->headers());
    }

    public function testEachHeaderValueIsAlwaysAnArray(): void
    {
        $response = $this->makeResponse([
            'Content-Length' => ['512'],
            'X-Custom' => ['value'],
        ]);

        foreach ($response->headers() as $values) {
            $this->assertIsArray($values);
        }
    }

    // -------------------------------------------------------------------------
    // hasHeader()
    // -------------------------------------------------------------------------

    public function testHasHeaderReturnsTrueForPresentHeader(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    public function testHasHeaderReturnsFalseForAbsentHeader(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertFalse($response->hasHeader('X-Missing'));
    }

    public function testHasHeaderIsCaseInsensitive(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertTrue($response->hasHeader('content-type'));
        $this->assertTrue($response->hasHeader('CONTENT-TYPE'));
        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    public function testHasHeaderReturnsFalseOnEmptyHeaders(): void
    {
        $this->assertFalse($this->makeResponse()->hasHeader('Set-Cookie'));
    }

    // -------------------------------------------------------------------------
    // header()
    // -------------------------------------------------------------------------

    public function testHeaderReturnsFirstValueForSingleValueHeader(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertSame('application/json', $response->header('Content-Type'));
    }

    public function testHeaderReturnsFirstValueForMultiValueHeader(): void
    {
        $response = $this->makeResponse([
            'Set-Cookie' => ['sessionid=abc; Path=/; HttpOnly', 'csrftoken=xyz; Path=/'],
        ]);

        $this->assertSame('sessionid=abc; Path=/; HttpOnly', $response->header('Set-Cookie'));
    }

    public function testHeaderReturnsNullByDefaultWhenAbsent(): void
    {
        $this->assertNull($this->makeResponse()->header('X-Missing'));
    }

    public function testHeaderReturnsProvidedDefaultWhenAbsent(): void
    {
        $response = $this->makeResponse();

        $this->assertSame('fallback', $response->header('X-Missing', 'fallback'));
    }

    public function testHeaderIsCaseInsensitive(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['text/html']]);

        $this->assertSame('text/html', $response->header('content-type'));
        $this->assertSame('text/html', $response->header('CONTENT-TYPE'));
        $this->assertSame('text/html', $response->header('Content-Type'));
    }

    public function testHeaderDoesNotReturnDefaultWhenHeaderIsPresent(): void
    {
        $response = $this->makeResponse(['X-Custom' => ['real-value']]);

        $this->assertSame('real-value', $response->header('X-Custom', 'should-not-return'));
    }

    // -------------------------------------------------------------------------
    // headerAll()
    // -------------------------------------------------------------------------

    public function testHeaderAllReturnsAllValuesForMultiValueHeader(): void
    {
        $cookies = [
            'sessionid=abc; Path=/; HttpOnly',
            'csrftoken=xyz; Path=/; SameSite=Lax',
            'theme=dark; Path=/',
        ];

        $response = $this->makeResponse(['Set-Cookie' => $cookies]);

        $this->assertSame($cookies, $response->headerAll('Set-Cookie'));
    }

    public function testHeaderAllReturnsSingleElementArrayForSingleValueHeader(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertSame(['application/json'], $response->headerAll('Content-Type'));
    }

    public function testHeaderAllReturnsEmptyArrayWhenAbsent(): void
    {
        $this->assertSame([], $this->makeResponse()->headerAll('X-Missing'));
    }

    public function testHeaderAllIsCaseInsensitive(): void
    {
        $response = $this->makeResponse([
            'Set-Cookie' => ['a=1', 'b=2'],
        ]);

        $this->assertSame(['a=1', 'b=2'], $response->headerAll('set-cookie'));
        $this->assertSame(['a=1', 'b=2'], $response->headerAll('SET-COOKIE'));
        $this->assertSame(['a=1', 'b=2'], $response->headerAll('Set-Cookie'));
    }

    public function testHeaderAllPreservesOrderOfValues(): void
    {
        $values = ['first', 'second', 'third'];
        $response = $this->makeResponse(['X-Multi' => $values]);

        $this->assertSame($values, $response->headerAll('X-Multi'));
    }

    public function testHeaderVsHeaderAllBehaviourDifference(): void
    {
        $response = $this->makeResponse([
            'Set-Cookie' => ['first=1', 'second=2', 'third=3'],
        ]);

        // header() gives the first one only
        $this->assertSame('first=1', $response->header('Set-Cookie'));

        // headerAll() gives every one
        $this->assertCount(3, $response->headerAll('Set-Cookie'));
    }

    // -------------------------------------------------------------------------
    // dump()
    // -------------------------------------------------------------------------

    public function testDumpContainsStatusCode(): void
    {
        $response = $this->makeResponse([], 201);

        $this->assertStringContainsString('HTTP Status: 201', $response->dump());
    }

    public function testDumpRendersEachCookieOnSeparateLine(): void
    {
        $response = $this->makeResponse([
            'Set-Cookie' => [
                'sessionid=abc; Path=/; HttpOnly',
                'csrftoken=xyz; Path=/; SameSite=Lax',
            ],
        ]);

        $dump = $response->dump();

        $this->assertStringContainsString("Set-Cookie: sessionid=abc; Path=/; HttpOnly", $dump);
        $this->assertStringContainsString("Set-Cookie: csrftoken=xyz; Path=/; SameSite=Lax", $dump);

        // The header name must appear once per value, not once for all values
        $this->assertEquals(2, substr_count($dump, 'Set-Cookie:'));
    }

    public function testDumpRendersSingleValueHeaderCorrectly(): void
    {
        $response = $this->makeResponse(['Content-Type' => ['application/json']]);

        $this->assertStringContainsString('Content-Type: application/json', $response->dump());
        $this->assertEquals(1, substr_count($response->dump(), 'Content-Type:'));
    }

    public function testDumpContainsBodyPreview(): void
    {
        $response = $this->makeResponse([], 200, 'hello world');

        $this->assertStringContainsString('hello world', $response->dump());
    }

    public function testDumpTruncatesLongBody(): void
    {
        $body = str_repeat('x', 600);
        $response = $this->makeResponse([], 200, $body);

        $this->assertStringContainsString('...[truncated]', $response->dump());
    }

    public function testDumpDoesNotTruncateShortBody(): void
    {
        $response = $this->makeResponse([], 200, 'short');

        $this->assertStringNotContainsString('[truncated]', $response->dump());
    }

    // -------------------------------------------------------------------------
    // toArray()
    // -------------------------------------------------------------------------

    public function testToArrayStructure(): void
    {
        $response = $this->makeResponse(['X-Foo' => ['bar']], 200, 'body');
        $array = $response->toArray();

        $this->assertArrayHasKey('body', $array);
        $this->assertArrayHasKey('statusCode', $array);
        $this->assertArrayHasKey('headers', $array);
    }

    public function testToArrayPreservesMultiValueHeaders(): void
    {
        $cookies = ['sessionid=abc', 'csrftoken=xyz'];
        $response = $this->makeResponse(['Set-Cookie' => $cookies]);

        $array = $response->toArray();

        $this->assertSame($cookies, $array['headers']['Set-Cookie']);
    }

    public function testToArrayHeadersAreMultiValueArrays(): void
    {
        $response = $this->makeResponse([
            'Content-Type' => ['application/json'],
            'Set-Cookie' => ['a=1', 'b=2'],
        ]);

        foreach ($response->toArray()['headers'] as $values) {
            $this->assertIsArray($values);
        }
    }

    // -------------------------------------------------------------------------
    // status(), body(), isSuccess()
    // -------------------------------------------------------------------------

    public function testStatusReturnsStatusCode(): void
    {
        $this->assertSame(404, $this->makeResponse([], 404)->status());
    }

    public function testBodyReturnsBody(): void
    {
        $this->assertSame('hello', $this->makeResponse([], 200, 'hello')->body());
    }

    #[DataProvider('successfulStatusCodeProvider')]
    public function testIsSuccessForSuccessfulStatusCodes(int $status): void
    {
        $this->assertTrue($this->makeResponse([], $status)->isSuccess());
    }

    public static function successfulStatusCodeProvider(): array
    {
        return [
            'OK' => [200],
            'Created' => [201],
            'Accepted' => [202],
            'No Content' => [204],
        ];
    }

    #[DataProvider('failedStatusCodeProvider')]
    public function testIsNotSuccessForNonSuccessfulStatusCodes(int $status): void
    {
        $this->assertFalse($this->makeResponse([], $status)->isSuccess());
    }

    public static function failedStatusCodeProvider(): array
    {
        return [
            'Bad Request' => [400],
            'Unauthorized' => [401],
            'Not Found' => [404],
            'Internal Server Error' => [500],
            'Redirect' => [302],
        ];
    }

    // -------------------------------------------------------------------------
    // json()
    // -------------------------------------------------------------------------

    public function testJsonParsesObjectAsAssociativeArray(): void
    {
        $response = $this->makeResponse([], 200, '{"name":"Alice","age":30}');

        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertSame('Alice', $data['name']);
        $this->assertSame(30, $data['age']);
    }

    public function testJsonThrowsOnInvalidJson(): void
    {
        $response = $this->makeResponse([], 200, 'not json');

        $this->expectException(\JsonException::class);

        $response->json();
    }

    public function testJsonWithAssociativeFalseReturnsObject(): void
    {
        $response = $this->makeResponse([], 200, '{"key":"value"}');

        $data = $response->json(associative: false);

        $this->assertIsObject($data);
        $this->assertSame('value', $data->key);
    }

    // -------------------------------------------------------------------------
    // debug()
    // -------------------------------------------------------------------------

    public function testDebugOutputsAndReturnsSelf(): void
    {
        $response = $this->makeResponse([], 200, 'test');

        ob_start();
        $returned = $response->debug();
        $output = ob_get_clean();

        $this->assertSame($response, $returned);
        $this->assertStringContainsString('HTTP Status: 200', $output);
    }
}
