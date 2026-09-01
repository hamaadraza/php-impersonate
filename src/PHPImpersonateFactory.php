<?php

namespace Raza\PHPImpersonate;

use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Backward-compatible aliases for {@see PHPImpersonate}'s static methods.
 *
 * Every method here forwards, unchanged, to its counterpart on PHPImpersonate.
 * It used to repeat their bodies instead, which meant two copies of the same
 * defaults drifting apart unnoticed.
 *
 * @deprecated Call the static methods on {@see PHPImpersonate} directly —
 *             `PHPImpersonate::get(...)` rather than
 *             `PHPImpersonateFactory::get(...)`. This class will be removed in
 *             a future major version.
 *
 * @phpstan-type BrowserName 'chrome99'|'chrome99_android'|'chrome120'|'edge99'|'edge101'|'firefox133'|'firefox135'|'chrome110'|'safari153'|'safari155'|'safari170'|'safari172_ios'|'safari180'|'safari180_ios'|'safari184'|'safari184_ios'|'safari260_ios'|'chrome100'|'chrome101'|'chrome104'|'chrome107'|'chrome116'|'chrome119'|'chrome123'|'chrome124'|'chrome131'|'chrome131_android'|'chrome133a'|'chrome136'|'safari260'|'tor145'|'chrome142'|'chrome145'|'chrome146'|'chrome150'|'firefox144'|'firefox147'|'safari2601'|'okhttp4_android'
 */
class PHPImpersonateFactory
{
    /**
     * Get the response from a URL using GET method
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function get(
        string $url,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::get($url, $headers, $timeout, $browser, $curlOptions, $engine);
    }

    /**
     * Post data to a URL and return response
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the POST request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function post(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::post($url, $data, $headers, $timeout, $browser, $curlOptions, $engine);
    }

    /**
     * Get headers and status code for a URL using HEAD request
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function head(
        string $url,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::head($url, $headers, $timeout, $browser, $curlOptions, $engine);
    }

    /**
     * Delete a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function delete(
        string $url,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::delete($url, $headers, $timeout, $browser, $curlOptions, $engine);
    }

    /**
     * Patch a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the PATCH request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function patch(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::patch($url, $data, $headers, $timeout, $browser, $curlOptions, $engine);
    }

    /**
     * Put a resource at a URL
     *
     * @param string $url The URL to request
     * @param array<string,mixed>|null $data Data to send with the PUT request
     * @param array<string,string> $headers Headers to send with the request
     * @param int $timeout Timeout in seconds
     * @param BrowserName $browser Browser to impersonate (see BrowserName constants)
     * @param array<string,mixed> $curlOptions Custom curl options to add to the request
     * @param PHPImpersonate::ENGINE_* $engine Engine to use ('auto', 'ffi' or 'process')
     * @throws RequestException
     */
    public static function put(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = PHPImpersonate::DEFAULT_TIMEOUT,
        string $browser = PHPImpersonate::DEFAULT_BROWSER,
        array $curlOptions = [],
        string $engine = PHPImpersonate::ENGINE_AUTO
    ): Response {
        return PHPImpersonate::put($url, $data, $headers, $timeout, $browser, $curlOptions, $engine);
    }
}
