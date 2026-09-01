<?php

namespace Raza\PHPImpersonate\Support;

use InvalidArgumentException;
use Raza\PHPImpersonate\Request;

/**
 * Transport-independent request preparation: URL/scheme validation, header
 * normalisation, body encoding, and header-injection guards.
 *
 * Both the process-based client (PHPImpersonate) and the FFI-based client
 * (the FFI engine) share this so their security-sensitive handling (CRLF rejection,
 * scheme allow-listing, case-insensitive Content-Type) can never diverge.
 */
final class RequestPreparer
{
    /**
     * Validate the request URL: non-empty, well-formed, http/https only.
     *
     * @throws InvalidArgumentException
     */
    public static function validateRequest(Request $request): void
    {
        if (empty(trim($request->getUrl()))) {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        if (! filter_var($request->getUrl(), FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL format');
        }

        // FILTER_VALIDATE_URL accepts ftp://, file://localhost/..., etc.; this is
        // an HTTP client, and passing other schemes to curl invites SSRF surprises
        $scheme = strtolower((string) parse_url($request->getUrl(), PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Unsupported URL scheme \"$scheme\": only http and https are allowed");
        }
    }

    /**
     * Encode $data for a request body, honouring the caller's Content-Type
     * (any casing) and falling back to $defaultContentType, which it also sets.
     *
     * @param array<string,mixed>|null $data
     * @param array<string,string> $headers Modified in place to add the default Content-Type.
     * @throws InvalidArgumentException
     */
    public static function prepareBody(
        ?array $data,
        array &$headers,
        string $defaultContentType = 'application/x-www-form-urlencoded'
    ): ?string {
        if ($data === null) {
            return null;
        }

        // The caller's Content-Type (any casing) wins; the method default applies otherwise
        $contentType = self::findHeaderValue($headers, 'Content-Type');

        if ($contentType === null) {
            $headers['Content-Type'] = $defaultContentType;
            $contentType = $defaultContentType;
        }

        if (str_contains($contentType, 'application/json')) {
            try {
                return json_encode($data, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Failed to encode data as JSON: ' . $e->getMessage());
            }
        }

        return http_build_query($data);
    }

    /**
     * Reject header names/values that could smuggle extra header lines or
     * confuse header parsing (header injection).
     *
     * @throws InvalidArgumentException
     */
    public static function assertHeaderIsSafe(string $name, string $value): void
    {
        if ($name === '' || preg_match('/[\r\n\0]/', $name . $value) || str_contains($name, ':')) {
            throw new InvalidArgumentException(
                sprintf('Invalid header "%s": names must be non-empty without ":" and neither part may contain CR, LF, or NUL', $name)
            );
        }
    }

    /**
     * Find a header value by name, case-insensitively (header names are
     * case-insensitive per RFC 9110 §5.1).
     *
     * @param array<string,string> $headers
     */
    public static function findHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Normalise a headers array: accept both "Name" => "Value" and the
     * "Name: Value" list form.
     *
     * Malformed entries are rejected rather than dropped. Silently discarding
     * them meant a typo'd header simply never reached the wire, with nothing to
     * show for it — and it sat oddly beside {@see assertHeaderIsSafe()}, which
     * throws. Both now fail loudly.
     *
     * Names that differ only in case are ONE header (RFC 9110 §5.1), so they are
     * folded together here — the one place both engines pass through, which is
     * why neither has to know about it. Left unfolded,
     * `['User-Agent' => …, 'user-agent' => …]` reached the wire as two
     * User-Agent lines on both engines: a bot signal in its own right, and the
     * same duplication that was already fixed for a caller header colliding with
     * the browser profile's.
     *
     * @param array<int|string,mixed> $headers
     * @return array<string,string>
     * @throws InvalidArgumentException
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        /** @var array<string,string> $names lower-cased name => the spelling already in use */
        $names = [];

        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                // List form: a single "Header: Value" string.
                if (! is_string($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid header at index %d: list-form entries must be "Name: Value" strings, %s given',
                        $key,
                        get_debug_type($value)
                    ));
                }

                $colonPos = strpos($value, ':');
                $name = $colonPos === false ? '' : trim(substr($value, 0, $colonPos));

                if ($name === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid header "%s": list-form entries must be "Name: Value" with a non-empty name',
                        $value
                    ));
                }

                $value = trim(substr($value, $colonPos + 1));
            } else {
                if (! is_string($value) && ! is_numeric($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid value for header "%s": expected a string or number, %s given',
                        $key,
                        get_debug_type($value)
                    ));
                }

                $name = $key;
                $value = (string) $value;
            }

            // Keep the first spelling's position and casing, let the last value
            // win — precisely what PHP's own array assignment already does when
            // two entries spell the name identically. Position matters: header
            // order is part of the fingerprint (see CurlProcess::collectHeaderLines()).
            $slot = $names[strtolower($name)] ??= $name;
            $normalized[$slot] = $value;
        }

        return $normalized;
    }
}
