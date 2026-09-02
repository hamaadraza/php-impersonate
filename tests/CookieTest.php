<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cookies across a redirect chain, on both engines.
 *
 * The canonical login flow is POST → 302 carrying Set-Cookie → GET. Before
 * the fix neither engine ran a cookie engine, so the cookie set on the hop
 * was neither sent on the follow-up nor surfaced to the caller: the request
 * came back 200 with an empty session, and nothing said so.
 */
class CookieTest extends TestCase
{
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
    public function testACookieSetOnARedirectHopIsSentOnTheFollowUp(string $engine): void
    {
        // httpbin answers this with a 302 + Set-Cookie and redirects to /cookies,
        // which echoes the cookies it received.
        $response = (new PHPImpersonate('chrome146', 30, [], $engine))
            ->sendGet(TestServer::httpbin('/cookies/set?session=abc123'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['session' => 'abc123'], TestServer::cookies($response), "$engine dropped the cookie on the hop");
    }

    #[DataProvider('engineProvider')]
    public function testEveryHopsSetCookieIsSurfacedToTheCaller(string $engine): void
    {
        $response = (new PHPImpersonate('chrome146', 30, [], $engine))
            ->sendGet(TestServer::httpbin('/cookies/set?session=abc123'));

        // The final response (/cookies) sets nothing; the 302 did. headers()
        // describes the final response only, so the hop's cookie must come
        // through the dedicated accessor.
        $this->assertSame([], $response->headerAll('Set-Cookie'));

        $cookies = $response->setCookieHeaders();
        $this->assertCount(1, $cookies, "$engine lost the redirect hop's Set-Cookie");
        $this->assertStringStartsWith('session=abc123', $cookies[0]);
    }

    /**
     * The FFI engine shares one easy handle per configuration across every
     * client in the process, and curl_easy_reset() keeps the cookie jar. A
     * cookie one caller collected must not ride along on the next caller's
     * request.
     */
    public function testCookiesDoNotLeakBetweenClientsOnTheFfiEngine(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }

        $first = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);
        $first->sendGet(TestServer::httpbin('/cookies/set?session=abc123'));

        // Same key, so the same cached handle.
        $second = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);
        $seen = TestServer::cookies($second->sendGet(TestServer::httpbin('/cookies')));

        $this->assertSame([], $seen, 'a cookie from one request leaked into the next on the shared handle');

        // Nor does the first client itself keep it: the engine is per request,
        // and persisting a session is the caller's job via setCookieHeaders().
        $this->assertSame([], TestServer::cookies($first->sendGet(TestServer::httpbin('/cookies'))));
    }

    #[DataProvider('engineProvider')]
    public function testACallerSuppliedCookieHeaderStillWorks(string $engine): void
    {
        $seen = TestServer::cookies((new PHPImpersonate('chrome146', 30, [], $engine))
            ->sendGet(TestServer::httpbin('/cookies'), ['Cookie' => 'session=fromcaller']));

        $this->assertSame(['session' => 'fromcaller'], $seen);
    }
}
