<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Ffi\LibResolver;
use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * A second libcurl in the process — the one compiled into the php binary for
 * ext-curl on setup-php's PHP 8.5 and on the official Docker images — can
 * capture libcurl-impersonate's own internal setopt calls. The symptoms were
 * `curl_easy_impersonate()` answering CURLE_UNKNOWN_OPTION (48) and then a
 * segmentation fault in curl_easy_cleanup(), because the stock libcurl had
 * written into a handle whose layout it does not share.
 *
 * Two guarantees are pinned here, each with a small C fixture built on the
 * spot (skipped where there is no compiler):
 *
 *  1. RTLD_DEEPBIND is the right fix on glibc: an executable that defines
 *     curl_easy_setopt() captures the library's calls without it and does not
 *     with it.
 *  2. Whatever the platform, a library that answers 48 makes the FFI engine
 *     UNAVAILABLE with a readable reason, and the PHP process survives —
 *     the poisoned handle is never handed back to be freed.
 */
class FfiForeignLibcurlTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    private string $dir;

    protected function setUp(): void
    {
        if (! PlatformDetector::isLinux()) {
            $this->markTestSkipped('glibc dynamic-linker semantics');
        }
        // Opt-in: these build C fixtures with gcc. CI sets the variable on its
        // Linux legs; on a developer machine the compiler may be missing, or
        // (seen on WSL) the linker may stall inside the 9p filesystem driver
        // when spawned from PHPUnit, and a fixture build must never hang the
        // suite. Run with PHP_IMPERSONATE_BUILD_FIXTURES=1 to include them.
        if (! filter_var((string) getenv('PHP_IMPERSONATE_BUILD_FIXTURES'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('set PHP_IMPERSONATE_BUILD_FIXTURES=1 to build and run the C fixtures');
        }
        if (shell_exec('command -v gcc 2>/dev/null') === null || trim((string) shell_exec('command -v gcc 2>/dev/null')) === '') {
            $this->markTestSkipped('needs gcc to build the fixtures');
        }

        $this->dir = sys_get_temp_dir() . '/php-impersonate-foreign-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->cleanup[] = $this->dir;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    private function compile(string $name, string $source, string $flags): string
    {
        $src = $this->dir . "/$name.c";
        $out = $this->dir . '/' . $name;
        file_put_contents($src, $source);
        $this->cleanup[] = $src;
        $this->cleanup[] = $out;

        // Built from inside the fixture directory with a deadline: on WSL the
        // linker has been seen to stall in the 9p filesystem driver when run
        // from a project checkout, and a fixture that cannot be built is a
        // reason to skip, never to hang the suite.
        $cmd = ['gcc', ...explode(' ', $flags), '-o', $out, $src];
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $err = '';
        $deadline = microtime(true) + 60;
        while (proc_get_status($p)['running']) {
            $err .= (string) stream_get_contents($pipes[2]);
            stream_get_contents($pipes[1]);
            if (microtime(true) > $deadline) {
                proc_terminate($p, 9);
                proc_close($p);
                $this->markTestSkipped('gcc did not finish building the fixture within 60s');
            }
            usleep(50000);
        }
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) {
            $this->markTestSkipped("gcc could not build the fixture: $err");
        }

        return $out;
    }

    /**
     * Run a command to completion and return [exit code, combined output].
     *
     * Not named run(): PHPUnit\Framework\TestCase::run() is the runner's own
     * entry point, and a helper by that name re-executed this very test from
     * inside itself, forever.
     *
     * @return array{0: int, 1: string}
     */
    private function spawnChild(array $argv, array $env = []): array
    {
        $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), $out];
    }

    public function testDeepbindStopsAnExecutableDefinedSetoptFromCapturingTheLibrary(): void
    {
        if (PlatformDetector::isMusl()) {
            $this->markTestSkipped('RTLD_DEEPBIND is a glibc feature');
        }
        $lib = LibResolver::resolve();
        if ($lib === null) {
            $this->markTestSkipped('no shared library on this host');
        }

        // Models ext-curl compiled into the php binary: the executable itself
        // defines curl_easy_setopt(), and executable symbols win every lookup.
        $host = $this->compile('host', <<<'C'
            #include <stdio.h>
            #include <dlfcn.h>
            #ifndef RTLD_DEEPBIND
            #define RTLD_DEEPBIND 0x8
            #endif
            int curl_easy_setopt(void *h, int o, ...) { (void)h; (void)o; return 48; }
            int main(int argc, char **argv) {
                void *lib = dlopen(argv[1], RTLD_NOW | (argc > 2 ? RTLD_DEEPBIND : 0));
                if (!lib) { printf("dlopen failed: %s\n", dlerror()); return 2; }
                void *(*init)(void) = dlsym(lib, "curl_easy_init");
                int (*imp)(void *, const char *, int) = dlsym(lib, "curl_easy_impersonate");
                void *h = init();
                printf("rc=%d\n", imp(h, "firefox147", 1));
                return 0; /* deliberately no cleanup: a captured handle would crash there */
            }
            C, '-ldl -rdynamic');

        [$code, $plain] = $this->spawnChild([$host, $lib]);
        $this->assertSame(0, $code, $plain);
        $this->assertStringContainsString('rc=48', $plain, 'the fixture must reproduce the capture; without it this test proves nothing');

        [$code, $deep] = $this->spawnChild([$host, $lib, 'deepbind']);
        $this->assertSame(0, $code, $deep);
        $this->assertStringContainsString('rc=0', $deep, 'RTLD_DEEPBIND must let the library bind to its own curl_easy_setopt');
    }

    public function testALibraryThatAnswers48MakesTheEngineUnavailableWithoutCrashing(): void
    {
        // A stand-in libcurl-impersonate that loads, creates a handle, and
        // then answers CURLE_UNKNOWN_OPTION to every impersonation — what a
        // captured library looks like from PHP. Its cleanup aborts the process
        // on purpose: reaching it would be the crash this test forbids.
        $fake = $this->compile('libfake-impersonate.so', <<<'C'
            #include <stdlib.h>
            static int handle;
            void *curl_easy_init(void) { return &handle; }
            void curl_easy_reset(void *h) { (void)h; }
            int curl_easy_setopt(void *h, int o, ...) { (void)h; (void)o; return 0; }
            int curl_easy_perform(void *h) { (void)h; return 0; }
            int curl_easy_getinfo(void *h, int i, ...) { (void)h; (void)i; return 0; }
            const char *curl_easy_strerror(int c) { (void)c; return "fake"; }
            int curl_easy_impersonate(void *h, const char *t, int d) { (void)h; (void)t; (void)d; return 48; }
            void *curl_slist_append(void *l, const char *s) { (void)s; return l; }
            void curl_slist_free_all(void *l) { (void)l; }
            void curl_easy_cleanup(void *h) { (void)h; abort(); }
            C, '-shared -fPIC');

        $script = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';'
            . ' $ok = Raza\PHPImpersonate\PHPImpersonate::ffiAvailable();'
            . ' echo "available=", var_export($ok, true), "\n", Raza\PHPImpersonate\PHPImpersonate::ffiUnavailableReason(), "\n";'
            . ' $c = new Raza\PHPImpersonate\PHPImpersonate("chrome146", 5);'
            . ' echo "engine=", $c->engine(), "\n";';

        [$code, $out] = $this->spawnChild(
            [PHP_BINARY, '-d', 'ffi.enable=1', '-r', $script],
            [LibResolver::ENV_VAR => $fake]
        );

        $this->assertSame(0, $code, "the process must survive a captured library, got exit $code:\n$out");
        $this->assertStringContainsString('available=false', $out, $out);
        $this->assertStringContainsString('returned 48', $out, 'the reason must name the code');
        $this->assertStringContainsString('engine=process', $out, "'auto' must fall back to the executable engine");
    }
}
