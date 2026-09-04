<?php

declare(strict_types=1);

/**
 * `php -S` router for FfiBodyBudgetTest: answers every request with a body of
 * exactly `?bytes=N` bytes, streamed in 64 KiB pieces.
 *
 * httpbin cannot serve this: both implementations cap /bytes at 100 KB, and
 * go-httpbin refuses a /drip past its request-body limit. A budget measured in
 * megabytes needs a body measured in megabytes.
 */
$bytes = max(0, (int) ($_GET['bytes'] ?? 0));

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $bytes);

$piece = str_repeat('x', 65536);
while ($bytes > 0) {
    $take = min($bytes, 65536);
    echo $take === 65536 ? $piece : substr($piece, 0, $take);
    $bytes -= $take;
}
