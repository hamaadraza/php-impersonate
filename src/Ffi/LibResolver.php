<?php

namespace Raza\PHPImpersonate\Ffi;

use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * Locates the libcurl-impersonate shared library for the FFI client.
 *
 * Resolution order:
 *   1. The PHP_IMPERSONATE_LIB environment variable (explicit path).
 *   2. A library bundled under bin/<platform> (e.g. fetched by
 *      `composer update-binaries -- --libs`).
 *
 * The shared library is large (~15-25 MB per platform) and is NOT committed to
 * the package by default, so this returns null unless one has been provisioned.
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
