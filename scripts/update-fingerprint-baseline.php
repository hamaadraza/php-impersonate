<?php

/**
 * Refresh the pinned fingerprint baseline used by tests/FingerprintBaselineTest.php.
 *
 * The baseline is what stops every fingerprint assertion in the suite from
 * being self-referential: the parity test only proves the two engines agree
 * with EACH OTHER, so if both drift together — a rebuilt shared library, a
 * changed profile — nothing notices. This records what the profiles actually
 * put on the wire, so a change has to be looked at by a human.
 *
 * Run it ONLY after deliberately updating the bundled binaries, and only once
 * you have satisfied yourself the new fingerprints are the ones a real browser
 * sends. Re-pinning to silence a failure defeats the point of the file.
 *
 *   php scripts/update-fingerprint-baseline.php [--url=https://tls.peet.ws/api/all]
 *
 * Exit codes: 0 = written, 1 = error.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Raza\PHPImpersonate\PHPImpersonate;

/** Representative of each distinct TLS stack the package impersonates. */
const PINNED = ['firefox147', 'chrome110', 'firefox133', 'safari153'];

$url = 'https://tls.peet.ws/api/all';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } else {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$versionFile = $root . '/bin/VERSION';
$version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

try {
    $fingerprints = [];

    foreach (PINNED as $browser) {
        fwrite(STDOUT, "Capturing $browser ... ");
        $body = (new PHPImpersonate($browser, 30))->sendGet($url)->json();
        $ja4r = $body['tls']['ja4_r'] ?? null;
        // The HTTP/2 fingerprint covers what JA4 cannot see: two profiles can share
        // a TLS shape (chrome99 and chrome110 do) and still differ on the wire.
        $akamai = $body['http2']['akamai_fingerprint'] ?? null;

        if (! is_string($ja4r) || $ja4r === '') {
            throw new RuntimeException("no ja4_r returned for $browser");
        }
        if (! is_string($akamai) || $akamai === '') {
            throw new RuntimeException("no akamai_fingerprint returned for $browser");
        }

        $fingerprints[$browser] = ['ja4_r' => $ja4r, 'akamai' => $akamai];
        fwrite(STDOUT, "\n    ja4_r  $ja4r\n    akamai $akamai\n");
        usleep(250000); // be gentle with the public service
    }

    $payload = [
        'note' => 'Regenerate with `php scripts/update-fingerprint-baseline.php` — only after verifying '
            . 'the new fingerprints against a real browser. See tests/FingerprintBaselineTest.php.',
        'curl_impersonate_version' => $version,
        'source' => $url,
        'fingerprints' => $fingerprints,
    ];

    $dest = $root . '/tests/fixtures/fingerprint-baseline.json';
    if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0755, true) && ! is_dir(dirname($dest))) {
        throw new RuntimeException('Cannot create tests/fixtures');
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($dest, $json) === false) {
        throw new RuntimeException("Cannot write $dest");
    }

    fwrite(STDOUT, "\n✓ Wrote " . count($fingerprints) . " fingerprints to tests/fixtures/fingerprint-baseline.json ($version).\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
