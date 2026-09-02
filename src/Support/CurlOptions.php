<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Support;

use Raza\PHPImpersonate\Exception\InvalidArgumentException;

/**
 * The single, typed allow-list of custom curl options both engines accept.
 *
 * This is the one source of truth shared by the executable engine (which renders
 * each option as a `--flag`) and the FFI engine (which applies it via
 * curl_easy_setopt with the correct C type). Keeping it common guarantees the
 * two engines accept and apply exactly the same options.
 *
 * Fingerprint-affecting options — ciphers, curves, tls-*, the HTTP version, and
 * the User-Agent — are deliberately NOT here: overriding them would silently
 * break the browser impersonation this library exists to provide.
 */
final class CurlOptions
{
    public const TYPE_STRING = 'string';
    public const TYPE_LONG = 'long';
    public const TYPE_BOOL = 'bool';

    // libcurl option ids (from curl.h), used by the FFI engine. Verified against
    // the bundled library — a wrong id here silently misbehaves (variadic setopt).
    public const CURLOPT_PROXY = 10004;
    public const CURLOPT_PROXYUSERPWD = 10006;
    public const CURLOPT_NOPROXY = 10177;
    public const CURLOPT_REFERER = 10016;
    public const CURLOPT_CAINFO = 10065;
    public const CURLOPT_CAPATH = 10097;
    public const CURLOPT_MAXREDIRS = 68;
    public const CURLOPT_SSL_VERIFYPEER = 64;
    public const CURLOPT_SSL_VERIFYHOST = 81;
    /** CURLOPTTYPE_OFF_T (30000) + 117. */
    public const CURLOPT_MAXFILESIZE_LARGE = 30117;

    /**
     * Default cap on a response body, in bytes, applied by both engines when the
     * caller sets no `max-filesize`.
     *
     * Both engines buffer the whole body before returning it, and the FFI
     * engine's buffer is C memory that PHP's memory_limit cannot see. Measured
     * against a server streaming 1 MB chunks indefinitely, the FFI engine
     * reached 568 MB of RSS in three seconds while PHP reported a 4 MB peak;
     * the executable engine filled the temp directory instead. A finite default
     * turns that into a RequestException (curl error 63) instead of an OOM kill
     * or a full disk. curl also enforces it on chunked, unknown-length bodies.
     */
    public const DEFAULT_MAX_FILESIZE = 268435456; // 256 MiB

    /**
     * name => [type, curlopt id]. The name doubles as the executable engine's
     * CLI flag. `insecure` has no single id: it maps to two setopts on FFI and
     * to the `--insecure` boolean flag on the executable (see the engines).
     *
     * @var array<string, array{0: self::TYPE_*, 1: int|null}>
     */
    private const REGISTRY = [
        'proxy' => [self::TYPE_STRING, self::CURLOPT_PROXY],
        'proxy-user' => [self::TYPE_STRING, self::CURLOPT_PROXYUSERPWD],
        'noproxy' => [self::TYPE_STRING, self::CURLOPT_NOPROXY],
        'referer' => [self::TYPE_STRING, self::CURLOPT_REFERER],
        'cacert' => [self::TYPE_STRING, self::CURLOPT_CAINFO],
        'capath' => [self::TYPE_STRING, self::CURLOPT_CAPATH],
        'max-redirs' => [self::TYPE_LONG,   self::CURLOPT_MAXREDIRS],
        'max-filesize' => [self::TYPE_LONG, self::CURLOPT_MAXFILESIZE_LARGE],
        'insecure' => [self::TYPE_BOOL,   null],
    ];

    /**
     * Options where an EMPTY string is itself a value rather than an absent one.
     *
     * curl documents `--proxy ""` (CURLOPT_PROXY set to "") as the way to
     * disable proxying for a transfer, overriding the http_proxy/HTTPS_PROXY
     * environment variables libcurl otherwise honours. Dropped along with the
     * other empty strings, it left a caller no way to say "go direct" — and
     * said nothing about having ignored what they passed.
     *
     * @var list<string>
     */
    private const EMPTY_IS_MEANINGFUL = ['proxy'];

    /**
     * Whether an empty string is a meaningful value for this option, rather
     * than a way of spelling "unset". See {@see EMPTY_IS_MEANINGFUL}.
     */
    public static function emptyIsMeaningful(string $key): bool
    {
        return in_array($key, self::EMPTY_IS_MEANINGFUL, true);
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function isAllowed(string $key): bool
    {
        return isset(self::REGISTRY[$key]);
    }

    /**
     * @return self::TYPE_*
     */
    public static function type(string $key): string
    {
        return self::REGISTRY[$key][0];
    }

    public static function optId(string $key): ?int
    {
        return self::REGISTRY[$key][1];
    }

    /**
     * Interpret a boolean-option value the way curl's own flags do
     * (`true`, `1`, `"1"`, `"true"`, `"yes"`, `"on"` are all enabled).
     */
    public static function isEnabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string,mixed> $curlOptions
     * @throws InvalidArgumentException listing the supported options.
     */
    public static function assertAllowed(array $curlOptions): void
    {
        $unknown = array_diff(array_keys($curlOptions), self::allowedKeys());

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported curl option(s): %s. Supported options: %s. '
                . '(Options that would alter the browser fingerprint are intentionally not configurable.)',
                implode(', ', $unknown),
                implode(', ', self::allowedKeys())
            ));
        }

        foreach ($curlOptions as $key => $value) {
            if ($value === null || is_scalar($value)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid value for curl option "%s": expected a scalar, %s given.',
                $key,
                get_debug_type($value)
            ));
        }

        foreach ($curlOptions as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (self::type($key) === self::TYPE_LONG && ! is_numeric($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for curl option "%s": expected a number, %s given.',
                    $key,
                    var_export($value, true)
                ));
            }

            // Reject a boolean value curl would not recognise instead of quietly
            // reading it as "off". filter_var() collapses everything it does not
            // know to false, so `'insecure' => 'enable'` (or a typo like 'ture')
            // used to mean the opposite of what the caller wrote, with nothing
            // said — out of step with every other check in this class, which
            // throws. FILTER_NULL_ON_FAILURE is what distinguishes "false" from
            // "not a boolean at all".
            if (self::type($key) === self::TYPE_BOOL
                && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for curl option "%s": expected a boolean, %s given. '
                    . 'Accepted: true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "on"/"off".',
                    $key,
                    var_export($value, true)
                ));
            }

            // No control characters in a value, ever. The executable engine
            // renders the credential-bearing options into curl's config file,
            // whose format is line-oriented: a newline ends the line and curl
            // reads whatever follows as ANOTHER option. A `proxy` string from a
            // rotating-proxy list or a tenant's settings could otherwise smuggle
            // in `proxy`, `insecure` or `data = @/etc/passwd`. Mirrors the CRLF
            // rule {@see RequestPreparer::assertHeaderIsSafe()} applies to headers.
            if (self::type($key) === self::TYPE_STRING && preg_match('/[\r\n\0]/', (string) $value)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for curl option "%s": values may not contain CR, LF, or NUL.',
                    $key
                ));
            }
        }
    }

    /**
     * Canonicalise validated options into the exact value shape both engines apply.
     *
     * Bool options collapse to `true` (or vanish), long options become ints and
     * string options non-empty strings. Both engines must consume the result of
     * this, because a loose value otherwise means opposite things on each:
     * `['insecure' => 'no']` reads as "off" through {@see isEnabled()} on the FFI
     * engine, while the executable engine would render it as `--insecure no`,
     * which both enables the flag and hands curl `no` as an extra URL to fetch.
     *
     * Key order is canonical too. Curl does not care in what order it is handed
     * independent options, but the FFI engine cache keys on the serialised
     * result — so without this, two spellings of one configuration would mint
     * two engines, each with its own handle and connection pool.
     *
     * @param array<string,mixed> $curlOptions Already checked by {@see assertAllowed()}.
     * @return array<string,bool|int|string>
     */
    public static function normalize(array $curlOptions): array
    {
        $normalized = [];

        foreach ($curlOptions as $key => $value) {
            if (! self::isAllowed($key) || $value === null) {
                continue;
            }

            switch (self::type($key)) {
                case self::TYPE_BOOL:
                    // Drop rather than emit `false`: an absent flag is how both
                    // engines express "off", and nothing reaches the wire.
                    if (self::isEnabled($value)) {
                        $normalized[$key] = true;
                    }

                    break;

                case self::TYPE_LONG:
                    $normalized[$key] = (int) $value;

                    break;

                case self::TYPE_STRING:
                    $string = (string) $value;
                    if ($string !== '' || self::emptyIsMeaningful($key)) {
                        $normalized[$key] = $string;
                    }

                    break;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
