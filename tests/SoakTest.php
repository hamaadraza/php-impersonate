<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Sustained load on one client: the question a queue worker asks and no
 * other test in this suite does, since every other test is a handful of
 * requests in a fresh process.
 *
 * What it caught, the first time it was run by hand: the FFI engine grew by
 * about 3.5 KB per request, without bound — 350 MB per 100,000 requests.
 * Each request assigned two fresh PHP closures to C function-pointer fields
 * (the body and header write callbacks), and PHP allocates a libffi
 * trampoline for every such assignment that it never frees. The callbacks
 * are now created once per engine; this pins that, plus file descriptors,
 * temp files and child processes, for both engines.
 */
class SoakTest extends TestCase
{
    /** Requests per engine. Enough for a per-request leak to dwarf allocator noise. */
    private const REQUESTS = ['ffi' => 1500, 'process' => 300];

    /** Requests sent before measuring, so allocator and cache warm-up is excluded. */
    private const WARMUP = 100;

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
    public function testAClientDoesNotLeakUnderSustainedLoad(string $engine): void
    {
        $url = TestServer::httpbin('/get');
        $client = new PHPImpersonate('chrome146', 15, [], $engine);
        $requests = self::REQUESTS[$engine];

        for ($i = 0; $i < self::WARMUP; $i++) {
            $client->sendGet($url);
        }
        gc_collect_cycles();

        $phpBefore = memory_get_usage();
        $rssBefore = self::rssKb();
        $fdsBefore = self::openFds();
        $tempBefore = self::tempFiles();

        $errors = 0;
        for ($i = 0; $i < $requests; $i++) {
            if ($client->sendGet($url)->status() !== 200) {
                $errors++;
            }
        }
        gc_collect_cycles();

        $this->assertSame(0, $errors, "$engine: requests failed during the soak");

        // PHP-heap growth is the portable signal: the leaked callbacks were
        // PHP closures and CData, visible here. Allow generous allocator slack;
        // the leak was ~5 MB at this request count.
        $phpGrowth = memory_get_usage() - $phpBefore;
        $this->assertLessThan(
            512 * 1024,
            $phpGrowth,
            sprintf('%s: PHP memory grew by %.1f KB over %d requests (%.2f KB/request) — something is retained per request', $engine, $phpGrowth / 1024, $requests, $phpGrowth / 1024 / $requests)
        );

        // Resident memory catches growth PHP cannot see (C allocations). Only
        // where /proc exists; the threshold leaves room for allocator noise.
        if ($rssBefore !== null) {
            $rssGrowth = (int) self::rssKb() - $rssBefore;
            $this->assertLessThan(
                2048,
                $rssGrowth,
                sprintf('%s: RSS grew by %d KB over %d requests (%.2f KB/request)', $engine, $rssGrowth, $requests, $rssGrowth / $requests)
            );
        }

        if ($fdsBefore !== null) {
            $this->assertSame($fdsBefore, self::openFds(), "$engine: file descriptors leaked");
        }

        $this->assertSame($tempBefore, self::tempFiles(), "$engine: temp files left behind");
    }

    /**
     * Engines are not only long-lived: closeFfiEngines() discards them between
     * batches, and the cache evicts its least recently used entry once a
     * process uses more than a handful of browsers. Both build fresh engines,
     * so anything a constructor retains for the process leaks by another route
     * — which is what per-engine callbacks did, at 1.79 KB per cycle.
     */
    public function testCyclingEnginesDoesNotAccumulate(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }

        $url = TestServer::httpbin('/get');
        $cycle = function () use ($url): void {
            (new PHPImpersonate('chrome146', 15, [], PHPImpersonate::ENGINE_FFI))->sendGet($url);
            PHPImpersonate::closeFfiEngines();
        };

        for ($i = 0; $i < 10; $i++) {
            $cycle();
        }
        gc_collect_cycles();

        $phpBefore = memory_get_usage();
        $rssBefore = self::rssKb();

        $cycles = 150;
        for ($i = 0; $i < $cycles; $i++) {
            $cycle();
        }
        gc_collect_cycles();

        $phpGrowth = memory_get_usage() - $phpBefore;
        $this->assertLessThan(
            256 * 1024,
            $phpGrowth,
            sprintf('PHP memory grew by %.1f KB over %d engine create/close cycles (%.2f KB/cycle)', $phpGrowth / 1024, $cycles, $phpGrowth / 1024 / $cycles)
        );

        if ($rssBefore !== null) {
            $rssGrowth = (int) self::rssKb() - $rssBefore;
            $this->assertLessThan(
                1024,
                $rssGrowth,
                sprintf('RSS grew by %d KB over %d engine create/close cycles (%.2f KB/cycle)', $rssGrowth, $cycles, $rssGrowth / $cycles)
            );
        }
    }

    private static function rssKb(): ?int
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status === false) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private static function openFds(): ?int
    {
        if (! is_dir('/proc/self/fd')) {
            return null;
        }

        return count(scandir('/proc/self/fd') ?: []) - 2;
    }

    private static function tempFiles(): int
    {
        return count(glob(sys_get_temp_dir() . '/curl_*') ?: []);
    }
}
