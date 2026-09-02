<?php

namespace Raza\PHPImpersonate;

class Response
{
    /**
     * @param string $body Response body
     * @param int $statusCode HTTP status code
     * @param array<string, string[]> $headers Response headers — each name maps to a list of values.
     *                                          Multiple values are preserved (e.g. several Set-Cookie lines).
     * @param list<string> $setCookieHeaders Every Set-Cookie value received on ANY response in the
     *                                       redirect chain, in wire order (see {@see setCookieHeaders()}).
     * @param string|null $effectiveUrl The URL the transfer ended on, after redirects
     *                                  (see {@see effectiveUrl()}); null when unknown.
     */
    public function __construct(
        private string $body,
        private int $statusCode,
        private array $headers,
        private array $setCookieHeaders = [],
        private ?string $effectiveUrl = null
    ) {
    }

    /**
     * The URL this response actually came from — the last hop of any redirect
     * chain, or the request URL when nothing redirected.
     *
     * The engines follow redirects, so without this a caller cannot tell where
     * a request ended up: which host answered, whether a login bounced to an
     * error page, or whether a public URL was redirected somewhere internal.
     * It is also what to check when enforcing a URL policy of your own, since
     * the library deliberately applies none (see the README's security notes).
     *
     * Null only for a Response built by hand without one.
     */
    public function effectiveUrl(): ?string
    {
        return $this->effectiveUrl;
    }

    /**
     * Every Set-Cookie value received while making this request, including
     * those on redirect responses, in the order they arrived.
     *
     * {@see headers()} and {@see headerAll()} describe the FINAL response only,
     * and that is the wrong place to look for cookies: a session cookie is
     * routinely set on the 302 after a login and never repeated on the page it
     * redirects to. The engines follow such a cookie for the rest of the
     * redirect chain; this is how the caller gets to keep it for the next
     * request.
     *
     * @return list<string>
     */
    public function setCookieHeaders(): array
    {
        return $this->setCookieHeaders;
    }

    /**
     * Get the response body.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Parse the response body as JSON.
     *
     * @param bool $associative When true, returns array instead of object
     * @param int<1, max> $depth Maximum nesting depth; json_decode() rejects anything below 1
     * @param int $flags JSON decode flags
     * @return mixed
     * @throws \JsonException If JSON decoding fails
     */
    public function json(bool $associative = true, int $depth = 512, int $flags = 0): mixed
    {
        return json_decode($this->body, $associative, $depth, $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the response status code.
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Check if the response was successful (status code 200–299).
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Get all response headers.
     *
     * Each header name maps to a list of values. Most headers will have a single
     * entry, but repeatable headers like Set-Cookie will have one entry per line.
     *
     * @return array<string, string[]>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Check whether a header is present (case-insensitive).
     */
    public function hasHeader(string $name): bool
    {
        foreach ($this->headers as $key => $_) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the first value of a header (case-insensitive).
     *
     * For headers that can appear multiple times (e.g. Set-Cookie) use
     * {@see headerAll()} instead so no values are lost.
     *
     * @param string $name Header name
     * @param string|null $default Returned when the header is absent
     */
    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                // A present-but-empty value list means the same as absent to a
                // caller asking for one value. The parser never produces one,
                // but the constructor is public.
                return $values[0] ?? $default;
            }
        }

        return $default;
    }

    /**
     * Get every value of a header (case-insensitive).
     *
     * Necessary for headers that are legitimately repeated, most commonly
     * Set-Cookie (RFC 6265 §4.1.1 explicitly forbids folding Set-Cookie values
     * into a single comma-separated line).
     *
     * @param string $name Header name
     * @return string[] Empty array when the header is absent
     */
    public function headerAll(string $name): array
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    /**
     * Response headers whose values are masked by {@see dump()}. Set-Cookie is
     * the one that matters in practice — a session cookie is a bearer
     * credential, and dumps are pasted into issues and tickets.
     *
     * @var list<string>
     */
    private const SENSITIVE_HEADERS = [
        'set-cookie',
        'set-cookie2',
        'authorization',
        'proxy-authorization',
        'www-authenticate',
        'proxy-authenticate',
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
    ];

    /**
     * Dump response details for debugging.
     *
     * Values of headers that carry credentials are masked by default: this
     * string is written to a log or pasted into a bug report far more often
     * than it is read once and discarded, and a leaked Set-Cookie is a session.
     * Pass false to see them verbatim.
     *
     * The BODY is never masked — it cannot be, in general — so a dump is still
     * not something to log unconsidered when the response carries a token.
     */
    public function dump(bool $maskCredentials = true): string
    {
        $output = "HTTP Status: {$this->statusCode}\n\n";

        $output .= "Headers:\n";
        foreach ($this->headers as $name => $values) {
            $mask = $maskCredentials && in_array(strtolower($name), self::SENSITIVE_HEADERS, true);

            foreach ($values as $value) {
                $output .= $name . ': ' . ($mask ? '***' : $value) . "\n";
            }
        }

        $output .= "\nBody (first 500 chars):\n";
        $output .= substr($this->body, 0, 500);

        if (strlen($this->body) > 500) {
            $output .= "...[truncated]";
        }

        return $output;
    }

    /**
     * Debug response details to output.
     *
     * @return self
     */
    public function debug(bool $maskCredentials = true): self
    {
        echo $this->dump($maskCredentials);

        return $this;
    }

    /**
     * Serialise the response to a plain array.
     *
     * @return array{body: string, statusCode: int, headers: array<string, string[]>}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
        ];
    }
}
