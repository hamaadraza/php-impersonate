<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Support\CurlOptions;

/**
 * The shared, typed curl-option allow-list used by both engines. Pure unit
 * tests — no binary or network required.
 */
class CurlOptionsTest extends TestCase
{
    public function testAllowedKeysAreTheCuratedSet(): void
    {
        $this->assertSame(
            ['proxy', 'proxy-user', 'noproxy', 'referer', 'cacert', 'capath', 'max-redirs', 'insecure'],
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

    /**
     * @dataProvider boolProvider
     */
    public function testIsEnabledMatchesCurlSemantics(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, CurlOptions::isEnabled($value));
    }
}
