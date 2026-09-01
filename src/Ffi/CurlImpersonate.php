<?php

namespace Raza\PHPImpersonate\Ffi;

use FFI;
use Raza\PHPImpersonate\Support\CaBundle;
use Raza\PHPImpersonate\Support\CurlOptions;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Thin FFI binding over libcurl-impersonate. Performs one HTTP request per call
 * on a reused easy handle (so keep-alive connections are cached between calls),
 * capturing the body and headers in memory via open_memstream where available.
 *
 * This is the low-level FFI engine behind PHPImpersonate; it deals only in already
 * validated/normalised inputs.
 */
final class CurlImpersonate
{
    // libcurl option/info constants (stable ABI values from curl.h).
    private const CURLOPT_URL = 10002;
    private const CURLOPT_WRITEDATA = 10001;
    private const CURLOPT_HEADERDATA = 10029;
    private const CURLOPT_FOLLOWLOCATION = 52;
    private const CURLOPT_MAXREDIRS = 68;
    private const CURLOPT_TIMEOUT_MS = 155;
    private const CURLOPT_CONNECTTIMEOUT_MS = 156;
    private const CURLOPT_CUSTOMREQUEST = 10036;
    private const CURLOPT_NOBODY = 44;
    private const CURLOPT_POST = 47;
    private const CURLOPT_POSTFIELDSIZE_LARGE = 30120; // CURLOPTTYPE_OFF_T (30000) + 120
    private const CURLOPT_COPYPOSTFIELDS = 10165;
    private const CURLOPT_HTTPHEADER = 10023;
    private const CURLOPT_CAINFO = 10065;
    private const CURLOPT_ACCEPT_ENCODING = 10102;
    private const CURLOPT_SSL_SESSIONID_CACHE = 150;
    private const CURLINFO_RESPONSE_CODE = 2097154;

    private const CURLE_OK = 0;
    private const CURLE_TOO_MANY_REDIRECTS = 47;

    /** Match the curl command-line tool's default redirect cap (the executable engine). */
    private const MAX_REDIRECTS = 50;

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
        // The FFI engine is POSIX-only: it captures responses via open_memstream,
        // which a Windows curl-impersonate DLL does not export (and PHP FFI resolves
        // every declared symbol eagerly at cdef time). On Windows the executable
        // engine is always used instead.
        if (PlatformDetector::isWindows()) {
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
     * Perform a request. Inputs are assumed already validated/normalised.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $curlOptions Applied per {@see CurlOptions} (already validated).
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

        // Canonicalise to the same value shape the executable engine applies, so
        // a loose value (e.g. 'insecure' => 'no') can never mean one thing here
        // and the opposite there. Idempotent — callers normally pre-normalise.
        $curlOptions = CurlOptions::normalize($curlOptions);

        // Reset per request but keep the connection cache on this handle.
        $ffi->curl_easy_reset($h);

        // Opened inside the try, and each reader registered the moment it exists,
        // so a failure opening the second sink still releases the first. Left
        // above the try, that FILE* and its malloc'd buffer leaked.
        $readers = [];
        $slist = null;

        try {
            [$bodyFp, $readBody] = $this->openSink();
            $readers[] = $readBody;

            [$hdrFp, $readHeaders] = $this->openSink();
            $readers[] = $readHeaders;

            $ffi->curl_easy_setopt($h, self::CURLOPT_URL, $url);
            $ffi->curl_easy_setopt($h, self::CURLOPT_FOLLOWLOCATION, 1);
            // Cap redirects to match the executable engine (curl's tool default);
            // without this, libcurl's default differs and the engines disagree.
            $ffi->curl_easy_setopt($h, self::CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
            $ffi->curl_easy_setopt($h, self::CURLOPT_TIMEOUT_MS, $timeout * 1000);
            $ffi->curl_easy_setopt($h, self::CURLOPT_CONNECTTIMEOUT_MS, $timeout * 1000);
            $ffi->curl_easy_setopt($h, self::CURLOPT_WRITEDATA, $bodyFp);
            $ffi->curl_easy_setopt($h, self::CURLOPT_HEADERDATA, $hdrFp);

            // Apply the full browser fingerprint (TLS, HTTP/2, base headers).
            // A target the loaded library does not know returns non-zero and
            // applies nothing — without this check the request would silently go
            // out with a plain libcurl fingerprint, defeating the whole purpose.
            $rc = $ffi->curl_easy_impersonate($h, $browser, 1);
            if ($rc !== self::CURLE_OK) {
                throw new RequestException(
                    "libcurl-impersonate does not support target '$browser' ($rc). "
                    . 'The installed shared library may be older than this package '
                    . "'s browser list; refresh it with `composer update-libraries`.",
                    $rc
                );
            }

            // Enable transparent decompression (mirrors the executable's --compressed).
            $ffi->curl_easy_setopt($h, self::CURLOPT_ACCEPT_ENCODING, '');

            // Disable TLS session-ID/ticket resumption: a resumed handshake adds a
            // pre_shared_key extension, changing the JA3/JA4 fingerprint. Keeping
            // every handshake fresh makes the fingerprint deterministic and equal
            // to the executable engine's (a new process each request never resumes).
            $ffi->curl_easy_setopt($h, self::CURLOPT_SSL_SESSIONID_CACHE, 0);

            $this->applyCaBundle($h, $curlOptions);
            $this->applyMethod($h, $method, $body);
            $this->applyCurlOptions($h, $curlOptions);

            $slist = $this->buildHeaderList($headers);
            if ($slist !== null) {
                $ffi->curl_easy_setopt($h, self::CURLOPT_HTTPHEADER, $slist);
            }

            $rc = $ffi->curl_easy_perform($h);

            $ffi->fflush($bodyFp);
            $ffi->fflush($hdrFp);

            // Hitting the redirect cap is not fatal: the last response is captured,
            // and the executable engine returns it too. Return it for parity
            // instead of throwing, so callers get one contract on both engines.
            if ($rc !== self::CURLE_OK && $rc !== self::CURLE_TOO_MANY_REDIRECTS) {
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
            foreach ($readers as $reader) {
                $reader(true);
            }
        }
    }

    /**
     * Open an in-memory capture sink backed by open_memstream (POSIX-only; the
     * engine never runs on Windows — see isSupported()).
     *
     * @return array{0: \FFI\CData, 1: callable(bool=): string} [FILE*, reader]
     *   The reader returns captured bytes; pass true to close/cleanup and return ''.
     */
    private function openSink(): array
    {
        $ffi = $this->ffi;

        $buf = $ffi->new('char*');
        $size = $ffi->new('unsigned long');
        $fp = $ffi->open_memstream(FFI::addr($buf), FFI::addr($size));
        if ($fp === null || FFI::isNull($fp)) {
            throw new RequestException('libcurl-impersonate: open_memstream() failed');
        }

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

        // A bodyless POST is a POST with an EMPTY body, not one whose body is
        // merely unspecified. Left null, the CURLOPT_POST below arms libcurl's
        // read callback, and the DEFAULT read callback reads the process's own
        // stdin — so `PHPImpersonate::post($url)` with no data sent whatever had
        // been piped into the caller as the request body. Worse, when stdin is a
        // pipe that stays open the transfer blocks inside that callback before it
        // ever starts, where CURLOPT_TIMEOUT_MS cannot interrupt it, and the call
        // hangs indefinitely. Declaring the size below is what says "zero bytes".
        if ($method === 'POST' && $body === null) {
            $body = '';
        }

        if ($body !== null) {
            // Set the exact size first so binary bodies (with embedded NUL bytes)
            // are sent verbatim instead of being cut at the first NUL by strlen();
            // COPYPOSTFIELDS then copies the buffer so it need not outlive this call.
            // Checked: a wrong option id here would otherwise corrupt bodies silently.
            $rc = $this->ffi->curl_easy_setopt($h, self::CURLOPT_POSTFIELDSIZE_LARGE, strlen($body));
            if ($rc !== self::CURLE_OK) {
                throw new RequestException("libcurl-impersonate: failed to set POSTFIELDSIZE ($rc)", $rc);
            }
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_COPYPOSTFIELDS, $body);
        }

        if ($method === 'POST') {
            // Let libcurl own the verb for POST instead of pinning it with
            // CUSTOMREQUEST. A pinned verb survives a redirect, and on a
            // 301/302/303 libcurl does what browsers do — switch to GET and drop
            // the body — while the pinned string keeps saying POST. The result
            // was the worst of both: a POST carrying no body at all.
            // The body — empty or not — has already been declared above, so this
            // only settles the verb; on its own it would arm the read callback.
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_POST, 1);

            return;
        }

        // Pin the method verb explicitly whenever it isn't a plain bodyless GET.
        // In particular a body must not silently promote GET (or DELETE) to POST,
        // which COPYPOSTFIELDS does on its own — matching the executable engine.
        if ($method !== 'GET' || $body !== null) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_CUSTOMREQUEST, $method);
        }
    }

    /**
     * Apply the shared, validated custom curl options to the handle, each with
     * the C type libcurl expects (variadic setopt is not type-checked). Keys are
     * validated up front by {@see CurlOptions::assertAllowed()}.
     *
     * @param \FFI\CData $h
     * @param array<string,mixed> $curlOptions
     */
    private function applyCurlOptions($h, array $curlOptions): void
    {
        foreach ($curlOptions as $key => $value) {
            if (! CurlOptions::isAllowed($key)) {
                continue;
            }

            switch (CurlOptions::type($key)) {
                case CurlOptions::TYPE_STRING:
                    if ($value !== null && $value !== '') {
                        $this->ffi->curl_easy_setopt($h, CurlOptions::optId($key), (string) $value);
                    }

                    break;

                case CurlOptions::TYPE_LONG:
                    $this->ffi->curl_easy_setopt($h, CurlOptions::optId($key), (int) $value);

                    break;

                case CurlOptions::TYPE_BOOL:
                    if ($key === 'insecure' && CurlOptions::isEnabled($value)) {
                        // curl's -k: disable both peer and host verification.
                        $this->ffi->curl_easy_setopt($h, CurlOptions::CURLOPT_SSL_VERIFYPEER, 0);
                        $this->ffi->curl_easy_setopt($h, CurlOptions::CURLOPT_SSL_VERIFYHOST, 0);
                    }

                    break;
            }
        }
    }

    /**
     * @param \FFI\CData $h
     * @param array<string,mixed> $curlOptions
     */
    private function applyCaBundle($h, array $curlOptions): void
    {
        // Let a user-supplied cacert/capath win instead of the default bundle.
        if (isset($curlOptions['cacert']) || isset($curlOptions['capath'])) {
            return;
        }

        // POSIX-only engine (see isSupported()), so a CA bundle path is always
        // the right mechanism; BoringSSL does not auto-discover the trust store.
        $ca = CaBundle::path();
        if ($ca !== null) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_CAINFO, $ca);
        }

        $caDir = CaBundle::directory();
        if ($caDir !== null) {
            $this->ffi->curl_easy_setopt($h, CurlOptions::CURLOPT_CAPATH, $caDir);
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
