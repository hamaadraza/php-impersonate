<?php

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Browser\BrowserConfig;

/**
 * The suite's only external anchor for what actually goes on the wire.
 *
 * Every other fingerprint assertion is self-referential. EngineParityTest proves
 * the two engines agree with EACH OTHER; TlsFingerprintTest proves a fingerprint
 * is well-formed and non-empty. Neither notices if both engines drift together —
 * which is exactly what a `composer update-impersonate` does, since it refreshes
 * the shared library and BrowserConfig in one go. This pins what the profiles
 * emit so that such a change has to be looked at instead of absorbed.
 *
 * Two fingerprints are pinned, because one is not enough:
 *
 *  - JA4_r, the raw unhashed form, listing ciphers, extensions and signature
 *    algorithms in sorted order. Sorting is what makes it stable for Chrome,
 *    whose per-connection extension shuffle moves JA3 on every request, and a
 *    failure names the cipher that appeared or vanished rather than just
 *    reporting two different hashes.
 *  - The Akamai HTTP/2 fingerprint, which covers a layer JA4 cannot see at all.
 *    Measured: chrome99 and chrome110 produce byte-identical JA4_r and are told
 *    apart only by their HTTP/2 SETTINGS, so a JA4-only baseline would have
 *    accepted one silently substituted for the other.
 *
 * The baseline records the curl-impersonate version it was captured against.
 * After a deliberate binary update these may legitimately change: verify the new
 * values are what a real browser sends, then re-pin with
 * `php scripts/update-fingerprint-baseline.php`.
 */
class FingerprintBaselineTest extends TestCase
{
    /** @var array{curl_impersonate_version: string, fingerprints: array<string, array<string,string>>} */
    private static array $baseline;

    /**
     * One live request per browser, shared by every assertion below.
     *
     * @var array<string, array<string,mixed>>
     */
    private static array $observed = [];

    public static function setUpBeforeClass(): void
    {
        self::$baseline = self::readBaseline();
    }

    protected function setUp(): void
    {
        TestServer::requireTls($this);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pinnedBrowserProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::readBaseline()['fingerprints']) as $browser) {
            $cases[$browser] = [$browser];
        }

        return $cases;
    }

    #[DataProvider('pinnedBrowserProvider')]
    public function testFingerprintsMatchThePinnedBaseline(string $browser): void
    {
        $pinned = self::$baseline['fingerprints'][$browser];
        $observed = $this->observe($browser);

        $this->assertSame($pinned['ja4_r'], $observed['tls']['ja4_r'] ?? null, $this->drifted($browser, 'TLS (JA4_r)'));
        $this->assertSame(
            $pinned['akamai'],
            $observed['http2']['akamai_fingerprint'] ?? null,
            $this->drifted($browser, 'HTTP/2 (Akamai)')
        );
    }

    /**
     * Cross-check the wire against this package's OWN declared profile.
     *
     * Unlike the pinned baseline, this compares two independent sources — what
     * BrowserConfig declares (which drives the executable engine) and what the
     * ClientHello actually carried — so it catches our config drifting out of
     * step with the shared library's built-in profile. That is the divergence
     * the FFI engine would otherwise hide, since it impersonates by name and
     * never reads BrowserConfig at all.
     *
     * JA4 omits GREASE, so the cipher counts line up directly.
     */
    #[DataProvider('pinnedBrowserProvider')]
    public function testWireCipherCountMatchesTheDeclaredProfile(string $browser): void
    {
        $onWire = explode(',', explode('_', (string) $this->observe($browser)['tls']['ja4_r'])[1]);
        $declared = explode(':', BrowserConfig::getConfig($browser)['ciphers']);

        $this->assertCount(
            count($declared),
            $onWire,
            "$browser: BrowserConfig declares " . count($declared) . ' ciphers but the ClientHello carried '
                . count($onWire) . ' — the profile and the shared library disagree.'
        );
    }

    /**
     * The same cross-check one layer up. The Akamai fingerprint is
     * `SETTINGS|WINDOW_UPDATE|PRIORITY|PSEUDO_HEADER_ORDER`, and its first two
     * segments are exactly what BrowserConfig declares, so they can be compared
     * without a lookup table.
     */
    #[DataProvider('pinnedBrowserProvider')]
    public function testHttp2FingerprintMatchesTheDeclaredProfile(string $browser): void
    {
        $options = BrowserConfig::getConfig($browser)['options'];
        $segments = explode('|', (string) $this->observe($browser)['http2']['akamai_fingerprint']);

        $this->assertSame($options['http2-settings'], $segments[0], "$browser: HTTP/2 SETTINGS differ from the profile");
        $this->assertSame(
            $options['http2-window-update'],
            $segments[1],
            "$browser: HTTP/2 window update differs from the profile"
        );

        // Only some profiles pin an order; the rest take curl's default.
        if (isset($options['http2-pseudo-headers-order'])) {
            $this->assertSame(
                $options['http2-pseudo-headers-order'],
                str_replace(',', '', $segments[3]),
                "$browser: HTTP/2 pseudo-header order differs from the profile"
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function observe(string $browser): array
    {
        // These two cross-checks only MEAN anything under the FFI engine.
        //
        // The claim they make is that BrowserConfig has not drifted from the
        // shared library's own built-in profile — which holds only when the
        // library is what drove the wire. The process engine drives it FROM the
        // very BrowserConfig values being asserted, so under that engine the
        // comparison is a value against itself and passes by construction. On
        // the Windows CI leg, where FFI is unavailable by design, AUTO resolves
        // to the process engine and both tests went quietly tautological.
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped(
                'These compare BrowserConfig against what the shared library put on the wire, '
                . 'which is only a real comparison under the FFI engine. The process engine '
                . 'drives the ClientHello from BrowserConfig itself, so the assertion would '
                . 'compare a value with itself and pass regardless.'
            );
        }

        // Cached across data sets: three assertions per browser would otherwise
        // be three requests to a public service that rate-limits.
        return self::$observed[$browser] ??= (new PHPImpersonate($browser, 30, [], PHPImpersonate::ENGINE_FFI))
            ->sendGet(TestServer::tls())
            ->json();
    }

    private function drifted(string $browser, string $layer): string
    {
        return sprintf(
            "%s no longer sends the %s fingerprint it was pinned to.\n"
            . "This is NOT automatically a bug: the bundled curl-impersonate may have changed.\n"
            . "Baseline captured against: %s. Bundled now: %s.\n"
            . 'Verify the new fingerprint is what a real browser sends, then re-pin with '
            . '`php scripts/update-fingerprint-baseline.php`.',
            $browser,
            $layer,
            self::$baseline['curl_impersonate_version'],
            self::bundledVersion()
        );
    }

    /**
     * @return array{curl_impersonate_version: string, fingerprints: array<string, array<string,string>>}
     */
    private static function readBaseline(): array
    {
        $path = __DIR__ . '/fixtures/fingerprint-baseline.json';
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Missing fingerprint baseline fixture: $path");
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function bundledVersion(): string
    {
        $file = dirname(__DIR__) . '/bin/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : 'unknown';
    }
}
