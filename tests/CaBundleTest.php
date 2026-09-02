<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Support\CaBundle;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * CA bundle resolution. Pure unit tests — no binary or network required.
 */
class CaBundleTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE', 'SSL_CERT_DIR'] as $var) {
            $this->saved[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $var => $value) {
            $value === false ? putenv($var) : putenv("$var=$value");
        }

        foreach ($this->tempPaths as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    private function tempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ca_bundle_test');
        file_put_contents($path, "# test bundle\n");
        $this->tempPaths[] = $path;

        return $path;
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/ca_dir_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0700);
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * The whole point of setting these variables is to override the system
     * bundle, so they must win even when a distro path exists — which, on any
     * normal Linux box, it does.
     */
    public function testSslCertFileWinsOverSystemPaths(): void
    {
        $custom = $this->tempFile();
        putenv("SSL_CERT_FILE=$custom");

        $this->assertSame($custom, CaBundle::path());
    }

    public function testCurlCaBundleWinsOverSslCertFile(): void
    {
        $preferred = $this->tempFile();
        $other = $this->tempFile();
        putenv("CURL_CA_BUNDLE=$preferred");
        putenv("SSL_CERT_FILE=$other");

        $this->assertSame($preferred, CaBundle::path());
    }

    public function testUnreadableEnvPathThrowsInsteadOfWideningTrust(): void
    {
        // An operator setting this is usually NARROWING what to trust, so
        // quietly substituting the distro bundle widens it back — the opposite
        // of the instruction, and invisible. Fail closed instead, as curl does.
        putenv('SSL_CERT_FILE=/nonexistent/nope.pem');

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('/nonexistent/nope.pem');

        CaBundle::path();
    }

    public function testEmptyEnvValueIsIgnored(): void
    {
        putenv('SSL_CERT_FILE=');

        $resolved = CaBundle::path();

        $this->assertNotSame('', $resolved);
    }

    public function testDirectoryReadsSslCertDir(): void
    {
        $dir = $this->tempDir();
        putenv("SSL_CERT_DIR=$dir");

        $this->assertSame($dir, CaBundle::directory());
    }

    public function testDirectoryIsNullWhenUnsetOrInvalid(): void
    {
        $this->assertNull(CaBundle::directory());

        putenv('SSL_CERT_DIR=/nonexistent/dir');
        $this->assertNull(CaBundle::directory());
    }

    public function testPathIsAFileWhenResolved(): void
    {
        $resolved = CaBundle::path();

        if ($resolved === null) {
            $this->markTestSkipped('No CA bundle on this system.');
        }

        $this->assertFileExists($resolved);
        $this->assertFileIsReadable($resolved);
    }
}
