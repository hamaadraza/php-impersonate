<?php

namespace Raza\PHPImpersonate\Process;

use Raza\PHPImpersonate\Support\CaBundle;
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
                $extractedStatus = $this->extractStatusFromHeaders(ResponseHeaderParser::parse($rawHeaders));
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
     * Extract an HTTP status code from parsed response headers.
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

        [$options, $headersTempFiles] = $this->buildCurlOptions($method, $outputFile, $headerFile, $headers);
        $additionalTempFiles = [];

        if ($body !== null) {
            $additionalTempFiles = $this->addBodyToOptions($options, $body);
        }

        $options = $this->mergeBrowserConfig($options, $browserConfig);

        // Add custom curl options (already validated).
        $options = array_merge($options, $this->curlOptions);

        $allTempFiles = array_merge($headersTempFiles, $additionalTempFiles);

        try {
            // argv array for proc_open array mode: executed directly, no shell involved.
            $command = CommandBuilder::buildCurlCommandArgs($browserCmd, [$url], $options);

            return ['command' => $command, 'tempFiles' => $allTempFiles];
        } catch (\Exception $e) {
            foreach ($allTempFiles as $tempFile) {
                $this->deleteTempFile($tempFile);
            }

            throw new RequestException('Failed to build curl command: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build base curl options.
     *
     * @param array<string,string> $headers
     * @return array{0: array<string,mixed>, 1: array<int,string>} [options, tempFiles]
     */
    private function buildCurlOptions(
        string $method,
        string $outputFile,
        string $headerFile,
        array $headers
    ): array {
        $options = [
            's' => true, // silent mode
            'no-progress-meter' => true,
            'L' => true, // follow redirects
            'w' => '%{http_code}', // write out format
            'max-time' => $this->timeout,
            'o' => $outputFile, // output file
            'D' => $headerFile, // dump headers file
        ];

        if (strtoupper($method) === 'HEAD') {
            // -X HEAD makes curl wait for a body the server never sends and can
            // hang until max-time on keep-alive connections; --head is correct.
            $options['head'] = true;
        } else {
            $options['X'] = $method;
        }

        $this->addSslCertOptions($options);

        $tempFiles = [];

        // Use a temp file when headers are too large for the command line.
        if (! empty($headers)) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
                $headerLines[] = "$name: $value";
            }

            $totalHeaderSize = array_sum(array_map('strlen', $headerLines));
            $maxHeaderSize = 7000; // conservative (Windows command line limit is ~8191)

            if ($totalHeaderSize > $maxHeaderSize) {
                $headersFile = $this->createTempFile('curl_impersonate_request_headers');
                $content = implode("\n", $headerLines) . "\n";
                if (file_put_contents($headersFile, $content) === false) {
                    $this->deleteTempFile($headersFile);

                    throw new RequestException('Failed to write request headers to temporary file');
                }
                $tempFiles[] = $headersFile;
                $options['H'][] = "@$headersFile";
            } else {
                foreach ($headerLines as $headerLine) {
                    $options['H'][] = $headerLine;
                }
            }
        }

        return [$options, $tempFiles];
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

        if (PlatformDetector::isWindows()) {
            $options['ca-native'] = true;

            return;
        }

        $ca = CaBundle::path();
        if ($ca !== null) {
            $options['cacert'] = $ca;
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
     * Merge a browser's fingerprint config into the curl options.
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

        if (isset($browserConfig['headers'])) {
            foreach ($browserConfig['headers'] as $name => $value) {
                $options['H'][] = "$name: $value";
            }
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

        $output .= stream_get_contents($pipes[1]) ?: '';
        $errors .= stream_get_contents($pipes[2]) ?: '';

        $exitCode = proc_close($process);

        return $this->processCommandOutput($output, $errors, $exitCode, $command);
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
        $outputLines = array_filter(explode("\n", trim($output)));
        $errorLines = array_filter(explode("\n", trim($errors)));

        $lastLine = end($outputLines) ?: '';
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
