<?php

/**
 * Download the latest curl-impersonate binaries from upstream releases and
 * refresh this package's bundled binaries under bin/.
 *
 * Each platform directory maps to one upstream release asset (the standalone
 * `curl-impersonate` binary). The host-platform binary is verified by running
 * `--version` and checking for the IMPERSONATE marker; cross-platform binaries
 * are placed as-is (they cannot be executed here).
 *
 * Usage:
 *   php scripts/update-binaries.php [--version=vX.Y.Z] [--only=a,b] [--dry-run]
 *
 * Options:
 *   --version=TAG   Release tag to install (default: latest release).
 *   --only=LIST     Comma-separated platform dirs (default: all). E.g.
 *                   --only=linux-x86_64,windows-x86_64
 *   --libs          Also install the libcurl-impersonate shared library for the
 *                   optional FFI client (large; not committed by default).
 *   --libs-only     Install only the shared libraries, not the executables.
 *   --dry-run       Print the plan and asset URLs; download nothing.
 *
 * Exit codes: 0 = success, 1 = error.
 */

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/BinaryInstaller.php';

$options = parseArgs($argv);
$binDir = dirname(__DIR__) . '/bin';

try {
    $installer = new BinaryInstaller($binDir);

    $version = $options['version'] ?? $installer->latestReleaseTag();
    fwrite(STDOUT, "curl-impersonate release: $version\n");

    $platforms = $installer->platformMap();
    if ($options['only'] !== null) {
        $wanted = array_map('trim', explode(',', $options['only']));
        $unknown = array_diff($wanted, array_keys($platforms));
        if ($unknown !== []) {
            throw new \RuntimeException('Unknown platform(s): ' . implode(', ', $unknown)
                . '. Known: ' . implode(', ', array_keys($platforms)));
        }
        $platforms = array_intersect_key($platforms, array_flip($wanted));
    }

    $doExe = ! $options['libs-only'];
    /** @var bool $doLibs */
    $doLibs = $options['libs'] || $options['libs-only'];

    fwrite(STDOUT, "\nPlan:\n");
    foreach ($platforms as $dir => $spec) {
        if ($doExe) {
            fwrite(STDOUT, sprintf("  %-20s <- %s\n", $dir, $installer->assetName($version, $spec)));
        }
        if ($doLibs) {
            fwrite(STDOUT, sprintf("  %-20s <- %s (lib)\n", $dir, $installer->libAssetName($version, $spec)));
        }
    }

    if ($options['dry-run']) {
        fwrite(STDOUT, "\n--dry-run: nothing downloaded.\n");
        exit(0);
    }

    fwrite(STDOUT, "\n");
    $results = [];
    foreach ($platforms as $dir => $spec) {
        if ($doExe) {
            fwrite(STDOUT, "Installing $dir ... ");
            $results[$dir] = $installer->install($version, $dir, $spec);
            fwrite(STDOUT, $results[$dir]['message'] . "\n");
        }
        if ($doLibs) {
            fwrite(STDOUT, "Installing $dir library ... ");
            $lib = $installer->installLib($version, $dir, $spec);
            fwrite(STDOUT, $lib['message'] . "\n");
        }
    }

    $installer->writeVersionFile($version);

    fwrite(STDOUT, "\n✓ Done. Bundled binaries now at $version (recorded in bin/VERSION).\n");
    $unverified = array_filter($results, fn ($r) => ! $r['verified']);
    if ($unverified !== []) {
        fwrite(STDOUT, "\nNote: these could not be executed on this host — verify on their target OS:\n");
        foreach (array_keys($unverified) as $d) {
            fwrite(STDOUT, "  - $d\n");
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @return array{version: ?string, only: ?string, dry-run: bool}
 */
function parseArgs(array $argv): array
{
    $opts = ['version' => null, 'only' => null, 'dry-run' => false, 'libs' => false, 'libs-only' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $opts['dry-run'] = true;
        } elseif ($arg === '--libs') {
            $opts['libs'] = true;
        } elseif ($arg === '--libs-only') {
            $opts['libs-only'] = true;
        } elseif (str_starts_with($arg, '--version=')) {
            $opts['version'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--only=')) {
            $opts['only'] = substr($arg, 7);
        } else {
            fwrite(STDERR, "Unknown option: $arg\n");
            exit(1);
        }
    }

    return $opts;
}
