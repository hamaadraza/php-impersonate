<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Browser\BrowserName;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;

/**
 * The process-wide FFI engine cache must stay bounded and must be keyed by
 * BROWSER alone. Every entry pins a curl handle plus its kept-alive
 * connections, so without an LRU cap a worker rotating across every profile
 * would leak handles — and with the curl options in the key, the ordinary
 * scraping pattern (a different proxy per request) minted a new engine per
 * request and never reused a connection. No network needed — engines are
 * created without performing requests.
 */
class FfiEngineCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }
        PHPImpersonate::closeFfiEngines();
    }

    protected function tearDown(): void
    {
        PHPImpersonate::closeFfiEngines();
    }

    public function testCacheIsBoundedAndEvictsLeastRecentlyUsed(): void
    {
        $max = $this->maxEngines();
        $browsers = array_slice(BrowserName::getAll(), 0, $max + 1);
        $this->assertCount($max + 1, $browsers, 'need more profiles than the cache bound to test eviction');

        // Fill the cache with $max distinct browsers.
        $first = $this->engineFor($browsers[0]);
        $second = $this->engineFor($browsers[1]);
        for ($i = 2; $i < $max; $i++) {
            $this->engineFor($browsers[$i]);
        }
        $this->assertCount($max, $this->cachedEngines());

        // Touch the first so the second becomes the least recently used entry.
        $this->assertSame($first, $this->engineFor($browsers[0]), 'cache hit must return the same engine');

        // One more distinct browser: the cap holds, the second is evicted.
        $this->engineFor($browsers[$max]);
        $this->assertCount($max, $this->cachedEngines());
        $this->assertSame($first, $this->engineFor($browsers[0]), 'recently used engine must survive eviction');
        $this->assertNotSame($second, $this->engineFor($browsers[1]), 'least recently used engine must be evicted');
    }

    /**
     * A different proxy — or any other curl option — per request must NOT mint
     * a new engine. libcurl's connection cache already refuses to reuse a
     * connection whose proxy or TLS settings differ, and every option is
     * re-applied after curl_easy_reset(), so one handle serves them all.
     */
    public function testDifferentOptionsShareOneEngineForTheSameBrowser(): void
    {
        $engine = fn (array $options): CurlImpersonate => $this->engineForOptions('chrome146', $options);

        $shared = $engine([]);
        $this->assertSame($shared, $engine(['proxy' => 'http://127.0.0.1:8080']));
        $this->assertSame($shared, $engine(['proxy' => 'http://127.0.0.1:8081', 'insecure' => true]));
        $this->assertSame($shared, $engine(['max-redirs' => 3, 'referer' => 'https://ref.test/']));

        $this->assertCount(1, $this->cachedEngines(), 'options must not fragment the cache');
    }

    /**
     * Rotating proxies is the pattern that used to thrash the cache: 17
     * proxies exceeded the 16-entry bound, so every request evicted the
     * previous engine and paid FFI::cdef() plus curl_easy_init() again.
     */
    public function testRotatingProxiesDoNotThrashTheCache(): void
    {
        $engines = [];
        for ($port = 9000; $port < 9000 + $this->maxEngines() + 1; $port++) {
            $engines[] = $this->engineForOptions('chrome146', ['proxy' => "http://127.0.0.1:$port"]);
        }

        $this->assertCount(1, array_unique(array_map('spl_object_id', $engines)));
        $this->assertCount(1, $this->cachedEngines());
    }

    public function testDifferentBrowsersNeverShareAnEngine(): void
    {
        // A connection carries the TLS fingerprint it was opened with, so two
        // browsers on one handle would let one profile's connection serve the
        // other's requests.
        $this->assertNotSame($this->engineFor('chrome146'), $this->engineFor('firefox147'));
        $this->assertCount(2, $this->cachedEngines());
    }

    public function testCloseFfiEnginesEmptiesCache(): void
    {
        $engine = $this->engineFor('chrome146');
        $this->assertCount(1, $this->cachedEngines());

        PHPImpersonate::closeFfiEngines();

        $this->assertCount(0, $this->cachedEngines());
        $this->assertNotSame($engine, $this->engineFor('chrome146'), 'engines must be recreated after close');
    }

    private function engineFor(string $browser): CurlImpersonate
    {
        return $this->engineForOptions($browser, []);
    }

    /**
     * @param array<string,mixed> $curlOptions
     */
    private function engineForOptions(string $browser, array $curlOptions): CurlImpersonate
    {
        $client = new PHPImpersonate($browser, 30, $curlOptions, PHPImpersonate::ENGINE_FFI);

        $method = (new ReflectionClass(PHPImpersonate::class))->getMethod('ffiEngine');
        $method->setAccessible(true);

        return $method->invoke($client);
    }

    /**
     * @return array<string, CurlImpersonate>
     */
    private function cachedEngines(): array
    {
        $property = (new ReflectionClass(PHPImpersonate::class))->getProperty('ffiEngines');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function maxEngines(): int
    {
        return (new ReflectionClass(PHPImpersonate::class))->getConstant('MAX_FFI_ENGINES');
    }
}
