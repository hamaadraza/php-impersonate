<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Browser\Browser;
use Raza\PHPImpersonate\Process\CurlProcess;
use Raza\PHPImpersonate\Config\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Platform\PlatformDetector;

/**
 * The guards that only matter when something has already gone wrong.
 *
 * Both controls here were entirely uncovered. That is the wrong place to have
 * no tests: a masking function that silently stops masking looks exactly like
 * one that works, and the failure is only visible in a log nobody reads until
 * it matters.
 */
class SecurityControlsTest extends TestCase
{
    protected function tearDown(): void
    {
        // This class overrides Configuration's static per-platform settings.
        Configuration::reset();
    }

    /**
     * The argv stored on a RequestException, which routinely reaches logs.
     *
     * @param list<string> $argv
     */
    #[DataProvider('redactionProvider')]
    public function testCommandRedaction(array $argv, string $expected): void
    {
        $redact = (new ReflectionClass(CurlProcess::class))->getMethod('redactCommand');
        $redact->setAccessible(true);

        $this->assertSame($expected, $redact->invoke(null, $argv));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function redactionProvider(): array
    {
        return [
            'proxy value is masked' => [
                ['curl', '--proxy', 'http://user:pw@proxy.test:8080', 'https://example.com/'],
                'curl --proxy *** https://example.com/',
            ],
            'short proxy flag too' => [
                ['curl', '-x', 'http://user:pw@proxy.test:8080'],
                'curl -x ***',
            ],
            'proxy-user value is masked' => [
                ['curl', '--proxy-user', 'user:hunter2'],
                'curl --proxy-user ***',
            ],
            'short proxy-user flag too' => [
                ['curl', '-U', 'user:hunter2'],
                'curl -U ***',
            ],
            // A URL can carry userinfo even though credentials no longer reach
            // argv through options.
            'userinfo in a URL' => [
                ['curl', 'https://user:secret@example.com/path'],
                'curl https://***@example.com/path',
            ],
            'userinfo without a password' => [
                ['curl', 'http://token@example.com/'],
                'curl http://***@example.com/',
            ],
            'ordinary argv is untouched' => [
                ['curl', '-s', '--max-time', '30', 'https://example.com/a?b=c'],
                'curl -s --max-time 30 https://example.com/a?b=c',
            ],
            // Masking is positional: only the value that FOLLOWS the flag goes,
            // and the next argument after that must survive.
            'only the flag value is consumed' => [
                ['curl', '--proxy', 'http://p:1', '--max-time', '30'],
                'curl --proxy *** --max-time 30',
            ],
        ];
    }

    /**
     * `which_command` is interpolated into a shell string, and
     * Configuration::setPlatformConfig() is public — so a value that is not a
     * bare command name must be refused rather than executed.
     *
     * @param string $configured
     */
    #[DataProvider('unsafeWhichCommandProvider')]
    public function testUnsafeWhichCommandIsRejected(string $configured): void
    {
        $platform = \Raza\PHPImpersonate\Platform\PlatformDetector::getPlatform();
        $original = Configuration::get('which_command');

        try {
            Configuration::setPlatformConfig($platform, ['which_command' => $configured]);

            $find = (new ReflectionClass(Browser::class))->getMethod('findInPath');
            $find->setAccessible(true);

            // A rejected value falls back to which/where, so the lookup simply
            // finds nothing for a name that does not exist — the point is that
            // the injected fragment never runs. A marker file would exist if it had.
            $marker = sys_get_temp_dir() . '/php-impersonate-injection-marker';
            // Guarded rather than suppressed: PHPUnit's error handler promotes a
            // warning from unlink() into a test warning regardless of `@`, and
            // phpunit.xml.dist sets failOnWarning="true".
            if (is_file($marker)) {
                unlink($marker);
            }

            $browser = (new ReflectionClass(Browser::class))->newInstanceWithoutConstructor();
            $find->invoke($browser, 'definitely-not-a-real-binary-name', $platform);

            $this->assertFileDoesNotExist($marker, "injected shell fragment ran: $configured");
        } finally {
            Configuration::setPlatformConfig($platform, ['which_command' => is_string($original) ? $original : 'which']);

            $marker = sys_get_temp_dir() . '/php-impersonate-injection-marker';
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeWhichCommandProvider(): array
    {
        $marker = sys_get_temp_dir() . '/php-impersonate-injection-marker';

        return [
            'command chaining' => ["which; touch $marker"],
            'command substitution' => ['which $(touch ' . $marker . ')'],
            'backticks' => ['which `touch ' . $marker . '`'],
            'pipe' => ["which | touch $marker"],
            'ampersand' => ["which && touch $marker"],
            'newline' => ["which\ntouch $marker"],
        ];
    }

    /**
     * Exercises the REAL guard in Browser::findInPath(), not a copy of its
     * regex. Asserting against a pattern re-declared in this file only proved a
     * string literal behaved like itself: the guard could be loosened, tightened
     * or inverted and every case here would still pass.
     *
     * A legitimately configured command must actually be USED — the observable
     * difference being that findInPath() returns the path such a command
     * resolves, where a rejected one falls back to the platform default.
     */
    public function testLegitimateWhichCommandIsUsedByTheRealGuard(): void
    {
        if (PlatformDetector::isWindows()) {
            $this->markTestSkipped('POSIX which(1) semantics');
        }

        $findInPath = new ReflectionMethod(Browser::class, 'findInPath');
        $findInPath->setAccessible(true);

        $browser = (new ReflectionClass(Browser::class))->newInstanceWithoutConstructor();
        $platform = PlatformDetector::getPlatform();

        // An absolute path made of allowed characters passes the guard and is
        // the command actually run: it resolves `sh` exactly as bare `which` does.
        Configuration::setPlatformConfig($platform, ['which_command' => '/usr/bin/which']);
        $viaAbsolute = $findInPath->invoke($browser, 'sh', $platform);

        Configuration::setPlatformConfig($platform, ['which_command' => 'which']);
        $viaDefault = $findInPath->invoke($browser, 'sh', $platform);

        $this->assertNotNull($viaDefault, 'sanity: bare `which sh` should resolve');
        $this->assertSame(
            $viaDefault,
            $viaAbsolute,
            'a configured, allow-listed which_command must be honoured, not silently replaced by the default'
        );
    }
}
