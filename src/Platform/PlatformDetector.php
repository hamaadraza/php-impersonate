<?php

namespace Raza\PHPImpersonate\Platform;

class PlatformDetector
{
    public const PLATFORM_LINUX = 'linux';
    public const PLATFORM_WINDOWS = 'windows';
    public const PLATFORM_MACOS = 'macos';

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

    public static function isWindows(): bool
    {
        return self::getPlatform() === self::PLATFORM_WINDOWS;
    }

    public static function isLinux(): bool
    {
        return self::getPlatform() === self::PLATFORM_LINUX;
    }

    /**
     * Check if the current platform is supported
     */
    public static function isSupported(): bool
    {
        $platform = self::getPlatform();

        return in_array($platform, [self::PLATFORM_LINUX, self::PLATFORM_WINDOWS]);
    }

    /**
     * Get the binary directory for the current platform
     */
    public static function getBinaryDir(): string
    {
        $platform = self::getPlatform();

        return "bin/{$platform}";
    }

    /**
     * Get the file extension for the current platform
     */
    public static function getFileExtension(): string
    {
        $platform = self::getPlatform();

        return match ($platform) {
            self::PLATFORM_WINDOWS => '.bat',
            self::PLATFORM_LINUX => '',
            self::PLATFORM_MACOS => '', // Future support
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
            self::PLATFORM_MACOS => '\\', // Future support
            default => '\\',
        };
    }
}
