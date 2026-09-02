<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Process\CurlProcess;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * The executable engine against a child that does not end the way curl
 * should: killed by a signal, or unable to write its temp files at all.
 *
 * Both used to pass as something else. A child killed by a signal reports
 * exit code -1 — the same "indeterminate" value an old PHP gave a clean run —
 * so a curl killed after printing its write-out was reported as a 200. And an
 * unwritable temp directory surfaced as an E_NOTICE from tempnam(), which
 * Laravel and Symfony turn into an ErrorException that no documented catch
 * covers.
 */
class ProcessFailureTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        if (PlatformDetector::isWindows()) {
            $this->markTestSkipped('POSIX signals and shell scripts');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    /**
     * A BrowserInterface whose "curl-impersonate" is a shell script.
     */
    private function fakeCurl(string $script): BrowserInterface
    {
        $file = sys_get_temp_dir() . '/php-impersonate-fake-curl-' . bin2hex(random_bytes(4));
        file_put_contents($file, "#!/bin/sh\n" . $script . "\n");
        chmod($file, 0700);
        $this->cleanup[] = $file;

        return new class($file) implements BrowserInterface {
            public function __construct(private string $path)
            {
            }

            public function getExecutablePath(): string
            {
                return $this->path;
            }

            public function getName(): string
            {
                return 'fake';
            }

            /** @return array<string,mixed> */
            public function getConfig(): array
            {
                return ['ciphers' => 'X', 'headers' => []];
            }
        };
    }

    public function testAChildKilledBySignalIsAFailureEvenAfterAWriteOut(): void
    {
        // Prints a plausible write-out (effective URL, then a 200) and then
        // kills itself: the status line alone would have said "success".
        $engine = new CurlProcess($this->fakeCurl("printf 'http://example.test/\\n200'; kill -9 \$\$"), 5, []);

        try {
            $engine->request('GET', 'http://example.test/', [], null);
            $this->fail('a signal-terminated curl must not produce a response');
        } catch (RequestException $e) {
            $this->assertStringContainsString('terminated by signal 9', $e->getMessage());
        }
    }

    public function testACleanExitWithAWriteOutIsStillASuccess(): void
    {
        // The same script minus the kill: the write-out is read as before.
        $engine = new CurlProcess($this->fakeCurl("printf 'http://example.test/final\\n204'"), 5, []);

        $result = $engine->request('GET', 'http://example.test/', [], null);

        $this->assertSame(204, $result['status']);
        $this->assertSame('http://example.test/final', $result['url']);
    }

    public function testAnUnwritableTempDirectoryIsARequestException(): void
    {
        // sys_get_temp_dir() is cached per process, so this has to run in a
        // child with TMPDIR pointing nowhere — and with a handler that turns
        // notices into exceptions, as application frameworks do.
        $code = 'set_error_handler(function ($n, $s) { throw new ErrorException($s, $n); });'
            . ' try { (new Raza\PHPImpersonate\PHPImpersonate("chrome146", 5, [], "process"))->sendGet("http://127.0.0.1:1/"); }'
            . ' catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }';

        $process = proc_open(
            [PHP_BINARY, '-r', 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; ' . $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__ . '/..',
            ['TMPDIR' => '/nonexistent/php-impersonate', 'PATH' => (string) getenv('PATH')]
        );
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringContainsString('RequestException', $out, $out);
        $this->assertStringContainsString('/nonexistent/php-impersonate', $out, 'the message must name the directory');
        $this->assertStringNotContainsString('ErrorException', $out);
    }
}
