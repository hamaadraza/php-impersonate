<?php

namespace Raza\PHPImpersonate\Config;

use Raza\PHPImpersonate\Platform\PlatformDetector;

class Configuration
{
    /**
     * Per-platform settings. Only the PATH-lookup command lives here; the other
     * keys this used to carry (file extension, path separator, executable check,
     * temp dir) were never read by anything and are gone.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $platformConfigs = [
        PlatformDetector::PLATFORM_LINUX => [
            'which_command' => 'which',
        ],
        PlatformDetector::PLATFORM_WINDOWS => [
            'which_command' => 'where',
        ],
        PlatformDetector::PLATFORM_MACOS => [
            'which_command' => 'which',
        ],
    ];

    /**
     * Get configuration for the current platform
     */
    public static function getPlatformConfig(): array
    {
        $platform = PlatformDetector::getPlatform();

        return self::$platformConfigs[$platform] ?? self::$platformConfigs[PlatformDetector::PLATFORM_LINUX];
    }

    /**
     * Get a specific configuration value for the current platform
     */
    public static function get(string $key): mixed
    {
        $config = self::getPlatformConfig();

        return $config[$key] ?? null;
    }

    /**
     * Get fallback binary directories for the current platform
     */
    public static function getBinaryDirFallbacks(): array
    {
        return array_map(
            fn ($suffix) => "bin/{$suffix}",
            PlatformDetector::getBinaryDirFallbacks()
        );
    }

    /**
     * Set configuration for a platform
     */
    public static function setPlatformConfig(string $platform, array $config): void
    {
        // Merge onto the platform's own existing config so a partial override
        // keeps that platform's defaults; fall back to Linux only for new platforms
        self::$platformConfigs[$platform] = array_merge(
            self::$platformConfigs[$platform]
                ?? self::$platformConfigs[PlatformDetector::PLATFORM_LINUX]
                ?? [],
            $config
        );
    }
}
