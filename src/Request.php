<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate;

use Raza\PHPImpersonate\Exception\InvalidArgumentException;

class Request
{
    private string $method;

    /**
     * @param string $method HTTP method; normalised to upper case
     * @param string $url The URL to request
     * @param array<string,string> $headers Request headers
     * @param string|null $body Request body content
     * @throws InvalidArgumentException If the method is empty or contains
     *                                  anything but token characters.
     */
    public function __construct(
        string $method,
        private string $url,
        private array $headers = [],
        private ?string $body = null
    ) {
        // The verb is the third thing that reaches the wire verbatim, alongside
        // the URL and the headers — both of which are already guarded against
        // control characters. It was not, and the bundled curl does not reject
        // them either: a method of "GET\r\nX-Injected: evil" split the request
        // line in two. Restrict it to an RFC 9110 §5.6.2 token, which is all a
        // method may be, rather than only excluding CR/LF/NUL.
        if ($method === '' || preg_match('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', $method)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP method "%s": a method must be a non-empty RFC 9110 token '
                . '(letters, digits, and any of !#$%%&\'*+-.^_`|~).',
                $method
            ));
        }

        // Normalise here, at the one boundary every request passes through, so
        // no engine has to remember to. The FFI engine already uppercased before
        // CURLOPT_CUSTOMREQUEST while the executable engine passed -X through
        // verbatim, so `new Request('get', …)` reached the server as GET on one
        // engine and as `get` — a 400 from most servers — on the other.
        $this->method = strtoupper($method);
    }

    /**
     * Get the request method (upper case)
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the request URL
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the request headers
     *
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the request body
     *
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Create a new request with the given headers
     *
     * @param array<string,string> $headers
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /**
     * Create a new request with the given body
     *
     * @param string|null $body
     * @return self
     */
    public function withBody(?string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /**
     * Create a GET request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @return self
     */
    public static function get(string $url, array $headers = []): self
    {
        return new self('GET', $url, $headers);
    }

    /**
     * Create a POST request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @param string|null $body
     * @return self
     */
    public static function post(string $url, array $headers = [], ?string $body = null): self
    {
        return new self('POST', $url, $headers, $body);
    }

    /**
     * Create a HEAD request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @return self
     */
    public static function head(string $url, array $headers = []): self
    {
        return new self('HEAD', $url, $headers);
    }

    /**
     * Create a DELETE request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @return self
     */
    public static function delete(string $url, array $headers = []): self
    {
        return new self('DELETE', $url, $headers);
    }

    /**
     * Create a PATCH request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @param string|null $body
     * @return self
     */
    public static function patch(string $url, array $headers = [], ?string $body = null): self
    {
        return new self('PATCH', $url, $headers, $body);
    }

    /**
     * Create a PUT request
     *
     * @param string $url
     * @param array<string,string> $headers
     * @param string|null $body
     * @return self
     */
    public static function put(string $url, array $headers = [], ?string $body = null): self
    {
        return new self('PUT', $url, $headers, $body);
    }
}
