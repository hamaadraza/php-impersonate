<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Browser\BrowserConfig;
use Raza\PHPImpersonate\Scripts\UpstreamPatch;
use Raza\PHPImpersonate\Scripts\ConfigGenerator;

/**
 * BrowserConfig must equal what the generator produces from the upstream
 * curl.patch of the release the bundled binaries were built from.
 *
 * scripts/update-browsers.php is append-only: a profile already in the file is
 * never touched again. That is how firefox133/135 kept a TLS record_size_limit
 * of 4001 (0x4001 pasted as decimal; upstream says 16385), chrome116 kept an
 * ECH extension Chrome 116 never had, safari2601 lacked no-tls-session-ticket,
 * safari170 lacked its Sec-Fetch headers and edge101 carried a different build
 * number — for as long as the file existed. The executable engine no longer
 * renders a built-in profile from this data, but BrowserConfig is public API
 * and drives custom profiles, so it must still be right. Offline: the fixture
 * is the upstream patch for bin/VERSION, gzipped.
 */
class BrowserConfigUpstreamTest extends TestCase
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $upstream = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/lib/Http.php';
        require_once __DIR__ . '/../scripts/lib/UpstreamPatch.php';
        require_once __DIR__ . '/../scripts/lib/ConfigGenerator.php';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function upstream(): array
    {
        if (self::$upstream !== null) {
            return self::$upstream;
        }

        $version = trim((string) file_get_contents(__DIR__ . '/../bin/VERSION'));
        $fixture = __DIR__ . "/fixtures/curl-impersonate-$version.patch.gz";

        if (! is_file($fixture)) {
            throw new \RuntimeException(
                "Missing $fixture. After updating the bundled binaries, vendor the matching upstream patch: "
                . "curl -sSL https://raw.githubusercontent.com/lexiforest/curl-impersonate/$version/patches/curl.patch "
                . "| gzip -9 > tests/fixtures/curl-impersonate-$version.patch.gz"
            );
        }

        $patch = gzdecode((string) file_get_contents($fixture));
        if ($patch === false) {
            throw new \RuntimeException("Could not decompress $fixture");
        }

        return self::$upstream = UpstreamPatch::parseTargets($patch);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function browserProvider(): array
    {
        $out = [];
        foreach (BrowserConfig::getAvailableBrowsers() as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    public function testEveryUpstreamTargetIsPresentAndNothingElse(): void
    {
        $this->assertSame(
            [],
            array_diff(array_keys(self::upstream()), BrowserConfig::getAvailableBrowsers()),
            'upstream has targets this package lacks — run composer update-browsers'
        );
        $this->assertSame(
            [],
            array_diff(BrowserConfig::getAvailableBrowsers(), array_keys(self::upstream())),
            'this package has profiles upstream does not know — the binary cannot impersonate them'
        );
    }

    #[DataProvider('browserProvider')]
    public function testProfileMatchesUpstreamExactly(string $name): void
    {
        $upstream = self::upstream();
        $this->assertArrayHasKey($name, $upstream);

        $php = "return [\n" . ConfigGenerator::toPhpArrayEntry($name, $upstream[$name]) . "\n];";
        /** @var array<string, array<string,mixed>> $generated */
        $generated = eval($php);

        $this->assertSame(
            self::canonical($generated[$name]),
            self::canonical(BrowserConfig::getConfig($name)),
            "$name has drifted from upstream. Regenerate it from the vendored patch rather than editing by hand."
        );
    }

    /**
     * Header ORDER is part of the fingerprint and is compared as-is. The order
     * of the curl options is not — every flag is independent — so they are
     * sorted, or a profile written before the generator existed would fail on
     * nothing but the position of a key.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function canonical(array $config): array
    {
        if (isset($config['options']) && is_array($config['options'])) {
            ksort($config['options']);
        }
        ksort($config);

        return $config;
    }
}
