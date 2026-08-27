<?php

namespace Raza\PHPImpersonate;

use InvalidArgumentException;
use Raza\PHPImpersonate\Ffi\LibResolver;
use Raza\PHPImpersonate\Ffi\CurlImpersonate;
use Raza\PHPImpersonate\Support\RequestPreparer;
use Raza\PHPImpersonate\Browser\BrowserInterface;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Support\ResponseHeaderParser;

/**
 * ClientInterface implementation backed by libcurl-impersonate through PHP FFI.
 *
 * Compared to the executable-based {@see PHPImpersonate}, this spawns no
 * process and reuses keep-alive connections across requests on the same client,
 * so it is markedly faster for many requests. It requires the FFI extension to
 * be usable and the libcurl-impersonate shared library to be present (see
 * {@see LibResolver}); use {@see isAvailable()} to detect that, and prefer
 * {@see ClientFactory::create()} which falls back to PHPImpersonate otherwise.
 *
 * @phpstan-import-type BrowserName from PHPImpersonate
 */
class FfiClient implements ClientInterface
{
    private const DEFAULT_BROWSER = 'firefox147';
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_TIMEOUT = 3600;
    private const MIN_TIMEOUT = 1;

    private string $browser;
    private CurlImpersonate $engine;

    /** Cached result of the one-time real load probe. */
    private static ?bool $availabilityProbe = null;

    /**
     * @param BrowserName|BrowserInterface $browser Browser to impersonate (name or instance).
     * @param int $timeout Request timeout in seconds.
     * @param array<string,mixed> $curlOptions Supported curl options (e.g. 'proxy', 'proxy-user').
     *                                          See {@see supportedCurlOptions()}.
     * @param string|null $libPath Explicit libcurl-impersonate path (defaults to auto-resolution).
     * @throws RequestException If FFI or the library is unavailable, or the handle cannot be created.
     * @throws InvalidArgumentException If the timeout or a curl option is invalid.
     */
    public function __construct(
        string|BrowserInterface $browser = self::DEFAULT_BROWSER,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private array $curlOptions = [],
        ?string $libPath = null
    ) {
        $this->validateTimeout($timeout);
        $this->validateCurlOptions($curlOptions);
        $this->browser = $browser instanceof BrowserInterface ? $browser->getName() : $browser;

        if (! CurlImpersonate::isSupported()) {
            throw new RequestException('FFI is not available in this environment (ext-ffi disabled).');
        }

        $resolved = $libPath ?? LibResolver::resolve();
        if ($resolved === null) {
            throw new RequestException(
                'libcurl-impersonate shared library not found. It ships with this '
                . 'package under bin/<platform>/; if it is missing, reinstall or '
                . 'set the ' . LibResolver::ENV_VAR . ' environment variable to a library path.'
            );
        }

        try {
            $this->engine = new CurlImpersonate($resolved);
        } catch (\Throwable $e) {
            throw new RequestException('Failed to load libcurl-impersonate via FFI: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The curl option names this transport supports (e.g. 'proxy', 'proxy-user').
     * Other raw curl options are only available on the executable transport.
     *
     * @return list<string>
     */
    public static function supportedCurlOptions(): array
    {
        return CurlImpersonate::supportedOptionKeys();
    }

    /**
     * Whether an FFI-backed client can actually be used right now: FFI usable in
     * this SAPI, the shared library resolvable, and — crucially — the library
     * actually loadable via FFI on this platform. The load is probed once and
     * cached, so a library that cannot load (e.g. an ABI mismatch) makes this
     * return false and callers such as {@see ClientFactory} fall back cleanly
     * rather than crashing. Safe and cheap to call for driver selection.
     */
    public static function isAvailable(): bool
    {
        if (self::$availabilityProbe !== null) {
            return self::$availabilityProbe;
        }

        if (! CurlImpersonate::isSupported()) {
            return self::$availabilityProbe = false;
        }

        $lib = LibResolver::resolve();
        if ($lib === null) {
            return self::$availabilityProbe = false;
        }

        try {
            // Constructing the engine performs FFI::cdef + curl_easy_init; if the
            // library is incompatible this throws and we report unavailable.
            new CurlImpersonate($lib);
            self::$availabilityProbe = true;
        } catch (\Throwable $e) {
            self::$availabilityProbe = false;
        }

        return self::$availabilityProbe;
    }

    /**
     * @inheritDoc
     */
    public function send(Request $request): Response
    {
        RequestPreparer::validateRequest($request);

        $headers = RequestPreparer::normalizeHeaders($request->getHeaders());
        foreach ($headers as $name => $value) {
            RequestPreparer::assertHeaderIsSafe((string) $name, (string) $value);
        }

        $result = $this->engine->request(
            $request->getMethod(),
            $request->getUrl(),
            $headers,
            $request->getBody(),
            $this->browser,
            $this->timeout,
            $this->curlOptions
        );

        $isHead = strtoupper($request->getMethod()) === 'HEAD';

        return new Response(
            $isHead ? '' : $result['body'],
            $result['status'],
            ResponseHeaderParser::parse($result['headers'])
        );
    }

    /**
     * @inheritDoc
     */
    public function sendGet(string $url, array $headers = []): Response
    {
        return $this->send(Request::get($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendPost(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers);

        return $this->send(Request::post($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendHead(string $url, array $headers = []): Response
    {
        return $this->send(Request::head($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendDelete(string $url, array $headers = []): Response
    {
        return $this->send(Request::delete($url, $headers));
    }

    /**
     * @inheritDoc
     */
    public function sendPatch(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers, 'application/json');

        return $this->send(Request::patch($url, $headers, $body));
    }

    /**
     * @inheritDoc
     */
    public function sendPut(string $url, ?array $data = null, array $headers = []): Response
    {
        $headers = RequestPreparer::normalizeHeaders($headers);
        $body = RequestPreparer::prepareBody($data, $headers, 'application/json');

        return $this->send(Request::put($url, $headers, $body));
    }

    private function validateTimeout(int $timeout): void
    {
        if ($timeout < self::MIN_TIMEOUT || $timeout > self::MAX_TIMEOUT) {
            throw new InvalidArgumentException(
                sprintf('Timeout must be between %d and %d seconds, got %d', self::MIN_TIMEOUT, self::MAX_TIMEOUT, $timeout)
            );
        }
    }

    /**
     * @param array<string,mixed> $curlOptions
     * @throws InvalidArgumentException on any option the FFI transport can't apply.
     */
    private function validateCurlOptions(array $curlOptions): void
    {
        $supported = CurlImpersonate::supportedOptionKeys();
        $unsupported = array_diff(array_keys($curlOptions), $supported);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'The FFI transport does not support the curl option(s): %s. '
                . 'Supported: %s. Use the executable transport (PHPImpersonate) for other options.',
                implode(', ', $unsupported),
                implode(', ', $supported)
            ));
        }
    }
}
