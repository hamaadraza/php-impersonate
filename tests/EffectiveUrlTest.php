<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Response;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where a request ended up. The engines follow redirects, so without this a
 * caller could not tell which host actually answered — or enforce any URL
 * policy of their own on the hops.
 */
class EffectiveUrlTest extends TestCase
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
    public function testEffectiveUrlIsTheLastHopOfARedirectChain(string $engine): void
    {
        $response = (new PHPImpersonate('chrome146', 30, [], $engine))
            ->sendGet(TestServer::httpbin('/redirect-to?url=%2Fanything%3Fhop%3Dfinal'));

        $this->assertSame(200, $response->status());
        $this->assertSame(TestServer::httpbin('/anything?hop=final'), $response->effectiveUrl(), "$engine reported the wrong final URL");
    }

    #[DataProvider('engineProvider')]
    public function testEffectiveUrlIsTheRequestUrlWhenNothingRedirected(string $engine): void
    {
        $url = TestServer::httpbin('/get?a=1');
        $response = (new PHPImpersonate('chrome146', 30, [], $engine))->sendGet($url);

        $this->assertSame($url, $response->effectiveUrl());
    }

    #[DataProvider('engineProvider')]
    public function testEffectiveUrlIsTheRedirectResponseWhenTheCapIsHit(string $engine): void
    {
        // With redirects disabled the 3xx itself is the response, and that is
        // where the transfer ended — the Location is for the caller to vet.
        $url = TestServer::httpbin('/redirect-to?url=%2Fanything');
        $response = (new PHPImpersonate('chrome146', 30, ['max-redirs' => 0], $engine))->sendGet($url);

        $this->assertSame(302, $response->status());
        $this->assertSame($url, $response->effectiveUrl());
        $this->assertSame('/anything', $response->header('Location'));
    }

    public function testAHandBuiltResponseHasNoEffectiveUrl(): void
    {
        $this->assertNull((new Response('', 200, []))->effectiveUrl());
    }
}
