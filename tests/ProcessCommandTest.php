<?php

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Process\CurlProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Browser\BrowserInterface;

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
        $before = glob(sys_get_temp_dir() . '/curl_impersonate_request_headers*') ?: [];

        try {
            $this->argv([], ['X-Bad' => "value\r\nInjected: 1"]);
            $this->fail('an injected header should have been rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid header', $e->getMessage());
        }

        $after = glob(sys_get_temp_dir() . '/curl_impersonate_request_headers*') ?: [];
        $this->assertSame($before, $after, 'a rejected request must not leave a temp file behind');
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
            // Whatever follows must be another flag or the URL, never a value.
            $next = $argv[$index + 1] ?? '';
            $this->assertTrue(
                str_starts_with($next, '-') || $next === 'https://example.com/',
                "--insecure was followed by a stray argument: '$next'"
            );
        } else {
            $this->assertNotContains('--insecure', $argv);
        }

        // The URL must remain the one and only positional argument.
        $positionals = array_values(array_filter(
            array_slice($argv, 1),
            fn ($a) => ! str_starts_with($a, '-') && ! str_contains($a, ': ') && ! str_starts_with($a, '/tmp/')
        ));
        $this->assertSame(['https://example.com/'], array_values(array_filter(
            $positionals,
            fn ($a) => str_starts_with($a, 'https://')
        )));
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
}
