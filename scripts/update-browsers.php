<?php

/**
 * Sync browser impersonation configs from upstream lexiforest/curl-impersonate.
 *
 * Parses the authoritative `impersonate_opts` table in the upstream
 * `patches/curl.patch`, then adds any target NOT already present to this
 * package — BrowserConfig, the BrowserName constants + getAll(), and the
 * three @phpstan-type BrowserName docblocks. Existing, already-validated
 * configs are never modified (append-only), so re-running is safe/idempotent.
 *
 * Usage:
 *   php scripts/update-browsers.php [--dry-run] [--ref=main] [--patch-file=PATH]
 *
 * Options:
 *   --dry-run       Report what would change; write nothing.
 *   --ref=REF       Git ref/branch/tag of curl-impersonate to read (default: main).
 *   --patch-file=P  Read the patch from a local file instead of downloading.
 *
 * Exit codes: 0 = success (0 or more added), 1 = error.
 */

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

use Raza\PHPImpersonate\Browser\BrowserConfig;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/UpstreamPatch.php';
require __DIR__ . '/lib/ConfigGenerator.php';
require __DIR__ . '/lib/SourceEditor.php';

$options = parseArgs($argv);
$srcDir = dirname(__DIR__) . '/src';

try {
    $patch = $options['patch-file'] !== null
        ? readLocalPatch($options['patch-file'])
        : UpstreamPatch::download($options['ref']);

    fwrite(STDOUT, "Parsing upstream impersonation targets...\n");
    $targets = UpstreamPatch::parseTargets($patch);
    fwrite(STDOUT, sprintf("  found %d targets upstream\n", count($targets)));

    $existing = array_keys(BrowserConfig::getAllConfigs());
    $missing = array_values(array_filter(
        array_keys($targets),
        fn ($name) => ! in_array($name, $existing, true)
    ));

    if ($missing === []) {
        fwrite(STDOUT, "\n✓ Already up to date — all " . count($existing) . " upstream targets present.\n");
        exit(0);
    }

    fwrite(STDOUT, "\nNew targets to add (" . count($missing) . "):\n");
    foreach ($missing as $name) {
        fwrite(STDOUT, "  + $name\n");
    }

    // Build the PHP config block and the constant metadata for each new target.
    $configBlocks = [];
    foreach ($missing as $name) {
        $configBlocks[$name] = ConfigGenerator::toPhpArrayEntry($name, $targets[$name]);
    }

    // The full name list (existing + new) drives the docblock union rewrite.
    $allNames = array_values(array_unique(array_merge($existing, $missing)));

    if ($options['dry-run']) {
        fwrite(STDOUT, "\n--dry-run: no files written. Sample generated config:\n\n");
        fwrite(STDOUT, $configBlocks[$missing[0]] . "\n");
        exit(0);
    }

    $editor = new SourceEditor($srcDir);
    $editor->insertBrowserConfigs($configBlocks);
    $editor->addBrowserNameConstants($missing);
    $editor->rewriteBrowserNameUnions($allNames);

    fwrite(STDOUT, "\n✓ Added " . count($missing) . " browser(s). Files updated:\n");
    foreach ($editor->modifiedFiles() as $f) {
        fwrite(STDOUT, "  - " . $f . "\n");
    }
    fwrite(STDOUT, "\nNext: run `composer format` then `composer test`.\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @return array{dry-run: bool, ref: string, patch-file: ?string}
 */
function parseArgs(array $argv): array
{
    $opts = ['dry-run' => false, 'ref' => 'main', 'patch-file' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $opts['dry-run'] = true;
        } elseif (str_starts_with($arg, '--ref=')) {
            $opts['ref'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--patch-file=')) {
            $opts['patch-file'] = substr($arg, 13);
        } else {
            fwrite(STDERR, "Unknown option: $arg\n");
            exit(1);
        }
    }

    return $opts;
}

function readLocalPatch(string $path): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new \RuntimeException("Cannot read patch file: $path");
    }

    return $content;
}
