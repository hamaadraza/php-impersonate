<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate;

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
use Raza\PHPImpersonate\Exception\InvalidArgumentException;
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

    /** Why the last {@see ffiAvailable()} answered false, for diagnostics. */
    private static ?string $ffiUnavailableReason = null;

    /**
     * FFI engines shared process-wide, keyed by (library, browser) — and
     * deliberately NOT by the curl options. Sharing a handle lets keep-alive
     * connections be reused across requests — including the throwaway
     * instances the static helpers create, so `PHPImpersonate::get()` is as
     * fast as a retained client — while the per-browser key prevents a
     * connection (and its TLS fingerprint) from being reused across different
     * browsers. See {@see ffiEngine()}.
     *
     * The options are left out of the key because libcurl already isolates
     * what they affect: its connection cache only reuses a connection whose
     * proxy, TLS verification and CA settings match, and every option is
     * re-applied to the handle on every request after curl_easy_reset(). With
     * options in the key, the ordinary scraping pattern — a different proxy
     * per request — minted a new key per request, thrashed the LRU below, paid
     * FFI::cdef() plus curl_easy_init() on every miss, and never reused a
     * connection at all.
     *
     * Bounded to {@see MAX_FFI_ENGINES} entries (least recently used evicted
     * first): each entry pins a curl handle plus its kept-alive connections,
     * and 39 browsers is more than the bound, so a worker that rotates across
     * every profile would otherwise grow without limit.
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
     * The process that owns {@see $ffiEngines}.
     *
     * After pcntl_fork() the child inherits the cache, and with it every
     * handle's kept-alive connection — one kernel socket now shared by two
     * processes. Two TLS record layers on one socket diverge after the first
     * write, and on plain HTTP one process can read the other's response. The
     * child must therefore not use the parent's engines, nor free them: a
     * curl_easy_cleanup() there sends close_notify / FIN on the PARENT's
     * connection. A pid mismatch makes the cache abandon them instead.
     */
    private static int $ffiEnginesPid = 0;

    /**
     * @param BrowserName|BrowserInterface $browser Browser to use (name or browser instance).
     *                                               The full list is
     *                                               {@see \Raza\PHPImpersonate\Browser\BrowserName::getAll()};
     *                                               the union above is generated by
     *                                               scripts/update-browsers.php and is authoritative.
     *                                               (A prose copy used to live here and had already
     *                                               drifted to 38 of the 39 names.)
     * @param int $timeout Request timeout in seconds
     * @param array<string,mixed> $curlOptions Custom curl options (e.g. 'proxy')
     * @param self::ENGINE_* $engine Which engine to use; 'auto' (default) picks the
     *                               fast FFI engine when usable, else the executable.
     * @throws RequestException If the requested engine is unavailable or no curl-impersonate binary can be found
     * @throws InvalidArgumentException If the browser name, the timeout, an option, or the engine name is invalid
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
        // Canonicalise once, so both engines apply exactly the same value shape.
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
            // Windows is no special case: the DLL is bundled, so an unusable
            // engine there means what it means everywhere — the extension.
            self::$ffiUnavailableReason = extension_loaded('FFI')
                ? sprintf('The ffi extension is loaded but ffi.enable=%s does not permit it in the %s SAPI.', (string) ini_get('ffi.enable'), PHP_SAPI)
                : 'The ffi extension is not loaded.';

            return false;
        }

        // Memoised, so this is a cheap lookup rather than a filesystem walk.
        $lib = LibResolver::resolve();
        if ($lib === null) {
            self::$ffiUnavailableReason = sprintf(
                'No libcurl-impersonate shared library was found for %s (set %s, or run bin/php-impersonate-install).',
                PlatformDetector::getPlatformDescription(),
                LibResolver::ENV_VAR
            );

            return false;
        }

        if (self::$ffiProbe !== null && self::$ffiProbedLib === $lib) {
            return self::$ffiProbe;
        }

        self::$ffiProbedLib = $lib;

        try {
            $engine = new CurlImpersonate($lib); // the library loads (FFI::cdef + init)
        } catch (\Throwable $e) {
            self::$ffiUnavailableReason = sprintf('%s could not be loaded: %s', $lib, $e->getMessage());

            return self::$ffiProbe = false;
        }

        // …and can apply a profile. A library that loads but answers non-zero
        // here would fail EVERY request with "does not support target", so it
        // is treated as absent and 'auto' takes the executable engine instead.
        $rc = $engine->probeTarget(self::DEFAULT_BROWSER);
        if ($rc !== 0) {
            self::$ffiUnavailableReason = sprintf(
                "%s loaded, but curl_easy_impersonate('%s') returned %d. %s",
                $lib,
                self::DEFAULT_BROWSER,
                $rc,
                $rc === 48
                    ? 'Code 48 (CURLE_UNKNOWN_OPTION) from a known target means the library\'s own setopt calls '
                        . 'reached a different libcurl — the one compiled into this php binary for ext-curl. '
                        . 'On glibc the library is loaded with RTLD_DEEPBIND to prevent exactly this, so on '
                        . 'this platform (musl, or a build where dlopen is not reachable) it cannot be prevented; '
                        . 'the executable engine is used instead. Run scripts/ffi-diagnose.php for details.'
                    : 'The library may be older than this package\'s browser list; refresh it with `composer update-libraries`.'
            );

            return self::$ffiProbe = false;
        }

        self::$ffiUnavailableReason = null;

        return self::$ffiProbe = true;
    }

    /**
     * Why {@see ffiAvailable()} is false — the missing extension, the missing
     * library, a library that would not load, or one that loaded but cannot
     * apply a profile — or null when the FFI engine is available. Meant for
     * a diagnostics page or a CI step; 'auto' already acts on it.
     */
    public static function ffiUnavailableReason(): ?string
    {
        self::ffiAvailable();

        return self::$ffiUnavailableReason;
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
     * @param array{status: int, headers: string, body: string, url: string} $result
     */
    private function buildResponse(Request $request, array $result): Response
    {
        $isHead = strtoupper($request->getMethod()) === 'HEAD';

        return new Response(
            $isHead ? '' : $result['body'],
            $result['status'],
            ResponseHeaderParser::parse($result['headers']),
            ResponseHeaderParser::setCookieHeaders($result['headers']),
            $result['url'] !== '' ? $result['url'] : $request->getUrl()
        );
    }

    /**
     * The process-wide FFI engine for this client's browser.
     *
     * Engines are keyed by (library, browser), NOT just the library: curl
     * reuses keep-alive connections whose TLS config it considers a match, but
     * that check does not cover the full impersonation profile — so reusing
     * one connection across two browsers would send the first browser's
     * fingerprint for the second, silently defeating impersonation. Isolating
     * by browser keeps same-browser requests fast (connection reuse) while
     * never leaking a fingerprint between browsers. Options are applied per
     * request and need no isolation of their own; see {@see $ffiEngines}.
     */
    private function ffiEngine(): CurlImpersonate
    {
        $lib = LibResolver::resolve();
        if ($lib === null) {
            throw new RequestException('libcurl-impersonate shared library not found.');
        }

        $key = $lib . "\0" . $this->browserName;

        self::abandonEnginesOfAnotherProcess();

        if (isset(self::$ffiEngines[$key])) {
            // LRU touch: re-append so the least recently used entry evicts first.
            $engine = self::$ffiEngines[$key];
            unset(self::$ffiEngines[$key]);

            return self::$ffiEngines[$key] = $engine;
        }

        while (count(self::$ffiEngines) >= self::MAX_FFI_ENGINES) {
            unset(self::$ffiEngines[array_key_first(self::$ffiEngines)]);
        }

        self::registerShutdownRelease();

        return self::$ffiEngines[$key] = new CurlImpersonate($lib);
    }

    /** Whether the shutdown hook below has been installed. */
    private static bool $shutdownRegistered = false;

    /**
     * Release the cached engines deterministically, before PHP tears the object
     * graph down on its own.
     *
     * Each engine owns a curl handle AND the FFI instance the handle must be
     * freed through. At shutdown PHP destroys statics in no guaranteed order,
     * so the FFI object can go first, leaving ~CurlImpersonate() to call
     * curl_easy_cleanup() through a scope that no longer exists — the classic
     * way an FFI extension segfaults on exit, after every test has already
     * passed. Running the release from a shutdown function removes the ordering
     * question entirely: it fires while both are still alive.
     *
     * Engines are recreated lazily, so this costs nothing if PHP keeps running.
     */
    private static function registerShutdownRelease(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::closeFfiEngines();
        });
    }

    /**
     * Close every cached FFI engine, releasing their curl handles and any
     * kept-alive connections. Engines are recreated lazily on the next FFI
     * request; useful between batches in long-running workers.
     */
    public static function closeFfiEngines(): void
    {
        // Also reached from the shutdown hook, which a forked child inherits:
        // its exit must not close the parent's connections.
        self::abandonEnginesOfAnotherProcess();

        self::$ffiEngines = [];
    }

    /**
     * Drop engines inherited across a fork without freeing their handles; see
     * {@see $ffiEnginesPid}. A no-op in the process that created them.
     */
    private static function abandonEnginesOfAnotherProcess(): void
    {
        $pid = getmypid();
        if ($pid === false) {
            return;
        }

        if (self::$ffiEnginesPid !== $pid) {
            if (self::$ffiEnginesPid !== 0) {
                foreach (self::$ffiEngines as $engine) {
                    $engine->abandon();
                }
                self::$ffiEngines = [];
            }
            self::$ffiEnginesPid = $pid;
        }
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

        // The name is checked here, for both engines, so that the one other
        // thing Browser can fail on — locating a usable binary — reaches the
        // caller as what it is. It used to arrive as "Invalid browser: curl-
        // impersonate binary not found…", which sent people checking a name
        // that was fine.
        //
        // A caller mistake, so InvalidArgumentException — the type the README
        // and the exception's own docblock promise for "an unknown browser",
        // and what BrowserConfig::getConfig() already throws. It arrived as
        // RequestException here, as a bare \RuntimeException from
        // `new Browser()`, and as InvalidArgumentException from BrowserConfig:
        // three types for one mistake, one of them outside the library's
        // catch-all marker.
        if (! BrowserConfig::hasConfig($browser)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid browser: '%s' is not a supported browser. Available: %s",
                $browser,
                implode(', ', BrowserConfig::getAvailableBrowsers())
            ));
        }

        if ($this->engine === self::ENGINE_PROCESS) {
            // Browser throws the library's own types (InvalidArgumentException
            // for the name, RequestException for a binary it cannot find).
            $this->browser = new Browser($browser);
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
        return BrowserConfig::matchesBuiltin($browser->getName(), $browser->getConfig());
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
                    // The probe already knows exactly why; a fixed message hid
                    // it (a library that loaded but reached a foreign libcurl
                    // is not "disabled or missing").
                    throw new RequestException(
                        'The FFI engine was requested but is not available: '
                        . (self::$ffiUnavailableReason ?? 'the ffi extension is disabled or the shared library is missing.')
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
