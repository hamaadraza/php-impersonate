<?php

namespace Raza\PHPImpersonate\Process;

use Raza\PHPImpersonate\Support\CaBundle;
use Raza\PHPImpersonate\Support\CurlOptions;
use Raza\PHPImpersonate\Browser\BrowserConfig;
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
     * @return array{status: int, headers: string, body: string, url: string} Raw header block + body + effective URL.
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

            return [
                'status' => $statusCode,
                'headers' => $rawHeaders,
                'body' => $responseBody,
                'url' => $result['url'] !== '' ? $result['url'] : $url,
            ];
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

        if (BrowserConfig::matchesBuiltin($this->browser->getName(), $browserConfig)) {
            // Let the binary apply its OWN profile, through the same
            // curl_easy_impersonate() call the FFI engine makes. It sets the
            // ciphers, curves, signature algorithms, extension order, HTTP/2
            // settings AND the default headers, and libcurl merges the caller's
            // headers into the profile's slots — so the two engines are
            // byte-identical by construction rather than by data entry.
            //
            // Rendering BrowserConfig into --ciphers/--curves/--tls-* flags
            // instead (the path below) is how five profiles drifted from the
            // library unnoticed: firefox133/135 sent a TLS record_size_limit of
            // 4001 (a hex value pasted as decimal), chrome116 sent an ECH
            // extension Chrome 116 never had, safari2601 sent a session ticket,
            // safari170 lacked its Sec-Fetch headers, and edge101 carried a
            // different build number. JA4 does not encode most of that, so the
            // parity tests stayed green.
            $options['impersonate'] = $this->browser->getName();
            $options['compressed'] = true;
            $headerLines = $this->collectHeaderLines($headers, []);
        } else {
            // A custom BrowserInterface profile: the library has no table entry
            // for it, so render it by hand. This is the only path where
            // BrowserConfig-shaped data reaches the wire.
            $options = $this->mergeBrowserConfig($options, $browserConfig);

            // Assemble and validate before creating any temp file, so a malformed
            // header still surfaces as InvalidArgumentException and leaves nothing
            // behind on disk.
            $headerLines = $this->collectHeaderLines($headers, $browserConfig['headers'] ?? []);
        }
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
        $content = implode("\n", $lines) . "\n";

        // A SHORT write is a failure too. On a full disk file_put_contents()
        // returns the bytes it managed, not false, and a truncated header or
        // config file would be handed to curl as if it were whole.
        if (file_put_contents($file, $content) !== strlen($content)) {
            $this->deleteTempFile($file);

            throw new RequestException("Failed to write $what to a temporary file (disk full?)");
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
            // MUST stay the first argument. curl reads $CURL_HOME/.curlrc,
            // $XDG_CONFIG_HOME/curlrc or ~/.curlrc unless -q/--disable LEADS
            // argv, and a curlrc can add headers, a proxy, --insecure or a
            // --data body to every request this engine sends — verified: a
            // `header = "X-Injected-By: curlrc"` line reached the server, and a
            // `proxy = …` line redirected every request. The FFI engine never
            // reads it, so the two engines silently disagreed. CommandBuilder
            // emits options in array order, which is what keeps this first.
            'q' => true,
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
            // Turn on curl's in-memory cookie engine for this one process, so a
            // cookie set on a redirect hop (login → 302 + Set-Cookie → GET) is
            // sent on the follow-up the way every browser does. An empty
            // string is curl's documented spelling for "engine on, no file";
            // nothing is written to disk and nothing outlives the process.
            'b' => '',
            // Write-out: the URL the transfer ended on, then the status code —
            // status LAST, because processCommandOutput() reads the final line
            // as the status and the line before it as the effective URL.
            'w' => "%{url_effective}\n%{http_code}",
            'max-time' => $this->timeout,
            // A cap on the body, because it is buffered whole (see
            // CurlOptions::DEFAULT_MAX_FILESIZE). A caller's own max-filesize
            // is merged in later and overrides this.
            'max-filesize' => CurlOptions::DEFAULT_MAX_FILESIZE,
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

        // Compared against the length, not just false: a full disk yields a
        // partial write, and a truncated body must not go out as the request.
        if (file_put_contents($bodyFile, $body) !== strlen($body)) {
            $this->deleteTempFile($bodyFile);

            throw new RequestException('Failed to write request body to temporary file (disk full?)');
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
     * @return array{status_code: string, url: string, stdout: array<int,string>, output: array<int,string>}
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

        // Since PHP 8.0 a name in disable_functions behaves as undefined, so an
        // unguarded call dies with a bare \Error that escapes every exception
        // this library documents. proc_open is among the first things a
        // hardened host disables, so say what is wrong and what to do.
        if (! function_exists('proc_open')) {
            throw new RequestException(
                'The executable engine needs proc_open(), which this host disables '
                . '(see the disable_functions ini setting). Enable it, or use the FFI '
                . "engine (engine: '" . \Raza\PHPImpersonate\PHPImpersonate::ENGINE_FFI . "'), which spawns no process."
            );
        }

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
     * @return array{status_code: string, url: string, stdout: array<int,string>, output: array<int,string>}
     */
    private function handleProcess($process, array $pipes, int $timeout, string $command): array
    {
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        $output = '';
        $errors = '';

        // proc_get_status() REAPS the child the moment it first reports the
        // process is no longer running, which leaves the proc_close() below
        // with nothing to wait for: on PHP 8.2 it then answers -1 for a
        // perfectly successful run (8.3 and 8.4 cache the code and return it).
        // So the status read AT THE TRANSITION is the authoritative exit code,
        // and proc_close()'s return is only a fallback. -1 means "not reported".
        $reapedExitCode = -1;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $reapedExitCode = $status['exitcode'];

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

        $closedExitCode = proc_close($process);

        // Prefer whichever actually reported a code; see $reapedExitCode above.
        $exitCode = $reapedExitCode >= 0 ? $reapedExitCode : $closedExitCode;

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
     * @return array{status_code: string, url: string, stdout: array<int,string>, output: array<int,string>}
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

        // The write-out is `url_effective` then `http_code`, so the effective
        // URL is the line before the status. Absent (a transfer that never
        // produced a write-out) it is simply empty and the caller falls back to
        // the request URL.
        $count = count($outputLines);
        $effectiveUrl = $count >= 2 ? trim((string) $outputLines[$count - 2]) : '';

        // $statusCode is always a numeric string here; only the range needs checking.
        $hasValidStatusCode = (int) $statusCode >= 100 && (int) $statusCode < 600;

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
        //
        // A NEGATIVE code is a different thing from a non-zero one: it means the
        // platform could not tell us how the process ended, not that anything
        // went wrong. Only the status code can decide there — and reporting a
        // request that plainly returned 200 as a failure is the one answer
        // certain to be wrong.
        $failed = $exitCode < 0
            ? ! $hasValidStatusCode
            : ($exitCode !== 0 && $exitCode !== self::CURL_EXIT_TOO_MANY_REDIRECTS);

        if ($failed) {
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
            'url' => $effectiveUrl,
            // stdout only: safe to mine for response content
            'stdout' => $outputLines,
            // stdout + stderr: for diagnostics, never for response bodies
            'output' => array_merge($outputLines, $errorLines),
        ];
    }
}
