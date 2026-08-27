<?php

namespace Raza\PHPImpersonate\Ffi;

use FFI;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Thin FFI binding over libcurl-impersonate. Performs one HTTP request per call
 * on a reused easy handle (so keep-alive connections are cached between calls),
 * capturing the body and headers in memory via open_memstream where available.
 *
 * This is the low-level engine behind FfiClient; it deals only in already
 * validated/normalised inputs.
 */
final class CurlImpersonate
{
    // libcurl option/info constants (stable ABI values from curl.h).
    private const CURLOPT_URL = 10002;
    private const CURLOPT_WRITEDATA = 10001;
    private const CURLOPT_HEADERDATA = 10029;
    private const CURLOPT_FOLLOWLOCATION = 52;
    private const CURLOPT_TIMEOUT_MS = 155;
    private const CURLOPT_CONNECTTIMEOUT_MS = 156;
    private const CURLOPT_CUSTOMREQUEST = 10036;
    private const CURLOPT_NOBODY = 44;
    private const CURLOPT_POSTFIELDSIZE_LARGE = 120;
    private const CURLOPT_COPYPOSTFIELDS = 10165;
    private const CURLOPT_HTTPHEADER = 10023;
    private const CURLOPT_CAINFO = 10065;
    private const CURLOPT_SSL_OPTIONS = 216;
    private const CURLOPT_ACCEPT_ENCODING = 10102;
    private const CURLOPT_PROXY = 10004;
    private const CURLOPT_PROXYUSERPWD = 10006;
    private const CURLOPT_NOPROXY = 10177;
    private const CURLINFO_RESPONSE_CODE = 2097154;

    private const CURLSSLOPT_NATIVE_CA = 16; // 1 << 4
    private const CURLE_OK = 0;

    /**
     * Curl options this transport understands, mapped to their CURLOPT id.
     * Keys mirror the executable transport's option names so the same
     * $curlOptions array works with either. Values are string options.
     */
    private const SUPPORTED_OPTIONS = [
        'proxy' => self::CURLOPT_PROXY,
        'proxy-user' => self::CURLOPT_PROXYUSERPWD,
        'noproxy' => self::CURLOPT_NOPROXY,
    ];

    private const HEADER = <<<'CDEF'
        void *curl_easy_init(void);
        int curl_easy_setopt(void *handle, int option, ...);
        int curl_easy_perform(void *handle);
        int curl_easy_getinfo(void *handle, int info, ...);
        void curl_easy_reset(void *handle);
        void curl_easy_cleanup(void *handle);
        const char *curl_easy_strerror(int code);
        int curl_easy_impersonate(void *handle, const char *target, int default_headers);
        void *curl_slist_append(void *list, const char *string);
        void curl_slist_free_all(void *list);
        void *open_memstream(char **bufp, unsigned long *sizep);
        void *fopen(const char *path, const char *mode);
        int fflush(void *stream);
        int fclose(void *stream);
        void free(void *ptr);
        CDEF;

    private FFI $ffi;

    /** @var \FFI\CData|null Reused easy handle for connection keep-alive. */
    private $handle;

    public function __construct(string $libPath)
    {
        $this->ffi = FFI::cdef(self::HEADER, $libPath);
        $this->handle = $this->ffi->curl_easy_init();
        if ($this->handle === null) {
            throw new RequestException('libcurl-impersonate: curl_easy_init() failed');
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            $this->ffi->curl_easy_cleanup($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Whether FFI is usable in this SAPI at all (independent of library presence).
     */
    public static function isSupported(): bool
    {
        if (! extension_loaded('FFI')) {
            return false;
        }
        // FFI is always enabled on the CLI; other SAPIs need ffi.enable truthy
        // (a "preload" setting only permits FFI from preloaded files).
        if (PHP_SAPI === 'cli') {
            return true;
        }
        $enable = strtolower((string) ini_get('ffi.enable'));

        return in_array($enable, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Curl option names supported by this transport (see SUPPORTED_OPTIONS).
     *
     * @return list<string>
     */
    public static function supportedOptionKeys(): array
    {
        return array_keys(self::SUPPORTED_OPTIONS);
    }

    /**
     * Perform a request. Inputs are assumed already validated/normalised.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $curlOptions Only keys in SUPPORTED_OPTIONS are applied.
     * @return array{status: int, headers: string, body: string}
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        string $browser,
        int $timeout,
        array $curlOptions = []
    ): array {
        $ffi = $this->ffi;
        $h = $this->handle;

        // Reset per request but keep the connection cache on this handle.
        $ffi->curl_easy_reset($h);

        [$bodyFp, $readBody] = $this->openSink();
        [$hdrFp, $readHeaders] = $this->openSink();

        $slist = null;

        try {
            $ffi->curl_easy_setopt($h, self::CURLOPT_URL, $url);
            $ffi->curl_easy_setopt($h, self::CURLOPT_FOLLOWLOCATION, 1);
            $ffi->curl_easy_setopt($h, self::CURLOPT_TIMEOUT_MS, $timeout * 1000);
            $ffi->curl_easy_setopt($h, self::CURLOPT_CONNECTTIMEOUT_MS, $timeout * 1000);
            $ffi->curl_easy_setopt($h, self::CURLOPT_WRITEDATA, $bodyFp);
            $ffi->curl_easy_setopt($h, self::CURLOPT_HEADERDATA, $hdrFp);

            // Apply the full browser fingerprint (TLS, HTTP/2, base headers).
            $ffi->curl_easy_impersonate($h, $browser, 1);

            // Enable transparent decompression (mirrors the executable's --compressed).
            $ffi->curl_easy_setopt($h, self::CURLOPT_ACCEPT_ENCODING, '');

            $this->applyCaBundle($h);
            $this->applyMethod($h, $method, $body);
            $this->applyCurlOptions($h, $curlOptions);

            $slist = $this->buildHeaderList($headers);
            if ($slist !== null) {
                $ffi->curl_easy_setopt($h, self::CURLOPT_HTTPHEADER, $slist);
            }

            $rc = $ffi->curl_easy_perform($h);

            $ffi->fflush($bodyFp);
            $ffi->fflush($hdrFp);

            if ($rc !== self::CURLE_OK) {
                // Depending on the PHP/FFI build, a `const char *` return may
                // already arrive as a PHP string; otherwise it is CData.
                $err = $ffi->curl_easy_strerror($rc);
                $msg = is_string($err) ? $err : FFI::string($err);

                throw new RequestException("libcurl-impersonate request failed ($rc): $msg", $rc);
            }

            $status = $ffi->new('long');
            $ffi->curl_easy_getinfo($h, self::CURLINFO_RESPONSE_CODE, FFI::addr($status));

            return [
                'status' => (int) $status->cdata,
                'headers' => $readHeaders(),
                'body' => $readBody(),
            ];
        } finally {
            if ($slist !== null) {
                $ffi->curl_slist_free_all($slist);
            }
            $readBody(true);
            $readHeaders(true);
        }
    }

    /**
     * Open an in-memory (or temp-file) capture sink.
     *
     * @return array{0: \FFI\CData, 1: callable(bool=): string} [FILE*, reader]
     *   The reader returns captured bytes; pass true to close/cleanup and return ''.
     */
    private function openSink(): array
    {
        $ffi = $this->ffi;

        if (! PlatformDetector::isWindows()) {
            $buf = $ffi->new('char*');
            $size = $ffi->new('unsigned long');
            $fp = $ffi->open_memstream(FFI::addr($buf), FFI::addr($size));

            $closed = false;
            $reader = function (bool $close = false) use ($ffi, $fp, &$buf, $size, &$closed): string {
                if ($closed) {
                    return '';
                }
                if ($close) {
                    $ffi->fclose($fp);
                    if (! FFI::isNull($buf)) {
                        $ffi->free($buf);
                    }
                    $closed = true;

                    return '';
                }
                $ffi->fflush($fp);

                return FFI::isNull($buf) ? '' : FFI::string($buf, $size->cdata);
            };

            return [$fp, $reader];
        }

        // Windows fallback: default fwrite() into a temp file, read it back.
        $path = tempnam(sys_get_temp_dir(), 'ffi_curl');
        $fp = $ffi->fopen($path, 'wb');
        $closedFp = false;
        $reader = function (bool $close = false) use ($ffi, $fp, $path, &$closedFp): string {
            if (! $closedFp) {
                $ffi->fflush($fp);
            }
            if ($close) {
                if (! $closedFp) {
                    $ffi->fclose($fp);
                    $closedFp = true;
                }
                @unlink($path);

                return '';
            }
            if (! $closedFp) {
                $ffi->fclose($fp);
                $closedFp = true;
            }

            return is_file($path) ? (string) file_get_contents($path) : '';
        };

        return [$fp, $reader];
    }

    /**
     * @param \FFI\CData $h
     */
    private function applyMethod($h, string $method, ?string $body): void
    {
        $method = strtoupper($method);

        if ($method === 'HEAD') {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_NOBODY, 1);

            return;
        }

        if ($method !== 'GET') {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null) {
            // Set size first so binary/JSON bodies are sent verbatim; COPYPOSTFIELDS
            // copies the buffer so it need not outlive this call.
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_POSTFIELDSIZE_LARGE, strlen($body));
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_COPYPOSTFIELDS, $body);
        }
    }

    /**
     * Apply the supported subset of $curlOptions to the handle. Unknown keys are
     * ignored here (FfiClient rejects them up front with a clear message).
     *
     * @param \FFI\CData $h
     * @param array<string,mixed> $curlOptions
     */
    private function applyCurlOptions($h, array $curlOptions): void
    {
        foreach ($curlOptions as $key => $value) {
            if (isset(self::SUPPORTED_OPTIONS[$key]) && $value !== null && $value !== '') {
                $this->ffi->curl_easy_setopt($h, self::SUPPORTED_OPTIONS[$key], (string) $value);
            }
        }
    }

    /**
     * @param \FFI\CData $h
     */
    private function applyCaBundle($h): void
    {
        if (PlatformDetector::isWindows()) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_SSL_OPTIONS, self::CURLSSLOPT_NATIVE_CA);

            return;
        }
        $ca = \Raza\PHPImpersonate\Support\CaBundle::path();
        if ($ca !== null) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_CAINFO, $ca);
        }
    }

    /**
     * Build a curl_slist of "Name: Value" headers, or null when empty.
     *
     * @param array<string,string> $headers
     * @return \FFI\CData|null
     */
    private function buildHeaderList(array $headers)
    {
        $list = null;
        foreach ($headers as $name => $value) {
            $list = $this->ffi->curl_slist_append($list, "$name: $value");
        }

        return $list;
    }
}
