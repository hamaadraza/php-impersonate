<?php

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
 */
final class LibResolver
{
    public const ENV_VAR = 'PHP_IMPERSONATE_LIB';

    /**
     * Resolve an existing, readable library path, or null if none is available.
     */
    public static function resolve(): ?string
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
     * @return list<string>
     */
    private static function libraryNames(): array
    {
        if (PlatformDetector::isWindows()) {
            return ['libcurl-impersonate.dll', 'libcurl.dll'];
        }
        if (PlatformDetector::isMacOS()) {
            return ['libcurl-impersonate.dylib', 'libcurl-impersonate.4.dylib'];
        }

        return ['libcurl-impersonate.so', 'libcurl-impersonate.so.4'];
    }
}
