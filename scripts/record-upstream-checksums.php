<?php

/**
 * Record the sha256 of every UPSTREAM release asset the committed binaries
 * were derived from, and check that the committed files can be reproduced.
 *
 * bin/CHECKSUMS pins what is on disk — after `strip` — so it cannot be
 * compared with anything upstream publishes. This writes bin/UPSTREAM-CHECKSUMS
 * with, per committed artifact:
 *
 *   - the sha256 of the upstream release archive it came from,
 *   - the sha256 of the member inside that archive, exactly as upstream built it,
 *   - and, where this host can run the same strip step, confirmation that
 *     stripping that member reproduces the committed file byte for byte.
 *
 * Together with the tag in bin/VERSION this lets anyone re-derive a bundled
 * binary from upstream's own release and check it, which the self-attested
 * post-strip digests alone could not offer.
 *
 * Usage:
 *   php scripts/record-upstream-checksums.php [--version=vX.Y.Z] [--all]
 *
 *   --all   Also cover the on-demand (non-committed) platforms.
 *
 * Exit codes: 0 = written (and every reproducible artifact reproduced),
 * 1 = error or a committed file that does NOT match upstream.
 */

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/BinaryInstaller.php';

$root = dirname(__DIR__);
$binDir = $root . '/bin';
$installer = new BinaryInstaller($binDir);

$version = null;
$all = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $version = substr($arg, 10);
    } elseif ($arg === '--all') {
        $all = true;
    } else {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(1);
    }
}
$version ??= trim((string) @file_get_contents($binDir . '/VERSION'));
if ($version === '') {
    fwrite(STDERR, "No version: pass --version=TAG or create bin/VERSION.\n");
    exit(1);
}

/** The platforms whose artifacts are committed to git. */
$committed = ['linux-x86_64', 'macos-aarch64', 'windows-x86_64'];
$platforms = $installer->platformMap();
if (! $all) {
    $platforms = array_intersect_key($platforms, array_flip($committed));
}

$lines = [
    '# sha256 of the UPSTREAM lexiforest/curl-impersonate release assets the bundled',
    '# artifacts were derived from, and of the raw member inside each archive.',
    '# Written by scripts/record-upstream-checksums.php. The member digests are what',
    '# bin/CHECKSUMS pins and bin/php-impersonate-install verifies; the reproduced',
    '# lines are the files as committed (stripped on Linux), so a bundled binary can',
    '# be re-derived from upstream and checked:',
    '#',
    '#   curl -sSLO https://github.com/lexiforest/curl-impersonate/releases/download/' . $version . '/<asset>',
    '#   tar -xzf <asset> && sha256sum <member>            # must equal the member digest',
    '#   strip --strip-unneeded <member> (Linux only)      # then must equal the reproduced line',
    '#',
    '# macOS and Windows artifacts are shipped exactly as upstream built them.',
    '# Release: ' . $version,
    '#',
    '# <sha256>  asset|member|reproduced  <asset name or bin-relative path>',
];

$failed = false;

foreach ($platforms as $dir => $spec) {
    $jobs = [
        ['asset' => $installer->assetName($version, $spec), 'dest' => $dir . '/' . $spec['dest'], 'member' => $spec['member'], 'lib' => false],
    ];
    if ($installer->libIsUsable($dir)) {
        $jobs[] = ['asset' => $installer->libAssetName($version, $spec), 'dest' => $dir . '/' . $installer->libDestName($dir), 'member' => null, 'lib' => true];
    }

    foreach ($jobs as $job) {
        fwrite(STDOUT, "{$job['asset']} ... ");
        $result = $installer->upstreamDigests($version, $dir, $spec, $job['lib']);

        $lines[] = sprintf('%s  asset  %s', $result['asset_sha256'], $job['asset']);
        $lines[] = sprintf('%s  member  %s', $result['member_sha256'], $job['dest']);

        $committedFile = $binDir . '/' . $job['dest'];
        if (! is_file($committedFile)) {
            fwrite(STDOUT, "recorded (not present locally)\n");

            continue;
        }

        $committedHash = $installer->sha256($committedFile);
        if ($result['stripped_sha256'] === null) {
            // Not strippable on this host: on macOS/Windows the member IS the
            // shipped file, so compare it directly.
            $ok = hash_equals($result['member_sha256'], $committedHash);
        } else {
            $ok = hash_equals($result['stripped_sha256'], $committedHash);
        }

        $lines[] = sprintf('%s  reproduced=%s  %s', $committedHash, $ok ? 'yes' : 'NO', $job['dest']);
        fwrite(STDOUT, $ok ? "reproduced\n" : "DOES NOT MATCH the committed file\n");
        $failed = $failed || ! $ok;
    }
}

file_put_contents($binDir . '/UPSTREAM-CHECKSUMS', implode("\n", $lines) . "\n");
fwrite(STDOUT, "\nWrote bin/UPSTREAM-CHECKSUMS.\n");

if ($failed) {
    fwrite(STDERR, "At least one committed artifact could not be reproduced from upstream. Investigate before releasing.\n");
    exit(1);
}
exit(0);
