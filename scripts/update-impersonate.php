<?php

/**
 * One-shot updater: refresh bundled binaries AND browser configs from upstream
 * lexiforest/curl-impersonate, in the correct order (binaries first so new
 * configs — e.g. post-quantum Chrome — are backed by a capable binary).
 *
 * Usage:
 *   php scripts/update-impersonate.php [--binaries-only] [--configs-only]
 *                                      [--version=vX.Y.Z] [--ref=main] [--dry-run]
 *
 * With no flags it runs both steps. All other flags are forwarded to the
 * underlying scripts (see update-binaries.php / update-browsers.php).
 *
 * Exit codes: 0 = success, non-zero = a step failed.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$binariesOnly = in_array('--binaries-only', $args, true);
$configsOnly = in_array('--configs-only', $args, true);

if ($binariesOnly && $configsOnly) {
    fwrite(STDERR, "Use only one of --binaries-only / --configs-only.\n");
    exit(1);
}

$forward = array_values(array_filter(
    $args,
    fn ($a) => ! in_array($a, ['--binaries-only', '--configs-only'], true)
));

$php = PHP_BINARY;
$dir = __DIR__;

/**
 * Run a child script, streaming its output; return its exit code.
 */
$run = static function (string $script, array $passthru) use ($php, $dir): int {
    // --ref only applies to the config step; --version/--only only to binaries.
    $binaryFlags = ['--version=', '--only='];
    $configFlags = ['--ref=', '--patch-file='];
    $isConfig = str_contains($script, 'browsers');
    $filtered = array_filter($passthru, function ($a) use ($binaryFlags, $configFlags, $isConfig) {
        foreach ($isConfig ? $binaryFlags : $configFlags as $prefix) {
            if (str_starts_with($a, $prefix)) {
                return false;
            }
        }

        return true;
    });

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($dir . '/' . $script);
    foreach ($filtered as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    passthru($cmd, $code);

    return $code;
};

$steps = [];
if (! $configsOnly) {
    $steps[] = ['update-binaries.php', '── Updating binaries ──'];
}
if (! $binariesOnly) {
    $steps[] = ['update-browsers.php', '── Updating browser configs ──'];
}

foreach ($steps as [$script, $banner]) {
    fwrite(STDOUT, "\n$banner\n\n");
    $code = $run($script, $forward);
    if ($code !== 0) {
        fwrite(STDERR, "\nStep failed: $script (exit $code)\n");
        exit($code);
    }
}

fwrite(STDOUT, "\n✓ Update complete. Run `composer format && composer test` to finish.\n");
exit(0);
