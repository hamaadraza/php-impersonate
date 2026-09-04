<?php

declare(strict_types=1);

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
 *
 * @internal Not part of the public API; use {@see \Raza\PHPImpersonate\PHPImpersonate}.
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
    private const CURLOPT_SSL_OPTIONS = 216; // CURLOPTTYPE_VALUES (0) + 216

    /** CURLSSLOPT_NATIVE_CA: verify against the OS trust store (Windows). */
    private const CURLSSLOPT_NATIVE_CA = 16;

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
    private const CURLE_UNKNOWN_OPTION = 48;
    private const CURLE_FILESIZE_EXCEEDED = 63;

    /** Match the curl command-line tool's default redirect cap (the executable engine). */
    private const MAX_REDIRECTS = 50;

    /**
     * The callbacks are declared through a struct field rather than as a
     * parameter type because curl_easy_setopt() is variadic: FFI cannot know
     * that a given variadic argument is a function pointer, but it does turn a
     * PHP closure assigned to a function-pointer struct FIELD into a C
     * trampoline, and that field is then passed as an ordinary pointer.
     *
     * `size_t` is spelled `unsigned long long` rather than `unsigned long`
     * because that is 64 bits on every architecture this package supports,
     * including Windows. Unix is LP64, where `unsigned long` is also 64 bits,
     * but Windows x64 is LLP64, where it is 32 — so the obvious spelling would
     * declare the callback's lengths half the width libcurl passes them. Both
     * supported architectures (x86_64, aarch64) are 64-bit, so one typedef
     * covers them all; a 32-bit target would need its own.
     */
    private const HEADER = <<<'CDEF'
        typedef unsigned long long size_t;
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

    /**
     * Set once curl_easy_impersonate() has answered CURLE_UNKNOWN_OPTION on
     * this handle. See {@see markForeignLibcurl()}: the handle may then have
     * been written to by a different libcurl with a different struct layout,
     * and freeing it is what turns a wrong answer into a segmentation fault.
     */
    private bool $touchedByForeignLibcurl = false;

    /** glibc's dlopen(3) flags. RTLD_DEEPBIND does not exist on musl or macOS. */
    private const RTLD_NOW = 0x2;
    private const RTLD_DEEPBIND = 0x8;

    /**
     * The write callbacks and their buffer, created ONCE PER LIBRARY for the
     * whole process and shared by every engine loaded from it.
     *
     * PHP allocates a libffi trampoline for every closure assigned to a C
     * function-pointer field, and never frees it — there is no API to. Created
     * per request, as these once were, that retained two trampolines and two
     * closures per request: about 3.5 KB each time, without bound, or 350 MB
     * per 100,000 requests in a queue worker. Created per ENGINE it was 500×
     * smaller but still unbounded, because {@see
     * \Raza\PHPImpersonate\PHPImpersonate::closeFfiEngines()} and the engine
     * cache's own LRU eviction both build fresh engines (measured: 1.79 KB per
     * create/close cycle). Keyed by library, the count is finally fixed: two
     * trampolines for the life of the process, however many engines come and go.
     *
     * Sharing ONE buffer across engines is sound for the same reason the engine
     * cache is: this engine is not reentrant and serves one request at a time
     * (see PHPImpersonate::$ffiEngines). Nothing but libcurl runs between the
     * reset at the start of a request and the read at the end of it.
     *
     * The FFI instance is kept here too, deliberately. A holder outlives its
     * creating scope badly: once that FFI object is collected, reading `->fn`
     * throws "Attempt to read field 'fn' of non C struct/union". Holding the
     * instance that made them pins the scope for as long as the callbacks live.
     *
     * @var array<string, array{ffi: FFI, capture: ResponseCapture, body: \FFI\CData, header: \FFI\CData}>
     */
    private static array $sinks = [];

    /** @var \FFI\CData Shared body callback; see the docblock above. */
    private $bodySink;

    /** @var \FFI\CData Shared header callback; see the docblock above. */
    private $headerSink;

    /** The shared buffer the two callbacks append to; emptied around every request. */
    private ResponseCapture $capture;

    public function __construct(string $libPath)
    {
        self::bindSymbolsLocally($libPath);

        $this->ffi = FFI::cdef(self::HEADER, $libPath);
        $this->handle = $this->ffi->curl_easy_init();
        if ($this->handle === null) {
            throw new RequestException('libcurl-impersonate: curl_easy_init() failed');
        }

        $sinks = self::sharedSinks($libPath, $this->ffi);
        $this->capture = $sinks['capture'];
        $this->bodySink = $sinks['body'];
        $this->headerSink = $sinks['header'];
    }

    /**
     * The process-wide callbacks for one library, created on first use.
     *
     * Capturing straight into PHP strings through libcurl's callbacks replaced
     * open_memstream(): a C buffer that grew by doubling and was then copied
     * whole into PHP, about 3.5× the body in RSS for a large response and none
     * of it visible to memory_limit. A callback appends each chunk as it
     * arrives, so the only copy is PHP's.
     *
     * The closures capture the small ResponseCapture object and nothing else.
     * They live for the rest of the process, so capturing an engine would pin
     * that engine, its curl handle and its kept-alive connections forever.
     *
     * The body callback also enforces a byte budget (see bodyBudget()). The
     * try/catch cannot catch the one failure that matters most here: running
     * past memory_limit inside the callback is a fatal error, and it unwinds
     * straight through libcurl's C frames — a bare 500 in FPM, a dead queue
     * worker elsewhere, and no exception for the caller. `max-filesize` does
     * not prevent it either: its default (256 MiB) is above PHP's default
     * memory_limit (128M), so with stock settings any body between roughly
     * 60 MB and 256 MB was fatal. Refusing the chunk that would cross the
     * budget makes curl stop with a write error, which the engine then throws
     * as it does for any other callback failure.
     *
     * @return array{ffi: FFI, capture: ResponseCapture, body: \FFI\CData, header: \FFI\CData}
     */
    private static function sharedSinks(string $libPath, FFI $ffi): array
    {
        if (isset(self::$sinks[$libPath])) {
            return self::$sinks[$libPath];
        }

        $capture = new ResponseCapture();

        $body = $ffi->new('php_impersonate_cb_holder');
        $body->fn = static function ($ptr, int $size, int $nmemb, $userdata) use ($capture): int {
            $length = $size * $nmemb;

            try {
                if ($length > 0) {
                    $buffered = strlen($capture->body);
                    if ($buffered + $length > $capture->budget) {
                        throw new RequestException(sprintf(
                            'the response body does not fit in memory: %d bytes buffered, %d more arrived, '
                            . '%d permitted with memory_limit=%s (raise memory_limit, or set a lower `max-filesize`)',
                            $buffered,
                            $length,
                            $capture->budget,
                            (string) ini_get('memory_limit')
                        ), self::CURLE_FILESIZE_EXCEEDED);
                    }
                    $capture->body .= FFI::string($ptr, $length);
                }

                return $length;
            } catch (\Throwable $e) {
                // Never let an exception cross the C boundary: report a short
                // write so curl aborts with CURLE_WRITE_ERROR, and rethrow later.
                $capture->error = $e;

                return 0;
            }
        };

        $header = $ffi->new('php_impersonate_cb_holder');
        $header->fn = static function ($ptr, int $size, int $nmemb, $userdata) use ($capture): int {
            $length = $size * $nmemb;

            try {
                if ($length > 0) {
                    $capture->headers .= FFI::string($ptr, $length);
                }

                return $length;
            } catch (\Throwable $e) {
                $capture->error = $e;

                return 0;
            }
        };

        return self::$sinks[$libPath] = [
            'ffi' => $ffi,
            'capture' => $capture,
            'body' => $body,
            'header' => $header,
        ];
    }

    /**
     * How many body bytes this request may buffer before the write callback
     * refuses the next chunk.
     *
     * Appending to a PHP string can briefly hold the old and the new copy at
     * once, so the body may take at most half of what memory_limit leaves
     * free, less a margin for the headers, the chunk being appended and
     * whatever else the request allocates. No limit means no budget.
     */
    private static function bodyBudget(): int
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        $free = $limit - memory_get_usage(true);
        if ($free <= 0) {
            return 0;
        }

        return max(intdiv($free, 4), intdiv($free, 2) - 3 * 1024 * 1024);
    }

    /**
     * memory_limit in bytes, or -1 when unlimited. Same shorthand PHP accepts
     * for the setting itself (`128M`, `1G`, `262144`).
     */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };
    }

    /**
     * Forget the curl handle WITHOUT freeing it.
     *
     * For a process that inherited this engine across pcntl_fork(): the
     * handle's connections are the parent's sockets, and curl_easy_cleanup()
     * here would send close_notify / FIN on them. Leaking one easy handle in
     * the child is the lesser harm. The engine is unusable afterwards.
     *
     * @internal Called by PHPImpersonate's engine cache; not part of the public API.
     */
    public function abandon(): void
    {
        $this->handle = null;
    }

    public function __destruct()
    {
        if ($this->handle === null) {
            return;
        }

        // A handle another libcurl has written into must not be handed back
        // to this one to free: that is the crash. Leaking one easy handle is
        // the lesser harm, and the engine is unavailable at that point anyway.
        if (! $this->touchedByForeignLibcurl) {
            $this->ffi->curl_easy_cleanup($this->handle);
        }

        $this->handle = null;
    }

    /**
     * Load the library so that its OWN definitions win for its internal calls.
     *
     * libcurl-impersonate calls curl_easy_setopt() and friends through the
     * PLT. When the php executable itself defines those names — ext-curl
     * compiled into the binary rather than loaded as a module, which is how
     * setup-php builds PHP 8.5 and how the official Docker images build every
     * version — the executable's symbols take precedence over the library's
     * for the library's own calls. curl_easy_impersonate() then hands its
     * options to a stock libcurl: it answers CURLE_UNKNOWN_OPTION (48) and,
     * worse, writes into a handle whose struct layout it does not share, so
     * the process crashes later in curl_easy_cleanup(). A library loaded as a
     * shared object (a module's dependency) does NOT interpose this way, which
     * is why the problem only shows on some builds.
     *
     * RTLD_DEEPBIND makes the dynamic linker search the library itself before
     * the global scope, which is exactly the binding a self-contained library
     * needs. FFI::cdef() reuses the mapping this dlopen() creates, flags
     * included. glibc only: musl has no such flag (there, {@see probeTarget()}
     * detects the problem and the engine is reported unavailable), macOS does
     * not need it (its two-level namespace already binds locally), and neither
     * does Windows, where a DLL's imports bind to the module named in its own
     * import table and its internal calls never go through one at all.
     * Verified against an executable that defines curl_easy_setopt(): 48
     * without this, 0 with it.
     */
    private static function bindSymbolsLocally(string $libPath): void
    {
        if (! PlatformDetector::isLinux() || PlatformDetector::isMusl()) {
            return;
        }

        try {
            $libc = FFI::cdef('void *dlopen(const char *filename, int flags);');
            // Deliberately never dlclose()d: the mapping must outlive every
            // handle, and FFI holds its own reference for the process anyway.
            $libc->dlopen($libPath, self::RTLD_NOW | self::RTLD_DEEPBIND);
        } catch (\Throwable) {
            // dlopen not reachable through FFI on this build: proceed without
            // the pre-binding; the functional probe still guards the outcome.
        }
    }

    /**
     * Record that curl_easy_impersonate() returned CURLE_UNKNOWN_OPTION, which
     * from a known target means its internal setopt calls reached a different
     * libcurl. Nothing is trusted about this handle afterwards.
     */
    private function markForeignLibcurl(): void
    {
        $this->touchedByForeignLibcurl = true;
    }

    /**
     * Ask the loaded library to apply a profile, without sending anything.
     *
     * Loading the library and creating a handle proves less than it seems: on
     * an Alpine leg the library loaded, the handle came up, and then every
     * call to curl_easy_impersonate() answered CURLE_UNKNOWN_OPTION (48) — a
     * code the profile table never returns for an unknown NAME (that is 43),
     * only for a setopt the library made on its own behalf that landed
     * somewhere it did not expect, such as a second libcurl already loaded
     * into the PHP process. Whatever the cause, an engine that cannot apply
     * its default profile is not available, and this is how it is found out
     * BEFORE it is chosen.
     *
     * @return int libcurl's return code; 0 means the profile applied.
     */
    public function probeTarget(string $browser): int
    {
        $this->ffi->curl_easy_reset($this->handle);

        $rc = (int) $this->ffi->curl_easy_impersonate($this->handle, $browser, 1);
        if ($rc === self::CURLE_UNKNOWN_OPTION) {
            $this->markForeignLibcurl();
        }

        return $rc;
    }

    /**
     * Whether FFI is usable in this SAPI at all (independent of library presence).
     */
    public static function isSupported(): bool
    {
        if (! extension_loaded('FFI')) {
            return false;
        }

        // Windows is supported here too. It was refused outright while the
        // engine captured responses through open_memstream, which no Windows
        // build exports; responses now come through libcurl's own write
        // callbacks, so no POSIX-specific symbol is left. What remains is
        // ordinary: the DLL has to be present, which on Windows means running
        // bin/php-impersonate-install, and ffiAvailable() reports it plainly
        // when it is not.
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

        // The callbacks are process-wide (see self::$sinks); only their buffer
        // is per request, and it is emptied at both ends of one.
        $capture = $this->capture;
        $capture->reset();
        // Per request, because memory_limit and current usage both move.
        $capture->budget = self::bodyBudget();

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
            // Re-applied every request because curl_easy_reset() clears them,
            // but always from the same holders (see the property docblock).
            $ffi->curl_easy_setopt($h, self::CURLOPT_WRITEFUNCTION, $this->bodySink->fn);
            $ffi->curl_easy_setopt($h, self::CURLOPT_HEADERFUNCTION, $this->headerSink->fn);

            // Apply the full browser fingerprint (TLS, HTTP/2, base headers).
            // A target the loaded library does not know returns non-zero and
            // applies nothing — without this check the request would silently go
            // out with a plain libcurl fingerprint, defeating the whole purpose.
            $rc = $ffi->curl_easy_impersonate($h, $browser, 1);
            if ($rc === self::CURLE_UNKNOWN_OPTION) {
                $this->markForeignLibcurl();
            }
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

            $slist = $this->buildHeaderList($headers, RequestPreparer::implicitHeaderSuppressions($method, $body, $headers));
            if ($slist !== null) {
                $ffi->curl_easy_setopt($h, self::CURLOPT_HTTPHEADER, $slist);
            }

            $rc = $ffi->curl_easy_perform($h);

            if ($capture->error !== null) {
                $inner = $capture->error;

                throw new RequestException(
                    'libcurl-impersonate: failed while receiving the response: ' . $inner->getMessage(),
                    // The budget abort carries curl's own "too large" code so
                    // callers see one code for an oversized body on both
                    // engines, whichever cap stopped it; anything else keeps
                    // the write error curl reported.
                    $inner instanceof RequestException && $inner->getCode() !== 0 ? $inner->getCode() : $rc,
                    $inner
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
                'headers' => $capture->headers,
                'body' => $capture->body,
                'url' => $effectiveUrl,
            ];
        } finally {
            if ($slist !== null) {
                $ffi->curl_slist_free_all($slist);
            }
            // The returned array holds its own reference to the strings; drop
            // the buffers' so a large body is not kept alive until the next
            // request by closures that themselves live for the whole process.
            $capture->reset();
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

        // BoringSSL does not auto-discover a trust store, so one has to be
        // named explicitly or asked for by name.
        $ca = CaBundle::path();
        if ($ca !== null) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_CAINFO, $ca);
        }

        $caDir = CaBundle::directory();
        if ($caDir !== null) {
            $this->ffi->curl_easy_setopt($h, CurlOptions::CURLOPT_CAPATH, $caDir);
        }

        // Only when nothing was resolved, which on Windows is the normal case:
        // CaBundle knows the Unix bundle locations and no Windows equivalent
        // exists, so without this every HTTPS request would fail verification
        // for want of any trust store at all. Mirrors the executable engine's
        // `--ca-native` (see CurlProcess::addSslCertOptions()); an explicit
        // CURL_CA_BUNDLE or SSL_CERT_FILE still wins, which is the point of
        // setting it.
        if ($ca === null && $caDir === null && PlatformDetector::isWindows()) {
            $this->ffi->curl_easy_setopt($h, self::CURLOPT_SSL_OPTIONS, self::CURLSSLOPT_NATIVE_CA);
        }
    }

    /**
     * Build a curl_slist of "Name: Value" headers, or null when empty.
     *
     * @param array<string,string> $headers
     * @param list<string> $rawLines Lines appended verbatim (libcurl's `Name:` removal form).
     * @return \FFI\CData|null
     */
    private function buildHeaderList(array $headers, array $rawLines = [])
    {
        $list = null;
        foreach ($headers as $name => $value) {
            $list = $this->ffi->curl_slist_append($list, RequestPreparer::headerLine((string) $name, $value));
        }
        foreach ($rawLines as $line) {
            $list = $this->ffi->curl_slist_append($list, $line);
        }

        return $list;
    }
}
