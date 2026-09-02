<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Support\CurlOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shared, typed curl-option allow-list used by both engines. Pure unit
 * tests — no binary or network required.
 */
class CurlOptionsTest extends TestCase
{
    public function testAllowedKeysAreTheCuratedSet(): void
    {
        $this->assertSame(
            ['proxy', 'proxy-user', 'noproxy', 'referer', 'cacert', 'capath', 'max-redirs', 'max-filesize', 'insecure'],
            CurlOptions::allowedKeys()
        );
    }

    public function testAssertAllowedPassesForSupportedOptions(): void
    {
        CurlOptions::assertAllowed([
            'proxy' => 'http://127.0.0.1:8080',
            'insecure' => true,
            'max-redirs' => 5,
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * Fingerprint-affecting and internal options must never be accepted.
     */
    public function testAssertAllowedRejectsUnsupportedOptions(): void
    {
        foreach (['ciphers', 'curves', 'user-agent', 'http2', 'output', 'config'] as $bad) {
            try {
                CurlOptions::assertAllowed([$bad => 'x']);
                $this->fail("'$bad' should not be an allowed option");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsupported curl option', $e->getMessage());
                $this->assertStringContainsString($bad, $e->getMessage());
            }
        }
    }

    /**
     * A control character in an option value is an injection, not a typo.
     *
     * The executable engine renders `proxy`/`proxy-user` into curl's config
     * file, whose format is line-oriented: a newline ends that option and curl
     * reads the rest of the value as ANOTHER option. A proxy string taken from
     * a rotating-proxy list or a tenant's settings could otherwise add `proxy`
     * (redirecting the request, Authorization header and all, to an endpoint
     * the attacker picked), `insecure`, or `data = @/etc/passwd`.
     */
    #[DataProvider('controlCharacterValues')]
    public function testAssertAllowedRejectsControlCharactersInStringValues(string $key, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('may not contain CR, LF, or NUL');

        CurlOptions::assertAllowed([$key => $value]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function controlCharacterValues(): array
    {
        return [
            'proxy-user smuggling a proxy' => ['proxy-user', "user:pass\nproxy = http://attacker.example\n# "],
            'proxy smuggling insecure' => ['proxy', "http://127.0.0.1:8080\ninsecure"],
            'carriage return' => ['proxy', "http://127.0.0.1:8080\rinsecure"],
            'bare line feed' => ['noproxy', "example.com\nfoo"],
            'referer' => ['referer', "http://example.com\nX"],
            'cacert path' => ['cacert', "/etc/ssl/certs/ca.pem\nfoo"],
            'NUL byte' => ['proxy-user', "user:pass\0truncated"],
        ];
    }

    /**
     * The guard must not cost legitimate credentials: quotes and backslashes are
     * escaped for curl's config parser, not rejected.
     */
    public function testAssertAllowedAcceptsAwkwardButValidCredentials(): void
    {
        CurlOptions::assertAllowed([
            'proxy' => 'socks5://127.0.0.1:1080',
            'proxy-user' => 'user:pa"ss\\word with spaces!',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testTypesAndOptionIds(): void
    {
        $this->assertSame(CurlOptions::TYPE_STRING, CurlOptions::type('proxy'));
        $this->assertSame(CurlOptions::TYPE_LONG, CurlOptions::type('max-redirs'));
        $this->assertSame(CurlOptions::TYPE_BOOL, CurlOptions::type('insecure'));

        $this->assertSame(CurlOptions::CURLOPT_PROXY, CurlOptions::optId('proxy'));
        $this->assertSame(CurlOptions::CURLOPT_MAXREDIRS, CurlOptions::optId('max-redirs'));
        $this->assertNull(CurlOptions::optId('insecure')); // special-cased by the engines
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function boolProvider(): array
    {
        return [
            'true' => [true, true],
            'int 1' => [1, true],
            'string 1' => ['1', true],
            'yes' => ['yes', true],
            'false' => [false, false],
            'int 0' => [0, false],
            'string 0' => ['0', false],
            'off' => ['off', false],
        ];
    }

    #[DataProvider('boolProvider')]
    public function testIsEnabledMatchesCurlSemantics(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, CurlOptions::isEnabled($value));
    }

    // -------------------------------------------------------------------------
    // normalize() — the shared canonical value shape both engines apply
    // -------------------------------------------------------------------------

    /**
     * A bool option collapses to `true` or vanishes; it must NEVER keep a loose
     * value. The executable engine renders any non-bool as `--flag value`, and
     * curl reads that trailing value as an extra URL to fetch.
     */
    public function testNormalizeCollapsesBoolOptions(): void
    {
        $this->assertSame(['insecure' => true], CurlOptions::normalize(['insecure' => 'yes']));
        $this->assertSame(['insecure' => true], CurlOptions::normalize(['insecure' => 1]));
        $this->assertSame(['insecure' => true], CurlOptions::normalize(['insecure' => true]));

        // Disabled means absent, not `false`: an absent flag is how both engines
        // express "off", and nothing reaches the wire.
        $this->assertSame([], CurlOptions::normalize(['insecure' => 'no']));
        $this->assertSame([], CurlOptions::normalize(['insecure' => '0']));
        $this->assertSame([], CurlOptions::normalize(['insecure' => false]));
    }

    public function testNormalizeCastsLongOptionsToInt(): void
    {
        $this->assertSame(['max-redirs' => 5], CurlOptions::normalize(['max-redirs' => '5']));
        $this->assertSame(['max-redirs' => 0], CurlOptions::normalize(['max-redirs' => 0]));
    }

    public function testNormalizeDropsNullAndEmptyStrings(): void
    {
        // An empty cacert is "unset", and must not suppress the default CA
        // bundle or emit an empty argument.
        $this->assertSame([], CurlOptions::normalize(['cacert' => null, 'capath' => '']));
        $this->assertSame(['proxy' => 'http://p:1'], CurlOptions::normalize(['proxy' => 'http://p:1']));
    }

    public function testNormalizeKeepsAnEmptyProxyBecauseCurlGivesItMeaning(): void
    {
        // curl documents `--proxy ""` / CURLOPT_PROXY set to "" as the way to
        // disable proxying, overriding http_proxy/HTTPS_PROXY. Dropped with the
        // other empty strings, a caller had no way to say "go direct" — and was
        // never told the value had been ignored.
        $this->assertSame(['proxy' => ''], CurlOptions::normalize(['proxy' => '']));
        $this->assertTrue(CurlOptions::emptyIsMeaningful('proxy'));
        $this->assertFalse(CurlOptions::emptyIsMeaningful('cacert'));
    }

    public function testNormalizeIsIdempotent(): void
    {
        $once = CurlOptions::normalize(['insecure' => 'yes', 'max-redirs' => '3', 'proxy' => 'http://p:1']);

        $this->assertSame($once, CurlOptions::normalize($once));
    }

    /**
     * Key order is part of the canonical form. Curl does not care in what order
     * it is handed independent options, but the FFI engine cache keys on the
     * serialised result — so two spellings of one configuration would otherwise
     * mint two engines, each with its own handle and connection pool.
     */
    public function testNormalizeCanonicalisesKeyOrder(): void
    {
        $a = CurlOptions::normalize(['proxy' => 'http://p:1', 'insecure' => true, 'max-redirs' => 3]);
        $b = CurlOptions::normalize(['max-redirs' => 3, 'proxy' => 'http://p:1', 'insecure' => true]);

        $this->assertSame($a, $b);
        $this->assertSame(['insecure', 'max-redirs', 'proxy'], array_keys($a));
        $this->assertSame(serialize($a), serialize($b), 'the FFI cache key must not depend on key order');
    }

    public function testNormalizeSkipsUnknownKeys(): void
    {
        $this->assertSame(['proxy' => 'http://p:1'], CurlOptions::normalize([
            'proxy' => 'http://p:1',
            'ciphers' => 'AES',
        ]));
    }

    // -------------------------------------------------------------------------
    // assertAllowed() — value validation
    // -------------------------------------------------------------------------

    public function testAssertAllowedRejectsNonScalarValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected a scalar');

        CurlOptions::assertAllowed(['proxy' => ['http://p:1']]);
    }

    public function testAssertAllowedRejectsNonNumericLongValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected a number');

        CurlOptions::assertAllowed(['max-redirs' => 'many']);
    }

    public function testAssertAllowedAcceptsNumericStringForLongOptions(): void
    {
        CurlOptions::assertAllowed(['max-redirs' => '10']);
        $this->addToAssertionCount(1);
    }
}
