<?php

namespace Raza\PHPImpersonate;

use RuntimeException;
use InvalidArgumentException;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Ffi\LibResolver;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Platform\CommandBuilder;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Support\ResponseHeaderParser;
use Raza\PHPImpersonate\Exception\PlatformNotSupportedException;

/**
 * @phpstan-type BrowserName 'chrome99'|'chrome99_android'|'chrome120'|'edge99'|'edge101'|'firefox133'|'firefox135'|'chrome110'|'safari153'|'safari155'|'safari170'|'safari172_ios'|'safari180'|'safari180_ios'|'safari184'|'safari184_ios'|'safari260_ios'|'chrome100'|'chrome101'|'chrome104'|'chrome107'|'chrome116'|'chrome119'|'chrome123'|'chrome124'|'chrome131'|'chrome131_android'|'chrome133a'|'chrome136'|'safari260'|'tor145'|'chrome142'|'chrome145'|'chrome146'|'chrome150'|'firefox144'|'firefox147'|'safari2601'|'okhttp4_android'
 */
class PHPImpersonate implements ClientInterface
{
    private const DEFAULT_BROWSER = 'firefox147';
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_TIMEOUT = 3600; // 1 hour max
    private const MIN_TIMEOUT = 1;
    private const PROCESS_TIMEOUT_BUFFER = 5;

    /**
     * Engine selection. 'auto' uses the fast in-process FFI engine when it is
     * usable and can apply the given options, otherwise the executable engine.
     */
    public const ENGINE_AUTO = 'auto';
    public const ENGINE_FFI = 'ffi';
    public const ENGINE_PROCESS = 'process';

    /** Resolved executable-backed browser (process engine only; lazy). */
    private ?BrowserInterface $browser = null;
    private string $browserName;
    private string $engine;
    private array $tempFiles = [];

    /** Cached one-time FFI load probe. */
    private static ?bool $ffiProbe = null;

    /**
     * FFI engines shared process-wide, keyed by library path. Sharing the curl
     * handle across clients is what lets keep-alive connections be reused between
     * requests — including across the throwaway instances the static helpers
     * create — so `PHPImpersonate::get()` is as fast as a retained client.
     *
     * @var array<string, CurlImpersonate>
     */
    private static array $ffiEngines = [];

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
        $this->engine = $this->resolveEngine($engine, $curlOptions);
        $this->initializeBrowser($browser);

        if ($this->engine === self::ENGINE_FFI) {
            $this->assertFfiOptionsSupported($curlOptions);
        } else {
            $this->validateCurlOptions($curlOptions);
        }
    }

    /**
     * Cleanup temp files on destruction
     */
    public function __destruct()
    {
        $this->cleanupAllTempFiles();
    }

    /**
     * @inheritDoc
     */
    public function send(Request $request): Response
    {
        $this->validateRequest($request);

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
        if (self::$ffiProbe !== null) {
            return self::$ffiProbe;
        }
        if (! CurlImpersonate::isSupported()) {
            return self::$ffiProbe = false;
        }
        $lib = LibResolver::resolve();
        if ($lib === null) {
            return self::$ffiProbe = false;
        }

        try {
            // Build the shared engine now (FFI::cdef + curl_easy_init) so the
            // probe is not wasted — the first real request reuses this instance.
            self::$ffiEngines[$lib] ??= new CurlImpersonate($lib);
            self::$ffiProbe = true;
        } catch (\Throwable $e) {
            self::$ffiProbe = false;
        }

        return self::$ffiProbe;
    }

    /**
     * The process-wide FFI engine for the resolved library, created on first use.
     */
    private static function ffiEngine(): CurlImpersonate
    {
        $lib = LibResolver::resolve();
        if ($lib === null) {
            throw new RequestException('libcurl-impersonate shared library not found.');
        }

        return self::$ffiEngines[$lib] ??= new CurlImpersonate($lib);
    }

    /**
     * Execute a request through the executable engine.
     */
    private function sendViaProcess(Request $request): Response
    {
        $tempFiles = $this->createTempFiles();

        try {
            $commandResult = $this->buildCommand(
                $request->getMethod(),
                $request->getUrl(),
                $tempFiles['body'],
                $tempFiles['headers'],
                // Normalize here so "Header: Value" list entries work for every
                // method, not just the sendPost/sendPatch/sendPut convenience paths
                $this->normalizeHeaders($request->getHeaders()),
                $request->getBody()
            );

            $command = $commandResult['command'];
            $additionalTempFiles = $commandResult['tempFiles'];

            $result = $this->runCommand($command);

            // HEAD responses have no body; with --head the output file holds the
            // header block instead, so don't present it as a body
            $isHead = strtoupper($request->getMethod()) === 'HEAD';

            // On Windows with .bat files, the output might not be written to files properly
            // So we capture the response directly from the command output
            $responseBody = $isHead ? '' : $this->readTempFile($tempFiles['body']);
            $extractedStatus = null;

            // If the temp file is empty, try to get response from command stdout
            // (never stderr — warnings there must not be mistaken for a body)
            if (! $isHead && empty($responseBody) && ! empty($result['stdout'])) {
                $extracted = $this->captureResponseFromOutput($result['stdout']);
                $responseBody = $extracted['body'];
                $extractedStatus = $extracted['status'];
            }

            $responseHeaders = $this->parseHeaders(
                $this->readTempFile($tempFiles['headers'])
            );



            // If we still don't have a proper status code, try to extract it from headers
            if ($extractedStatus === '0' || $extractedStatus === 0) {
                $extractedStatus = $this->extractStatusFromHeaders($responseHeaders);
            }

            // Use extracted status code if available, otherwise use the one from result
            $statusCode = $extractedStatus !== null ? (int)$extractedStatus : (int)$result['status_code'];

            return new Response($responseBody, $statusCode, $responseHeaders);

        } finally {
            $this->cleanupTempFiles($tempFiles);
            // Clean up additional temporary files (body data files)
            if (isset($additionalTempFiles)) {
                foreach ($additionalTempFiles as $tempFile) {
                    $this->deleteTempFile($tempFile);
                }
            }
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
        $headers = $this->normalizeHeaders($headers);
        $body = $this->prepareRequestBody($data, $headers);

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
        $headers = $this->normalizeHeaders($headers);
        $body = $this->prepareRequestBody($data, $headers, 'application/json');

        return $this->send(Request::patch($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendPut(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = $this->normalizeHeaders($headers);
        $body = $this->prepareRequestBody($data, $headers, 'application/json');

        return $this->send(Request::put($url, $headers, $body));
    }

    // Static convenience methods
    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function get(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendGet($url, $headers);
    }

    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function post(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendPost($url, $data, $headers);
    }

    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function head(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendHead($url, $headers);
    }

    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function delete(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendDelete($url, $headers);
    }

    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function patch(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendPatch($url, $data, $headers);
    }

    /**
     * @param BrowserName $browser Browser name (see BrowserName constants or constructor docblock)
     */
    public static function put(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        return (new self($browser, $timeout, $curlOptions))->sendPut($url, $data, $headers);
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

        // Check if platform is supported
        $supportedPlatforms = [
            PlatformDetector::PLATFORM_LINUX,
            PlatformDetector::PLATFORM_WINDOWS,
            PlatformDetector::PLATFORM_MACOS,
        ];

        if (! in_array($platform, $supportedPlatforms, true)) {
            throw new PlatformNotSupportedException(
                $platform,
                $supportedPlatforms
            );
        }

        // Check if architecture is supported
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
    private function initializeBrowser(string|BrowserInterface $browser): void
    {
        if ($browser instanceof BrowserInterface) {
            $this->browser = $browser;
            $this->browserName = $browser->getName();

            return;
        }

        $this->browserName = $browser;

        if ($this->engine === self::ENGINE_PROCESS) {
            $this->validatePlatform();

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
     * Resolve the effective engine from the requested one and the given options.
     *
     * @param array<string,mixed> $curlOptions
     * @return self::ENGINE_FFI|self::ENGINE_PROCESS
     */
    private function resolveEngine(string $requested, array $curlOptions): string
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
                return (self::ffiAvailable() && $this->ffiCanHandle($curlOptions))
                    ? self::ENGINE_FFI
                    : self::ENGINE_PROCESS;

            default:
                throw new InvalidArgumentException(
                    "Unknown engine '$requested'. Use 'auto', 'ffi', or 'process'."
                );
        }
    }

    /**
     * Whether the FFI engine can apply every supplied curl option.
     *
     * @param array<string,mixed> $curlOptions
     */
    private function ffiCanHandle(array $curlOptions): bool
    {
        return array_diff(array_keys($curlOptions), CurlImpersonate::supportedOptionKeys()) === [];
    }

    /**
     * @param array<string,mixed> $curlOptions
     * @throws InvalidArgumentException on any option the FFI engine cannot apply.
     */
    private function assertFfiOptionsSupported(array $curlOptions): void
    {
        $unsupported = array_diff(array_keys($curlOptions), CurlImpersonate::supportedOptionKeys());
        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'The FFI engine does not support the curl option(s): %s. Supported: %s. '
                . "Use the process engine (engine: PHPImpersonate::ENGINE_PROCESS) for other options.",
                implode(', ', $unsupported),
                implode(', ', CurlImpersonate::supportedOptionKeys())
            ));
        }
    }

    /**
     * Execute a request through the in-process FFI engine.
     */
    private function sendViaFfi(Request $request): Response
    {
        $engine = self::ffiEngine();

        $headers = $this->normalizeHeaders($request->getHeaders());
        foreach ($headers as $name => $value) {
            $this->assertHeaderIsSafe((string) $name, (string) $value);
        }

        $result = $engine->request(
            $request->getMethod(),
            $request->getUrl(),
            $headers,
            $request->getBody(),
            $this->browserName,
            $this->timeout,
            $this->curlOptions
        );

        $isHead = strtoupper($request->getMethod()) === 'HEAD';

        return new Response(
            $isHead ? '' : $result['body'],
            $result['status'],
            ResponseHeaderParser::parse($result['headers'])
        );
    }

    /**
     * Validate curl options
     */
    private function validateCurlOptions(array $curlOptions): void
    {
        $forbiddenOptions = [
            // Conflict with how responses are captured internally
            'o', 'output', 'D', 'dump-header', 'w', 'write-out',
            // Write files to paths chosen by the server or outside our control
            'O', 'remote-name', 'remote-name-all', 'J', 'remote-header-name', 'output-dir',
            // Load arbitrary curl config, which can re-introduce any of the above
            'K', 'config',
            // Redirect diagnostics to files
            'trace', 'trace-ascii', 'stderr',
            // Start a second request with a fresh option set, bypassing all checks
            ':', 'next',
        ];

        foreach ($forbiddenOptions as $option) {
            if (isset($curlOptions[$option])) {
                throw new InvalidArgumentException(
                    "Curl option '$option' is not allowed as it conflicts with internal usage"
                );
            }
        }
    }

    /**
     * Validate request object
     */
    private function validateRequest(Request $request): void
    {
        RequestPreparer::validateRequest($request);
    }

    /**
     * Prepare request body based on content type and data
     */
    private function prepareRequestBody(
        ?array $data,
        array &$headers,
        string $defaultContentType = 'application/x-www-form-urlencoded'
    ): ?string {
        return RequestPreparer::prepareBody($data, $headers, $defaultContentType);
    }

    /**
     * Create temporary files for the request/response
     */
    private function createTempFiles(): array
    {
        return [
            'body' => $this->createTempFile('curl_impersonate_body'),
            'headers' => $this->createTempFile('curl_impersonate_headers'),
        ];
    }

    /**
     * Create a single temporary file.
     *
     * Every created file is tracked in $this->tempFiles so the destructor can
     * clean up files orphaned by exceptions between creation and use.
     */
    private function createTempFile(string $prefix): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempFile === false) {
            throw new RequestException('Unable to create temporary file');
        }

        if (! is_writable($tempFile)) {
            @unlink($tempFile);

            throw new RequestException('Created temporary file is not writable');
        }

        // Owner-only: these files hold request/response bodies and headers (cookies, tokens)
        if (! chmod($tempFile, 0600)) {
            @unlink($tempFile);

            throw new RequestException('Unable to set temporary file permissions');
        }

        $this->tempFiles[] = $tempFile;

        return $tempFile;
    }

    /**
     * Read content from temporary file
     */
    private function readTempFile(string $filePath): string
    {
        if (! file_exists($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);

        return $content !== false ? $content : '';
    }

    /**
     * Capture response body and status code from command output array
     * This handles the case where .bat files don't properly redirect output to files
     */
    private function captureResponseFromOutput(array $output): array
    {
        if (empty($output)) {
            return ['body' => '', 'status' => '0'];
        }

        // Join all output lines
        $fullOutput = implode("\n", $output);

        // Remove any trailing temp file paths that might be added by curl on Windows
        $fullOutput = preg_replace('/\S+\\\\.*\.tmp$/', '', $fullOutput);
        $fullOutput = trim($fullOutput);

        // The response should be everything except the last line which contains the status code
        $lines = explode("\n", $fullOutput);
        $statusCode = '0';

        // Extract the status code from the last line if it's numeric
        if (count($lines) > 1) {
            $lastLine = trim(end($lines));
            if (is_numeric($lastLine) && strlen($lastLine) <= 3) {
                $statusCode = $lastLine;
                // Remove the status code line
                array_pop($lines);
                $fullOutput = implode("\n", $lines);
            }
        } elseif (count($lines) === 1) {
            // If there's only one line and it's a numeric status code, it's likely a HEAD request
            $singleLine = trim($lines[0]);
            if (is_numeric($singleLine) && strlen($singleLine) <= 3) {
                $statusCode = $singleLine;
                $fullOutput = ''; // HEAD requests should have empty body
            }
        }

        return ['body' => $fullOutput, 'status' => $statusCode];
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string, string[]> $headers
     * @return string|null Null when no status line is present — callers must not
     *                     assume success in that case.
     */
    private function extractStatusFromHeaders(array $headers): ?string
    {
        if (isset($headers['HTTP_STATUS'][0])) {
            if (preg_match('/(\d{3})/', $headers['HTTP_STATUS'][0], $matches)) {
                return $matches[1];
            }
        }

        foreach ($headers as $values) {
            foreach ($values as $value) {
                if (preg_match('/HTTP\/[\d.]+\s+(\d{3})/', $value, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Clean up temporary files
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteTempFile($file);
        }
    }

    /**
     * Clean up all tracked temporary files
     */
    private function cleanupAllTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            $this->deleteTempFile($file);
        }
        $this->tempFiles = [];
    }

    /**
     * Delete a single temporary file
     */
    private function deleteTempFile(string $file): void
    {
        if (file_exists($file)) {
            @unlink($file);
        }

        $this->tempFiles = array_diff($this->tempFiles, [$file]);
    }

    /**
     * Build the curl command
     */
    private function buildCommand(
        string $method,
        string $url,
        string $outputFile,
        string $headerFile,
        array $headers = [],
        ?string $body = null
    ): array {
        $browserCmd = $this->browser->getExecutablePath();
        $browserConfig = $this->browser->getConfig();

        [$options, $headersTempFiles] = $this->buildCurlOptions($method, $outputFile, $headerFile, $headers);
        $additionalTempFiles = [];

        if ($body !== null) {
            $additionalTempFiles = $this->addBodyToOptions($options, $body);
        }

        // Add browser-specific configuration
        $options = $this->mergeBrowserConfig($options, $browserConfig);

        // Add custom curl options (validated ones only)
        $options = array_merge($options, $this->curlOptions);

        // Merge all temp files for cleanup
        $allTempFiles = array_merge($headersTempFiles, $additionalTempFiles);

        try {
            // argv array for proc_open array mode: executed directly, no shell involved
            $command = CommandBuilder::buildCurlCommandArgs($browserCmd, [$url], $options);

            return ['command' => $command, 'tempFiles' => $allTempFiles];
        } catch (\Exception $e) {
            // Clean up any temporary files that were created
            foreach ($allTempFiles as $tempFile) {
                $this->deleteTempFile($tempFile);
            }

            throw new RequestException('Failed to build curl command: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build base curl options
     *
     * @return array{0: array, 1: array<string>} [options, tempFiles]
     */
    private function buildCurlOptions(
        string $method,
        string $outputFile,
        string $headerFile,
        array $headers
    ): array {
        $options = [
            's' => true, // silent mode,
            'no-progress-meter' => true,
            'L' => true, // follow redirects
            'w' => '%{http_code}', // write out format
            'max-time' => $this->timeout,
            'o' => $outputFile, // output file
            'D' => $headerFile, // dump headers file
        ];

        if (strtoupper($method) === 'HEAD') {
            // -X HEAD makes curl wait for a body the server never sends and can
            // hang until max-time on keep-alive connections; --head is correct
            $options['head'] = true;
        } else {
            $options['X'] = $method; // HTTP method
        }

        // Handle SSL CA certificates based on platform
        $this->addSslCertOptions($options);

        $tempFiles = [];

        // Add headers - use temp file if headers are too large to avoid command line length limits
        if (! empty($headers)) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $this->assertHeaderIsSafe((string)$name, (string)$value);
                $headerLines[] = "$name: $value";
            }

            // Calculate total header size to determine if we need a temp file
            $totalHeaderSize = array_sum(array_map('strlen', $headerLines));
            $maxHeaderSize = 7000; // Conservative limit for command line (Windows limit is ~8191)

            if ($totalHeaderSize > $maxHeaderSize) {
                // Write headers to temp file and use @filename syntax
                $headersFile = $this->createTempFile('curl_impersonate_request_headers');
                $content = implode("\n", $headerLines) . "\n";
                if (file_put_contents($headersFile, $content) === false) {
                    $this->deleteTempFile($headersFile);

                    throw new RequestException('Failed to write request headers to temporary file');
                }
                $tempFiles[] = $headersFile;
                $options['H'][] = "@$headersFile";
            } else {
                // Use inline headers
                foreach ($headerLines as $headerLine) {
                    $options['H'][] = $headerLine;
                }
            }
        }

        return [$options, $tempFiles];
    }

    /**
     * Add SSL certificate options based on platform
     * curl-impersonate uses BoringSSL which doesn't auto-detect system CA certs on Linux
     */
    private function addSslCertOptions(array &$options): void
    {
        // Check if user has already specified cacert in curlOptions
        if (isset($this->curlOptions['cacert']) || isset($this->curlOptions['capath'])) {
            return;
        }

        if (PlatformDetector::isWindows()) {
            $options['ca-native'] = true;

            return;
        }

        if (PlatformDetector::isMacOS()) {
            // macOS typically has certs in a standard location that curl-impersonate can find
            // but we'll add the common path as fallback
            $macCertPath = '/etc/ssl/cert.pem';
            if (file_exists($macCertPath) && is_readable($macCertPath)) {
                $options['cacert'] = $macCertPath;
            }

            return;
        }

        // Linux: curl-impersonate with BoringSSL needs explicit CA cert path
        $caCertPath = $this->findLinuxCaCertBundle();
        if ($caCertPath !== null) {
            $options['cacert'] = $caCertPath;
        }
    }

    /**
     * Find the CA certificate bundle on Linux systems
     * Different distros store certs in different locations
     */
    private function findLinuxCaCertBundle(): ?string
    {
        // Common CA certificate bundle locations on Linux
        $possiblePaths = [
            '/etc/ssl/certs/ca-certificates.crt',      // Debian/Ubuntu/Gentoo
            '/etc/pki/tls/certs/ca-bundle.crt',        // RHEL/CentOS/Fedora
            '/etc/ssl/ca-bundle.pem',                   // openSUSE
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem', // CentOS/RHEL 7+
            '/etc/ssl/certs/ca-bundle.crt',            // Some distros
            '/var/lib/ca-certificates/ca-bundle.pem',  // Some distros
            '/etc/ssl/cert.pem',                        // Alpine/BSD-like
            '/usr/local/share/certs/ca-root-nss.crt',  // FreeBSD
            '/etc/pki/tls/cert.pem',                   // Fedora/RHEL alternative
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        // Try the SSL_CERT_FILE environment variable as last resort
        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile !== false && file_exists($envCertFile) && is_readable($envCertFile)) {
            return $envCertFile;
        }

        return null;
    }

    /**
     * Add request body to curl options
     */
    private function addBodyToOptions(array &$options, string $body): array
    {
        // Always use temporary files for large data to avoid command line length limits
        // This prevents escapeshellarg() from failing on Windows with large arguments
        $bodyFile = $this->createTempFile('curl_body_data');

        if (file_put_contents($bodyFile, $body) === false) {
            $this->deleteTempFile($bodyFile);

            throw new RequestException('Failed to write request body to temporary file');
        }

        // Always --data-binary: plain --data strips CRs and stops at NUL bytes, which
        // corrupts binary and multi-line payloads. The body was encoded exactly as
        // intended upstream, so send it verbatim regardless of content type.
        $options['data-binary'] = "@$bodyFile";

        return [$bodyFile];
    }

    /**
     * Merge browser configuration with curl options
     */
    private function mergeBrowserConfig(array $options, array $browserConfig): array
    {
        // Add ciphers if specified
        if (isset($browserConfig['ciphers'])) {
            $options['ciphers'] = $browserConfig['ciphers'];
        }

        // Add curves if specified
        if (isset($browserConfig['curves'])) {
            $options['curves'] = $browserConfig['curves'];
        }

        // Add signature hashes if specified
        if (isset($browserConfig['signature-hashes'])) {
            $options['signature-hashes'] = $browserConfig['signature-hashes'];
        }

        // Add browser-specific headers
        if (isset($browserConfig['headers'])) {
            foreach ($browserConfig['headers'] as $name => $value) {
                $options['H'][] = "$name: $value";
            }
        }

        // Add browser-specific options
        if (isset($browserConfig['options'])) {
            foreach ($browserConfig['options'] as $option => $value) {
                if (is_bool($value)) {
                    if ($value) {
                        $options[$option] = true;
                    }
                } else {
                    $options[$option] = $value;
                }
            }
        }

        return $options;
    }

    /**
     * Run the curl command with enhanced error handling
     *
     * @param list<string> $command argv array (executable first); proc_open array
     *                              mode runs it directly without any shell
     */
    private function runCommand(array $command): array
    {
        $processTimeout = $this->timeout + self::PROCESS_TIMEOUT_BUFFER;

        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"],   // stderr
        ];

        $displayCommand = implode(' ', $command);

        $process = proc_open($command, $descriptorspec, $pipes);

        if (! is_resource($process)) {
            throw new RequestException("Failed to execute command: $displayCommand");
        }

        try {
            return $this->handleProcess($process, $pipes, $processTimeout, $displayCommand);
        } finally {
            $this->closeProcess($process, $pipes);
        }
    }

    /**
     * Handle process execution with timeout
     */
    private function handleProcess($process, array $pipes, int $timeout, string $command): array
    {
        // Close stdin
        fclose($pipes[0]);

        // Set pipes to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        $output = '';
        $errors = '';

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process, 9); // SIGKILL

                throw new RequestException(
                    "Command execution timed out after $timeout seconds",
                    0,
                    null,
                    $command
                );
            }

            // Block until the process produces output (or 200ms passes so the
            // timeout check above still runs) instead of busy-polling
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);

            // Read available data
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout !== false) {
                $output .= $stdout;
            }
            if ($stderr !== false) {
                $errors .= $stderr;
            }
        }

        // Get remaining output
        $output .= stream_get_contents($pipes[1]) ?: '';
        $errors .= stream_get_contents($pipes[2]) ?: '';

        $exitCode = proc_close($process);

        return $this->processCommandOutput($output, $errors, $exitCode, $command);
    }

    /**
     * Close process and pipes safely
     */
    private function closeProcess($process, array $pipes): void
    {
        foreach (array_slice($pipes, 1) as $pipe) { // Skip stdin (already closed)
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * Process command output and determine success/failure
     */
    private function processCommandOutput(
        string $output,
        string $errors,
        int $exitCode,
        string $command
    ): array {
        $outputLines = array_filter(explode("\n", trim($output)));
        $errorLines = array_filter(explode("\n", trim($errors)));

        $lastLine = end($outputLines) ?: '';
        $statusCode = is_numeric($lastLine) ? $lastLine : '0';

        // Check if we have a valid HTTP status code
        $hasValidStatusCode = is_numeric($statusCode) &&
                             ((int)$statusCode >= 100 && (int)$statusCode < 600);

        // Consider request successful if we have a valid HTTP status code
        if ($exitCode !== 0 && ! $hasValidStatusCode) {
            $allOutput = array_merge($outputLines, $errorLines);
            $errorMessage = implode("\n", $allOutput);

            throw new RequestException(
                "Command execution failed with exit code $exitCode: $errorMessage",
                $exitCode,
                null,
                $command,
                $allOutput
            );
        }

        return [
            'status_code' => $statusCode,
            // stdout only: safe to mine for response content
            'stdout' => $outputLines,
            // stdout + stderr: for diagnostics, never for response bodies
            'output' => array_merge($outputLines, $errorLines),
        ];
    }

    /**
     * Parse response headers, preserving all values for duplicate header names.
     *
     * Per RFC 9110 §5.3, multiple fields with the same name are valid.
     * Set-Cookie in particular must never be comma-joined (RFC 6265 §4.1.1),
     * so we always store values as string[] lists.
     *
     * @return array<string, string[]>
     */
    private function parseHeaders(string $headersContent): array
    {
        return ResponseHeaderParser::parse($headersContent);
    }

    /**
     * Reject header names/values that could smuggle extra header lines or
     * confuse curl's -H parsing (header injection).
     */
    private function assertHeaderIsSafe(string $name, string $value): void
    {
        RequestPreparer::assertHeaderIsSafe($name, $value);
    }

    /**
     * Find a header value by name, case-insensitively (header names are
     * case-insensitive per RFC 9110 §5.1).
     */
    private function findHeaderValue(array $headers, string $name): ?string
    {
        return RequestPreparer::findHeaderValue($headers, $name);
    }

    /**
     * Normalize headers with improved validation
     */
    private function normalizeHeaders(array $headers): array
    {
        return RequestPreparer::normalizeHeaders($headers);
    }
}
