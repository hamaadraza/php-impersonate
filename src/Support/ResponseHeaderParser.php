<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Support;

/**
 * Parses a raw HTTP response header block into a name => values map.
 *
 * Shared by both clients so header handling is identical. Per RFC 9110 §5.3
 * repeated fields are valid, and Set-Cookie in particular must never be
 * comma-joined (RFC 6265 §4.1.1), so values are always kept as string[] lists.
 * On redirect chains only the final response's header block is retained.
 *
 * The status line is returned separately from the header map: it is not a
 * header, so callers iterating headers() never have to filter out a synthetic
 * entry that was never on the wire.
 *
 * @internal Not part of the public API.
 */
final class ResponseHeaderParser
{
    /**
     * The response headers, without the status line.
     *
     * @return array<string, string[]>
     */
    public static function parse(string $headersContent): array
    {
        return self::parseAll($headersContent)['headers'];
    }

    /**
     * The final response's status line (e.g. "HTTP/2 200"), or null when the
     * block contains none.
     */
    public static function statusLine(string $headersContent): ?string
    {
        return self::parseAll($headersContent)['status'];
    }

    /**
     * Parse the block into its final status line and its header map.
     *
     * @return array{status: string|null, headers: array<string, string[]>}
     */
    public static function parseAll(string $headersContent): array
    {
        if (trim($headersContent) === '') {
            return ['status' => null, 'headers' => []];
        }

        /** @var array<string, string[]> $headers */
        $headers = [];
        $status = null;

        // Handle multiple HTTP responses (redirects) — keep only the final block.
        $sections = preg_split('/\r?\n\r?\n/', trim($headersContent));

        if (! $sections) {
            return ['status' => null, 'headers' => []];
        }

        $lines = explode("\n", self::finalHeaderSection($sections));

        /** @var string|null $lastName the field a continuation line would extend */
        $lastName = null;

        /**
         * Field names are case-insensitive (RFC 9110 §5.1), so `Set-Cookie`
         * and `set-cookie` in one response are the same field. Keyed by the
         * raw name they became two entries, and Response's lookups — which
         * stop at the first case-insensitive match — returned only one of
         * them. Reachable on HTTP/1.1 through a proxy or CDN that appends its
         * own lowercase headers to an origin's mixed-case ones. The first
         * spelling seen is the one kept.
         *
         * @var array<string,string> lower-cased name => the spelling in use
         */
        $spellings = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            // obs-fold (RFC 9112 §5.2): a line opening with SP or HTAB continues
            // the field value above it, and the RFC's remedy is to replace the
            // fold with a space and join. Parsed as its own line instead, a
            // continuation without a colon was silently dropped and one with a
            // colon became a header with a nonsense name — `X-Note: part1`
            // folded over ` Sat, 01 Jan 2020 00:00:00 GMT` produced a header
            // called "Sat, 01 Jan 2020 00".
            //
            // Only ever a continuation AFTER a field line: a fold belongs to a
            // field value, so it cannot follow the status line. That is what
            // keeps a merely indented header from being swallowed by the line
            // above it.
            if ($lastName !== null && ($rawLine[0] === ' ' || $rawLine[0] === "\t")) {
                $last = count($headers[$lastName]) - 1;
                $headers[$lastName][$last] = rtrim($headers[$lastName][$last]) . ' ' . $line;

                continue;
            }

            if (str_starts_with($line, 'HTTP/')) {
                $status = $line;
                $lastName = null;

                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));

                if ($name !== '') {
                    $name = $spellings[strtolower($name)] ??= $name;
                    $headers[$name][] = $value;
                    $lastName = $name;
                }
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }

    /**
     * Every Set-Cookie value in the block, from EVERY response in the redirect
     * chain, in wire order.
     *
     * {@see parse()} keeps only the final response's headers, which is right
     * for everything except cookies: a session cookie is routinely set on the
     * 302 that follows a login and never on the page it redirects to, so the
     * final block alone loses it. Redirect responses carry no field but this
     * one that a caller needs to persist.
     *
     * @return list<string>
     */
    public static function setCookieHeaders(string $headersContent): array
    {
        $cookies = [];

        foreach (preg_split('/\r?\n/', $headersContent) ?: [] as $line) {
            $colonPos = strpos($line, ':');

            if ($colonPos !== false && strcasecmp(trim(substr($line, 0, $colonPos)), 'Set-Cookie') === 0) {
                $cookies[] = trim(substr($line, $colonPos + 1));
            }
        }

        return $cookies;
    }

    /**
     * The last section that is actually a header block.
     *
     * libcurl delivers TRAILER fields through the same callback as headers, and
     * they arrive after the blank line that terminates the header section — so
     * on a trailered (chunked or HTTP/2) response the final section is the
     * trailers. Taking it blindly returned ONLY those, silently dropping every
     * real header — Content-Type, Set-Cookie, Location — and the status line
     * with them.
     *
     * A header block always opens with a status line and a trailer block never
     * does, which is what tells the two apart.
     *
     * @param list<string> $sections
     */
    private static function finalHeaderSection(array $sections): string
    {
        $start = null;

        for ($i = count($sections) - 1; $i >= 0; $i--) {
            if (preg_match('#^\s*HTTP/#i', $sections[$i])) {
                $start = $i;

                break;
            }
        }

        if ($start === null) {
            // No status line anywhere — keep the old behaviour rather than
            // inventing one, so a block captured without one still parses.
            return (string) end($sections);
        }

        // A genuine header block carries fields as well as a status line, and
        // whatever follows it is trailers. A status line sitting alone in its
        // section is not that: the split was an artefact of how the block was
        // captured rather than a trailer boundary, so keep what follows instead
        // of discarding it.
        if (self::hasFieldLine($sections[$start])) {
            return $sections[$start];
        }

        return implode("\n", array_slice($sections, $start));
    }

    /**
     * Whether a section carries at least one `Name: value` line of its own.
     */
    private static function hasFieldLine(string $section): bool
    {
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'HTTP/')) {
                continue;
            }

            if (str_contains($line, ':')) {
                return true;
            }
        }

        return false;
    }
}
