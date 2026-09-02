<?php

namespace Raza\PHPImpersonate\Platform;

class PlatformDetector
{
    public const PLATFORM_LINUX = 'linux';
    public const PLATFORM_WINDOWS = 'windows';
    public const PLATFORM_MACOS = 'macos';
    public const PLATFORM_UNKNOWN = 'unknown';

    public const ARCH_X86_64 = 'x86_64';
    public const ARCH_AARCH64 = 'aarch64';
    public const ARCH_UNKNOWN = 'unknown';

    public const LIBC_GNU = 'gnu';
    public const LIBC_MUSL = 'musl';

    /**
     * Get the current platform
     */
    public static function getPlatform(): string
    {
        $os = PHP_OS;

        // Check Darwin/macOS FIRST - "Darwin" contains "win" which would match Windows check
        if (stripos($os, 'Darwin') !== false) {
            return self::PLATFORM_MACOS;
        }

        // Check for Windows (WINNT, WIN32, Windows)
        if (stripos($os, 'WIN') === 0 || stripos($os, 'Windows') !== false) {
            return self::PLATFORM_WINDOWS;
        }

        if (stripos($os, 'Linux') !== false) {
            return self::PLATFORM_LINUX;
        }

        // Anything else is genuinely unknown, and saying "linux" was worse than
        // saying nothing: FreeBSD, SunOS and Cygwin (which contains "WIN", but
        // not at offset 0) all reported Linux, so isSupported() answered true
        // and binary resolution went looking for a glibc Linux ELF that cannot
        // execute on any of them — surfacing later as a confusing
        // "not found for linux-x86_64", or an exec-format error.
        return self::PLATFORM_UNKNOWN;
    }

    /**
     * Get the current CPU architecture
     */
    public static function getArchitecture(): string
    {
        $machine = strtolower(php_uname('m'));

        return match (true) {
            in_array($machine, ['x86_64', 'amd64', 'x64'], true) => self::ARCH_X86_64,
            in_array($machine, ['aarch64', 'arm64', 'arm64e'], true) => self::ARCH_AARCH64,
            default => self::ARCH_UNKNOWN,
        };
    }

    /**
     * Memoised result of {@see getLibcType()}; the probe shells out to `ldd`.
     */
    private static ?string $libcType = null;

    /**
     * Detect the C library type (glibc vs musl) on Linux.
     *
     * Memoised: the libc cannot change within a process, and the probe spawns a
     * shell. Callers reach it on hot paths — every LibResolver::resolve() goes
     * through getBinaryDirFallbacks() -> isMusl() -> here.
     */
    public static function getLibcType(): string
    {
        return self::$libcType ??= self::detectLibcType();
    }

    private static function detectLibcType(): string
    {
        if (self::getPlatform() !== self::PLATFORM_LINUX) {
            return self::LIBC_GNU; // Not applicable for non-Linux
        }

        // Method 1: Check if /etc/alpine-release exists (Alpine uses musl)
        if (file_exists('/etc/alpine-release')) {
            return self::LIBC_MUSL;
        }

        // Method 2: the dynamic loader itself, which needs no subprocess. This
        // runs once per PHP process — and under PHP-FPM that is once per web
        // request, where the `ldd` probe below cost a shell plus a process on
        // every request that reached this library, for both engines. The
        // loader path is the definitive answer on every mainstream distro, so
        // `ldd` is only a last resort for an unfamiliar layout.
        foreach (['/lib/ld-musl-x86_64.so.1', '/lib/ld-musl-aarch64.so.1'] as $muslLoader) {
            if (file_exists($muslLoader)) {
                return self::LIBC_MUSL;
            }
        }
        foreach (['/lib64/ld-linux-x86-64.so.2', '/lib/ld-linux-aarch64.so.1', '/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2'] as $gnuLoader) {
            if (file_exists($gnuLoader)) {
                return self::LIBC_GNU;
            }
        }

        // Method 3: Check ldd --version output.
        // shell_exec() returns string|false|null — false when the process could
        // not be spawned at all. The null check alone let `false` through to
        // stripos(), where it silently coerced to '' and every probe below
        // quietly missed.
        //
        // function_exists() first: since PHP 8.0 a name in disable_functions
        // behaves as UNDEFINED, so calling it throws an Error that `@` does not
        // suppress. shell_exec is among the first things hardened hosts disable,
        // and such a host is exactly where the FFI engine — which needs no
        // subprocess at all — should still work. Method 3 below reaches the same
        // answer from the filesystem.
        $lddOutput = function_exists('shell_exec') ? @shell_exec('ldd --version 2>&1') : null;
        if (is_string($lddOutput)) {
            if (stripos($lddOutput, 'musl') !== false) {
                return self::LIBC_MUSL;
            }
            if (stripos($lddOutput, 'GLIBC') !== false || stripos($lddOutput, 'GNU libc') !== false) {
                return self::LIBC_GNU;
            }
        }

        // Default to GNU libc
        return self::LIBC_GNU;
    }

    /**
     * Check if running on musl-based Linux (e.g., Alpine)
     */
    public static function isMusl(): bool
    {
        return self::getPlatform() === self::PLATFORM_LINUX && self::getLibcType() === self::LIBC_MUSL;
    }

    public static function isWindows(): bool
    {
        return self::getPlatform() === self::PLATFORM_WINDOWS;
    }

    public static function isLinux(): bool
    {
        return self::getPlatform() === self::PLATFORM_LINUX;
    }

    public static function isMacOS(): bool
    {
        return self::getPlatform() === self::PLATFORM_MACOS;
    }

    /**
     * Check if the current platform is supported
     */
    public static function isSupported(): bool
    {
        $platform = self::getPlatform();
        $arch = self::getArchitecture();

        // Check platform support
        if (! in_array($platform, [self::PLATFORM_LINUX, self::PLATFORM_WINDOWS, self::PLATFORM_MACOS], true)) {
            return false;
        }

        // Check architecture support
        if ($arch === self::ARCH_UNKNOWN) {
            return false;
        }

        return true;
    }

    /**
     * Get the binary directory suffix for the current platform and architecture
     * Returns something like: linux-x86_64, linux-aarch64-musl, macos-aarch64, etc.
     */
    public static function getBinaryDirSuffix(): string
    {
        $platform = self::getPlatform();
        $arch = self::getArchitecture();

        $suffix = "{$platform}-{$arch}";

        // Add musl suffix for musl-based Linux systems
        if ($platform === self::PLATFORM_LINUX && self::isMusl()) {
            $suffix .= '-musl';
        }

        return $suffix;
    }

    /**
     * Get the binary directory for the current platform
     */
    public static function getBinaryDir(): string
    {
        return "bin/" . self::getBinaryDirSuffix();
    }

    /**
     * Get fallback binary directories to check (for backwards compatibility)
     * Returns an array of directory suffixes to try, in order of preference
     *
     * @return list<string>
     */
    public static function getBinaryDirFallbacks(): array
    {
        $platform = self::getPlatform();
        $arch = self::getArchitecture();

        $fallbacks = [];

        // Primary: platform-arch[-musl]
        $fallbacks[] = self::getBinaryDirSuffix();

        // If on musl, also try the non-musl version as fallback
        if ($platform === self::PLATFORM_LINUX && self::isMusl()) {
            $fallbacks[] = "{$platform}-{$arch}";
        }

        // Legacy fallback: just platform name (bin/linux, bin/windows, bin/macos)
        $fallbacks[] = $platform;

        return $fallbacks;
    }

    /**
     * Get a human-readable description of the current platform
     */
    public static function getPlatformDescription(): string
    {
        $platform = self::getPlatform();
        $arch = self::getArchitecture();
        $libc = $platform === self::PLATFORM_LINUX ? ' (' . self::getLibcType() . ')' : '';

        return "{$platform}-{$arch}{$libc}";
    }

    /**
     * Get supported architectures
     *
     * @return list<string>
     */
    public static function getSupportedArchitectures(): array
    {
        return [self::ARCH_X86_64, self::ARCH_AARCH64];
    }
}
