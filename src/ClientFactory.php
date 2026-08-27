<?php

namespace Raza\PHPImpersonate;

use InvalidArgumentException;
use Raza\PHPImpersonate\Browser\BrowserInterface;

/**
 * Selects the best available transport behind {@see ClientInterface}.
 *
 * By default it prefers the faster FFI-backed {@see FfiClient} (no process per
 * request, keep-alive connection reuse) and transparently falls back to the
 * executable-based {@see PHPImpersonate} when FFI or the shared library is not
 * available — so callers get a working client either way.
 *
 * @phpstan-import-type BrowserName from PHPImpersonate
 */
final class ClientFactory
{
    public const DRIVER_AUTO = 'auto';
    public const DRIVER_FFI = 'ffi';
    public const DRIVER_PROCESS = 'process';

    /**
     * @param BrowserName|BrowserInterface $browser
     * @param array<string,mixed> $curlOptions Custom curl options (process driver only).
     * @param self::DRIVER_* $driver Which transport to use; 'auto' picks FFI when usable.
     */
    public static function create(
        string|BrowserInterface $browser = 'firefox147',
        int $timeout = 30,
        array $curlOptions = [],
        string $driver = self::DRIVER_AUTO
    ): ClientInterface {
        switch ($driver) {
            case self::DRIVER_FFI:
                return new FfiClient($browser, $timeout);

            case self::DRIVER_PROCESS:
                return new PHPImpersonate($browser, $timeout, $curlOptions);

            case self::DRIVER_AUTO:
                // Custom curl options are only meaningful to the executable path,
                // so keep the process driver whenever any are supplied.
                if ($curlOptions === [] && FfiClient::isAvailable()) {
                    try {
                        return new FfiClient($browser, $timeout);
                    } catch (\Throwable $e) {
                        // Any late FFI failure: fall back to the executable so the
                        // caller always gets a working client.
                    }
                }

                return new PHPImpersonate($browser, $timeout, $curlOptions);

            default:
                throw new InvalidArgumentException(
                    "Unknown driver '$driver'. Use 'auto', 'ffi', or 'process'."
                );
        }
    }

    /**
     * Name of the driver create() would pick under 'auto' right now.
     *
     * @return self::DRIVER_FFI|self::DRIVER_PROCESS
     */
    public static function preferredDriver(array $curlOptions = []): string
    {
        return ($curlOptions === [] && FfiClient::isAvailable())
            ? self::DRIVER_FFI
            : self::DRIVER_PROCESS;
    }
}
