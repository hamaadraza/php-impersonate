<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * How the executable engine finds and vets its binary.
 *
 * Offline and shell-free: the binary inside this package's own bin/ directory
 * is trusted as shipped (running `--version` on it proved nothing a checksum
 * did not, and cost a process per PHP-FPM request), PATH is scanned natively
 * rather than through `which`, and anything found outside the package is
 * verified with proc_open in array mode — never a shell.
 */
class BrowserResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    private string|false $savedPath = false;

    protected function setUp(): void
    {
        if (PlatformDetector::isWindows()) {
            $this->markTestSkipped('POSIX executable-bit semantics');
        }
        $this->savedPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->savedPath === false ? 'PATH' : 'PATH=' . $this->savedPath);
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    /**
     * A directory holding a fake executable that prints $versionOutput.
     *
     * @return array{0: string, 1: string} [directory, executable path]
     */
    private function fakeBinary(string $name, string $versionOutput): array
    {
        $dir = sys_get_temp_dir() . '/php-impersonate-path-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $this->cleanup[] = $dir;

        $file = "$dir/$name";
        file_put_contents($file, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($versionOutput) . "\n");
        chmod($file, 0700);
        $this->cleanup[] = $file;

        return [$dir, $file];
    }

    private function browser(): Browser
    {
        return (new ReflectionClass(Browser::class))->newInstanceWithoutConstructor();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(Browser::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->browser(), ...$args);
    }

    public function testTheBundledBinaryIsUsedWithoutBeingExecuted(): void
    {
        try {
            $browser = new Browser('chrome146');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('No bundled binary on this host: ' . $e->getMessage());
        }

        $packageBin = realpath(__DIR__ . '/../bin');
        $this->assertStringStartsWith((string) $packageBin, $browser->getExecutablePath());

        // Nothing was verified by execution: the per-process cache is untouched.
        $verified = (new ReflectionClass(Browser::class))->getProperty('verifiedBinaries');
        $verified->setAccessible(true);
        $this->assertArrayNotHasKey($browser->getExecutablePath(), $verified->getValue());
    }

    public function testPathIsScannedNativelyWithoutAShell(): void
    {
        [$dir, $file] = $this->fakeBinary('php-impersonate-fake-' . getmypid(), 'curl 8.0.0-IMPERSONATE');
        putenv('PATH=' . $dir . PATH_SEPARATOR . (string) $this->savedPath);

        $this->assertSame($file, $this->invoke('findInPath', basename($file), PlatformDetector::getPlatform()));
    }

    public function testANameAbsentFromPathResolvesToNull(): void
    {
        $this->assertNull($this->invoke('findInPath', 'definitely-not-a-real-binary-name', PlatformDetector::getPlatform()));
    }

    public function testAPlainCurlOnPathIsRejectedAndCurlImpersonateAccepted(): void
    {
        [, $plain] = $this->fakeBinary('curl-plain', 'curl 8.5.0 (x86_64-pc-linux-gnu) libcurl/8.5.0');
        [, $impersonate] = $this->fakeBinary('curl-imp', 'curl 8.21.0-IMPERSONATE (Linux) libcurl/8.21.0-IMPERSONATE BoringSSL');

        $platform = PlatformDetector::getPlatform();
        $this->assertFalse($this->invoke('isCurlImpersonate', $plain, $platform), 'a stock curl must not pass as curl-impersonate');
        $this->assertTrue($this->invoke('isCurlImpersonate', $impersonate, $platform));
    }

    public function testVerificationRunsThroughProcOpenNotAShell(): void
    {
        // Shell metacharacters in the path must arrive verbatim: with array-mode
        // proc_open there is no shell to interpret them, so a name containing
        // `$(…)` is just a name. A shell would have failed to find the file.
        $dir = sys_get_temp_dir() . '/php-impersonate-$(echo injected)-' . bin2hex(random_bytes(3));
        mkdir($dir, 0700);
        $this->cleanup[] = $dir;
        $file = "$dir/curl-impersonate";
        file_put_contents($file, "#!/bin/sh\necho 'curl 8.21.0-IMPERSONATE'\n");
        chmod($file, 0700);
        $this->cleanup[] = $file;

        $this->assertTrue($this->invoke('isCurlImpersonate', $file, PlatformDetector::getPlatform()));
    }
}
