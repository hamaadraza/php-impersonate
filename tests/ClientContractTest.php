<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Exception\PHPImpersonateException;
use Raza\PHPImpersonate\Exception\InvalidArgumentException;
use Raza\PHPImpersonate\Exception\PlatformNotSupportedException;

/**
 * The public contract of the client: what it rejects, what it promises to
 * throw, and the engine-selection guard that protects a custom fingerprint.
 *
 * These are all behaviours the library documents and none of them had a test —
 * the timeout bounds, the invalid-browser wrapping, and the BrowserInterface
 * branch that keeps a custom profile away from the by-name FFI engine.
 *
 * No network and no binary: every case fails during construction or validation.
 */
class ClientContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Timeout bounds (PHPImpersonate::validateTimeout)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeTimeoutProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the one-hour cap' => [3601],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRangeTimeoutProvider')]
    public function testTimeoutOutsideTheDocumentedRangeIsRejected(int $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1 and 3600 seconds');

        new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, $timeout);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function boundaryTimeoutProvider(): array
    {
        return ['lower bound' => [1], 'upper bound' => [3600]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaryTimeoutProvider')]
    public function testTimeoutBoundariesThemselvesAreAccepted(int $timeout): void
    {
        $client = new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, $timeout);

        $this->assertContains($client->engine(), [PHPImpersonate::ENGINE_FFI, PHPImpersonate::ENGINE_PROCESS]);
    }

    // -------------------------------------------------------------------------
    // Invalid browser names, on both engine paths
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function engineProvider(): array
    {
        return [
            'auto' => [PHPImpersonate::ENGINE_AUTO],
            'process' => [PHPImpersonate::ENGINE_PROCESS],
        ];
    }

    /**
     * The exception TYPE at the public boundary is the contract: the constructor
     * documents RequestException, and both paths wrap what they catch —
     * Browser's RuntimeException on the process path, BrowserConfig's
     * InvalidArgumentException on the FFI/auto path.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engineProvider')]
    public function testAnUnknownBrowserNameIsReportedAsARequestException(string $engine): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid browser');

        new PHPImpersonate('definitely-not-a-browser', 30, [], $engine);
    }

    // -------------------------------------------------------------------------
    // A BrowserInterface carrying a CUSTOM config must never run on FFI
    // -------------------------------------------------------------------------

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
     * The FFI engine impersonates BY NAME, applying the shared library's own
     * built-in profile; it never sees getConfig(). Letting a custom profile
     * through would silently put a different fingerprint on the wire than the
     * caller assembled — the one failure this library must not have.
     */
    public function testAcustomProfileFallsBackToTheProcessEngineUnderAuto(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI unavailable, so auto would pick the process engine anyway');
        }

        $custom = $this->browser('chrome136', ['ciphers' => 'AES128-SHA', 'headers' => ['X-Custom' => '1']]);

        $client = new PHPImpersonate($custom, 30, [], PHPImpersonate::ENGINE_AUTO);

        $this->assertSame(PHPImpersonate::ENGINE_PROCESS, $client->engine());
    }

    public function testAcustomProfileWithEngineForcedToFfiIsRefusedLoudly(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('requires a working FFI engine to force');
        }

        $custom = $this->browser('chrome136', ['ciphers' => 'AES128-SHA']);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('custom configuration');

        new PHPImpersonate($custom, 30, [], PHPImpersonate::ENGINE_FFI);
    }

    /**
     * The mirror case: an instance carrying exactly the built-in config is
     * equivalent to the name, so FFI is allowed to keep it.
     */
    public function testAbuiltinProfileInstanceStaysOnTheFfiEngine(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI unavailable');
        }

        $builtin = $this->browser('chrome136', BrowserConfig::getConfig('chrome136'));

        $client = new PHPImpersonate($builtin, 30, [], PHPImpersonate::ENGINE_AUTO);

        $this->assertSame(PHPImpersonate::ENGINE_FFI, $client->engine());
    }

    // -------------------------------------------------------------------------
    // Engine names
    // -------------------------------------------------------------------------

    public function testAnUnknownEngineNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown engine 'turbo'");

        new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, 30, [], 'turbo');
    }

    // -------------------------------------------------------------------------
    // One catch for everything the library throws
    // -------------------------------------------------------------------------

    /**
     * `catch (RequestException)` — what the README shows — cannot catch an
     * argument error, because InvalidArgumentException descends from
     * LogicException and shares no built-in parent with it. The marker interface
     * is what gives callers a single type that covers both.
     *
     * @return array<string, array{0: callable(): mixed}>
     */
    public static function everyFailureProvider(): array
    {
        return [
            'unusable URL' => [fn () => PHPImpersonate::get('not a url')],
            'unsupported scheme' => [fn () => PHPImpersonate::get('ftp://example.com/x')],
            'timeout out of range' => [fn () => new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, 0)],
            'unknown engine' => [fn () => new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, 30, [], 'turbo')],
            'unknown browser' => [fn () => new PHPImpersonate('nope')],
            'unsupported curl option' => [fn () => new PHPImpersonate(PHPImpersonate::DEFAULT_BROWSER, 30, ['user-agent' => 'x'])],
            'invalid HTTP method' => [fn () => new Request("GET\r\nX-Injected: evil", 'https://example.com/')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyFailureProvider')]
    public function testEveryFailureIsCatchableAsOneLibraryType(callable $trigger): void
    {
        try {
            $trigger();
            $this->fail('expected the call to throw');
        } catch (PHPImpersonateException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheMarkerDoesNotChangeTheExistingParents(): void
    {
        // Nothing that used to be catchable stops being catchable.
        $this->assertInstanceOf(\InvalidArgumentException::class, new InvalidArgumentException('x'));
        $this->assertInstanceOf(\RuntimeException::class, new RequestException('x'));
        $this->assertInstanceOf(\RuntimeException::class, new PlatformNotSupportedException('plan9'));
    }

    // -------------------------------------------------------------------------
    // HTTP method validation
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function badMethodProvider(): array
    {
        return [
            'CRLF injection' => ["GET\r\nX-Injected: evil"],
            'bare LF' => ["GET\nX: y"],
            'NUL' => ["GET\0"],
            'space' => ['GET /x HTTP/1.1'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badMethodProvider')]
    public function testAmethodThatIsNotATokenIsRejected(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        new Request($method, 'https://example.com/');
    }

    public function testOrdinaryAndExtensionMethodsAreStillAccepted(): void
    {
        foreach (['GET', 'post', 'PATCH', 'PROPFIND', 'M-SEARCH'] as $method) {
            $this->assertSame(strtoupper($method), (new Request($method, 'https://example.com/'))->getMethod());
        }
    }

    // -------------------------------------------------------------------------
    // An empty header value must be SENT, not silently delete the header
    // -------------------------------------------------------------------------

    public function testAnEmptyHeaderValueUsesCurlsSendEmptyForm(): void
    {
        // `Name:` tells libcurl to REMOVE the header; `Name;` sends it empty.
        $this->assertSame('X-Api-Key;', RequestPreparer::headerLine('X-Api-Key', ''));
        $this->assertSame('X-Api-Key: v', RequestPreparer::headerLine('X-Api-Key', 'v'));
    }
}
