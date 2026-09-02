<?php

/**
 * Run each stage of the FFI engine in a separate PHP process and report how
 * it ended, so a crash that takes the process down (a segmentation fault has
 * no exception to catch) at least names the stage it happened in — and the
 * environment it happened under.
 *
 * Usage:
 *   php scripts/ffi-diagnose.php [/path/to/libcurl-impersonate.so]
 *
 * Exit codes: 0 = every stage ran, 1 = a stage crashed or failed.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Ffi\LibResolver;

$lib = $argv[1] ?? LibResolver::resolve();

echo 'PHP ', PHP_VERSION, ' (', PHP_SAPI, ', ', PHP_OS, ' ', php_uname('m'), ')', PHP_EOL;
echo 'Thread safety: ', ZEND_THREAD_SAFE ? 'ZTS' : 'NTS', '; debug: ', ZEND_DEBUG_BUILD ? 'yes' : 'no', PHP_EOL;
echo 'ffi.enable=', var_export(ini_get('ffi.enable'), true),
'; opcache.enable_cli=', var_export(ini_get('opcache.enable_cli'), true),
'; opcache.jit=', var_export(ini_get('opcache.jit'), true),
'; opcache.jit_buffer_size=', var_export(ini_get('opcache.jit_buffer_size'), true), PHP_EOL;
echo 'Loaded extensions: ', implode(', ', get_loaded_extensions()), PHP_EOL;
if (extension_loaded('curl')) {
    $v = curl_version();
    echo 'ext-curl links libcurl ', $v['version'], ' / ', $v['ssl_version'], ' (a second libcurl in this process)', PHP_EOL;
}

try {
    // Resolvable without naming a library = defined by the php executable or a
    // library in its global scope. If that is a stock libcurl, the impersonate
    // library's own calls may bind to it (see CurlImpersonate::bindSymbolsLocally()).
    FFI::cdef('int curl_easy_setopt(void *h, int o, ...);');
    echo 'A curl_easy_setopt() is reachable in the global symbol scope (a libcurl is linked into, or exported by, this php binary).', PHP_EOL;
} catch (\Throwable) {
    echo 'No curl_easy_setopt() in the global symbol scope (ext-curl absent or loaded as a module).', PHP_EOL;
}
echo 'Library: ', $lib ?? '(none resolved)', PHP_EOL, PHP_EOL;

if ($lib === null) {
    echo 'No shared library: ', PHPImpersonate::ffiUnavailableReason(), PHP_EOL;
    exit(1);
}

$stages = [
    'FFI::cdef (one symbol)' => 'FFI::cdef("void *curl_easy_init(void);", $lib);',
    'curl_easy_init + cleanup' => '$f = FFI::cdef("void *curl_easy_init(void); void curl_easy_cleanup(void *h);", $lib); $h = $f->curl_easy_init(); if ($h === null) { exit(3); } $f->curl_easy_cleanup($h);',
    'variadic setopt (long)' => '$f = FFI::cdef("void *curl_easy_init(void); int curl_easy_setopt(void *h, int o, ...); void curl_easy_cleanup(void *h);", $lib); $h = $f->curl_easy_init(); $rc = $f->curl_easy_setopt($h, 52, 1); $f->curl_easy_cleanup($h); exit($rc === 0 ? 0 : 3);',
    'variadic setopt (string)' => '$f = FFI::cdef("void *curl_easy_init(void); int curl_easy_setopt(void *h, int o, ...); void curl_easy_cleanup(void *h);", $lib); $h = $f->curl_easy_init(); $rc = $f->curl_easy_setopt($h, 10002, "http://127.0.0.1/"); $f->curl_easy_cleanup($h); exit($rc === 0 ? 0 : 3);',
    'closure as C function pointer' => '$f = FFI::cdef("typedef unsigned long size_t; typedef size_t (*cb)(char *p, size_t s, size_t n, void *u); typedef struct { cb fn; } holder;", $lib); $h = $f->new("holder"); $h->fn = static function ($p, int $s, int $n, $u): int { return $s * $n; };',
    'engine: full header cdef + init' => 'new Raza\PHPImpersonate\Ffi\CurlImpersonate($lib);',
    'engine: curl_easy_impersonate(default)' => '$e = new Raza\PHPImpersonate\Ffi\CurlImpersonate($lib); $rc = $e->probeTarget(Raza\PHPImpersonate\PHPImpersonate::DEFAULT_BROWSER); echo "rc=$rc "; exit($rc === 0 ? 0 : 3);',
    'engine: request to a closed port (expect a RequestException)' => '$e = new Raza\PHPImpersonate\Ffi\CurlImpersonate($lib); try { $e->request("GET", "http://127.0.0.1:1/", [], null, Raza\PHPImpersonate\PHPImpersonate::DEFAULT_BROWSER, 3, []); echo "unexpected success "; } catch (Raza\PHPImpersonate\Exception\RequestException $x) { echo "threw as expected "; }',
];

$failed = false;

foreach ($stages as $name => $code) {
    $script = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; $lib = ' . var_export($lib, true) . '; ' . $code;
    $process = proc_open(
        [PHP_BINARY, '-d', 'ffi.enable=1', '-r', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (! is_resource($process)) {
        echo "cannot spawn php\n";
        exit(1);
    }
    $out = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_get_status($process);
    $exit = proc_close($process);

    $how = match (true) {
        ! empty($status['signaled']) || $exit === 139 || $exit === -1 => 'CRASHED (signal ' . ($status['termsig'] ?? '?') . ', exit ' . $exit . ')',
        $exit === 0 => 'ok',
        default => "failed (exit $exit)",
    };
    $failed = $failed || $exit !== 0;

    printf("%-58s %s%s\n", $name, $how, $out !== '' ? '  ' . str_replace("\n", ' | ', $out) : '');
}

exit($failed ? 1 : 0);
