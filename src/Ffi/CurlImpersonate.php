<?php

namespace Raza\PHPImpersonate\Ffi;

use FFI;
use Raza\PHPImpersonate\Support\CaBundle;
use Raza\PHPImpersonate\Support\CurlOptions;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Thin FFI binding over libcurl-impersonate. Performs one HTTP request per call
 * on a reused easy handle (so keep-alive connections are cached between calls),
 * capturing the body and headers straight into PHP strings through libcurl's
 * write callbacks.
 *
 * This is the low-level FFI engine behind PHPImpersonate; it deals only in already
 * validated/normalised inputs.
 */
final class CurlImpersonate
{
    // libcurl option/info constants (stable ABI values from curl.h).
    private const CURLOPT_URL = 10002;
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
    private const CURLOPT_COOKIEFILE = 10031;
    private const CURLOPT_COOKIELIST = 10135;
    private const CURLOPT_WRITEFUNCTION = 20011; // CURLOPTTYPE_FUNCTIONPOINT (20000) + 11
    private const CURLOPT_HEADERFUNCTION = 20079; // CURLOPTTYPE_FUNCTIONPOINT (20000) + 79

    /**
     * Long-typed protocol bitmask, deliberately in preference to the newer
     * CURLOPT_REDIR_PROTOCOLS_STR (10183): the bundled library answers 48
     * (CURLE_UNKNOWN_OPTION) for the string form despite reporting curl 8.x,
     * while it accepts this one. Verified against the bundled libraries.
     */
    private const CURLOPT_REDIR_PROTOCOLS = 182;

    /** CURLPROTO_HTTP | CURLPROTO_HTTPS. */
    private const CURLPROTO_HTTP_HTTPS = 1 | 2;
    private const CURLINFO_RESPONSE_CODE = 2097154; // CURLINFO_LONG (0x200000) + 2
    private const CURLINFO_EFFECTIVE_URL = 1048577; // CURLINFO_STRING (0x100000) + 1

    private const CURLE_OK = 0;
    private const CURLE_TOO_MANY_REDIRECTS = 47;

    /** Match the curl command-line tool's default redirect cap (the executable engine). */
    private const MAX_REDIRECTS = 50;

    /**
     * The callbacks are declared through a struct field rather than as a
     * parameter type because curl_easy_setopt() is variadic: FFI cannot know
     * that a given variadic argument is a function pointer, but it does turn a
     * PHP closure assigned to a function-pointer struct FIELD into a C
     * trampoline, and that field is then passed as an ordinary pointer.
     */
    private const HEADER = <<<'CDEF'
        typedef unsigned long size_t;
        typedef size_t (*php_impersonate_write_cb)(char *ptr, size_t size, size_t nmemb, void *userdata);
        typedef struct { php_impersonate_write_cb fn; } php_impersonate_cb_holder;
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
        // No shared library ships for Windows (see LibResolver::libraryNames()
        // and BinaryInstaller::libIsUsable()), so the executable engine is
        // always used there. The engine itself no longer depends on any
        // POSIX-only symbol — responses are captured through libcurl's own
        // write callbacks — so this is a packaging limit, not a technical one.
        if (PlatformDetector::isWindows()) {
            return false;
        }
        $enable = strtolower(trim((string) ini_get('ffi.enable')));

        // An explicit "off" wins everywhere, the CLI included: a hardened
        // php-cli.ini can set ffi.enable=0, and FFI::cdef() then throws
        // "FFI API is restricted by ffi.enable". Answering true on the strength
        // of the SAPI alone left this predicate disagreeing with the engine it
        // describes. (Verified against `php -d ffi.enable=0`.)
        if (in_array($enable, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        // Otherwise the CLI always has FFI, whatever the setting says — its
        // default is "preload", and FFI works there regardless.
        if (PHP_SAPI === 'cli') {
            return true;
        }

        // Elsewhere it takes an explicit "on": the default "preload" permits FFI
        // only from preloaded files, which ordinary request code is not.
        return in_array($enable, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Perform a request. Inputs are assumed already validated/normalised.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $curlOptions Applied per {@see CurlOptions} (already validated).
     * @return array{status: int, headers: string, body: string, url: string}
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

        // Cookies, in two steps that must both happen. curl_easy_reset() keeps
        // the cookie jar, and this handle is shared by every client with the
        // same (library, browser) key — so first WIPE whatever the previous
        // request left, or one caller's session cookie would ride along on the
        // next caller's request. Then enable the in-memory engine for this
        // request, so a cookie set on a redirect hop (login → 302 + Set-Cookie
        // → GET) is sent on the follow-up, as browsers do. Setting
        // CURLOPT_COOKIEFILE to "" is libcurl's documented way to say "engine
        // on, no file"; nothing touches disk.
        $ffi->curl_easy_setopt($h, self::CURLOPT_COOKIEFILE, '');
        $ffi->curl_easy_setopt($h, self::CURLOPT_COOKIELIST, 'ALL');

        // Capture straight into PHP strings via libcurl's callbacks. This used
        // to go through open_memstream(): a C buffer that grew by doubling,
        // then got copied whole into PHP — about 3.5× the body in RSS for a
        // large response, none of it visible to memory_limit. A callback
        // appends each 16 KB chunk as it arrives, so the only copy is PHP's.
        // The holders must outlive curl_easy_perform(): the C trampoline is
        // released with them.
        $responseBody = '';
        $responseHeaders = '';
        $callbackError = null;

        $bodySink = $ffi->new('php_impersonate_cb_holder');
        $bodySink->fn = static function ($ptr, int $size, int $nmemb, $userdata) use (&$responseBody, &$callbackError): int {
            $length = $size * $nmemb;

            try {
                if ($length > 0) {
                    $responseBody .= FFI::string($ptr, $length);
                }

                return $length;
            } catch (\Throwable $e) {
                // Never let an exception cross the C boundary: report a short
                // write so curl aborts with CURLE_WRITE_ERROR, and rethrow below.
                $callbackError = $e;

                return 0;
            }
        };

        $headerSink = $ffi->new('php_impersonate_cb_holder');
        $headerSink->fn = static function ($ptr, int $size, int $nmemb, $userdata) use (&$responseHeaders, &$callbackError): int {
            $length = $size * $nmemb;

            try {
                if ($length > 0) {
                    $responseHeaders .= FFI::string($ptr, $length);
                }

                return $length;
            } catch (\Throwable $e) {
                $callbackError = $e;

                return 0;
            }
        };

        $slist = null;

        try {
            $ffi->curl_easy_setopt($h, self::CURLOPT_URL, $url);
            $ffi->curl_easy_setopt($h, self::CURLOPT_FOLLOWLOCATION, 1);
            // Cap redirects to match the executable engine (curl's tool default);
            // without this, libcurl's default differs and the engines disagree.
            $ffi->curl_easy_setopt($h, self::CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
            // Redirects may only go where the initial URL was allowed to.
            // RequestPreparer::validateRequest() allow-lists http/https, but
            // libcurl's redirect default also permits ftp/ftps, so a server
            // could answer `Location: ftp://internal-host/` and reach a scheme
            // this client refuses to speak.
            //
            // The return code is checked because this one is a security
            // boundary: a library that does not know the option would otherwise
            // leave redirects unrestricted with nothing said, which is exactly
            // the silent-failure mode the restriction exists to prevent.
            $rc = $ffi->curl_easy_setopt($h, self::CURLOPT_REDIR_PROTOCOLS, self::CURLPROTO_HTTP_HTTPS);
            if ($rc !== self::CURLE_OK) {
                throw new RequestException(
                    "libcurl-impersonate: could not restrict redirect protocols to http/https ($rc). "
                    . 'Refusing to send the request, because a redirect could otherwise reach '
                    . 'a scheme this client rejects on input.',
                    $rc
                );
            }
            $ffi->curl_easy_setopt($h, self::CURLOPT_TIMEOUT_MS, $timeout * 1000);
            $ffi->curl_easy_setopt($h, self::CURLOPT_CONNECTTIMEOUT_MS, $timeout * 1000);
            // The body is buffered whole, so it needs a cap (see
            // CurlOptions::DEFAULT_MAX_FILESIZE). applyCurlOptions() below sets
            // the caller's own value when given.
            if (! isset($curlOptions['max-filesize'])) {
                $ffi->curl_easy_setopt($h, CurlOptions::CURLOPT_MAXFILESIZE_LARGE, CurlOptions::DEFAULT_MAX_FILESIZE);
            }
            $ffi->curl_easy_setopt($h, self::CURLOPT_WRITEFUNCTION, $bodySink->fn);
            $ffi->curl_easy_setopt($h, self::CURLOPT_HEADERFUNCTION, $headerSink->fn);

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

            if ($callbackError !== null) {
                throw new RequestException(
                    'libcurl-impersonate: failed while receiving the response: ' . $callbackError->getMessage(),
                    $rc,
                    $callbackError
                );
            }

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

            // Where the request ended up after redirects. The pointer belongs
            // to the handle and is only valid until the next call on it, so
            // copy it out now.
            $effective = $ffi->new('char*');
            $ffi->curl_easy_getinfo($h, self::CURLINFO_EFFECTIVE_URL, FFI::addr($effective));
            $effectiveUrl = FFI::isNull($effective) ? $url : FFI::string($effective);

            return [
                'status' => (int) $status->cdata,
                'headers' => $responseHeaders,
                'body' => $responseBody,
                'url' => $effectiveUrl,
            ];
        } finally {
            if ($slist !== null) {
                $ffi->curl_slist_free_all($slist);
            }
            // Leave no dangling trampoline on the handle: the next request sets
            // fresh ones, but a request that failed before reaching setopt()
            // would otherwise inherit pointers into freed holders.
            $ffi->curl_easy_setopt($h, self::CURLOPT_WRITEFUNCTION, null);
            $ffi->curl_easy_setopt($h, self::CURLOPT_HEADERFUNCTION, null);
        }
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
                    // An empty string still reaches libcurl where it MEANS
                    // something — `proxy` set to "" is how a transfer opts out
                    // of the environment's proxy. See CurlOptions::normalize().
                    if ($value !== null && ($value !== '' || CurlOptions::emptyIsMeaningful($key))) {
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
            $list = $this->ffi->curl_slist_append($list, RequestPreparer::headerLine((string) $name, $value));
        }

        return $list;
    }
}
