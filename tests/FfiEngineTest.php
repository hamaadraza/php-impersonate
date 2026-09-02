<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Request;
use Raza\PHPImpersonate\PHPImpersonate;
use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Live tests for the FFI engine, driven through the PHPImpersonate entry point
 * with engine: ENGINE_FFI. Skipped automatically when FFI or the shared library
 * is unavailable (e.g. CI without libs), or when httpbin is unreachable.
 */
class FfiEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (! PHPImpersonate::ffiAvailable()) {
            $this->markTestSkipped('FFI engine not available.');
        }

        // Diagnose an outage with the service probe, NOT with a live request
        // through the very engine under test.
        //
        // setUp() used to send a real request and, on any Throwable, latch
        // self::$functional = false for the rest of the process — skipping the
        // whole class. An httpbin outage, an httpbin 5xx and a genuinely broken
        // FFI engine were indistinguishable, so the one failure these tests
        // exist to catch silently removed them from the run. If httpbin answers
        // and FFI still cannot complete a request, that is a real failure.
        TestServer::requireHttpbin($this);
    }

    /**
     * @param array<string,mixed> $curlOptions
     */
    private function ffi(string $browser, int $timeout = 30, array $curlOptions = []): PHPImpersonate
    {
        return new PHPImpersonate($browser, $timeout, $curlOptions, PHPImpersonate::ENGINE_FFI);
    }

    public function testEngineIsFfi(): void
    {
        $this->assertSame(PHPImpersonate::ENGINE_FFI, $this->ffi('firefox147')->engine());
    }

    public function testGetReturnsImpersonatedUserAgent(): void
    {
        $response = $this->ffi('firefox147')->sendGet(TestServer::httpbin('/get'));

        $this->assertSame(200, $response->status());
        $ua = $response->json()['headers']['User-Agent'] ?? '';
        $this->assertStringContainsString('Firefox/147', $ua);
    }

    public function testPostJsonBody(): void
    {
        $response = $this->ffi('chrome146')->sendPost(TestServer::httpbin('/post'), ['name' => 'x', 'n' => 2], [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertEquals(['name' => 'x', 'n' => 2], $response->json()['json']);
    }

    public function testCustomHeaderIsSent(): void
    {
        $response = $this->ffi('chrome146')->sendGet(TestServer::httpbin('/headers'), ['X-Custom' => 'abc']);
        $this->assertSame('abc', $response->json()['headers']['X-Custom'] ?? null);
    }

    public function testHeadHasEmptyBody(): void
    {
        $response = $this->ffi('chrome146')->sendHead(TestServer::httpbin('/anything'));
        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    public function testCompressedResponseIsDecoded(): void
    {
        // httpbin serves genuinely gzip-encoded JSON here.
        $response = $this->ffi('chrome146')->sendGet(TestServer::httpbin('/gzip'));

        $this->assertSame(200, $response->status());
        $this->assertIsArray($response->json()); // json() would throw if still gzip
    }

    /**
     * Keep-alive reuse is the entire reason the FFI engine holds one easy handle
     * across requests (curl_easy_reset preserves the connection cache) and the
     * reason PHPImpersonate caches engines per config. Three 200s could never
     * show that: they pass identically whether the connection is reused or a
     * fresh TCP+TLS handshake happens each time.
     *
     * A local server that counts ACCEPTED CONNECTIONS can. Three requests over a
     * reused connection produce exactly one accept; without reuse, three.
     */
    public function testConnectionIsReusedAcrossRequests(): void
    {
        $port = 18932;
        $script = sprintf(
            '<?php $s=stream_socket_server("tcp://127.0.0.1:%d",$e,$m); $n=0;'
            . '$deadline=time()+20;'
            . 'while(time()<$deadline){ $c=@stream_socket_accept($s,2); if(!$c){continue;} $n++;'
            // Recorded the moment a connection is accepted, so the test can read
            // the tally as soon as its requests are done instead of waiting for
            // this loop to time out.
            . '  file_put_contents("%s",$n);'
            . '  stream_set_timeout($c,2);'
            . '  while(true){ $req=""; while(($l=fgets($c))!==false){ $req.=$l; if($l==="\r\n"||$l==="\n"){break;} }'
            . '    if($req===""){break;}'
            . '    fwrite($c,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok"); }'
            . '  fclose($c); }',
            $port,
            $countFile = sys_get_temp_dir() . '/php-impersonate-accepts-' . getmypid()
        );

        $scriptFile = sys_get_temp_dir() . '/php-impersonate-keepalive-' . getmypid() . '.php';
        file_put_contents($scriptFile, $script);
        // Guarded rather than suppressed: phpunit.xml.dist sets
        // failOnWarning="true", and PHPUnit promotes unlink()'s warning even
        // through `@`.
        if (is_file($countFile)) {
            unlink($countFile);
        }

        $server = proc_open(['php', $scriptFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($server);
        usleep(400000);

        try {
            $client = $this->ffi('chrome146');
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame(200, $client->sendGet("http://127.0.0.1:$port/get")->status());
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
            if (is_file($scriptFile)) {
                unlink($scriptFile);
            }
        }

        $accepts = is_file($countFile) ? (int) file_get_contents($countFile) : -1;
        if (is_file($countFile)) {
            unlink($countFile);
        }

        $this->assertSame(
            1,
            $accepts,
            "three requests should share one connection, but the server accepted $accepts"
        );
    }

    public function testBinaryBodyIsSentVerbatim(): void
    {
        // A body with an embedded NUL byte must not be truncated (regression: B1).
        $body = "AB\0CDEFGH"; // 9 bytes, NUL at index 2
        $response = $this->ffi('chrome146')->send(new Request(
            'POST',
            TestServer::httpbin('/anything'),
            ['Content-Type' => 'application/octet-stream'],
            $body
        ));

        $this->assertSame(200, $response->status());
        $this->assertSame('9', $response->json()['headers']['Content-Length'] ?? null);
    }

    public function testProxyOptionIsApplied(): void
    {
        // An unreachable proxy must fail — proving the proxy option takes effect.
        $client = $this->ffi('chrome146', 5, ['proxy' => 'http://127.0.0.1:9']);

        $this->expectException(RequestException::class);
        $client->sendGet(TestServer::httpbin('/get'));
    }
}
