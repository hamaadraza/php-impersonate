<?php

namespace Raza\PHPImpersonate\Process;

use Raza\PHPImpersonate\Support\CaBundle;
use Raza\PHPImpersonate\Support\CurlOptions;
use Raza\PHPImpersonate\Platform\CommandBuilder;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Support\ResponseHeaderParser;

/**
 * Executable engine: runs the bundled curl-impersonate binary once per request
 * (via proc_open array mode — no shell), capturing the response through temp
 * files. This is the low-level engine behind PHPImpersonate's process path and
 * the counterpart to {@see \Raza\PHPImpersonate\Ffi\CurlImpersonate}; it deals
 * only in already validated/normalised inputs.
 */
final class CurlProcess
{
    private const PROCESS_TIMEOUT_BUFFER = 5;

    /**
     * Options whose value can carry a credential, and so must never become an
     * argv entry. `proxy` counts because a proxy URL can embed `user:password`.
     *
     * @var list<string>
     */
    private const CREDENTIAL_OPTIONS = ['proxy', 'proxy-user'];

    /** @var array<int, string> Temp files created while serving requests, for cleanup. */
    private array $tempFiles = [];

    /**
     * @param array<string,mixed> $curlOptions Validated custom curl options.
     */
    public function __construct(
        private BrowserInterface $browser,
        private int $timeout,
        private array $curlOptions
    ) {
        // Canonicalise so a loose value cannot become a stray argv entry: a
        // bool option rendered as `--insecure no` would hand curl `no` as a
        // second URL. See CurlOptions::normalize().
        $this->curlOptions = CurlOptions::normalize($curlOptions);
    }

    public function __destruct()
    {
        $this->cleanupAllTempFiles();
    }

    /**
     * Perform one request. Inputs are assumed already validated/normalised.
     *
     * @param array<string,string> $headers
     * @return array{status: int, headers: string, body: string} Raw header block + body.
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $tempFiles = $this->createTempFiles();

        try {
            $commandResult = $this->buildCommand(
                $method,
                $url,
                $tempFiles['body'],
                $tempFiles['headers'],
                $headers,
                $body
            );

            $command = $commandResult['command'];
            $additionalTempFiles = $commandResult['tempFiles'];

            $result = $this->runCommand($command);

            // HEAD responses have no body; with --head the output file holds the
            // header block instead, so don't present it as a body.
            $isHead = strtoupper($method) === 'HEAD';

            // On Windows with .bat files, the output might not be written to files
            // properly, so we may need to recover the response from command stdout.
            $responseBody = $isHead ? '' : $this->readTempFile($tempFiles['body']);
            $extractedStatus = null;

            // stdout only (never stderr — warnings there must not become a body).
            // Strict '' check: a body of exactly "0" is falsy but is a real body.
            if (! $isHead && $responseBody === '' && ! empty($result['stdout'])) {
                $extracted = $this->captureResponseFromOutput($result['stdout']);
                $responseBody = $extracted['body'];
                $extractedStatus = $extracted['status'];
            }

            $rawHeaders = $this->readTempFile($tempFiles['headers']);

            // If we still don't have a proper status code, try the headers.
            // captureResponseFromOutput() reports an unknown status as the string '0'.
            if ($extractedStatus === '0') {
                $extractedStatus = $this->extractStatusFromHeaders($rawHeaders);
            }

            $statusCode = $extractedStatus !== null ? (int) $extractedStatus : (int) $result['status_code'];

            return ['status' => $statusCode, 'headers' => $rawHeaders, 'body' => $responseBody];
        } finally {
            $this->cleanupTempFiles($tempFiles);
            if (isset($additionalTempFiles)) {
                foreach ($additionalTempFiles as $tempFile) {
                    $this->deleteTempFile($tempFile);
                }
            }
        }
    }

    /**
     * Create the body + headers temp files for one request.
     *
     * @return array{body: string, headers: string}
     */
    private function createTempFiles(): array
    {
        return [
            'body' => $this->createTempFile('curl_impersonate_body'),
            'headers' => $this->createTempFile('curl_impersonate_headers'),
        ];
    }

    /**
     * Create a single temp file, tracked so the destructor can clean up files
     * orphaned by exceptions between creation and use.
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

    private function readTempFile(string $filePath): string
    {
        if (! file_exists($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);

        return $content !== false ? $content : '';
    }

    /**
     * Recover response body + status from stdout for the case where .bat files on
     * Windows don't redirect output to files properly.
     *
     * @param array<int,string> $output
     * @return array{body: string, status: string}
     */
    private function captureResponseFromOutput(array $output): array
    {
        if (empty($output)) {
            return ['body' => '', 'status' => '0'];
        }

        $fullOutput = implode("\n", $output);

        // Remove any trailing temp file paths that curl may append on Windows.
        $fullOutput = preg_replace('/\S+\\\\.*\.tmp$/', '', $fullOutput);
        $fullOutput = trim($fullOutput);

        // The response is everything except a trailing numeric status-code line.
        $lines = explode("\n", $fullOutput);
        $statusCode = '0';

        if (count($lines) > 1) {
            $lastLine = trim(end($lines));
            if (is_numeric($lastLine) && strlen($lastLine) <= 3) {
                $statusCode = $lastLine;
                array_pop($lines);
                $fullOutput = implode("\n", $lines);
            }
        } elseif (count($lines) === 1) {
            $singleLine = trim($lines[0]);
            if (is_numeric($singleLine) && strlen($singleLine) <= 3) {
                $statusCode = $singleLine;
                $fullOutput = ''; // a lone status code means a HEAD-style empty body
            }
        }

        return ['body' => $fullOutput, 'status' => $statusCode];
    }

    /**
     * Extract an HTTP status code from a raw response header block.
     *
     * @return string|null Null when no status line is present — callers must not
     *                     assume success in that case.
     */
    private function extractStatusFromHeaders(string $rawHeaders): ?string
    {
        $statusLine = ResponseHeaderParser::statusLine($rawHeaders);

        if ($statusLine !== null && preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $statusLine, $matches)) {
            return $matches[1];
        }

        // Fall back to the last status line anywhere in the block, in case the
        // final section could not be isolated (e.g. a truncated dump).
        if (preg_match_all('#^HTTP/[\d.]+\s+(\d{3})#mi', $rawHeaders, $matches)) {
            return (string) end($matches[1]);
        }

        return null;
    }

    /**
     * @param array<array-key,string> $files
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteTempFile($file);
        }
    }

    private function cleanupAllTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            $this->deleteTempFile($file);
        }
        $this->tempFiles = [];
    }

    private function deleteTempFile(string $file): void
    {
        if (file_exists($file)) {
            @unlink($file);
        }

        $this->tempFiles = array_diff($this->tempFiles, [$file]);
    }

    /**
     * Build the curl command as an argv array.
     *
     * @param array<string,string> $headers
     * @return array{command: list<string>, tempFiles: array<int,string>}
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

        $options = $this->buildCurlOptions($method, $outputFile, $headerFile);
        $options = $this->mergeBrowserConfig($options, $browserConfig);

        // Assemble and validate before creating any temp file, so a malformed
        // header still surfaces as InvalidArgumentException and leaves nothing
        // behind on disk.
        $headerLines = $this->collectHeaderLines($headers, $browserConfig['headers'] ?? []);
        [$credentials, $plainOptions] = $this->splitCredentialOptions($this->curlOptions);

        // Custom curl options (already validated and normalised).
        $options = array_merge($options, $plainOptions);

        $tempFiles = [];

        try {
            if ($body !== null) {
                $tempFiles = array_merge($tempFiles, $this->addBodyToOptions($options, $body));
            }

            // Every header goes through a 0600 temp file rather than argv. While
            // the process runs its argv is readable by any local user through
            // /proc/<pid>/cmdline (and `ps`), and a caller header routinely
            // carries an Authorization token or a session Cookie. The profile's
            // headers share the file so one list preserves the wire order.
            if ($headerLines !== []) {
                $file = $this->writeLinesToTempFile('curl_impersonate_request_headers', $headerLines, 'request headers');
                $tempFiles[] = $file;
                $options['H'] = ["@$file"];
            }

            // Credential-bearing options go to a curl config file for the same
            // reason: --proxy-user on the command line is world-readable.
            if ($credentials !== []) {
                $file = $this->writeLinesToTempFile('curl_impersonate_config', self::configLines($credentials), 'curl configuration');
                $tempFiles[] = $file;
                $options['config'] = $file;
            }

            // argv array for proc_open array mode: executed directly, no shell involved.
            $command = CommandBuilder::buildCurlCommandArgs($browserCmd, [$url], $options);

            return ['command' => $command, 'tempFiles' => $tempFiles];
        } catch (\Exception $e) {
            foreach ($tempFiles as $tempFile) {
                $this->deleteTempFile($tempFile);
            }

            if ($e instanceof RequestException) {
                throw $e;
            }

            throw new RequestException('Failed to build curl command: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Assemble every header line for one request, the caller's first.
     *
     * A caller header REPLACES the profile's of the same name rather than adding
     * to it: curl emits every header it is handed, so keeping both would put two
     * User-Agent lines on the wire — a bot signal in its own right, and a
     * divergence from the FFI engine, where libcurl replaces a profile header by
     * name. Names are matched case-insensitively (RFC 9110 §5.1).
     *
     * @param array<string,string> $callerHeaders
     * @param array<array-key,mixed> $profileHeaders
     * @return list<string>
     */
    private function collectHeaderLines(array $callerHeaders, array $profileHeaders): array
    {
        $lines = [];
        $supplied = [];

        foreach ($callerHeaders as $name => $value) {
            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
            $supplied[strtolower((string) $name)] = true;
            $lines[] = "$name: $value";
        }

        foreach ($profileHeaders as $name => $value) {
            if (isset($supplied[strtolower((string) $name)])) {
                continue;
            }

            // Validated too: profile headers now share a file with the caller's,
            // where an embedded newline would smuggle in an extra header line.
            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
            $lines[] = "$name: $value";
        }

        return $lines;
    }

    /**
     * Separate the options that can carry a credential from the rest.
     *
     * @param array<string,mixed> $curlOptions
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [credential-bearing, rest]
     */
    private function splitCredentialOptions(array $curlOptions): array
    {
        $credentials = array_intersect_key($curlOptions, array_flip(self::CREDENTIAL_OPTIONS));

        return [$credentials, array_diff_key($curlOptions, $credentials)];
    }

    /**
     * Render options as curl config-file lines for `--config`. Values are
     * double-quoted, so backslashes and quotes are escaped the way curl's own
     * config parser expects.
     *
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private static function configLines(array $options): array
    {
        $lines = [];

        foreach ($options as $name => $value) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $lines[] = sprintf('%s = "%s"', $name, $escaped);
        }

        return $lines;
    }

    /**
     * Write newline-separated lines to a fresh 0600 temp file.
     *
     * @param list<string> $lines
     * @param string $what Human label for the error message.
     */
    private function writeLinesToTempFile(string $prefix, array $lines, string $what): string
    {
        $file = $this->createTempFile($prefix);

        if (file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            $this->deleteTempFile($file);

            throw new RequestException("Failed to write $what to a temporary file");
        }

        return $file;
    }

    /**
     * Build base curl options. Headers are added separately by
     * {@see buildCommand()}, which routes them through a temp file.
     *
     * @return array<string,mixed>
     */
    private function buildCurlOptions(
        string $method,
        string $outputFile,
        string $headerFile
    ): array {
        $method = strtoupper($method);

        $options = [
            's' => true, // silent mode
            'no-progress-meter' => true,
            'L' => true, // follow redirects
            'w' => '%{http_code}', // write out format
            'max-time' => $this->timeout,
            'o' => $outputFile, // output file
            'D' => $headerFile, // dump headers file
        ];

        if ($method === 'HEAD') {
            // -X HEAD makes curl wait for a body the server never sends and can
            // hang until max-time on keep-alive connections; --head is correct.
            $options['head'] = true;
        } else {
            $options['X'] = $method;
        }

        $this->addSslCertOptions($options);

        return $options;
    }

    /**
     * Add CA certificate options. curl-impersonate uses BoringSSL, which does not
     * auto-discover the system trust store, so an explicit bundle is needed
     * (except on Windows, which uses the native store).
     *
     * @param array<string,mixed> $options
     */
    private function addSslCertOptions(array &$options): void
    {
        if (isset($this->curlOptions['cacert']) || isset($this->curlOptions['capath'])) {
            return;
        }

        $ca = CaBundle::path();
        $caDir = CaBundle::directory();

        if ($ca !== null) {
            $options['cacert'] = $ca;
        }
        if ($caDir !== null) {
            $options['capath'] = $caDir;
        }

        // Only when nothing was resolved: Windows has no bundle file to find,
        // so it falls back to the native trust store. An explicit
        // CURL_CA_BUNDLE/SSL_CERT_FILE still wins there, which is the point of
        // setting it.
        if ($ca === null && $caDir === null && PlatformDetector::isWindows()) {
            $options['ca-native'] = true;
        }
    }

    /**
     * Write the request body to a temp file and reference it with @file.
     *
     * @param array<string,mixed> $options
     * @return array<int,string> the created temp file(s)
     */
    private function addBodyToOptions(array &$options, string $body): array
    {
        // Temp file avoids command-line length limits and escapeshellarg issues.
        $bodyFile = $this->createTempFile('curl_body_data');

        if (file_put_contents($bodyFile, $body) === false) {
            $this->deleteTempFile($bodyFile);

            throw new RequestException('Failed to write request body to temporary file');
        }

        // Always --data-binary: plain --data strips CRs and stops at NUL bytes,
        // corrupting binary/multi-line payloads. Send the body verbatim.
        $options['data-binary'] = "@$bodyFile";

        return [$bodyFile];
    }

    /**
     * Merge a browser's fingerprint config into the curl options. The profile's
     * headers are handled by {@see collectHeaderLines()} instead, so that every
     * header reaches curl through one file in the right order.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $browserConfig
     * @return array<string,mixed>
     */
    private function mergeBrowserConfig(array $options, array $browserConfig): array
    {
        if (isset($browserConfig['ciphers'])) {
            $options['ciphers'] = $browserConfig['ciphers'];
        }
        if (isset($browserConfig['curves'])) {
            $options['curves'] = $browserConfig['curves'];
        }
        if (isset($browserConfig['signature-hashes'])) {
            $options['signature-hashes'] = $browserConfig['signature-hashes'];
        }

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
     * Run the curl command with timeout handling.
     *
     * @param list<string> $command argv array (executable first); proc_open array
     *                              mode runs it directly without any shell.
     * @return array{status_code: string, stdout: array<int,string>, output: array<int,string>}
     */
    private function runCommand(array $command): array
    {
        $processTimeout = $this->timeout + self::PROCESS_TIMEOUT_BUFFER;

        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $displayCommand = self::redactCommand($command);

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
     * @param resource $process
     * @param array<int,resource> $pipes
     * @return array{status_code: string, stdout: array<int,string>, output: array<int,string>}
     */
    private function handleProcess($process, array $pipes, int $timeout, string $command): array
    {
        fclose($pipes[0]);

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

            // Block until output is available (or 200ms passes so the timeout
            // check above still runs) instead of busy-polling.
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout !== false) {
                $output .= $stdout;
            }
            if ($stderr !== false) {
                $errors .= $stderr;
            }
        }

        // Strict false check: `?:` would discard a final chunk of exactly "0".
        $tailOut = stream_get_contents($pipes[1]);
        $tailErr = stream_get_contents($pipes[2]);
        if ($tailOut !== false) {
            $output .= $tailOut;
        }
        if ($tailErr !== false) {
            $errors .= $tailErr;
        }

        $exitCode = proc_close($process);

        return $this->processCommandOutput($output, $errors, $exitCode, $command);
    }

    /**
     * A printable form of the argv, for error messages.
     *
     * Credentials and headers no longer reach argv at all (they go through
     * 0600 temp files), but a URL can still carry userinfo or a token in its
     * query string, and this string is stored on the exception — where it tends
     * to end up in logs. Mask what looks like a credential.
     *
     * @param list<string> $command
     */
    private static function redactCommand(array $command): string
    {
        $redacted = [];
        $maskNext = false;

        foreach ($command as $arg) {
            if ($maskNext) {
                $redacted[] = '***';
                $maskNext = false;

                continue;
            }

            if (in_array($arg, ['--proxy', '-x', '--proxy-user', '-U'], true)) {
                $maskNext = true;
                $redacted[] = $arg;

                continue;
            }

            // scheme://user:secret@host/… -> scheme://***@host/…
            $redacted[] = preg_replace('#^([a-zA-Z][\w+.-]*://)[^/@\s]+@#', '$1***@', $arg) ?? $arg;
        }

        return implode(' ', $redacted);
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     */
    private function closeProcess($process, array $pipes): void
    {
        foreach (array_slice($pipes, 1) as $pipe) { // stdin already closed
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * @return array{status_code: string, stdout: array<int,string>, output: array<int,string>}
     */
    private function processCommandOutput(
        string $output,
        string $errors,
        int $exitCode,
        string $command
    ): array {
        // Keep every stdout line verbatim. array_filter() would drop a line that
        // is exactly "0" or blank, and on the Windows recovery path these lines
        // ARE the response body — the same body-of-"0" case guarded in request().
        $trimmedOutput = trim($output);
        $trimmedErrors = trim($errors);
        $outputLines = $trimmedOutput === '' ? [] : explode("\n", $trimmedOutput);
        // Diagnostics only, so blank lines are just noise here.
        $errorLines = $trimmedErrors === '' ? [] : array_values(array_filter(
            explode("\n", $trimmedErrors),
            static fn (string $line): bool => trim($line) !== ''
        ));

        $lastLine = $outputLines === [] ? '' : trim((string) end($outputLines));
        $statusCode = is_numeric($lastLine) ? $lastLine : '0';

        // $statusCode is always a numeric string here; only the range needs checking.
        $hasValidStatusCode = (int) $statusCode >= 100 && (int) $statusCode < 600;

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
}
