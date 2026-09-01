<?php

namespace Raza\PHPImpersonate;

use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Exception\InvalidArgumentException;

/**
 * Every exception below also implements
 * {@see \Raza\PHPImpersonate\Exception\PHPImpersonateException}, so a caller
 * wanting one catch for anything this library raises can use that instead of
 * listing both — `catch (RequestException)` alone misses the argument errors,
 * which are LogicExceptions and share no built-in parent with it.
 */
interface ClientInterface
{
    /**
     * Send a request and get a response
     *
     * @param Request $request The request to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function send(Request $request): Response;

    /**
     * Send a GET request
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendGet(string $url, array $headers = []): Response;

    /**
     * Send a POST request
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendPost(string $url, ?array $data = null, array $headers = []): Response;

    /**
     * Send a HEAD request
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendHead(string $url, array $headers = []): Response;

    /**
     * Send a DELETE request
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendDelete(string $url, array $headers = []): Response;

    /**
     * Send a PATCH request
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendPatch(string $url, ?array $data = null, array $headers = []): Response;

    /**
     * Send a PUT request
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send
     * @param array<string,string> $headers Headers to send
     * @return Response
     * @throws RequestException On a transport failure (DNS, TLS, timeout, broken transfer).
     * @throws InvalidArgumentException If the URL, headers, or data are unusable.
     */
    public function sendPut(string $url, ?array $data = null, array $headers = []): Response;
}
