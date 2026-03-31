<?php

namespace Raza\PHPImpersonate;

use RuntimeException;
use InvalidArgumentException;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Platform\CommandBuilder;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Exception\PlatformNotSupportedException;

/**
 * @phpstan-type BrowserName 'chrome99'|'chrome99_android'|'chrome100'|'chrome101'|'chrome104'|'chrome107'|'chrome110'|'chrome116'|'chrome119'|'chrome120'|'chrome123'|'chrome124'|'chrome131'|'chrome131_android'|'chrome133a'|'chrome136'|'edge99'|'edge101'|'firefox133'|'firefox135'|'safari153'|'safari155'|'safari170'|'safari172_ios'|'safari180'|'safari180_ios'|'safari184'|'safari184_ios'|'safari260'|'safari260_ios'|'tor145'
 */
class PHPImpersonate implements ClientInterface
{
    private const DEFAULT_BROWSER = 'chrome99_android';
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_TIMEOUT = 3600; // 1 hour max
    private const MIN_TIMEOUT = 1;
    private const PROCESS_TIMEOUT_BUFFER = 5;

    private BrowserInterface $browser;
    private array $tempFiles = [];

    /**
     * @param BrowserName|BrowserInterface $browser Browser to use (name or browser instance).
     *                                               Available browsers: chrome99, chrome99_android, chrome100, chrome101,
     *                                               chrome104, chrome107, chrome110, chrome116, chrome119, chrome120,
     *                                               chrome123, chrome124, chrome131, chrome131_android, chrome133a,
     *                                               chrome136, edge99, edge101, firefox133, firefox135, safari153,
     *                                               safari155, safari170, safari172_ios, safari180, safari180_ios,
     *                                               safari184, safari184_ios, safari260, safari260_ios, tor145
     * @param int $timeout Request timeout in seconds
     * @param array<string,mixed> $curlOptions Custom curl options
     * @throws RequestException If the browser is invalid or platform is not supported
     * @throws InvalidArgumentException If timeout is invalid
     */
    public function __construct(
        string|BrowserInterface $browser = self::DEFAULT_BROWSER,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private array $curlOptions = []
    ) {
        $this->validateTimeout($timeout);
        $this->validatePlatform();
        $this->initializeBrowser($browser);
        $this->validateCurlOptions($curlOptions);
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

        $tempFiles = $this->createTempFiles();

        try {
            $commandResult = $this->buildCommand(
                $request->getMethod(),
                $request->getUrl(),
                $tempFiles['body'],
                $tempFiles['headers'],
                $request->getHeaders(),
                $request->getBody()
            );

            $command = $commandResult['command'];
            $additionalTempFiles = $commandResult['tempFiles'];

            $result = $this->runCommand($command);

            // On Windows with .bat files, the output might not be written to files properly
            // So we capture the response directly from the command output
            $responseBody = $this->readTempFile($tempFiles['body']);
            $extractedStatus = null;

            // If the temp file is empty, try to get response from command output
            if (empty($responseBody) && ! empty($result['output'])) {
                $extracted = $this->captureResponseFromOutput($result['output']);
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

        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data, JSON_THROW_ON_ERROR);
        } else {
            $body = null;
        }

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
     * Initialize browser instance
     */
    private function initializeBrowser(string|BrowserInterface $browser): void
    {
        if (is_string($browser)) {
            try {
                $this->browser = new Browser($browser);
            } catch (RuntimeException $e) {
                throw new RequestException("Invalid browser: " . $e->getMessage(), 0, $e);
            }
        } else {
            $this->browser = $browser;
        }
    }

    /**
     * Validate curl options
     */
    private function validateCurlOptions(array $curlOptions): void
    {
        $forbiddenOptions = ['o', 'output', 'D', 'dump-header', 'w', 'write-out'];

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
        if (empty(trim($request->getUrl()))) {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        if (! filter_var($request->getUrl(), FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL format');
        }
    }

    /**
     * Prepare request body based on content type and data
     */
    private function prepareRequestBody(
        ?array $data,
        array &$headers,
        string $defaultContentType = 'application/x-www-form-urlencoded'
    ): ?string {
        if ($data === null) {
            return null;
        }

        $contentType = $headers['Content-Type'] ?? null;
        $isJson = $contentType && str_contains($contentType, 'application/json');

        if ($isJson) {
            try {
                return json_encode($data, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Failed to encode data as JSON: ' . $e->getMessage());
            }
        }

        // Set default content type if not specified
        if (! isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $defaultContentType;
        }

        if ($defaultContentType === 'application/json') {
            try {
                return json_encode($data, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Failed to encode data as JSON: ' . $e->getMessage());
            }
        }

        return http_build_query($data);
    }

    /**
     * Create temporary files for the request/response
     */
    private function createTempFiles(): array
    {
        $bodyFile = $this->createTempFile('curl_impersonate_body');
        $headerFile = $this->createTempFile('curl_impersonate_headers');

        $files = [
            'body' => $bodyFile,
            'headers' => $headerFile,
        ];

        // Track temp files for cleanup
        $this->tempFiles = array_merge($this->tempFiles, array_values($files));

        return $files;
    }

    /**
     * Create a single temporary file
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

        // Set safe permissions
        if (! chmod($tempFile, 0644)) {
            @unlink($tempFile);

            throw new RequestException('Unable to set temporary file permissions');
        }

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
     * Extract HTTP status code from response headers
     */
    private function extractStatusFromHeaders(array $headers): string
    {
        // Look for the HTTP status line we captured
        if (isset($headers['HTTP_STATUS'])) {
            if (preg_match('/(\d{3})/', $headers['HTTP_STATUS'], $matches)) {
                return $matches[1];
            }
        }

        // Fallback: check if we have any header that might contain status info
        foreach ($headers as $name => $value) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $value, $matches)) {
                return $matches[1];
            }
        }

        // Default to 200 if we can't determine
        return '200';
    }

    /**
     * Clean up temporary files
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteTempFile($file);
            // Remove from tracking
            $this->tempFiles = array_diff($this->tempFiles, [$file]);
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
            $additionalTempFiles = $this->addBodyToOptions($options, $body, $headers);
        }

        // Add browser-specific configuration
        $options = $this->mergeBrowserConfig($options, $browserConfig);

        // Add custom curl options (validated ones only)
        $options = array_merge($options, $this->curlOptions);

        // Merge all temp files for cleanup
        $allTempFiles = array_merge($headersTempFiles, $additionalTempFiles);

        try {
            $command = CommandBuilder::buildCurlCommand($browserCmd, [$url], $options);

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
            'X' => $method, // HTTP method
        ];

        // Handle SSL CA certificates based on platform
        $this->addSslCertOptions($options);

        $tempFiles = [];

        // Add headers - use temp file if headers are too large to avoid command line length limits
        if (! empty($headers)) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
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
    private function addBodyToOptions(array &$options, string $body, array $headers): array
    {
        $contentType = $headers['Content-Type'] ?? '';
        $isJson = str_contains($contentType, 'application/json');

        // Always use temporary files for large data to avoid command line length limits
        // This prevents escapeshellarg() from failing on Windows with large arguments
        $bodyFile = $this->createTempFile('curl_body_data');

        if (file_put_contents($bodyFile, $body) === false) {
            throw new RequestException('Failed to write request body to temporary file');
        }

        if ($isJson) {
            // Use data-binary for JSON to preserve formatting
            $options['data-binary'] = "@$bodyFile";
        } else {
            // Use data for form data, but with file reference to avoid command line limits
            $options['data'] = "@$bodyFile";
        }

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
     */
    private function runCommand(string $command): array
    {
        $processTimeout = $this->timeout + self::PROCESS_TIMEOUT_BUFFER;

        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"],   // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (! is_resource($process)) {
            throw new RequestException("Failed to execute command: $command");
        }

        try {
            return $this->handleProcess($process, $pipes, $processTimeout, $command);
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

            // Read available data
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout !== false) {
                $output .= $stdout;
            }
            if ($stderr !== false) {
                $errors .= $stderr;
            }

            usleep(10000); // 10ms sleep to prevent CPU spinning
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
            'output' => array_merge($outputLines, $errorLines),
        ];
    }

    /**
     * Parse response headers with improved handling
     */
    private function parseHeaders(string $headersContent): array
    {
        if (empty(trim($headersContent))) {
            return [];
        }

        $headers = [];

        // Handle multiple HTTP responses (redirects)
        $sections = preg_split('/\r?\n\r?\n/', trim($headersContent));

        if (! $sections) {
            return [];
        }

        // Get the last response headers
        $lastSection = end($sections);
        $lines = explode("\n", $lastSection);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Capture HTTP status line
            if (str_starts_with($line, 'HTTP/')) {
                $headers['HTTP_STATUS'] = $line;

                continue;
            }

            // Parse header line
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));

                if (! empty($name)) {
                    $headers[$name] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * Normalize headers with improved validation
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Handle "Header: Value" format
                $colonPos = strpos($value, ':');
                if ($colonPos !== false) {
                    $headerName = trim(substr($value, 0, $colonPos));
                    $headerValue = trim(substr($value, $colonPos + 1));

                    if (! empty($headerName)) {
                        $normalized[$headerName] = $headerValue;
                    }
                }
            } elseif (is_string($key) && (is_string($value) || is_numeric($value))) {
                $normalized[$key] = (string)$value;
            }
        }

        return $normalized;
    }
}
