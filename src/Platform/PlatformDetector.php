<?php

namespace Raza\PHPImpersonate\Platform;

class PlatformDetector
{
    public const PLATFORM_LINUX = 'linux';
    public const PLATFORM_WINDOWS = 'windows';
    public const PLATFORM_MACOS = 'macos';

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

        if (stripos($os, 'WIN') !== false || stripos($os, 'Windows') !== false) {
            return self::PLATFORM_WINDOWS;
        }

        if (stripos($os, 'Darwin') !== false || stripos($os, 'macOS') !== false) {
            return self::PLATFORM_MACOS;
        }

        // Default to Linux for Unix-like systems
        return self::PLATFORM_LINUX;
    }

    /**
     * Get the current CPU architecture
     */
    public static function getArchitecture(): string
    {
        $machine = php_uname('m');

        return match (true) {
            in_array($machine, ['x86_64', 'amd64', 'x64'], true) => self::ARCH_X86_64,
            in_array($machine, ['aarch64', 'arm64', 'arm64e'], true) => self::ARCH_AARCH64,
            default => self::ARCH_UNKNOWN,
        };
    }

    /**
     * Detect the C library type (glibc vs musl) on Linux
     */
    public static function getLibcType(): string
    {
        if (self::getPlatform() !== self::PLATFORM_LINUX) {
            return self::LIBC_GNU; // Not applicable for non-Linux
        }

        // Method 1: Check if /etc/alpine-release exists (Alpine uses musl)
        if (file_exists('/etc/alpine-release')) {
            return self::LIBC_MUSL;
        }

        // Method 2: Check ldd --version output
        $lddOutput = @shell_exec('ldd --version 2>&1');
        if ($lddOutput !== null) {
            if (stripos($lddOutput, 'musl') !== false) {
                return self::LIBC_MUSL;
            }
            if (stripos($lddOutput, 'GLIBC') !== false || stripos($lddOutput, 'GNU libc') !== false) {
                return self::LIBC_GNU;
            }
        }

        // Method 3: Check if musl-libc is present
        if (file_exists('/lib/ld-musl-x86_64.so.1') || file_exists('/lib/ld-musl-aarch64.so.1')) {
            return self::LIBC_MUSL;
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
     * Get the file extension for the current platform
     */
    public static function getFileExtension(): string
    {
        $platform = self::getPlatform();

        return match ($platform) {
            self::PLATFORM_WINDOWS => '.exe',
            self::PLATFORM_LINUX => '',
            self::PLATFORM_MACOS => '',
            default => '',
        };
    }

    /**
     * Get the command separator for the current platform
     */
    public static function getCommandSeparator(): string
    {
        $platform = self::getPlatform();

        return match ($platform) {
            self::PLATFORM_WINDOWS => '^',
            self::PLATFORM_LINUX => '\\',
            self::PLATFORM_MACOS => '\\',
            default => '\\',
        };
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
     */
    public static function getSupportedArchitectures(): array
    {
        return [self::ARCH_X86_64, self::ARCH_AARCH64];
    }
}
