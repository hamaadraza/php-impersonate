<?php

namespace Raza\PHPImpersonate;

class Response
{
    /**
     * @param string $body Response body
     * @param int $statusCode HTTP status code
     * @param array<string,string> $headers Response headers
     */
    public function __construct(
        private string $body,
        private int $statusCode,
        private array $headers,
        private array $headersMultiple,
    ) {}

    /**
     * Get the response body
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Parse the response body as JSON
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
     * Get the response status code
     *
     * @return int
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Get all response headers
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get all response headers with multiple values
     *
     * @return array<string,array<string>>
     */
    public function headersMultiple(): array
    {
        return $this->headersMultiple;
    }

    /**
     * Get a specific header value
     *
     * @param string $name Header name (case-insensitive)
     * @param string|null $default Default value if header not found
     * @return string|null
     */
    public function header(string $name, ?string $default = null): ?string
    {
        // Case-insensitive header lookup
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Check if the response was successful (status code 200-299)
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * For backward compatibility with array access
     *
     * @return array{body: string, statusCode: int, headers: array<string,string>}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
        ];
    }

    /**
     * Dump response details for debugging
     *
     * @return string
     */
    public function dump(): string
    {
        $output = "HTTP Status: {$this->statusCode}\n\n";

        // Headers
        $output .= "Headers:\n";
        foreach ($this->headers as $name => $value) {
            $output .= "$name: $value\n";
        }

        // Body preview
        $output .= "\nBody (first 500 chars):\n";
        $output .= substr($this->body, 0, 500);

        if (strlen($this->body) > 500) {
            $output .= "...[truncated]";
        }

        return $output;
    }

    /**
     * Debug response details to output
     *
     * @return self
     */
    public function debug(): self
    {
        echo $this->dump();

        return $this;
    }
}
