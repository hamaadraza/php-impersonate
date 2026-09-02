<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reachability helper the live suites gate on.
 *
 * Worth testing in its own right: it decides whether roughly a third of the
 * suite runs at all, and it used to treat any answer as a healthy one. Because
 * `ignore_errors` is set, a rate-limit page arrives as an ordinary body, so a
 * 429 read as "service up" and every dependent test then failed on that page
 * instead of skipping — noisiest exactly when the public service was busiest.
 */
class TestServerTest extends TestCase
{
    /**
     * @param array<int,string> $headers
     */
    #[DataProvider('responseHeaderProvider')]
    public function testStatusFromHeaders(array $headers, ?int $expected): void
    {
        $this->assertSame($expected, TestServer::statusFromHeaders($headers));
    }

    /**
     * @return array<string, array{0: array<int,string>, 1: int|null}>
     */
    public static function responseHeaderProvider(): array
    {
        return [
            'plain 200' => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            'rate limited' => [['HTTP/1.1 429 Too Many Requests'], 429],
            'unavailable' => [['HTTP/1.1 503 Service Unavailable'], 503],
            'http/2 status line' => [['HTTP/2 200'], 200],

            // The stream wrapper follows redirects, so the array holds one status
            // line per hop and only the final one describes what we received.
            'redirect chain keeps the last' => [[
                'HTTP/1.1 301 Moved Permanently',
                'Location: https://example.test/',
                'HTTP/1.1 429 Too Many Requests',
            ], 429],

            'no status line' => [['Content-Type: text/html'], null],
            'empty' => [[], null],
        ];
    }
}
