<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;

/**
 * The FFI engine cache across pcntl_fork().
 *
 * A forked child inherits the parent's engines and, with them, every kept-alive
 * connection: one kernel socket shared by two processes. The child must get
 * engines of its own, and neither its use of the cache nor its exit may free
 * the parent's handles — a curl_easy_cleanup() in the child would send
 * close_notify / FIN on the parent's connection. Queue workers and process
 * managers (pcntl-based ones, Laravel Horizon, Symfony Messenger with
 * pcntl) fork exactly like this.
 */
final class FfiForkTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine unavailable: ' . PHPImpersonate::ffiUnavailableReason());
        }
        TestServer::requireHttpbin($this);
    }

    public function testAForkedChildGetsItsOwnEngineAndTheParentsKeepsWorking(): void
    {
        $client = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);
        $this->assertSame(200, $client->sendGet(TestServer::httpbin('/get'))->status());
        $parentEngines = $this->engines();
        $this->assertNotSame([], $parentEngines, 'sanity: the parent has a cached engine');
        // Held here so the object cannot be freed and its slot recycled: PHP
        // reuses object handles, so a fresh engine in the child would
        // otherwise get the very same spl_object_id and look identical.
        $inherited = $parentEngines[0];

        $report = tempnam(sys_get_temp_dir(), 'php-impersonate-fork-');
        $this->assertNotFalse($report);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child. Report through a file and leave with SIGKILL: PHPUnit's
            // own state must not be torn down twice.
            $result = ['ok' => false, 'fresh' => false, 'inherited_abandoned' => false, 'error' => null];

            try {
                $status = $client->sendGet(TestServer::httpbin('/get'))->status();
                $result['ok'] = $status === 200;
                $result['fresh'] = ! in_array($inherited, $this->engines(), true);
                $result['inherited_abandoned'] = $this->handleOf($inherited) === null;
                // The child's own engines are its own: freeing them here is
                // correct and exercises the pid check on the shutdown path.
                PHPImpersonate::closeFfiEngines();
            } catch (\Throwable $e) {
                $result['error'] = $e->getMessage();
            }

            file_put_contents($report, json_encode($result));
            posix_kill((int) getmypid(), SIGKILL);
        }

        pcntl_waitpid($pid, $status);
        $child = json_decode((string) file_get_contents($report), true);
        @unlink($report);

        $this->assertIsArray($child, 'the child never reported');
        $this->assertNull($child['error'], 'the child request failed: ' . ($child['error'] ?? ''));
        $this->assertTrue($child['ok']);
        $this->assertTrue($child['fresh'], 'the child reused the parent\'s engine (and its socket)');
        $this->assertTrue($child['inherited_abandoned'], 'the inherited engine kept a handle the child could free');

        // The parent's engine is untouched by anything the child did.
        $this->assertSame($parentEngines, $this->engines());
        $this->assertNotNull($this->handleOf($inherited));
        $this->assertSame(200, $client->sendGet(TestServer::httpbin('/get'))->status());
    }

    public function testAForkedChildThatExitsWithoutRequestingLeavesTheParentsEngineAlone(): void
    {
        $client = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);
        $this->assertSame(200, $client->sendGet(TestServer::httpbin('/get'))->status());
        $parentEngines = $this->engines();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // What the inherited shutdown hook does at a child's exit: with
            // the pid check it abandons the handles instead of freeing them.
            PHPImpersonate::closeFfiEngines();
            posix_kill((int) getmypid(), SIGKILL);
        }

        pcntl_waitpid($pid, $status);

        $this->assertSame($parentEngines, $this->engines());
        $this->assertNotNull($this->handleOf($parentEngines[0]));
        $this->assertSame(200, $client->sendGet(TestServer::httpbin('/get'))->status());
    }

    /**
     * The cached engine objects, in cache order.
     *
     * @return list<CurlImpersonate>
     */
    private function engines(): array
    {
        $engines = (new ReflectionClass(PHPImpersonate::class))->getProperty('ffiEngines')->getValue();

        /** @var list<CurlImpersonate> */
        return array_values(is_array($engines) ? $engines : []);
    }

    private function handleOf(CurlImpersonate $engine): mixed
    {
        return (new ReflectionClass(CurlImpersonate::class))->getProperty('handle')->getValue($engine);
    }
}
