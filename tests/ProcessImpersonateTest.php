<?php

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Process\CurlProcess;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Browser\BrowserInterface;

/**
 * Offline checks on how the executable engine hands the browser identity to
 * the binary, and on the argv guards that only show up in production.
 *
 * Both engines must draw the fingerprint from ONE source: the binary's own
 * impersonation table. Rendering BrowserConfig into --ciphers/--tls-* flags
 * instead is how five profiles drifted from the shared library without a
 * single parity test noticing — JA4 does not encode a record_size_limit value,
 * and only 9 of 39 profiles were compared at all.
 */
class ProcessImpersonateTest extends TestCase
{
    /**
     * @param array<string,mixed> $config
     */
    private function browser(string $name, array $config): BrowserInterface
    {
        return new class($name, $config) implements BrowserInterface {
            /** @param array<string,mixed> $config */
            public function __construct(private string $name, private array $config)
            {
            }

            public function getExecutablePath(): string
            {
                return '/nonexistent/curl-impersonate';
            }

            public function getName(): string
            {
                return $this->name;
            }

            /** @return array<string,mixed> */
            public function getConfig(): array
            {
                return $this->config;
            }
        };
    }

    /**
     * @param array<string,string> $headers
     * @return array{argv: list<string>, files: array<string,string>}
     */
    private function build(BrowserInterface $browser, array $headers = []): array
    {
        $engine = new CurlProcess($browser, 30, []);

        $method = (new ReflectionClass(CurlProcess::class))->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var array{command: list<string>, tempFiles: array<int,string>} $result */
        $result = $method->invoke($engine, 'GET', 'https://example.com/', '/tmp/b', '/tmp/h', $headers, null);

        $files = [];
        foreach ($result['tempFiles'] as $file) {
            $files[$file] = is_file($file) ? (string) file_get_contents($file) : '';
            @unlink($file);
        }

        return ['argv' => $result['command'], 'files' => $files];
    }

    /**
     * @param list<string> $argv
     */
    private function valueAfter(array $argv, string $flag): ?string
    {
        $index = array_search($flag, $argv, true);

        return $index === false ? null : ($argv[$index + 1] ?? null);
    }

    // -------------------------------------------------------------------------
    // A built-in profile is applied by the binary itself
    // -------------------------------------------------------------------------

    public function testABuiltinProfileIsPassedByNameWithImpersonate(): void
    {
        $built = $this->build($this->browser('firefox133', BrowserConfig::getConfig('firefox133')));
        $argv = $built['argv'];

        $this->assertSame('firefox133', $this->valueAfter($argv, '--impersonate'));

        // None of the hand-rendered fingerprint flags may accompany it: they
        // would override parts of the binary's table with this package's copy.
        foreach (['--ciphers', '--curves', '--signature-hashes', '--http2-settings', '--tls-record-size-limit', '--tls-extension-order', '--ech'] as $flag) {
            $this->assertNotContains($flag, $argv, "$flag must not be rendered for a built-in profile");
        }

        // Nor may the profile's headers travel in the header file: libcurl adds
        // them from the table and merges the caller's into their slots.
        $headerFile = $this->valueAfter($argv, '-H');
        $this->assertNull($headerFile, 'a built-in profile with no caller headers needs no header file');
    }

    public function testCallerHeadersAloneGoToTheHeaderFileForABuiltinProfile(): void
    {
        $built = $this->build(
            $this->browser('chrome146', BrowserConfig::getConfig('chrome146')),
            ['Accept-Language' => 'de-DE', 'X-Custom' => '1']
        );

        $headerFile = substr((string) $this->valueAfter($built['argv'], '-H'), 1);
        $lines = array_values(array_filter(explode("\n", $built['files'][$headerFile] ?? '')));

        $this->assertSame(['Accept-Language: de-DE', 'X-Custom: 1'], $lines);
    }

    /**
     * The real Browser class carries the built-in config, so the ordinary
     * `new PHPImpersonate('chrome146', engine: 'process')` path takes the
     * --impersonate route too, not just a hand-built BrowserInterface.
     */
    public function testTheRealBrowserClassTakesTheImpersonateRoute(): void
    {
        try {
            $browser = new Browser('chrome146');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('No curl-impersonate binary on this host: ' . $e->getMessage());
        }

        $argv = $this->build($browser)['argv'];

        $this->assertSame('chrome146', $this->valueAfter($argv, '--impersonate'));
    }

    // -------------------------------------------------------------------------
    // A custom profile is still rendered by hand
    // -------------------------------------------------------------------------

    public function testACustomProfileIsRenderedFromItsOwnConfig(): void
    {
        $custom = $this->browser('chrome146', ['ciphers' => 'CUSTOM-CIPHERS', 'headers' => ['X-Profile' => 'p']]);

        $built = $this->build($custom, ['X-Caller' => 'c']);
        $argv = $built['argv'];

        $this->assertNotContains('--impersonate', $argv, 'a custom profile has no table entry to impersonate');
        $this->assertSame('CUSTOM-CIPHERS', $this->valueAfter($argv, '--ciphers'));

        $headerFile = substr((string) $this->valueAfter($argv, '-H'), 1);
        $this->assertSame(
            ['X-Profile: p', 'X-Caller: c'],
            array_values(array_filter(explode("\n", $built['files'][$headerFile] ?? '')))
        );
    }

    // -------------------------------------------------------------------------
    // argv guards
    // -------------------------------------------------------------------------

    /**
     * curl reads ~/.curlrc (or $CURL_HOME/.curlrc, $XDG_CONFIG_HOME/curlrc)
     * unless -q leads argv. Verified before the fix: a `header = …` line in a
     * curlrc reached the server, and a `proxy = …` line hijacked every request
     * this engine sent, while the FFI engine never saw either.
     */
    public function testCurlrcIsDisabledByALeadingDashQ(): void
    {
        $argv = $this->build($this->browser('chrome146', BrowserConfig::getConfig('chrome146')))['argv'];

        $this->assertSame('-q', $argv[1], '-q must be the FIRST argument, or curl still reads .curlrc');
    }

    public function testTheCookieEngineIsEnabledWithoutAFile(): void
    {
        $argv = $this->build($this->browser('chrome146', BrowserConfig::getConfig('chrome146')))['argv'];

        // `-b ""` is curl's documented spelling for "cookie engine on, no file".
        $this->assertSame('', $this->valueAfter($argv, '-b'));
        $this->assertNotContains('--cookie-jar', $argv, 'nothing may be written to disk');
    }

    public function testAResponseSizeCapIsAlwaysPresent(): void
    {
        $argv = $this->build($this->browser('chrome146', BrowserConfig::getConfig('chrome146')))['argv'];

        $this->assertSame(
            (string) \Raza\PHPImpersonate\Support\CurlOptions::DEFAULT_MAX_FILESIZE,
            $this->valueAfter($argv, '--max-filesize')
        );
    }

    public function testACallerMaxFilesizeOverridesTheDefault(): void
    {
        $engine = new CurlProcess($this->browser('chrome146', BrowserConfig::getConfig('chrome146')), 30, ['max-filesize' => 4096]);

        $method = (new ReflectionClass(CurlProcess::class))->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var array{command: list<string>, tempFiles: array<int,string>} $result */
        $result = $method->invoke($engine, 'GET', 'https://example.com/', '/tmp/b', '/tmp/h', [], null);
        foreach ($result['tempFiles'] as $file) {
            @unlink($file);
        }

        $argv = $result['command'];
        $this->assertSame(1, count(array_keys($argv, '--max-filesize', true)), 'exactly one --max-filesize');
        $this->assertSame('4096', $this->valueAfter($argv, '--max-filesize'));
    }
}
