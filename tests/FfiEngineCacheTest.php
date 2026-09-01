<?php

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;

/**
 * The process-wide FFI engine cache must stay bounded: every entry pins a curl
 * handle plus its kept-alive connections, and callers can mint unlimited
 * distinct keys (e.g. a new proxy per request), so without an LRU cap a
 * long-running worker would leak handles. No network needed — engines are
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

        // Fill the cache with $max distinct configurations.
        $first = $this->engineFor('ref0');
        $second = $this->engineFor('ref1');
        for ($i = 2; $i < $max; $i++) {
            $this->engineFor("ref$i");
        }
        $this->assertCount($max, $this->cachedEngines());

        // Touch ref0 so ref1 becomes the least recently used entry.
        $this->assertSame($first, $this->engineFor('ref0'), 'cache hit must return the same engine');

        // One more distinct configuration: the cap holds, ref1 is evicted.
        $this->engineFor("ref$max");
        $this->assertCount($max, $this->cachedEngines());
        $this->assertSame($first, $this->engineFor('ref0'), 'recently used engine must survive eviction');
        $this->assertNotSame($second, $this->engineFor('ref1'), 'least recently used engine must be evicted');
    }

    /**
     * Two spellings of one configuration must share an engine. The key is built
     * from the normalised options, and normalisation canonicalises key order —
     * without that, each spelling pinned its own handle and connection pool.
     */
    public function testEquivalentOptionsInAnyOrderShareOneEngine(): void
    {
        $engine = fn (array $options): CurlImpersonate => $this->engineForOptions($options);

        $first = $engine(['proxy' => 'http://127.0.0.1:8080', 'max-redirs' => 3]);
        $second = $engine(['max-redirs' => 3, 'proxy' => 'http://127.0.0.1:8080']);

        $this->assertSame($first, $second, 'option order must not change the cache key');
        $this->assertCount(1, $this->cachedEngines());
    }

    /**
     * Loose values are canonicalised too, so `'3'` and `3` are one configuration.
     */
    public function testEquivalentOptionValuesShareOneEngine(): void
    {
        $this->assertSame(
            $this->engineForOptions(['max-redirs' => 3, 'insecure' => true]),
            $this->engineForOptions(['max-redirs' => '3', 'insecure' => 'yes'])
        );
        $this->assertCount(1, $this->cachedEngines());
    }

    public function testCloseFfiEnginesEmptiesCache(): void
    {
        $engine = $this->engineFor('ref0');
        $this->assertCount(1, $this->cachedEngines());

        PHPImpersonate::closeFfiEngines();

        $this->assertCount(0, $this->cachedEngines());
        $this->assertNotSame($engine, $this->engineFor('ref0'), 'engines must be recreated after close');
    }

    /**
     * Resolve the cached engine for a client whose options make the key unique.
     */
    private function engineFor(string $referer): CurlImpersonate
    {
        return $this->engineForOptions(['referer' => "https://$referer.test/"]);
    }

    /**
     * Resolve the cached engine for a client with the given options.
     *
     * @param array<string,mixed> $curlOptions
     */
    private function engineForOptions(array $curlOptions): CurlImpersonate
    {
        $client = new PHPImpersonate('chrome146', 30, $curlOptions, PHPImpersonate::ENGINE_FFI);

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
