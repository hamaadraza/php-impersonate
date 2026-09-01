<?php

namespace Raza\PHPImpersonate;

class Response
{
    /**
     * @param string $body Response body
     * @param int $statusCode HTTP status code
     * @param array<string, string[]> $headers Response headers — each name maps to a list of values.
     *                                          Multiple values are preserved (e.g. several Set-Cookie lines).
     */
    public function __construct(
        private string $body,
        private int $statusCode,
        private array $headers
    ) {
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
     * @param int $depth Maximum nesting depth
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
     * Dump response details for debugging.
     */
    public function dump(): string
    {
        $output = "HTTP Status: {$this->statusCode}\n\n";

        $output .= "Headers:\n";
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $output .= "$name: $value\n";
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
    public function debug(): self
    {
        echo $this->dump();

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
