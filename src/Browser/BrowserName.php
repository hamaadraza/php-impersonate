<?php

namespace Raza\PHPImpersonate\Browser;

/**
 * Browser name constants for type-safe browser selection
 *
 * Use these constants instead of string literals for better IDE autocomplete and type safety.
 *
 * @phpstan-type BrowserName 'chrome99'|'chrome99_android'|'chrome100'|'chrome101'|'chrome104'|'chrome107'|'chrome110'|'chrome116'|'chrome119'|'chrome120'|'chrome123'|'chrome124'|'chrome131'|'chrome131_android'|'chrome133a'|'chrome136'|'edge99'|'edge101'|'firefox133'|'firefox135'|'safari153'|'safari155'|'safari170'|'safari172_ios'|'safari180'|'safari180_ios'|'safari184'|'safari184_ios'|'safari260'|'safari260_ios'|'tor145'
 */
class BrowserName
{
    // Chrome browsers
    public const CHROME_99 = 'chrome99';
    public const CHROME_99_ANDROID = 'chrome99_android';
    public const CHROME_100 = 'chrome100';
    public const CHROME_101 = 'chrome101';
    public const CHROME_104 = 'chrome104';
    public const CHROME_107 = 'chrome107';
    public const CHROME_110 = 'chrome110';
    public const CHROME_116 = 'chrome116';
    public const CHROME_119 = 'chrome119';
    public const CHROME_120 = 'chrome120';
    public const CHROME_123 = 'chrome123';
    public const CHROME_124 = 'chrome124';
    public const CHROME_131 = 'chrome131';
    public const CHROME_131_ANDROID = 'chrome131_android';
    public const CHROME_133A = 'chrome133a';
    public const CHROME_136 = 'chrome136';

    // Edge browsers
    public const EDGE_99 = 'edge99';
    public const EDGE_101 = 'edge101';

    // Firefox browsers
    public const FIREFOX_133 = 'firefox133';
    public const FIREFOX_135 = 'firefox135';

    // Safari browsers
    public const SAFARI_153 = 'safari153';
    public const SAFARI_155 = 'safari155';
    public const SAFARI_170 = 'safari170';
    public const SAFARI_172_IOS = 'safari172_ios';
    public const SAFARI_180 = 'safari180';
    public const SAFARI_180_IOS = 'safari180_ios';
    public const SAFARI_184 = 'safari184';
    public const SAFARI_184_IOS = 'safari184_ios';
    public const SAFARI_260 = 'safari260';
    public const SAFARI_260_IOS = 'safari260_ios';

    // Tor browser
    public const TOR_145 = 'tor145';

    /**
     * Get all available browser name constants
     *
     * @return array<string>
     */
    public static function getAll(): array
    {
        return [
            self::CHROME_99,
            self::CHROME_99_ANDROID,
            self::CHROME_100,
            self::CHROME_101,
            self::CHROME_104,
            self::CHROME_107,
            self::CHROME_110,
            self::CHROME_116,
            self::CHROME_119,
            self::CHROME_120,
            self::CHROME_123,
            self::CHROME_124,
            self::CHROME_131,
            self::CHROME_131_ANDROID,
            self::CHROME_133A,
            self::CHROME_136,
            self::EDGE_99,
            self::EDGE_101,
            self::FIREFOX_133,
            self::FIREFOX_135,
            self::SAFARI_153,
            self::SAFARI_155,
            self::SAFARI_170,
            self::SAFARI_172_IOS,
            self::SAFARI_180,
            self::SAFARI_180_IOS,
            self::SAFARI_184,
            self::SAFARI_184_IOS,
            self::SAFARI_260,
            self::SAFARI_260_IOS,
            self::TOR_145,
        ];
    }
}
