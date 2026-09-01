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
     * How long the output loop waits per iteration, in microseconds — long
     * enough not to spin, short enough that the timeout check stays responsive.
     */
    private const POLL_INTERVAL_US = 200000;

    /**
     * curl's exit code for CURLE_TOO_MANY_REDIRECTS, the one failure that still
     * yields a usable response: the final reply was received in full, only the
     * chain was cut short. The FFI engine returns it rather than throwing
     * ({@see \Raza\PHPImpersonate\Ffi\CurlImpersonate::request()}), so this
     * engine must too.
     */
    private const CURL_EXIT_TOO_MANY_REDIRECTS = 47;

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

            $responseBody = $isHead ? '' : $this->readTempFile($tempFiles['body']);
            $rawHeaders = $this->readTempFile($tempFiles['headers']);

            // The write-out is authoritative: `-w '%{http_code}'` is the status
            // curl itself reports, and -o/-D always capture, so there is nothing
            // to reconstruct. (A stdout-scraping fallback used to sit here for
            // Windows .bat wrappers, which proc_open's array mode cannot launch
            // at all — it only ever ran on ordinary empty-body responses, where
            // it risked reading a body's trailing numeric line as the status.)
            // Falling back to the header block covers the one real gap: a
            // write-out that never arrived.
            $statusCode = (int) $result['status_code'];

            if ($statusCode < 100 || $statusCode >= 600) {
                $fromHeaders = $this->extractStatusFromHeaders($rawHeaders);
                $statusCode = $fromHeaders !== null ? (int) $fromHeaders : $statusCode;
            }

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
            // A HEAD carries no body. curl rejects `--head` alongside
            // `--data-binary` while still parsing arguments (exit 2, before any
            // network I/O), whereas the FFI engine returns ahead of its own body
            // branch and simply drops it — so the same call threw on one engine
            // and succeeded on the other. Drop it here too, and let them agree.
            if ($body !== null && strtoupper($method) !== 'HEAD') {
                $tempFiles = array_merge($tempFiles, $this->addBodyToOptions($options, $body));
            } elseif ($body === null && strtoupper($method) === 'POST') {
                // A bodyless POST still has to reach curl as a POST, and with -X
                // withheld (see buildCurlOptions()) an empty --data-binary is what
                // says so without pinning the verb across redirects.
                $options['data-binary'] = '';
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
            //
            // The URL rides along in that file, and for the same reason: it can
            // carry `user:password@` userinfo or a token in its query string,
            // and as a positional argument it sat in /proc/<pid>/cmdline for
            // any local user to read for the life of the request — the very
            // exposure the headers and proxy options are routed away from.
            $configLines = self::configLines($credentials);
            $configLines[] = sprintf('url = "%s"', self::escapeConfigValue($url, 'URL'));

            $file = $this->writeLinesToTempFile('curl_impersonate_config', $configLines, 'curl configuration');
            $tempFiles[] = $file;
            $options['config'] = $file;

            // argv array for proc_open array mode: executed directly, no shell
            // involved. No positional argument: the URL comes from the config
            // file above.
            $command = CommandBuilder::buildCurlCommandArgs($browserCmd, [], $options);

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
     * Assemble every header line for one request, in the profile's own order.
     *
     * This reproduces libcurl's `Curl_http_merge_headers`, which is what the FFI
     * engine goes through, so both engines put the same bytes on the wire: each
     * profile header keeps its POSITION, a caller header of the same name
     * (matched case-insensitively, RFC 9110 §5.1) is substituted into that
     * position rather than added next to it, and caller headers with no profile
     * counterpart follow at the end.
     *
     * Position matters as much as content. Header order is itself a fingerprint,
     * and emitting the caller's headers first — as this did — put an
     * Authorization line ahead of `sec-ch-ua`, an ordering no browser produces
     * and one the FFI engine never emitted.
     *
     * @param array<string,string> $callerHeaders
     * @param array<array-key,mixed> $profileHeaders
     * @return list<string>
     */
    private function collectHeaderLines(array $callerHeaders, array $profileHeaders): array
    {
        // Validated up front: every line below ends up in one file, where an
        // embedded newline would smuggle in an extra header.
        $pending = [];
        foreach ($callerHeaders as $name => $value) {
            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
            $pending[] = [
                'name' => strtolower((string) $name),
                'line' => RequestPreparer::headerLine((string) $name, (string) $value),
            ];
        }

        $lines = [];

        foreach ($profileHeaders as $name => $value) {
            $match = self::findHeader($pending, strtolower((string) $name));

            if ($match !== null) {
                // Consume it, so that a second caller header of the same name
                // still reaches the wire from the tail — as libcurl sends it.
                $lines[] = $pending[$match]['line'];
                unset($pending[$match]);

                continue;
            }

            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
            $lines[] = RequestPreparer::headerLine((string) $name, (string) $value);
        }

        foreach ($pending as $header) {
            $lines[] = $header['line'];
        }

        return $lines;
    }

    /**
     * Index of the first unconsumed caller header with this lower-cased name.
     *
     * @param array<int, array{name: string, line: string}> $pending
     */
    private static function findHeader(array $pending, string $name): ?int
    {
        foreach ($pending as $index => $header) {
            if ($header['name'] === $name) {
                return $index;
            }
        }

        return null;
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
     * @throws RequestException If a value contains a config-file line break.
     */
    private static function configLines(array $options): array
    {
        $lines = [];

        foreach ($options as $name => $value) {
            $lines[] = sprintf(
                '%s = "%s"',
                $name,
                self::escapeConfigValue((string) $value, sprintf('value for curl option "%s"', $name))
            );
        }

        return $lines;
    }

    /**
     * Escape one value for curl's double-quoted config-file syntax.
     *
     * @param string $what Human label for the error message.
     * @throws RequestException If the value contains a config-file line break.
     */
    private static function escapeConfigValue(string $value, string $what): string
    {
        // Escaping quotes is not enough on its own: the config format is
        // line-oriented, so a raw newline ends this option and curl parses the
        // remainder of the value as another one. CurlOptions::assertAllowed()
        // and RequestPreparer::validateRequest() reject these already;
        // re-checked at the sink because this class is constructible directly
        // and only normalize() runs on that path.
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new RequestException(sprintf(
                'Invalid %s: may not contain CR, LF, or NUL.',
                $what
            ));
        }

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
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
            // -s alone also silences curl's ERROR messages, which left every
            // transport failure reported as "exit code 6: 000" — the write-out
            // digits standing where the cause should be, while the FFI engine
            // named it via curl_easy_strerror(). -S restores the diagnostic
            // without restoring the progress meter (already off, below).
            'S' => true,
            'no-progress-meter' => true,
            'L' => true, // follow redirects
            // Redirects may only go where the initial URL was allowed to.
            // RequestPreparer::validateRequest() allow-lists http/https, but
            // curl's own redirect default also permits ftp/ftps — so a server
            // could answer `Location: ftp://internal-host/` and reach a scheme
            // this client explicitly refuses to speak. Mirrors the FFI engine's
            // CURLOPT_REDIR_PROTOCOLS.
            'proto-redir' => '=http,https',
            'w' => '%{http_code}', // write out format
            'max-time' => $this->timeout,
            'o' => $outputFile, // output file
            'D' => $headerFile, // dump headers file
        ];

        if ($method === 'HEAD') {
            // -X HEAD makes curl wait for a body the server never sends and can
            // hang until max-time on keep-alive connections; --head is correct.
            $options['head'] = true;
        } elseif ($method !== 'POST') {
            // POST is deliberately absent: --data-binary already makes the request
            // a POST, and -X would additionally pin the verb across redirects. On
            // a 301/302/303 curl does what browsers do — switch to GET and drop
            // the body — while the pinned verb keeps saying POST, so the redirect
            // was followed with a POST carrying no body. See addBodyToOptions().
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
            //
            // stream_select() FAILS on proc_open() pipes under Windows — they
            // are anonymous pipes, not sockets — returning false immediately.
            // With the reads already non-blocking, that turned this loop into a
            // zero-delay spin that pinned a core for the whole request. Sleep
            // for the same interval by hand whenever select cannot do it.
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, self::POLL_INTERVAL_US) === false) {
                usleep(self::POLL_INTERVAL_US);
            }

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

        // Any failed transfer is an error, with the single carve-out the FFI
        // engine makes (see CurlImpersonate::request()), so both engines answer
        // one identical network event the same way.
        //
        // Excusing instead every failure that merely HAD a status code was worse
        // than it looks: `-w '%{http_code}'` prints the status curl already
        // received, so a transfer that broke AFTER the response line still
        // reported one. A partial file (18), a timeout mid-body (28), a receive
        // error (56) or an HTTP/2 stream error (92) therefore came back as an
        // ordinary 200 carrying a silently truncated body — which a caller
        // persists as real data, while the FFI engine threw for the same bytes.
        if ($exitCode !== 0 && $exitCode !== self::CURL_EXIT_TOO_MANY_REDIRECTS) {
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
