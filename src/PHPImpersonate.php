<?php

namespace Raza\PHPImpersonate;

use RuntimeException;
use InvalidArgumentException;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Ffi\LibResolver;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;
use Raza\PHPImpersonate\Process\CurlProcess;
use Raza\PHPImpersonate\Support\CurlOptions;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Support\ResponseHeaderParser;
use Raza\PHPImpersonate\Exception\PlatformNotSupportedException;

/**
 * The single entry point. Prepares and validates requests, selects an engine,
 * and returns a {@see Response}. The two engines are internal details:
 * {@see \Raza\PHPImpersonate\Ffi\CurlImpersonate} (fast, in-process) and
 * {@see \Raza\PHPImpersonate\Process\CurlProcess} (the bundled executable).
 *
 * @phpstan-type BrowserName 'chrome99'|'chrome99_android'|'chrome120'|'edge99'|'edge101'|'firefox133'|'firefox135'|'chrome110'|'safari153'|'safari155'|'safari170'|'safari172_ios'|'safari180'|'safari180_ios'|'safari184'|'safari184_ios'|'safari260_ios'|'chrome100'|'chrome101'|'chrome104'|'chrome107'|'chrome116'|'chrome119'|'chrome123'|'chrome124'|'chrome131'|'chrome131_android'|'chrome133a'|'chrome136'|'safari260'|'tor145'|'chrome142'|'chrome145'|'chrome146'|'chrome150'|'firefox144'|'firefox147'|'safari2601'|'okhttp4_android'
 */
class PHPImpersonate implements ClientInterface
{
    /**
     * Public so every entry point (including {@see PHPImpersonateFactory})
     * shares one definition of the defaults instead of re-declaring literals
     * that silently drift apart.
     */
    public const DEFAULT_BROWSER = 'firefox147';
    public const DEFAULT_TIMEOUT = 30;

    private const MAX_TIMEOUT = 3600; // 1 hour max
    private const MIN_TIMEOUT = 1;

    /**
     * Engine selection. 'auto' uses the fast in-process FFI engine when it is
     * usable, otherwise the executable engine. Both accept the same options.
     */
    public const ENGINE_AUTO = 'auto';
    public const ENGINE_FFI = 'ffi';
    public const ENGINE_PROCESS = 'process';

    /** Executable-backed browser (process engine only; null under FFI). */
    private ?BrowserInterface $browser = null;
    private string $browserName;
    private string $engine;
    private ?CurlProcess $processEngine = null;

    /**
     * Cached FFI load probe, together with the library path it was taken
     * against. Keying it means a library installed mid-process — picked up after
     * {@see LibResolver::clearCache()} — is probed afresh instead of being masked
     * by the earlier "no library" result. Only the expensive part (FFI::cdef plus
     * handle init) is cached; the cheap checks in front of it re-run each call.
     */
    private static ?bool $ffiProbe = null;
    private static ?string $ffiProbedLib = null;

    /**
     * FFI engines shared process-wide, keyed by (library, browser, options).
     * Sharing a handle lets keep-alive connections be reused across requests —
     * including the throwaway instances the static helpers create, so
     * `PHPImpersonate::get()` is as fast as a retained client — while the
     * per-config key prevents a connection (and its TLS fingerprint) from being
     * reused across different browsers. See {@see ffiEngine()}.
     *
     * Bounded to {@see MAX_FFI_ENGINES} entries (least recently used evicted
     * first): each entry pins a curl handle plus its kept-alive connections, and
     * callers can mint unlimited distinct keys (e.g. a new proxy per request),
     * which in a long-running worker would otherwise grow without limit.
     *
     * NOT REENTRANT. Sharing an engine means sharing one curl easy handle, which
     * is correct under ordinary sequential PHP but assumes one request is in
     * flight at a time. Two requests interleaved on the same key — Fibers,
     * Swoole coroutines, ext-parallel — would reconfigure and read the same
     * handle underneath each other. Under such a runtime, give each concurrent
     * worker its own process, or keep them on distinct keys.
     *
     * @var array<string, CurlImpersonate>
     */
    private static array $ffiEngines = [];

    /** Upper bound on cached FFI engines; see {@see $ffiEngines}. */
    private const MAX_FFI_ENGINES = 16;

    /**
     * @param BrowserName|BrowserInterface $browser Browser to use (name or browser instance).
     *                                               Available browsers: chrome99, chrome99_android, chrome100, chrome101,
     *                                               chrome104, chrome107, chrome110, chrome116, chrome119, chrome120,
     *                                               chrome123, chrome124, chrome131, chrome131_android, chrome133a,
     *                                               chrome136, chrome142, chrome145, chrome146, chrome150, edge99,
     *                                               edge101, firefox133, firefox135, firefox144, firefox147, safari153,
     *                                               safari155, safari170, safari172_ios, safari180, safari180_ios,
     *                                               safari184, safari184_ios, safari260, safari260_ios, safari2601, tor145
     * @param int $timeout Request timeout in seconds
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Which engine to use; 'auto' (default) picks the
     *                               fast FFI engine when usable, else the executable.
     * @throws RequestException If the browser is invalid or the requested engine is unavailable
     * @throws InvalidArgumentException If the timeout, an option, or the engine name is invalid
     */
    public function __construct(
        string|BrowserInterface $browser = self::DEFAULT_BROWSER,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ) {
        $this->validateTimeout($timeout);
        $this->validatePlatform();
        CurlOptions::assertAllowed($curlOptions);
        // Canonicalise once: both engines apply the result, and the FFI cache
        // key below is built from it, so equivalent configs share one engine.
        $this->curlOptions = CurlOptions::normalize($curlOptions);
        $this->engine = $this->resolveEngine($engine);
        $this->initializeBrowser($browser, $engine);
    }

    /**
     * @inheritDoc
     */
    public function send(Request $request): Response
    {
        RequestPreparer::validateRequest($request);

        return $this->engine === self::ENGINE_FFI
            ? $this->sendViaFfi($request)
            : $this->sendViaProcess($request);
    }

    /**
     * The name of the engine this client is using: 'ffi' or 'process'.
     */
    public function engine(): string
    {
        return $this->engine;
    }

    /**
     * Whether the fast in-process FFI engine is usable in this environment
     * (the `ffi` extension is available and the shared library loads). Probed
     * once and cached.
     */
    public static function ffiAvailable(): bool
    {
        if (! CurlImpersonate::isSupported()) {
            return false;
        }

        // Memoised, so this is a cheap lookup rather than a filesystem walk.
        $lib = LibResolver::resolve();
        if ($lib === null) {
            return false;
        }

        if (self::$ffiProbe !== null && self::$ffiProbedLib === $lib) {
            return self::$ffiProbe;
        }

        self::$ffiProbedLib = $lib;

        try {
            new CurlImpersonate($lib); // probe that the library loads (FFI::cdef + init)

            return self::$ffiProbe = true;
        } catch (\Throwable) {
            return self::$ffiProbe = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendGet(string $url, array $headers = []): Response
    {
        return $this->send(Request::get($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendPost(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers);

        return $this->send(Request::post($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendHead(string $url, array $headers = []): Response
    {
        return $this->send(Request::head($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendDelete(string $url, array $headers = []): Response
    {
        return $this->send(Request::delete($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendPatch(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers, 'application/json');

        return $this->send(Request::patch($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendPut(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers, 'application/json');

        return $this->send(Request::put($url, $headers, $body));
    }

    // Static convenience methods
    /**
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function get(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendGet($url, $headers);
    }

    /**
     * @param array<string,mixed>|null $data Data to send as the request body
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function post(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendPost($url, $data, $headers);
    }

    /**
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function head(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendHead($url, $headers);
    }

    /**
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function delete(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendDelete($url, $headers);
    }

    /**
     * @param array<string,mixed>|null $data Data to send as the request body
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function patch(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendPatch($url, $data, $headers);
    }

    /**
     * @param array<string,mixed>|null $data Data to send as the request body
     * @param array<string,string> $headers Headers to send with the request
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Engine to use; 'auto' (default) picks FFI when usable.
     */
    public static function put(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = self::ENGINE_AUTO
    ): Response {
        return (new self($browser, $timeout, $curlOptions, $engine))->sendPut($url, $data, $headers);
    }

    /**
     * Execute a request through the in-process FFI engine.
     */
    private function sendViaFfi(Request $request): Response
    {
        $headers = RequestPreparer::normalizeHeaders($request->getHeaders());
        foreach ($headers as $name => $value) {
            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
        }

        return $this->buildResponse($request, $this->ffiEngine()->request(
            $request->getMethod(),
            $request->getUrl(),
            $headers,
            $request->getBody(),
            $this->browserName,
            $this->timeout,
            $this->curlOptions
        ));
    }

    /**
     * Execute a request through the executable engine.
     */
    private function sendViaProcess(Request $request): Response
    {
        // Normalize here so "Header: Value" list entries work for every method,
        // not just the sendPost/sendPatch/sendPut convenience paths.
        $headers = RequestPreparer::normalizeHeaders($request->getHeaders());

        return $this->buildResponse($request, $this->processEngine()->request(
            $request->getMethod(),
            $request->getUrl(),
            $headers,
            $request->getBody()
        ));
    }

    /**
     * Build a Response from an engine's raw result (shared by both engines).
     *
     * @param array{status: int, headers: string, body: string} $result
     */
    private function buildResponse(Request $request, array $result): Response
    {
        $isHead = strtoupper($request->getMethod()) === 'HEAD';

        return new Response(
            $isHead ? '' : $result['body'],
            $result['status'],
            ResponseHeaderParser::parse($result['headers'])
        );
    }

    /**
     * The process-wide FFI engine for this client's exact configuration.
     *
     * Engines are keyed by (library, browser, options), NOT just the library:
     * curl reuses keep-alive connections whose TLS config it considers a match,
     * but that check does not cover the full impersonation profile — so reusing
     * one connection across two browsers would send the first browser's
     * fingerprint for the second, silently defeating impersonation. Isolating by
     * config keeps same-config requests fast (connection reuse) while never
     * leaking a fingerprint between browsers.
     *
     * The options were canonicalised — values and key order — by
     * {@see CurlOptions::normalize()}, so two spellings of one configuration
     * share an engine rather than minting two.
     */
    private function ffiEngine(): CurlImpersonate
    {
        $lib = LibResolver::resolve();
        if ($lib === null) {
            throw new RequestException('libcurl-impersonate shared library not found.');
        }

        $key = $lib . "\0" . $this->browserName . "\0" . serialize($this->curlOptions);

        if (isset(self::$ffiEngines[$key])) {
            // LRU touch: re-append so the least recently used entry evicts first.
            $engine = self::$ffiEngines[$key];
            unset(self::$ffiEngines[$key]);

            return self::$ffiEngines[$key] = $engine;
        }

        while (count(self::$ffiEngines) >= self::MAX_FFI_ENGINES) {
            unset(self::$ffiEngines[array_key_first(self::$ffiEngines)]);
        }

        return self::$ffiEngines[$key] = new CurlImpersonate($lib);
    }

    /**
     * Close every cached FFI engine, releasing their curl handles and any
     * kept-alive connections. Engines are recreated lazily on the next FFI
     * request; useful between batches in long-running workers.
     */
    public static function closeFfiEngines(): void
    {
        self::$ffiEngines = [];
    }

    /**
     * The executable engine for this client (created on first use).
     */
    private function processEngine(): CurlProcess
    {
        if ($this->browser === null) {
            // Unreachable: the process engine always resolves an executable browser.
            throw new RequestException('Executable browser was not resolved.');
        }

        return $this->processEngine ??= new CurlProcess($this->browser, $this->timeout, $this->curlOptions);
    }

    /**
     * Validate timeout value
     */
    private function validateTimeout(int $timeout): void
    {
        if ($timeout < self::MIN_TIMEOUT || $timeout > self::MAX_TIMEOUT) {
            throw new InvalidArgumentException(
                sprintf(
                    'Timeout must be between %d and %d seconds, got %d',
                    self::MIN_TIMEOUT,
                    self::MAX_TIMEOUT,
                    $timeout
                )
            );
        }
    }

    /**
     * Validate platform support
     */
    private function validatePlatform(): void
    {
        $platform = PlatformDetector::getPlatform();
        $arch = PlatformDetector::getArchitecture();

        $supportedPlatforms = [
            PlatformDetector::PLATFORM_LINUX,
            PlatformDetector::PLATFORM_WINDOWS,
            PlatformDetector::PLATFORM_MACOS,
        ];

        if (! in_array($platform, $supportedPlatforms, true)) {
            throw new PlatformNotSupportedException($platform, $supportedPlatforms);
        }

        $supportedArchitectures = PlatformDetector::getSupportedArchitectures();

        if ($arch === PlatformDetector::ARCH_UNKNOWN) {
            throw new PlatformNotSupportedException(
                $platform,
                $supportedPlatforms,
                php_uname('m'),
                $supportedArchitectures
            );
        }
    }

    /**
     * Resolve the browser. The executable-backed Browser (which locates the
     * binary) is only created for the process engine; the FFI engine needs just
     * the name and validates it against the known configs.
     */
    private function initializeBrowser(string|BrowserInterface $browser, string $requestedEngine): void
    {
        if ($browser instanceof BrowserInterface) {
            $this->browser = $browser;
            $this->browserName = $browser->getName();

            // The FFI engine impersonates BY NAME: it applies the shared
            // library's own built-in profile and never sees getConfig(). For an
            // instance carrying a custom config, staying on FFI would silently
            // send a different fingerprint than the caller assembled, so fall
            // back to the engine that can actually apply it.
            if ($this->engine === self::ENGINE_FFI && ! self::carriesBuiltinConfig($browser)) {
                if ($requestedEngine === self::ENGINE_FFI) {
                    throw new RequestException(sprintf(
                        "Browser '%s' supplies a custom configuration, which the FFI engine cannot apply "
                        . '(it impersonates by name using the shared library\'s built-in profiles). '
                        . "Use engine '%s', or pass a browser name instead of a BrowserInterface.",
                        $this->browserName,
                        self::ENGINE_PROCESS
                    ));
                }

                $this->engine = self::ENGINE_PROCESS;
            }

            return;
        }

        $this->browserName = $browser;

        if ($this->engine === self::ENGINE_PROCESS) {
            try {
                $this->browser = new Browser($browser);
            } catch (RuntimeException $e) {
                throw new RequestException("Invalid browser: " . $e->getMessage(), 0, $e);
            }
        } elseif (! BrowserConfig::hasConfig($browser)) {
            throw new RequestException(sprintf(
                "Invalid browser: '%s' is not a supported browser. Available: %s",
                $browser,
                implode(', ', BrowserConfig::getAvailableBrowsers())
            ));
        }
    }

    /**
     * Whether an instance carries exactly the built-in config for its name, in
     * which case the FFI engine's by-name profile is equivalent and using it
     * loses nothing. Anything else is a custom profile only the executable
     * engine can render.
     */
    private static function carriesBuiltinConfig(BrowserInterface $browser): bool
    {
        $name = $browser->getName();

        return BrowserConfig::hasConfig($name) && $browser->getConfig() === BrowserConfig::getConfig($name);
    }

    /**
     * Resolve the effective engine. Every supported curl option works on both
     * engines (see {@see CurlOptions}), so 'auto' simply prefers FFI when usable.
     *
     * @return self::ENGINE_FFI|self::ENGINE_PROCESS
     */
    private function resolveEngine(string $requested): string
    {
        switch ($requested) {
            case self::ENGINE_PROCESS:
                return self::ENGINE_PROCESS;

            case self::ENGINE_FFI:
                if (! self::ffiAvailable()) {
                    throw new RequestException(
                        'The FFI engine was requested but is not available '
                        . '(the ffi extension is disabled or the shared library is missing).'
                    );
                }

                return self::ENGINE_FFI;

            case self::ENGINE_AUTO:
                return self::ffiAvailable() ? self::ENGINE_FFI : self::ENGINE_PROCESS;

            default:
                throw new InvalidArgumentException(
                    "Unknown engine '$requested'. Use 'auto', 'ffi', or 'process'."
                );
        }
    }
}
