<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * The FFI engine against a body that memory_limit cannot hold.
 *
 * The write callback appends each chunk to a PHP string, and running past
 * memory_limit in there is a fatal error that unwinds through libcurl's C
 * frames — no exception, a dead worker. `max-filesize` did not prevent it:
 * its 256 MiB default is above PHP's default memory_limit of 128M, so with
 * stock settings any body between roughly 60 MB and 256 MB was fatal. The
 * engine now budgets the body against memory_limit before each request and
 * refuses the chunk that would cross it, which curl reports as a write error
 * and the engine throws with curl's own "too large" code.
 *
 * The body comes from a small socket server started here (see
 * tests/fixtures/body-server.php): httpbin caps /bytes at 100 KB and streams
 * /drip byte by byte, and `php -S` dropped a large body on Windows.
 */
final class FfiBodyBudgetTest extends TestCase
{
    /** curl's CURLE_FILESIZE_EXCEEDED, the code the max-filesize cap throws with. */
    private const CURLE_FILESIZE_EXCEEDED = 63;

    private const MIB = 1024 * 1024;

    /**
     * Headroom above current usage while the body arrives. The budget is at
     * most half of it less a margin — about 5 MiB — and the string growth that
     * budget allows peaks well under it.
     */
    private const HEADROOM = 16 * self::MIB;

    private string|false $originalMemoryLimit = false;

    /** @var resource|null */
    private $server = null;

    /** @var list<resource> */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine unavailable: ' . PHPImpersonate::ffiUnavailableReason());
        }

        $this->originalMemoryLimit = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        if ($this->originalMemoryLimit !== false) {
            ini_set('memory_limit', $this->originalMemoryLimit);
        }

        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server);
            $this->server = null;
        }
    }

    public function testABodyThatWouldExhaustMemoryLimitIsRefusedWithAnException(): void
    {
        $base = $this->startBodyServer();
        $client = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);

        // A first request settles the heap (engine, handle, connection) so the
        // headroom below is measured against what the transfer will see.
        $this->assertSame(1000, strlen($client->sendGet("$base/?bytes=1000")->body()));

        $this->assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + self::HEADROOM)));

        try {
            $client->sendGet("$base/?bytes=" . (12 * self::MIB));
            $this->fail('a 12 MiB body was buffered under a memory_limit 16 MiB above usage');
        } catch (RequestException $e) {
            $this->assertSame(self::CURLE_FILESIZE_EXCEEDED, $e->getCode(), $e->getMessage());
            $this->assertStringContainsString('memory_limit', $e->getMessage());
        }

        // The abort leaves the engine — and its shared handle — usable.
        $this->assertSame(1000, strlen($client->sendGet("$base/?bytes=1000")->body()));
    }

    public function testABodyWithinTheBudgetIsReturnedWhole(): void
    {
        $base = $this->startBodyServer();
        $client = new PHPImpersonate('chrome146', 30, [], PHPImpersonate::ENGINE_FFI);
        $this->assertSame(1000, strlen($client->sendGet("$base/?bytes=1000")->body()));

        $this->assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + self::HEADROOM)));

        $response = $client->sendGet("$base/?bytes=" . (2 * self::MIB));

        $this->assertSame(200, $response->status());
        $this->assertSame(2 * self::MIB, strlen($response->body()));
    }

    public function testNoMemoryLimitMeansNoBudget(): void
    {
        ini_set('memory_limit', '-1');

        $this->assertSame(PHP_INT_MAX, $this->bodyBudget());
    }

    public function testTheBudgetIsAtMostHalfOfWhatIsFree(): void
    {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * self::MIB));

        $budget = $this->bodyBudget();

        $this->assertGreaterThan(16 * self::MIB, $budget, 'a quarter of the headroom must always be usable');
        $this->assertLessThanOrEqual(32 * self::MIB, $budget, 'appending can hold two copies of the body');
    }

    #[DataProvider('memoryLimitProvider')]
    public function testMemoryLimitShorthandIsParsedAsPhpDoes(string $setting, int $bytes): void
    {
        $parse = (new ReflectionClass(CurlImpersonate::class))->getMethod('memoryLimitBytes');

        ini_set('memory_limit', $setting);

        $this->assertSame($bytes, $parse->invoke(null));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function memoryLimitProvider(): array
    {
        return [
            'megabytes' => ['128M', 128 * self::MIB],
            'gigabytes' => ['1G', 1024 * self::MIB],
            'kilobytes' => ['524288K', 512 * self::MIB],
            'bare bytes' => ['268435456', 256 * self::MIB],
            'unlimited' => ['-1', -1],
        ];
    }

    /**
     * Start the body server and return its base URL. The server picks its
     * own port and announces it, so there is no port race and no connect
     * probe — a probe against a not-yet-listening loopback port can even
     * connect to itself on Linux.
     */
    private function startBodyServer(): string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/body-server.php'],
            [1 => ['pipe', 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            __DIR__ . '/fixtures'
        );
        $this->assertIsResource($server, 'the body server did not start');
        $this->server = $server;
        $this->serverPipes = array_values($pipes);

        stream_set_timeout($pipes[1], 10);
        $line = fgets($pipes[1]);
        $this->assertIsString($line, 'the body server did not announce its port within ten seconds');
        $this->assertMatchesRegularExpression('/^port=\d+$/', trim($line));

        return 'http://127.0.0.1:' . (int) substr(trim($line), 5);
    }

    private function bodyBudget(): int
    {
        $budget = (new ReflectionClass(CurlImpersonate::class))->getMethod('bodyBudget');

        return $budget->invoke(null);
    }
}
