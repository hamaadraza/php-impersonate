<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use FFI;
use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;

/**
 * The two Windows decisions that fail silently rather than loudly, pinned on
 * every platform because both are about what the code says, not where it runs.
 */
class FfiWindowsSupportTest extends TestCase
{
    /**
     * The C header declares the write callbacks' lengths as `size_t`. Windows
     * x64 is LLP64, where `unsigned long` is 32 bits while libcurl passes 64,
     * so the obvious spelling is wrong there and right everywhere else. It
     * survives on x86-64 by luck — the value rides in the low half of the
     * register — which is exactly why nothing would report it.
     *
     * Asserted as a width rather than as source text, so it stays true however
     * the typedef is spelled, and so a future 32-bit target trips it here
     * instead of on the wire.
     */
    public function testTheDeclaredSizeTypeIs64BitsWide(): void
    {
        if (! extension_loaded('FFI')) {
            $this->markTestSkipped('needs the ffi extension to parse the header');
        }

        $header = (new ReflectionClass(CurlImpersonate::class))->getConstant('HEADER');
        $this->assertIsString($header);

        // Only the typedef is compiled. Feeding the whole header to cdef()
        // without a library would fail resolving curl_easy_impersonate, which
        // has nothing to do with what is being asserted.
        $declared = self::declaredSizeType((string) $header);
        $probe = FFI::cdef('typedef struct { ' . $declared . ' v; } php_impersonate_size_probe;');

        $this->assertSame(
            8,
            FFI::sizeof($probe->new('php_impersonate_size_probe')),
            "size_t is declared as `$declared`, which is not 64 bits wide here; "
            . 'on Windows (LLP64) `unsigned long` is 32 while libcurl passes 64'
        );
    }

    /**
     * The C type the header uses for size_t.
     */
    private static function declaredSizeType(string $header): string
    {
        $this_ = preg_match('/typedef\s+(.+?)\s+size_t\s*;/', $header, $m);

        return $this_ === 1 ? $m[1] : 'unsigned long';
    }

    /**
     * The FFI engine was POSIX-only while it captured responses through
     * open_memstream; it no longer does, so the installer must be willing to
     * fetch a Windows library. Silently refusing it is how the engine would
     * quietly stay unavailable there.
     */
    public function testTheInstallerConsidersAWindowsLibraryUsable(): void
    {
        $installerClass = __DIR__ . '/../scripts/lib/BinaryInstaller.php';
        if (! is_file($installerClass)) {
            $this->markTestSkipped('maintainer scripts are export-ignored from the dist package');
        }

        require_once __DIR__ . '/../scripts/lib/Http.php';
        require_once $installerClass;

        $installer = new \Raza\PHPImpersonate\Scripts\BinaryInstaller(__DIR__ . '/../bin');

        $this->assertTrue($installer->libIsUsable('windows-x86_64'), 'the Windows shared library is loadable now');
        $this->assertSame('libcurl-impersonate.dll', $installer->libDestName('windows-x86_64'));

        // And the platforms that always worked still do.
        $this->assertTrue($installer->libIsUsable('linux-x86_64'));
        $this->assertSame('libcurl-impersonate.so', $installer->libDestName('linux-x86_64'));
        $this->assertSame('libcurl-impersonate.dylib', $installer->libDestName('macos-aarch64'));
    }
}
