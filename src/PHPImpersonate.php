<?php

namespace Raza\PHPImpersonate;

use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Exception\RequestException;

class PHPImpersonate implements ClientInterface
{
    private const DEFAULT_BROWSER = 'chrome99_android';
    private const DEFAULT_TIMEOUT = 30;

    private BrowserInterface $browser;

    /**
     * @param string|BrowserInterface $browser Browser to use (name or browser instance)
     * @param int $timeout Request timeout in seconds
     * @param array<string,mixed> $curlOptions Custom curl options
     * @throws RequestException If the browser is invalid or platform is not supported
     */
    public function __construct(
        string|BrowserInterface $browser = self::DEFAULT_BROWSER,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private array $curlOptions = []
    ) {
        // Check if running on Linux
        if (PHP_OS !== 'Linux' && strpos(PHP_OS, 'Linux') === false) {
            throw new RequestException(
                'PHP-Impersonate requires a Linux operating system. Current OS: ' . PHP_OS
            );
        }

        if (is_string($browser)) {
            try {
                $this->browser = new Browser($browser);
            } catch (\RuntimeException $e) {
                throw new RequestException($e->getMessage(), 0, $e);
            }
        } else {
            $this->browser = $browser;
        }
    }

    /**
     * @inheritDoc
     */
    public function send(Request $request): Response
    {
        $method = $request->getMethod();
        $url = $request->getUrl();
        $headers = $request->getHeaders();
        $body = $request->getBody();

        $tempFiles = $this->createTempFiles();

        try {
            $command = $this->buildCommand(
                $method,
                $url,
                $tempFiles['body'],
                $tempFiles['headers'],
                $headers,
                $body
            );

            $result = $this->runCommand($command);

            $responseBody = file_exists($tempFiles['body'])
                ? (file_get_contents($tempFiles['body']) ?: '')
                : '';

            $statusCode = (int)$result['status_code'];

            $responseHeaders = $this->parseHeaders(
                file_exists($tempFiles['headers'])
                    ? (file_get_contents($tempFiles['headers']) ?: '')
                    : ''
            );

            return new Response($responseBody, $statusCode, $responseHeaders);
        } finally {
            $this->cleanupTempFiles($tempFiles);
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

        // Check if JSON content type is specified
        $isJson = isset($headers['Content-Type']) &&
                  strpos($headers['Content-Type'], 'application/json') !== false;

        // Encode data appropriately based on Content-Type
        $body = null;
        if ($data !== null) {
            if ($isJson) {
                $body = json_encode($data);
            } else {
                $body = http_build_query($data);
                // Set default Content-Type for form data if not specified
                if (! isset($headers['Content-Type'])) {
                    $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                }
            }
        }

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

        // Check if JSON content type is specified
        $isJson = isset($headers['Content-Type']) &&
                  strpos($headers['Content-Type'], 'application/json') !== false;

        // Encode data appropriately
        $body = null;
        if ($data !== null) {
            $body = $isJson ? json_encode($data) : http_build_query($data);
        }

        // Add default content type if not specified
        if ($data !== null && ! isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data);
        }

        return $this->send(Request::patch($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendPut(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = $this->normalizeHeaders($headers);

        // Always use JSON for PUT requests - this is the standard
        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data);
        } else {
            $body = null;
        }

        return $this->send(Request::put($url, $headers, $body));
    }

    /**
     * Static version - Get the response from a URL using GET method
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function get(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendGet($url, $headers);
    }

    /**
     * Static version - Post data to a URL and return response
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the POST request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function post(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendPost($url, $data, $headers);
    }

    /**
     * Static version - Get headers and status code for a URL using HEAD request
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function head(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendHead($url, $headers);
    }

    /**
     * Static version - Delete a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function delete(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendDelete($url, $headers);
    }

    /**
     * Static version - Patch a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the PATCH request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function patch(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendPatch($url, $data, $headers);
    }

    /**
     * Static version - Put a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the PUT request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param string $browser Browser to impersonate
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @return Response
     * @throws RequestException
     */
    public static function put(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        string $browser = self::DEFAULT_BROWSER,
        array $curlOptions = []
    ): Response {
        $client = new self($browser, $timeout, $curlOptions);

        return $client->sendPut($url, $data, $headers);
    }

    /**
     * Create temporary files for the request/response
     *
     * @return array{body: string, headers: string}
     */
    private function createTempFiles(): array
    {
        // Create with more reliable permissions
        $bodyFile = tempnam(sys_get_temp_dir(), 'curl_impersonate_body');
        $headerFile = tempnam(sys_get_temp_dir(), 'curl_impersonate_headers');

        // Ensure files are writable
        if (! is_writable($bodyFile) || ! is_writable($headerFile)) {
            throw new RequestException("Unable to create writable temporary files");
        }

        // Set permissions to be extra sure
        chmod($bodyFile, 0644);
        chmod($headerFile, 0644);

        return [
            'body' => $bodyFile,
            'headers' => $headerFile,
        ];
    }

    /**
     * Clean up temporary files
     *
     * @param array{body: string, headers: string} $files Temporary files to clean up
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Build the curl command with proper handling of JSON data
     */
    private function buildCommand(
        string $method,
        string $url,
        string $outputFile,
        string $headerFile,
        array $headers = [],
        ?string $body = null
    ): string {
        $browserCmd = $this->browser->getExecutablePath();

        // Base command with method and URL
        $cmd = sprintf(
            '%s -s -L -w "%%{http_code}" --max-time %d -o "%s" -D "%s" -X %s',
            escapeshellcmd($browserCmd),
            $this->timeout,
            $outputFile,
            $headerFile,
            $method
        );

        // Add headers
        foreach ($headers as $name => $value) {
            $cmd .= sprintf(' -H %s', escapeshellarg("$name: $value"));
        }

        // Add request body if present
        if ($body !== null) {
            // Check if it's JSON data
            $isJson = isset($headers['Content-Type']) &&
                      strpos($headers['Content-Type'], 'application/json') !== false;

            if ($isJson) {
                // Create temporary file for the data
                $bodyFile = tempnam(sys_get_temp_dir(), 'curl_impersonate_body');
                file_put_contents($bodyFile, $body);
                $cmd .= sprintf(' --data-binary @%s', escapeshellarg($bodyFile));
                // Add cleanup for this file
                register_shutdown_function(function () use ($bodyFile) {
                    if (file_exists($bodyFile)) {
                        @unlink($bodyFile);
                    }
                });
            } else {
                // For form data, use direct data parameter
                $cmd .= sprintf(' --data %s', escapeshellarg($body));
            }
        }

        // Add custom curl options
        foreach ($this->curlOptions as $option => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $cmd .= " --$option";
                }
            } else {
                $cmd .= sprintf(' --%s %s', $option, escapeshellarg((string)$value));
            }
        }

        // Add URL
        $cmd .= ' ' . escapeshellarg($url);

        return $cmd;
    }

    /**
     * Run the curl command with timeout protection
     *
     * @param string $command The command to execute
     * @return array{status_code: string, output: array}
     * @throws RequestException If command execution fails
     */
    private function runCommand(string $command): array
    {
        $output = [];
        $returnVar = null;

        // Set a process timeout slightly longer than the curl timeout
        $processTimeout = $this->timeout + 5;

        // Use proc_open instead of exec for better control
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"],   // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (! is_resource($process)) {
            throw new RequestException("Failed to execute command: $command");
        }

        // Set pipes to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Close stdin
        fclose($pipes[0]);

        // Set start time
        $startTime = time();
        $outputContent = '';
        $errorContent = '';

        // Read output with timeout
        while (true) {
            $status = proc_get_status($process);

            // Process has exited
            if (! $status['running']) {
                break;
            }

            // Check for timeout
            if ((time() - $startTime) > $processTimeout) {
                proc_terminate($process, 9); // SIGKILL
                proc_close($process);

                throw new RequestException(
                    "Command execution timed out after $processTimeout seconds: $command",
                    0,
                    null,
                    $command,
                    ['Timeout occurred']
                );
            }

            // Read from stdout and stderr
            $stdout = fread($pipes[1], 8192);
            $stderr = fread($pipes[2], 8192);

            if ($stdout) {
                $outputContent .= $stdout;
            }

            if ($stderr) {
                $errorContent .= $stderr;
            }

            // Prevent CPU overuse
            usleep(10000); // 10ms
        }

        // Get any remaining output
        while ($stdout = fread($pipes[1], 8192)) {
            $outputContent .= $stdout;
        }

        while ($stderr = fread($pipes[2], 8192)) {
            $errorContent .= $stderr;
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Get exit code
        $exitCode = proc_close($process);

        // Process output
        $output = array_filter(explode("\n", $outputContent));
        $errorOutput = array_filter(explode("\n", $errorContent));

        // Merge stderr into output for consistent handling
        if (! empty($errorOutput)) {
            $output = array_merge($output, $errorOutput);
        }

        $lastLine = end($output);

        if ($exitCode !== 0 && $status['exitcode'] !== 0) {
            // For HEAD requests specifically, we'll be more lenient
            if (strpos($command, '-X HEAD') !== false &&
                is_numeric($lastLine) &&
                ((int)$lastLine >= 200 && (int)$lastLine < 400)) {
                // This is likely a successful HEAD request despite the non-zero exit code
                return [
                    'status_code' => $lastLine,
                    'output' => $output,
                ];
            }

            // Otherwise, throw an exception with the output for debugging
            $errorOutput = implode("\n", $output);

            throw new RequestException(
                "Command execution failed with code $exitCode: $errorOutput",
                $exitCode,
                null,
                $command,
                $output
            );
        }

        return [
            'status_code' => is_numeric($lastLine) ? $lastLine : '0',
            'output' => $output,
        ];
    }

    /**
     * Parse response headers
     *
     * @param string $headersContent Raw headers
     * @return array<string,string> Parsed headers
     */
    private function parseHeaders(string $headersContent): array
    {
        $headers = [];

        // Split into header sections separated by empty lines and get last response
        $sections = preg_split('/\r?\n\r?\n/', trim($headersContent));

        if (! $sections) {
            return [];
        }

        $lastSection = end($sections);

        foreach (explode("\n", $lastSection) as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, 'HTTP/') === 0) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }

    /**
     * Normalize headers from various formats to a consistent format
     *
     * @param array<mixed> $headers Headers in various formats
     * @return array<string,string> Normalized headers
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_numeric($key)) {
                // If it contains a colon, it's already formatted
                if (is_string($value) && strpos($value, ':') !== false) {
                    list($headerName, $headerValue) = array_map('trim', explode(':', $value, 2));
                    $normalized[$headerName] = $headerValue;
                }
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
