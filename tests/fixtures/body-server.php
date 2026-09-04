<?php

declare(strict_types=1);

/**
 * A minimal HTTP/1.0 server for FfiBodyBudgetTest: `php body-server.php`
 * binds a free loopback port, prints `port=N` on stdout, and answers every
 * request with a body of exactly `?bytes=N` bytes, in 64 KiB writes.
 *
 * Not httpbin, which caps /bytes at 100 KB and streams /drip byte by byte;
 * and not `php -S`, which delivered a 4 MiB body on Windows but dropped the
 * connection partway through a 48 MiB one (curl error 56 at the client). A
 * blocking socket loop has neither limit.
 */
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'port=' . substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$piece = str_repeat('x', 65536);

while (($client = @stream_socket_accept($server, -1)) !== false) {
    $request = '';
    while (! str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }

    $bytes = preg_match('/bytes=(\d+)/', $request, $m) === 1 ? (int) $m[1] : 0;

    @fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: $bytes\r\nConnection: close\r\n\r\n");

    while ($bytes > 0) {
        $take = min($bytes, 65536);
        $written = @fwrite($client, $take === 65536 ? $piece : substr($piece, 0, $take));
        if ($written === false || $written === 0) {
            break; // the client went away (a budget abort does exactly that)
        }
        $bytes -= $written;
    }

    fclose($client);
}
