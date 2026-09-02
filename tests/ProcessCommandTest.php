<?php

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Process\CurlProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Offline checks on the argv the executable engine actually builds.
 *
 * These cover regressions that only ever showed up on the wire — a duplicated
 * header, a stray positional argument — without needing a binary, a network, or
 * a live service, so they run everywhere and fail fast.
 */
class ProcessCommandTest extends TestCase
{
    /**
     * A browser stub with a small, predictable profile.
     *
     * @param array<string,string> $headers
     */
    private function browser(array $headers): BrowserInterface
    {
        return new class($headers) implements BrowserInterface {
            /** @param array<string,string> $headers */
            public function __construct(private array $headers)
            {
            }

            public function getExecutablePath(): string
            {
                return '/nonexistent/curl-impersonate';
            }

            public function getName(): string
            {
                return 'stub';
            }

            /** @return array<string,mixed> */
            public function getConfig(): array
            {
                return ['ciphers' => 'STUB-CIPHERS', 'headers' => $this->headers];
            }
        };
    }

    /**
     * Build one request without running it, capturing both the argv and the
     * contents of every temp file it referenced (they are deleted immediately
     * afterwards, as the engine itself does).
     *
     * @param array<string,string> $profileHeaders
     * @param array<string,string> $requestHeaders
     * @param array<string,mixed> $curlOptions
     * @return array{argv: list<string>, files: array<string,string>}
     */
    private function build(
        array $profileHeaders,
        array $requestHeaders = [],
        array $curlOptions = [],
        string $method = 'GET'
    ): array {
        $engine = new CurlProcess($this->browser($profileHeaders), 30, $curlOptions);

        $build = (new ReflectionClass(CurlProcess::class))->getMethod('buildCommand');
        $build->setAccessible(true);

        /** @var array{command: list<string>, tempFiles: array<int,string>} $result */
        $result = $build->invoke(
            $engine,
            $method,
            'https://example.com/',
            '/tmp/body.out',
            '/tmp/headers.out',
            $requestHeaders,
            null
        );

        $files = [];
        foreach ($result['tempFiles'] as $file) {
            $files[$file] = is_file($file) ? (string) file_get_contents($file) : '';
            @unlink($file);
        }

        return ['argv' => $result['command'], 'files' => $files];
    }

    /**
     * Just the argv for one request.
     *
     * @param array<string,string> $profileHeaders
     * @param array<string,string> $requestHeaders
     * @param array<string,mixed> $curlOptions
     * @return list<string>
     */
    private function argv(
        array $profileHeaders,
        array $requestHeaders = [],
        array $curlOptions = [],
        string $method = 'GET'
    ): array {
        return $this->build($profileHeaders, $requestHeaders, $curlOptions, $method)['argv'];
    }

    /**
     * The header lines the request will actually send.
     *
     * Headers never appear in argv — they are written to a 0600 temp file and
     * referenced with `-H @file` — so this resolves that file.
     *
     * @param array<string,string> $profileHeaders
     * @param array<string,string> $requestHeaders
     * @return list<string>
     */
    private function sentHeaders(array $profileHeaders, array $requestHeaders = []): array
    {
        $built = $this->build($profileHeaders, $requestHeaders);
        $argv = $built['argv'];

        $lines = [];
        foreach ($argv as $i => $arg) {
            if ($arg !== '-H' || ! isset($argv[$i + 1])) {
                continue;
            }

            $value = $argv[$i + 1];
            $this->assertStringStartsWith('@', $value, 'headers must be passed by file, never inline in argv');

            $contents = $built['files'][substr($value, 1)] ?? '';
            foreach (explode("\n", trim($contents)) as $line) {
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    public function testCallerHeaderReplacesProfileHeader(): void
    {
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0', 'Accept' => 'text/html'],
            ['User-Agent' => 'CallerAgent/1.0']
        );

        // Exactly one User-Agent, and it is the caller's: curl emits every header
        // it is given, so keeping the profile's too would put both on the wire.
        $userAgents = array_values(array_filter($headers, fn ($h) => str_starts_with($h, 'User-Agent:')));
        $this->assertSame(['User-Agent: CallerAgent/1.0'], $userAgents);

        // Untouched profile headers still come through.
        $this->assertContains('Accept: text/html', $headers);
    }

    public function testHeaderOverrideIsCaseInsensitive(): void
    {
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0'],
            ['user-agent' => 'CallerAgent/1.0']
        );

        $this->assertSame(['user-agent: CallerAgent/1.0'], $headers);
    }

    public function testProfileHeadersAreKeptWhenNothingOverridesThem(): void
    {
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0', 'Accept-Language' => 'en-US']
        );

        $this->assertContains('User-Agent: ProfileAgent/9.0', $headers);
        $this->assertContains('Accept-Language: en-US', $headers);
    }

    /**
     * Header ORDER is part of the fingerprint, not just header content, and the
     * two engines have to agree on it.
     *
     * The FFI engine goes through libcurl's Curl_http_merge_headers: a profile
     * header keeps its position and a caller header of the same name is
     * substituted into that slot, while caller-only headers follow at the end.
     * This engine writes the list itself, so it has to reproduce that exactly.
     * It used to emit the caller's headers first, which put an Authorization
     * line ahead of sec-ch-ua — an order no browser produces.
     */
    public function testHeaderOrderMatchesTheBrowserProfile(): void
    {
        $headers = $this->sentHeaders(
            [
                'sec-ch-ua' => '"Chromium";v="131"',
                'User-Agent' => 'ProfileAgent/9.0',
                'Accept' => 'text/html',
                'Accept-Language' => 'en-US',
            ],
            [
                'Authorization' => 'Bearer token',
                'Accept-Language' => 'de-DE',
            ]
        );

        $this->assertSame([
            // Profile order is preserved outright...
            'sec-ch-ua: "Chromium";v="131"',
            'User-Agent: ProfileAgent/9.0',
            'Accept: text/html',
            // ...with the caller's value substituted into the profile's slot,
            // rather than hoisted to the front.
            'Accept-Language: de-DE',
            // A header the profile has no counterpart for goes last.
            'Authorization: Bearer token',
        ], $headers);
    }

    /**
     * Two spellings of one name are one header (RFC 9110 §5.1). Sending both
     * put two User-Agent lines on the wire — the same bot signal that a caller
     * header colliding with the profile's was already fixed for.
     *
     * Folded in RequestPreparer::normalizeHeaders(), which both engines pass
     * through, so this covers the executable engine's end of that contract.
     */
    /**
     * `-X POST` must not reach argv.
     *
     * -X pins the verb for every request on the handle, redirect follow-ups
     * included. On a 301/302/303 curl does what browsers do — switch to GET and
     * drop the body — but the pinned verb still says POST, so the redirect was
     * followed with a POST carrying no body at all. `--data-binary` already
     * makes the request a POST without pinning anything.
     */
    public function testPostDoesNotPinTheVerbWithDashX(): void
    {
        $argv = $this->argv(['User-Agent' => 'A/1'], [], [], 'POST');

        $this->assertNotContains('-X', $argv, '-X pins the verb across redirects');

        // Still unambiguously a POST: a bodyless POST says so with empty data.
        $this->assertContains('--data-binary', $argv);
    }

    /**
     * The other verbs keep -X: libcurl only rewrites POST on a redirect, so
     * there is nothing for a pinned PUT or DELETE to contradict.
     *
     * @param string $method
     */
    #[DataProvider('pinnedVerbProvider')]
    public function testOtherMethodsStillPinTheVerb(string $method): void
    {
        $argv = $this->argv(['User-Agent' => 'A/1'], [], [], $method);

        $this->assertContains('-X', $argv);
        $this->assertContains($method, $argv);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pinnedVerbProvider(): array
    {
        return [
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function testHeadUsesHeadFlagNotDashX(): void
    {
        $argv = $this->argv(['User-Agent' => 'A/1'], [], [], 'HEAD');

        // -X HEAD makes curl wait for a body the server never sends.
        $this->assertContains('--head', $argv);
        $this->assertNotContains('-X', $argv);
    }

    public function testCaseVariantCallerHeadersCollapseToOne(): void
    {
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0', 'Accept' => 'text/html'],
            RequestPreparer::normalizeHeaders(['User-Agent' => 'First/1.0', 'user-agent' => 'Second/2.0'])
        );

        // One User-Agent, carrying the last value given, still in the profile's slot.
        $this->assertSame([
            'User-Agent: Second/2.0',
            'Accept: text/html',
        ], $headers);
    }

    public function testCallerOnlyHeadersKeepTheirRelativeOrderAtTheEnd(): void
    {
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0'],
            ['X-One' => '1', 'X-Two' => '2', 'X-Three' => '3']
        );

        $this->assertSame([
            'User-Agent: ProfileAgent/9.0',
            'X-One: 1',
            'X-Two: 2',
            'X-Three: 3',
        ], $headers);
    }

    /**
     * Headers must never become argv entries: while the process runs, its argv
     * is readable by any local user through /proc/<pid>/cmdline and `ps`, and a
     * caller header routinely carries an Authorization token or a session Cookie.
     */
    public function testHeadersNeverAppearInArgv(): void
    {
        $built = $this->build(
            ['User-Agent' => 'ProfileAgent/9.0'],
            ['Authorization' => 'Bearer TOKEN-abc123', 'Cookie' => 'session=deadbeef']
        );

        $argv = implode(' ', $built['argv']);

        $this->assertStringNotContainsString('TOKEN-abc123', $argv);
        $this->assertStringNotContainsString('session=deadbeef', $argv);
        $this->assertStringNotContainsString('Authorization', $argv);

        // …but they are still sent, via the referenced file.
        $headers = $this->sentHeaders(
            ['User-Agent' => 'ProfileAgent/9.0'],
            ['Authorization' => 'Bearer TOKEN-abc123', 'Cookie' => 'session=deadbeef']
        );

        $this->assertContains('Authorization: Bearer TOKEN-abc123', $headers);
        $this->assertContains('Cookie: session=deadbeef', $headers);
    }

    /**
     * Small header sets used to be inlined into argv and only spilled to a file
     * past ~7000 characters, which left the common case exposed.
     */
    public function testEvenASingleSmallHeaderGoesThroughAFile(): void
    {
        $built = $this->build([], ['X-Tiny' => 'v']);

        $this->assertNotContains('X-Tiny: v', $built['argv']);
        $this->assertSame(['X-Tiny: v'], $this->sentHeaders([], ['X-Tiny' => 'v']));
    }

    /**
     * Proxy credentials must not reach argv either; they go to a curl config
     * file, which curl reads with --config.
     */
    public function testProxyCredentialsAreKeptOutOfArgv(): void
    {
        $built = $this->build([], [], [
            'proxy' => 'http://127.0.0.1:8080',
            'proxy-user' => 'alice:SuperSecret123',
            'insecure' => true,
        ]);

        $argv = implode(' ', $built['argv']);

        $this->assertStringNotContainsString('SuperSecret123', $argv);
        $this->assertStringNotContainsString('--proxy-user', $argv);
        $this->assertStringNotContainsString('127.0.0.1:8080', $argv);

        // Non-credential options still render as ordinary flags.
        $this->assertContains('--insecure', $built['argv']);

        // The credentials are handed over through the config file instead.
        $this->assertContains('--config', $built['argv']);
        $config = implode("\n", $built['files']);
        $this->assertStringContainsString('proxy-user = "alice:SuperSecret123"', $config);
        $this->assertStringContainsString('proxy = "http://127.0.0.1:8080"', $config);
    }

    /**
     * A quote or backslash in a credential must not break out of the config
     * file's quoted value.
     */
    public function testConfigFileEscapesQuotesAndBackslashes(): void
    {
        $built = $this->build([], [], ['proxy-user' => 'user:pa\\ss"word']);

        $config = implode("\n", $built['files']);
        $this->assertStringContainsString('proxy-user = "user:pa\\\\ss\\"word"', $config);
    }

    /**
     * A header carrying CR/LF/NUL is rejected outright rather than smuggling an
     * extra line into the shared header file.
     */
    public function testUnsafeHeaderIsRejectedAndLeavesNoTempFile(): void
    {
        $engine = new CurlProcess($this->browser([]), 30, []);

        $build = (new ReflectionClass(CurlProcess::class))->getMethod('buildCommand');
        $build->setAccessible(true);

        try {
            $build->invoke($engine, 'GET', 'https://example.com/', '/tmp/b', '/tmp/h', ['X-Bad' => "value\r\nInjected: 1"], null);
            $this->fail('an injected header should have been rejected');
        } catch (\ReflectionException | InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid header', $e->getMessage());
        }

        // Asserted against the engine's OWN record rather than a glob of the
        // shared system temp directory, which any concurrent process — another
        // test worker included — could change underneath this test. The
        // invariant is that headers are validated before a file is ever
        // created, so nothing was tracked and nothing needs cleaning up.
        $tracked = (new ReflectionClass(CurlProcess::class))->getProperty('tempFiles');
        $tracked->setAccessible(true);

        $this->assertSame([], $tracked->getValue($engine), 'a rejected request must not leave a temp file behind');
    }

    /**
     * A bool option must render as a bare flag or not at all. Rendered as
     * `--insecure no`, curl reads `no` as a second URL to fetch.
     *
     * @param mixed $value
     */
    #[DataProvider('looseBooleanProvider')]
    public function testBooleanOptionNeverEmitsAValueArgument($value, bool $expectedPresent): void
    {
        $argv = $this->argv([], [], ['insecure' => $value]);

        if ($expectedPresent) {
            $this->assertContains('--insecure', $argv);
            $index = (int) array_search('--insecure', $argv, true);
            // Whatever follows must be another flag, never a value.
            $next = $argv[$index + 1] ?? '';
            $this->assertTrue(
                str_starts_with($next, '-'),
                "--insecure was followed by a stray argument: '$next'"
            );
        } else {
            $this->assertNotContains('--insecure', $argv);
        }

        // There must be NO positional argument at all: every flag takes its own
        // value, and the URL now travels in the --config file so it cannot be
        // read out of /proc/<pid>/cmdline. A loose bool rendered as a detached
        // `no` would show up right here — which the previous version of this
        // assertion could not see, because it filtered the list down to
        // https:// entries before comparing, discarding the very argument it
        // was written to catch.
        $positionals = array_values(array_filter(
            array_slice($argv, 1),
            fn ($a) => ! str_starts_with($a, '-') && ! $this->isValueOfPrecedingFlag($argv, $a)
        ));

        $this->assertSame([], $positionals, 'argv must carry no positional arguments');
    }

    /**
     * Whether this argument is consumed as the value of the flag before it.
     *
     * @param list<string> $argv
     */
    private function isValueOfPrecedingFlag(array $argv, string $arg): bool
    {
        $index = array_search($arg, $argv, true);

        return $index !== false && $index > 0 && str_starts_with($argv[$index - 1], '-');
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function looseBooleanProvider(): array
    {
        return [
            'true' => [true, true],
            'string true' => ['true', true],
            'int one' => [1, true],
            'string yes' => ['yes', true],
            'false' => [false, false],
            'string no' => ['no', false],
            'string zero' => ['0', false],
            'empty string' => ['', false],
        ];
    }

    public function testHeadUsesHeadFlagRatherThanCustomRequest(): void
    {
        $argv = $this->argv([], [], [], 'HEAD');

        // -X HEAD makes curl wait for a body the server never sends.
        $this->assertContains('--head', $argv);
        $this->assertNotContains('-X', $argv);
    }

    // -------------------------------------------------------------------------
    // Exit-code handling
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string, 2: bool}>
     */
    public static function exitCodeProvider(): array
    {
        return [
            // [exit code, stdout write-out, should it throw]
            'clean success' => [0, '200', false],
            'redirect cap is excused, as on FFI' => [47, '302', false],
            'partial file must not pass as a 200' => [18, '200', true],
            'mid-body timeout must not pass' => [28, '200', true],
            'receive error must not pass' => [56, '200', true],
            'transport failure with no status' => [6, '000', true],
            // proc_get_status() reaps the child, so proc_close() answers -1 on
            // PHP 8.2 for a run that plainly succeeded. An indeterminate code is
            // not a failure, and inventing one broke every request on that
            // version the moment the engine started taking exit codes seriously.
            'indeterminate code with a status is not a failure' => [-1, '200', false],
            'indeterminate code with no status IS a failure' => [-1, '000', true],
        ];
    }

    #[DataProvider('exitCodeProvider')]
    public function testExitCodeDecidesFailureIndependentlyOfTheStatusLine(
        int $exitCode,
        string $writeOut,
        bool $shouldThrow
    ): void {
        $engine = new CurlProcess($this->browser([]), 5, []);

        $method = (new ReflectionClass(CurlProcess::class))->getMethod('processCommandOutput');
        $method->setAccessible(true);

        if ($shouldThrow) {
            $this->expectException(RequestException::class);
        }

        $result = $method->invoke($engine, $writeOut, '', $exitCode, 'curl');

        $this->assertSame($writeOut, $result['status_code']);
    }
}
