<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Support;

use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\Exception\InvalidArgumentException;

/**
 * Transport-independent request preparation: URL/scheme validation, header
 * normalisation, body encoding, and header-injection guards.
 *
 * Both the process-based client (PHPImpersonate) and the FFI-based client
 * (the FFI engine) share this so their security-sensitive handling (CRLF rejection,
 * scheme allow-listing, case-insensitive Content-Type) can never diverge.
 *
 * @internal Not part of the public API.
 */
final class RequestPreparer
{
    /**
     * Validate the request URL: non-empty, well-formed, http/https only.
     *
     * Deliberately NOT filter_var(FILTER_VALIDATE_URL). That filter predates
     * internationalised domains and rejects a good deal of what curl and every
     * browser accept — measured against the bundled binary: an IDN host
     * (`https://münchen.de/` fetches fine, the library refused it), an
     * underscore in a hostname (legal in DNS and common on internal networks),
     * and any non-ASCII character in a path or query. It is also no help for the
     * part that matters, happily passing `ftp://` and `file://`.
     *
     * So the checks here are the ones that earn their place: no control
     * characters, a parseable scheme and host, and an http/https allow-list.
     * Anything else is curl's business — a host that does not resolve is a
     * RequestException naming the real problem, which beats "Invalid URL format"
     * for a URL that was never invalid.
     *
     * @throws InvalidArgumentException
     */
    public static function validateRequest(Request $request): void
    {
        $url = $request->getUrl();

        if (trim($url) === '') {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        // No ASCII control characters or spaces, anywhere. This string becomes
        // the request target, where a CR or an LF could split it into a second
        // request; a raw space would truncate the request line. Callers with such
        // characters in a path or query must percent-encode them, as a browser
        // would. (\x20 is space, \x7F is DEL.)
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new InvalidArgumentException(
                'Invalid URL: spaces and control characters must be percent-encoded'
            );
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new InvalidArgumentException('Invalid URL format: a scheme and a host are required');
        }

        // Checked before the host so that ftp:// and file:// — which parse to no
        // host at all — report the scheme they were rejected for. This is an HTTP
        // client, and handing other schemes to curl invites SSRF surprises.
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Unsupported URL scheme \"$scheme\": only http and https are allowed");
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('Invalid URL format: a scheme and a host are required');
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

        // Media types are case-insensitive (RFC 9110 6.4.1), so match them that
        // way. The `+json` structured-syntax suffix (RFC 6839) counts too:
        // application/vnd.api+json, application/merge-patch+json,
        // application/problem+json and application/ld+json are all JSON, and a
        // literal 'application/json' test sent them as urlencoded form data
        // under a JSON content type — a body the server can only reject.
        if (preg_match('#[/+]json\b#i', $contentType)) {
            try {
                return json_encode($data, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Failed to encode data as JSON: ' . $e->getMessage());
            }
        }

        if (stripos($contentType, 'multipart/form-data') !== false) {
            return self::encodeMultipart($data, $headers, $contentType);
        }

        return http_build_query($data);
    }

    /**
     * Encode $data as a real multipart/form-data body.
     *
     * Asking for multipart used to hand back an http_build_query() string — a
     * urlencoded body wearing a multipart Content-Type, with no boundary at all.
     * Every conforming parser reads that as an empty form (PHP's own included),
     * so the request silently did nothing and nothing said so.
     *
     * The caller's boundary is used when they supplied one; otherwise a random
     * one is generated and written back into their Content-Type header, since a
     * multipart body without a boundary parameter is unparseable.
     *
     * @param array<string,mixed> $data
     * @param array<string,string> $headers Content-Type is updated in place.
     * @throws InvalidArgumentException
     */
    private static function encodeMultipart(array $data, array &$headers, string $contentType): string
    {
        $fields = self::flattenFields($data);
        $boundary = self::boundaryFrom($contentType);

        if ($boundary === null) {
            $boundary = '----PHPImpersonateFormBoundary' . bin2hex(random_bytes(16));
            self::setHeader(
                $headers,
                'Content-Type',
                rtrim(self::withoutBoundary($contentType), "; \t") . '; boundary=' . $boundary
            );
        }

        $body = '';

        foreach ($fields as [$name, $value]) {
            // A value containing the delimiter would terminate the body early and
            // truncate the form. Unreachable with a generated boundary; a caller
            // who supplied their own gets told rather than sending a broken body.
            if (str_contains($value, $boundary)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot encode multipart body: the value of "%s" contains the boundary "%s".',
                    $name,
                    $boundary
                ));
            }

            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . self::escapeFieldName($name) . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * Flatten nested arrays into "parent[child]" field names, and render scalars
     * exactly as the urlencoded path does — nulls dropped, bools as 1/0 — so the
     * two encodings carry the same data rather than differing by content type.
     *
     * @param array<array-key,mixed> $data
     * @return list<array{0: string, 1: string}>
     * @throws InvalidArgumentException
     */
    private static function flattenFields(array $data, string $prefix = ''): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                foreach (self::flattenFields($value, $name) as $nested) {
                    $fields[] = $nested;
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $fields[] = [$name, $value ? '1' : '0'];

                continue;
            }

            if (! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot encode multipart body: the value of "%s" must be a scalar or array, %s given.',
                    $name,
                    get_debug_type($value)
                ));
            }

            $fields[] = [$name, (string) $value];
        }

        return $fields;
    }

    /**
     * Percent-encode the characters that would otherwise break out of the
     * quoted field name and forge a part header — the same substitution browsers
     * make (HTML standard, "multipart/form-data encoding algorithm").
     */
    private static function escapeFieldName(string $name): string
    {
        return str_replace(["\0", "\r", "\n", '"'], ['%00', '%0D', '%0A', '%22'], $name);
    }

    /**
     * Strip any boundary parameter from a Content-Type.
     *
     * Only reached when a declared boundary was unusable — `boundary=""`, which
     * {@see boundaryFrom()} reports as absent. Appending the generated one
     * without removing that left a header carrying TWO boundary parameters
     * (`multipart/form-data; boundary=""; boundary=----PHPImpersonate…`): the
     * body used the generated delimiter, while a server honouring the first
     * parameter — or rejecting the duplicate outright, per RFC 2045 — read the
     * form as empty. rtrim() cannot remove it; the header does not end in one of
     * its trim characters.
     */
    private static function withoutBoundary(string $contentType): string
    {
        return preg_replace('/;\s*boundary\s*=\s*(?:"[^"]*"|[^;]*)/i', '', $contentType) ?? $contentType;
    }

    /**
     * Render one header as the line libcurl expects.
     *
     * An empty value needs the `Name;` form. Handed `Name:` with nothing after
     * the colon, libcurl REMOVES that header rather than sending it empty — so
     * a caller passing '' (an unset env var reaching an API key, say) not only
     * failed to send their own header, they could delete one of the browser
     * profile's: `['Accept-Language' => '']` stripped that line straight out of
     * the emitted fingerprint, since {@see \Raza\PHPImpersonate\Process\CurlProcess::collectHeaderLines()}
     * substitutes a caller header into the profile's slot. `Name;` is libcurl's
     * documented spelling for a header that is genuinely empty.
     */
    public static function headerLine(string $name, string $value): string
    {
        return $value === '' ? "$name;" : "$name: $value";
    }

    /**
     * The boundary already declared in a Content-Type, or null when there is none.
     */
    private static function boundaryFrom(string $contentType): ?string
    {
        if (! preg_match('/;\s*boundary\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $contentType, $m)) {
            return null;
        }

        $boundary = ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));

        return $boundary !== '' ? $boundary : null;
    }

    /**
     * Set a header, replacing any existing spelling of the name in place so the
     * caller's casing and position survive.
     *
     * @param array<string,string> $headers
     */
    private static function setHeader(array &$headers, string $name, string $value): void
    {
        foreach ($headers as $key => $_) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                $headers[$key] = $value;

                return;
            }
        }

        $headers[$name] = $value;
    }

    /**
     * Reject header names/values that could smuggle extra header lines or
     * confuse header parsing (header injection).
     *
     * @throws InvalidArgumentException
     */
    public static function assertHeaderIsSafe(string $name, string $value): void
    {
        // A field name is an RFC 9110 §5.1 token: letters, digits and
        // !#$%&'*+-.^_`|~ — nothing else. Rejecting only CR/LF/NUL and ":" let
        // a name with a space through, and the request went out only for the
        // server to answer 400 (HTTP/2 forbids such a name outright).
        if ($name === '' || ! preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid header "%s": a name must be a non-empty RFC 9110 token '
                . '(letters, digits, and any of !#$%%&\'*+-.^_`|~; no spaces, no ":").',
                $name
            ));
        }

        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid header "%s": the value may not contain CR, LF, or NUL', $name)
            );
        }
    }

    /**
     * Header lines that tell libcurl NOT to add a header it otherwise would —
     * its `Name:` form, with nothing after the colon — for the headers curl
     * generates that no browser sends.
     *
     *  - `Expect: 100-continue`, which curl adds to any HTTP/1.1 request with a
     *    body over a megabyte. Browsers never send it, and it also costs a
     *    round trip (or a one-second wait) before the body goes out.
     *  - `Content-Type: application/x-www-form-urlencoded`, which curl adds to
     *    a POST whose body was given as data — including the EMPTY data a
     *    bodyless POST is sent as. A browser's bodyless POST carries no
     *    Content-Type at all (just `Content-Length: 0`).
     *
     * A caller who sets either header themselves keeps theirs: the caller's
     * headers are checked case-insensitively before a suppression is added.
     *
     * @param array<string,string> $callerHeaders
     * @return list<string>
     */
    public static function implicitHeaderSuppressions(string $method, ?string $body, array $callerHeaders): array
    {
        $lines = [];

        if (self::findHeaderValue($callerHeaders, 'Expect') === null) {
            $lines[] = 'Expect:';
        }

        if (strtoupper($method) === 'POST' && $body === null && self::findHeaderValue($callerHeaders, 'Content-Type') === null) {
            $lines[] = 'Content-Type:';
        }

        return $lines;
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
