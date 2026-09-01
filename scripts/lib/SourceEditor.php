<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

/**
 * Applies the generated browser data to the package source files.
 */
final class SourceEditor
{
    /**
     * The files that declare a `@phpstan-type BrowserName` union. Every one of
     * them must actually contain the docblock — {@see rewriteBrowserNameUnions()}
     * fails if it does not, rather than quietly rewriting nothing.
     * `Browser/BrowserName.php` is not here: it carries the constants, not a union.
     */
    private const UNION_FILES = [
        'PHPImpersonate.php',
        'PHPImpersonateFactory.php',
    ];

    /** @var array<string, true> */
    private array $modified = [];

    public function __construct(private string $srcDir)
    {
    }

    /**
     * Insert generated config blocks just before the closing of
     * BrowserConfig::getAllConfigs().
     *
     * @param array<string, string> $configBlocks name => PHP entry text
     */
    public function insertBrowserConfigs(array $configBlocks): void
    {
        $file = $this->srcDir . '/Browser/BrowserConfig.php';
        $src = $this->read($file);

        $anchor = "        ];\n    }\n\n    /**\n     * Get configuration for a specific browser";
        if (! str_contains($src, $anchor)) {
            throw new \RuntimeException('Could not locate getAllConfigs() end anchor in BrowserConfig.php');
        }

        $insertion = implode("\n", array_values($configBlocks)) . "\n";
        $src = str_replace($anchor, $insertion . $anchor, $src);

        $this->write($file, $src);
    }

    /**
     * Add class constants and getAll() entries for new browsers.
     *
     * @param list<string> $names
     */
    public function addBrowserNameConstants(array $names): void
    {
        $file = $this->srcDir . '/Browser/BrowserName.php';
        $src = $this->read($file);

        $constLines = [];
        $getAllLines = [];
        foreach ($names as $name) {
            $const = self::constName($name);
            if (str_contains($src, "const $const ")) {
                continue; // idempotent
            }
            $constLines[] = "    public const $const = '$name';";
            $getAllLines[] = "            self::$const,";
        }

        if ($constLines !== []) {
            $constAnchor = "\n    /**\n     * Get all available browser name constants";
            $block = "\n    // Added by scripts/update-browsers.php\n" . implode("\n", $constLines) . "\n";
            $src = str_replace($constAnchor, $block . $constAnchor, $src);
        }

        if ($getAllLines !== []) {
            // Insert before the closing of the getAll() return array (end of class).
            $getAllAnchor = "        ];\n    }\n}";
            if (! str_contains($src, $getAllAnchor)) {
                throw new \RuntimeException('Could not locate getAll() end anchor in BrowserName.php');
            }
            $src = str_replace($getAllAnchor, implode("\n", $getAllLines) . "\n" . $getAllAnchor, $src);
        }

        $this->write($file, $src);
    }

    /**
     * Rewrite the @phpstan-type BrowserName union in every file that declares it.
     *
     * @param list<string> $allNames complete ordered list of browser names
     */
    public function rewriteBrowserNameUnions(array $allNames): void
    {
        $union = implode('|', array_map(fn ($n) => "'$n'", $allNames));

        foreach (self::UNION_FILES as $rel) {
            $file = $this->srcDir . '/' . $rel;
            $src = $this->read($file);
            $new = preg_replace(
                '/(@phpstan-type BrowserName )\S.*/',
                '$1' . self::pregReplacement($union),
                $src,
                -1,
                $count
            );

            // Loudly, not silently: a rewrite that matches nothing means the
            // docblock moved or was renamed, and the union would otherwise be
            // left stale while the script reported success.
            if ($new === null || $count === 0) {
                throw new \RuntimeException("No @phpstan-type BrowserName union found in $rel");
            }

            if ($new !== $src) {
                $this->write($file, $new);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function modifiedFiles(): array
    {
        return array_keys($this->modified);
    }

    private static function constName(string $name): string
    {
        if (! preg_match('/^([a-z]+)(.+)$/', $name, $m)) {
            return strtoupper($name);
        }

        return strtoupper($m[1]) . '_' . strtoupper($m[2]);
    }

    /** Escape a literal replacement for preg_replace ($ and \\). */
    private static function pregReplacement(string $s): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $s);
    }

    private function read(string $file): string
    {
        $src = @file_get_contents($file);
        if ($src === false) {
            throw new \RuntimeException("Cannot read $file");
        }

        return $src;
    }

    private function write(string $file, string $src): void
    {
        if (file_put_contents($file, $src) === false) {
            throw new \RuntimeException("Cannot write $file");
        }
        $this->modified[$file] = true;
    }
}
