<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Browser;

/**
 * Browser name constants for type-safe browser selection
 *
 * Use these constants instead of string literals for better IDE autocomplete and type safety.
 *
 * A profile reproduces the fingerprint of one specific browser release, and a
 * release ages: anti-bot vendors flag versions that have been out of support
 * for years, so a 2022 Chrome is a signal in itself even when its handshake is
 * byte-perfect. Profiles for releases older than 2024 are marked @deprecated
 * below and listed in {@see DEPRECATED}; they still work, and remain for
 * anyone who needs that exact version. Prefer {@see latest()} for a current
 * identity.
 */
class BrowserName
{
    // Chrome browsers
    /** @deprecated Chrome 99 dates from March 2022; use {@see latest()} for a current profile. */
    public const CHROME_99 = 'chrome99';
    /** @deprecated Chrome 99 dates from March 2022; use {@see latest()} for a current profile. */
    public const CHROME_99_ANDROID = 'chrome99_android';
    /** @deprecated Chrome 100 dates from March 2022; use {@see latest()} for a current profile. */
    public const CHROME_100 = 'chrome100';
    /** @deprecated Chrome 101 dates from April 2022; use {@see latest()} for a current profile. */
    public const CHROME_101 = 'chrome101';
    /** @deprecated Chrome 104 dates from August 2022; use {@see latest()} for a current profile. */
    public const CHROME_104 = 'chrome104';
    /** @deprecated Chrome 107 dates from October 2022; use {@see latest()} for a current profile. */
    public const CHROME_107 = 'chrome107';
    /** @deprecated Chrome 110 dates from February 2023; use {@see latest()} for a current profile. */
    public const CHROME_110 = 'chrome110';
    /** @deprecated Chrome 116 dates from August 2023; use {@see latest()} for a current profile. */
    public const CHROME_116 = 'chrome116';
    /** @deprecated Chrome 119 dates from October 2023; use {@see latest()} for a current profile. */
    public const CHROME_119 = 'chrome119';
    /** @deprecated Chrome 120 dates from December 2023; use {@see latest()} for a current profile. */
    public const CHROME_120 = 'chrome120';
    public const CHROME_123 = 'chrome123';
    public const CHROME_124 = 'chrome124';
    public const CHROME_131 = 'chrome131';
    public const CHROME_131_ANDROID = 'chrome131_android';
    public const CHROME_133A = 'chrome133a';
    public const CHROME_136 = 'chrome136';
    public const CHROME_142 = 'chrome142';
    public const CHROME_145 = 'chrome145';
    public const CHROME_146 = 'chrome146';
    public const CHROME_150 = 'chrome150';

    // Edge browsers
    /** @deprecated Edge 99 dates from March 2022; use {@see latest()} for a current profile. */
    public const EDGE_99 = 'edge99';
    /** @deprecated Edge 101 dates from April 2022; use {@see latest()} for a current profile. */
    public const EDGE_101 = 'edge101';

    // Firefox browsers
    public const FIREFOX_133 = 'firefox133';
    public const FIREFOX_135 = 'firefox135';
    public const FIREFOX_144 = 'firefox144';
    public const FIREFOX_147 = 'firefox147';

    // Safari browsers
    /** @deprecated Safari 15.3 dates from January 2022; use {@see latest()} for a current profile. */
    public const SAFARI_153 = 'safari153';
    /** @deprecated Safari 15.5 dates from May 2022; use {@see latest()} for a current profile. */
    public const SAFARI_155 = 'safari155';
    /** @deprecated Safari 17.0 dates from September 2023; use {@see latest()} for a current profile. */
    public const SAFARI_170 = 'safari170';
    /** @deprecated iOS Safari 17.2 dates from December 2023; use {@see latest()} for a current profile. */
    public const SAFARI_172_IOS = 'safari172_ios';
    public const SAFARI_180 = 'safari180';
    public const SAFARI_180_IOS = 'safari180_ios';
    public const SAFARI_184 = 'safari184';
    public const SAFARI_184_IOS = 'safari184_ios';
    public const SAFARI_260 = 'safari260';
    public const SAFARI_260_IOS = 'safari260_ios';
    public const SAFARI_2601 = 'safari2601';

    // Tor browser
    public const TOR_145 = 'tor145';

    // Added by scripts/update-browsers.php
    public const OKHTTP_4_ANDROID = 'okhttp4_android';

    /**
     * Profiles of browser releases older than 2024. They work exactly as they
     * always did; they are simply no longer a plausible identity for a live
     * client, and are excluded from {@see latest()}.
     *
     * @var list<string>
     */
    public const DEPRECATED = [
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
        self::EDGE_99,
        self::EDGE_101,
        self::SAFARI_153,
        self::SAFARI_155,
        self::SAFARI_170,
        self::SAFARI_172_IOS,
    ];

    /**
     * Whether a profile is one of the {@see DEPRECATED} old releases.
     */
    public static function isDeprecated(string $name): bool
    {
        return in_array($name, self::DEPRECATED, true);
    }

    /**
     * The newest non-deprecated profile of a family.
     *
     * A family is a browser optionally qualified by platform, exactly as the
     * names are spelled: `chrome`, `chrome_android`, `firefox`, `safari`,
     * `safari_ios`, `edge`, `tor`, `okhttp_android`. So `latest('chrome')` is
     * the newest desktop Chrome and `latest('safari_ios')` the newest iOS
     * Safari; a desktop family never answers with a mobile profile.
     *
     * Pin the result if you need the same identity across releases of this
     * package: it moves whenever a newer profile is added.
     *
     * @throws \Raza\PHPImpersonate\Exception\InvalidArgumentException If no profile matches the family.
     */
    public static function latest(string $family): string
    {
        $family = strtolower($family);
        $suffix = '';

        if (($underscore = strpos($family, '_')) !== false) {
            $suffix = substr($family, $underscore); // e.g. "_ios"
            $family = substr($family, 0, $underscore);
        }

        $best = null;
        $bestVersion = -1;

        foreach (self::getAll() as $name) {
            if (self::isDeprecated($name)) {
                continue;
            }

            // "<family><digits><optional letters><suffix>" — the letters cover
            // chrome133a; the digits are the version as upstream spells it
            // (safari2601 is 26.0.1 and does sort after safari260).
            if (! preg_match('/^' . preg_quote($family, '/') . '(\d+)[a-z]*' . preg_quote($suffix, '/') . '$/', $name, $m)) {
                continue;
            }

            $version = (int) $m[1];
            if ($version > $bestVersion) {
                $bestVersion = $version;
                $best = $name;
            }
        }

        if ($best === null) {
            throw new \Raza\PHPImpersonate\Exception\InvalidArgumentException(sprintf(
                "No current profile for browser family '%s%s'. Families: chrome, chrome_android, firefox, "
                . 'safari, safari_ios, edge, tor, okhttp_android.',
                $family,
                $suffix
            ));
        }

        return $best;
    }

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
            self::CHROME_142,
            self::CHROME_145,
            self::CHROME_146,
            self::CHROME_150,
            self::EDGE_99,
            self::EDGE_101,
            self::FIREFOX_133,
            self::FIREFOX_135,
            self::FIREFOX_144,
            self::FIREFOX_147,
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
            self::SAFARI_2601,
            self::TOR_145,
            self::OKHTTP_4_ANDROID,
        ];
    }
}
