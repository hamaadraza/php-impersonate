<?php

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
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
     * Build the argv for one request without running it.
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

        foreach ($result['tempFiles'] as $file) {
            @unlink($file);
        }

        return $result['command'];
    }

    /**
     * @param list<string> $argv
     * @return list<string> the value of every -H flag
     */
    private function headerArgs(array $argv): array
    {
        $headers = [];
        foreach ($argv as $i => $arg) {
            if ($arg === '-H' && isset($argv[$i + 1])) {
                $headers[] = $argv[$i + 1];
            }
        }

        return $headers;
    }

    public function testCallerHeaderReplacesProfileHeader(): void
    {
        $argv = $this->argv(
            ['User-Agent' => 'ProfileAgent/9.0', 'Accept' => 'text/html'],
            ['User-Agent' => 'CallerAgent/1.0']
        );

        $headers = $this->headerArgs($argv);

        // Exactly one User-Agent, and it is the caller's: curl emits every -H it
        // is given, so appending the profile's too would put both on the wire.
        $userAgents = array_values(array_filter($headers, fn ($h) => str_starts_with($h, 'User-Agent:')));
        $this->assertSame(['User-Agent: CallerAgent/1.0'], $userAgents);

        // Untouched profile headers still come through.
        $this->assertContains('Accept: text/html', $headers);
    }

    public function testHeaderOverrideIsCaseInsensitive(): void
    {
        $headers = $this->headerArgs($this->argv(
            ['User-Agent' => 'ProfileAgent/9.0'],
            ['user-agent' => 'CallerAgent/1.0']
        ));

        $this->assertSame(['user-agent: CallerAgent/1.0'], $headers);
    }

    public function testProfileHeadersAreKeptWhenNothingOverridesThem(): void
    {
        $headers = $this->headerArgs($this->argv(
            ['User-Agent' => 'ProfileAgent/9.0', 'Accept-Language' => 'en-US']
        ));

        $this->assertContains('User-Agent: ProfileAgent/9.0', $headers);
        $this->assertContains('Accept-Language: en-US', $headers);
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
