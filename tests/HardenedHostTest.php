<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;

/**
 * Hosts that disable proc_open() and/or shell_exec() through
 * `disable_functions`, which shared and hardened hosting does routinely.
 *
 * Since PHP 8.0 a disabled function behaves as UNDEFINED, so an unguarded
 * call dies with a bare \Error that escapes every exception this library
 * documents. Before the fix: `disable_functions=proc_open` produced
 * "Error: Call to undefined function proc_open()", and
 * `disable_functions=shell_exec` reported the bundled binary as missing and
 * told the user to run the installer — for a binary that was right there.
 *
 * Each case runs in a CHILD php process with the setting applied, because
 * disable_functions cannot be changed at runtime.
 */
class HardenedHostTest extends TestCase
{
    /**
     * Run PHP code in a child process with the given ini settings, returning
     * its combined output.
     *
     * @param array<string,string> $ini
     */
    private function runChild(array $ini, string $code): string
    {
        $argv = [PHP_BINARY];
        foreach ($ini as $key => $value) {
            $argv[] = '-d';
            $argv[] = "$key=$value";
        }
        $argv[] = '-r';
        $argv[] = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; ' . $code;

        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
        $this->assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    /** PHP that sends one request and prints the outcome as a single line. */
    private function requestScript(string $engine, string $url): string
    {
        return sprintf(
            'try { $r = (new Raza\PHPImpersonate\PHPImpersonate("chrome146", 10, [], %s))->sendGet(%s); '
            . 'echo "OK status=", $r->status(); } '
            . 'catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }',
            var_export($engine, true),
            var_export($url, true)
        );
    }

    public function testDisabledProcOpenIsReportedAsARequestExceptionNotAnError(): void
    {
        $out = $this->runChild(
            ['disable_functions' => 'proc_open'],
            $this->requestScript(PHPImpersonate::ENGINE_PROCESS, 'http://127.0.0.1:1/')
        );

        $this->assertStringContainsString('RequestException', $out, $out);
        $this->assertStringContainsString('proc_open', $out, 'the message must name the disabled function');
        $this->assertStringNotContainsString('Call to undefined function', $out);
    }

    public function testDisabledShellExecStillFindsTheBundledBinary(): void
    {
        TestServer::requireHttpbin($this);

        $out = $this->runChild(
            ['disable_functions' => 'shell_exec'],
            $this->requestScript(PHPImpersonate::ENGINE_PROCESS, TestServer::httpbin('/get'))
        );

        $this->assertStringStartsWith('OK status=200', $out, $out);
    }

    public function testBothDisabledStillExplainsInsteadOfBlamingTheBrowserName(): void
    {
        $out = $this->runChild(
            ['disable_functions' => 'proc_open,shell_exec'],
            $this->requestScript(PHPImpersonate::ENGINE_PROCESS, 'http://127.0.0.1:1/')
        );

        $this->assertStringContainsString('RequestException', $out, $out);
        $this->assertStringContainsString('proc_open', $out);
        $this->assertStringNotContainsString('Invalid browser', $out, 'a missing function must not be reported as a bad browser name');
        $this->assertStringNotContainsString('not bundled', $out, 'nor as a missing binary');
    }

    public function testAutoPrefersFfiWhenTheExecutableEngineCannotRun(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }
        TestServer::requireHttpbin($this);

        $out = $this->runChild(
            ['disable_functions' => 'proc_open,shell_exec'],
            $this->requestScript(PHPImpersonate::ENGINE_AUTO, TestServer::httpbin('/get'))
        );

        $this->assertStringStartsWith('OK status=200', $out, $out);
    }
}
