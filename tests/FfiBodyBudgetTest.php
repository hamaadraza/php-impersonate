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
 * The body comes from a `php -S` started here: httpbin caps /bytes at 100 KB.
 */
final class FfiBodyBudgetTest extends TestCase
{
    /** curl's CURLE_FILESIZE_EXCEEDED, the code the max-filesize cap throws with. */
    private const CURLE_FILESIZE_EXCEEDED = 63;

    private const MIB = 1024 * 1024;

    /** @var resource|null */
    private $server = null;

    private string|false $originalMemoryLimit = false;

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

        // 32 MiB of headroom: the budget lands at about 13 MiB, and the string
        // growth that budget allows peaks well under the limit.
        $this->assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * self::MIB)));

        try {
            $client->sendGet("$base/?bytes=" . (48 * self::MIB));
            $this->fail('a 48 MiB body was buffered under a memory_limit 32 MiB above usage');
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

        $this->assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * self::MIB)));

        $response = $client->sendGet("$base/?bytes=" . (4 * self::MIB));

        $this->assertSame(200, $response->status());
        $this->assertSame(4 * self::MIB, strlen($response->body()));
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

    private function bodyBudget(): int
    {
        $budget = (new ReflectionClass(CurlImpersonate::class))->getMethod('bodyBudget');

        return $budget->invoke(null);
    }

    /**
     * Start `php -S` on a free loopback port with the body router and return
     * its base URL.
     */
    private function startBodyServer(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "cannot find a free port: $errstr");
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/body-server.php'],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            __DIR__ . '/fixtures'
        );
        $this->assertIsResource($this->server, 'php -S did not start');

        // A full HTTP exchange, not a bare connect: on Linux a connect to a
        // closed loopback port in the ephemeral range can succeed by
        // connecting to ITSELF (the kernel picks the same port as source), so
        // a connect-only probe reported the server up at 0 ms and the first
        // real request then failed with "could not connect".
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($connection !== false) {
                stream_set_timeout($connection, 2);
                fwrite($connection, "GET /?bytes=1 HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                $reply = (string) stream_get_contents($connection, 512);
                fclose($connection);

                if (str_starts_with($reply, 'HTTP/1.') && str_contains($reply, ' 200 ')) {
                    return "http://127.0.0.1:$port";
                }
            }
            usleep(50000);
        }

        $this->fail("php -S did not answer on port $port within five seconds");
    }
}
