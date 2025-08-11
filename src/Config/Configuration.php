<?php

namespace Raza\PHPImpersonate\Config;

use Raza\PHPImpersonate\Platform\PlatformDetector;

class Configuration
{
    private static array $platformConfigs = [
        PlatformDetector::PLATFORM_LINUX => [
            'binary_dir' => 'bin/linux',
            'file_extension' => '',
            'command_separator' => '\\',
            'path_separator' => '/',
            'executable_check' => 'is_executable',
            'which_command' => 'which',
            'temp_dir' => null, // Use system default
        ],
        PlatformDetector::PLATFORM_WINDOWS => [
            'binary_dir' => 'bin/windows',
            'file_extension' => '.bat',
            'command_separator' => '^',
            'path_separator' => '\\',
            'executable_check' => 'file_exists', // Windows doesn't have is_executable
            'which_command' => 'where',
            'temp_dir' => null, // Use system default
        ],
        PlatformDetector::PLATFORM_MACOS => [
            'binary_dir' => 'bin/macos',
            'file_extension' => '',
            'command_separator' => '\\',
            'path_separator' => '/',
            'executable_check' => 'is_executable',
            'which_command' => 'which',
            'temp_dir' => null, // Use system default
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
    public static function get(string $key)
    {
        $config = self::getPlatformConfig();

        return $config[$key] ?? null;
    }

    /**
     * Set configuration for a platform
     */
    public static function setPlatformConfig(string $platform, array $config): void
    {
        self::$platformConfigs[$platform] = array_merge(
            self::$platformConfigs[PlatformDetector::PLATFORM_LINUX] ?? [],
            $config
        );
    }

    /**
     * Get all supported platforms
     */
    public static function getSupportedPlatforms(): array
    {
        return array_keys(self::$platformConfigs);
    }

    /**
     * Check if a platform has configuration
     */
    public static function hasPlatformConfig(string $platform): bool
    {
        return isset(self::$platformConfigs[$platform]);
    }
}
