<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Config;

use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * Process-wide, publicly mutable platform settings.
 *
 * @deprecated Its one remaining consumer is the `which_command` fallback in
 *             {@see \Raza\PHPImpersonate\Browser\Browser}, which is only reached
 *             when a native PATH scan finds nothing. Global mutable state of
 *             this kind will be removed in the next major version; nothing in
 *             an application needs to call it.
 */
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
     *
     * @return array<string,mixed>
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
     *
     * @return list<string>
     */
    public static function getBinaryDirFallbacks(): array
    {
        return array_map(
            fn ($suffix) => "bin/{$suffix}",
            PlatformDetector::getBinaryDirFallbacks()
        );
    }

    /**
     * The built-in defaults, kept so {@see reset()} can actually restore them.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $defaults = null;

    /**
     * Restore the built-in per-platform configuration, discarding every
     * override — including whole platforms added at runtime.
     *
     * {@see setPlatformConfig()} MERGES, so it can overwrite a key but never
     * remove one: a test that saved the config and set it back could not undo
     * an added key, and never touched a platform entry that had not existed
     * before. With the suite running in random order that made any assertion
     * about the exact shape of getPlatformConfig() depend on what ran first.
     */
    public static function reset(): void
    {
        if (self::$defaults !== null) {
            self::$platformConfigs = self::$defaults;
        }
    }

    /**
     * Set configuration for a platform
     *
     * @param array<string,mixed> $config
     */
    public static function setPlatformConfig(string $platform, array $config): void
    {
        // Snapshot the pristine defaults the first time anything overrides them.
        self::$defaults ??= self::$platformConfigs;

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
