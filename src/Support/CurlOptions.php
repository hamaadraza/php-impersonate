<?php

namespace Raza\PHPImpersonate\Support;

use InvalidArgumentException;

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
        'insecure' => [self::TYPE_BOOL,   null],
    ];

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
            if (self::type($key) === self::TYPE_LONG && $value !== null && ! is_numeric($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for curl option "%s": expected a number, %s given.',
                    $key,
                    var_export($value, true)
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
                    if ($string !== '') {
                        $normalized[$key] = $string;
                    }

                    break;
            }
        }

        return $normalized;
    }
}
