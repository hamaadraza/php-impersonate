<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Ffi;

use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * Locates the libcurl-impersonate shared library for the FFI client.
 *
 * Resolution order:
 *   1. The PHP_IMPERSONATE_LIB environment variable (explicit path).
 *   2. A library under bin/<platform> — bundled with the package for the
 *      mainstream platforms (Linux x86_64 glibc, macOS ARM64, Windows x86_64),
 *      fetched on demand elsewhere by `bin/php-impersonate-install`.
 *
 * Returns null when neither yields a readable library (e.g. an on-demand
 * platform where the installer has not been run).
 *
 * @internal Not part of the public API.
 */
final class LibResolver
{
    public const ENV_VAR = 'PHP_IMPERSONATE_LIB';

    /** Memoised resolution; false means "not resolved yet". */
    private static string|false|null $resolved = false;

    /**
     * Resolve an existing, readable library path, or null if none is available.
     *
     * Memoised: this runs on every FFI request, and each miss re-stats the
     * candidate paths and re-probes the libc through
     * PlatformDetector::isMusl(), which spawns `ldd`. Uncached it dominated the
     * cost of a request the FFI engine exists to keep process-free.
     */
    public static function resolve(): ?string
    {
        if (self::$resolved !== false) {
            return self::$resolved;
        }

        return self::$resolved = self::locate();
    }

    /**
     * Discard the memoised path, so a library installed (or an env var changed)
     * mid-process is picked up on the next resolve().
     */
    public static function clearCache(): void
    {
        self::$resolved = false;
    }

    private static function locate(): ?string
    {
        $env = getenv(self::ENV_VAR);
        if (is_string($env) && $env !== '' && is_file($env) && is_readable($env)) {
            return $env;
        }

        foreach (self::bundledCandidates() as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Candidate bundled library paths for the current platform.
     *
     * @return list<string>
     */
    private static function bundledCandidates(): array
    {
        $names = self::libraryNames();
        $baseDir = dirname(__DIR__, 2) . '/bin';

        $dirs = [];
        foreach (PlatformDetector::getBinaryDirFallbacks() as $suffix) {
            $dirs[] = $baseDir . '/' . $suffix;
        }

        $candidates = [];
        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                $candidates[] = $dir . '/' . $name;
            }
        }

        return $candidates;
    }

    /**
     * Possible library filenames for the current platform.
     *
     * Windows is included: the engine was POSIX-only while it captured
     * responses through open_memstream, and no longer is now that they come
     * through libcurl's own write callbacks. Its DLL is bundled alongside the
     * Linux and macOS libraries.
     *
     * @return list<string>
     */
    private static function libraryNames(): array
    {
        if (PlatformDetector::isWindows()) {
            return ['libcurl-impersonate.dll'];
        }

        if (PlatformDetector::isMacOS()) {
            return ['libcurl-impersonate.dylib', 'libcurl-impersonate.4.dylib'];
        }

        return ['libcurl-impersonate.so', 'libcurl-impersonate.so.4'];
    }
}
