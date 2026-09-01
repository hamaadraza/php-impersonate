<?php

namespace Raza\PHPImpersonate\Browser;

use RuntimeException;
use Raza\PHPImpersonate\Config\Configuration;
use Raza\PHPImpersonate\Platform\PlatformDetector;

class Browser implements BrowserInterface
{
    /**
     * Per-process cache of --version verification results, keyed by binary path.
     * Verification spawns a process, so doing it once per path (instead of once
     * per client construction) matters for the static one-request helpers.
     *
     * @var array<string, bool>
     */
    private static array $verifiedBinaries = [];

    private string $executablePath;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param string $name Browser name (e.g., 'chrome99_android')
     * @throws RuntimeException If the browser is not found
     */
    public function __construct(private string $name)
    {
        $this->validateBrowser();
        $this->resolveExecutablePath();
    }

    public function getExecutablePath(): string
    {
        return $this->executablePath;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Validate that the browser configuration exists
     *
     * @throws RuntimeException If the browser is not supported
     */
    private function validateBrowser(): void
    {
        if (! BrowserConfig::hasConfig($this->name)) {
            $availableBrowsers = BrowserConfig::getAvailableBrowsers();

            throw new RuntimeException(sprintf(
                "Browser '%s' not supported. Available browsers: %s",
                $this->name,
                implode(', ', $availableBrowsers)
            ));
        }

        $this->config = BrowserConfig::getConfig($this->name);
    }

    /**
     * Resolve the executable path for the curl-impersonate binary
     *
     * @throws RuntimeException If the binary is not found
     */
    private function resolveExecutablePath(): void
    {
        $platform = PlatformDetector::getPlatform();
        $binaryFile = $this->getBinaryFileName($platform);

        // Get all paths to check (with fallbacks for backwards compatibility)
        $paths = $this->getAllPossiblePaths($binaryFile);

        foreach ($paths as $path) {
            if ($this->isUsableExecutable($path, $platform)) {
                $this->executablePath = $path;

                return;
            }

            // If it's a name only, try resolving via "which" / "where"
            if ($this->isCommandName($path)) {
                $resolved = $this->findInPath($path, $platform);

                // Verify it like any other candidate. On Windows the name being
                // searched is "curl.exe" and Windows 10+ ships a stock one in
                // System32 — accepting that would swap curl-impersonate for a
                // plain curl instead of reporting the binary as missing.
                if ($resolved !== null && $this->isCurlImpersonate($resolved, $platform)) {
                    $this->executablePath = $resolved;

                    return;
                }
            }
        }

        throw new RuntimeException(sprintf(
            "curl-impersonate binary not found for %s. This platform is not bundled; "
            . "run `php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install` "
            . "(or `composer install-binaries` from this package) to download it. Checked paths: %s",
            PlatformDetector::getPlatformDescription(),
            implode(', ', array_filter($paths))
        ));
    }

    /**
     * Get the binary file name for the platform
     */
    private function getBinaryFileName(string $platform): string
    {
        return match ($platform) {
            PlatformDetector::PLATFORM_WINDOWS => 'curl.exe',
            default => 'curl-impersonate',
        };
    }

    /**
     * Get all possible paths to check for the binary
     *
     * @return array<int,string>
     */
    private function getAllPossiblePaths(string $binaryFile): array
    {
        $platform = PlatformDetector::getPlatform();
        $paths = [];

        // Get fallback directories (includes primary and legacy paths)
        $binaryDirs = Configuration::getBinaryDirFallbacks();

        foreach ($binaryDirs as $binaryDir) {
            // Package bin directory (when installed as dependency)
            $packagePath = $this->buildPath(__DIR__ . "/../../{$binaryDir}", $binaryFile);
            if ($packagePath) {
                $paths[] = $packagePath;
            }

            // Vendor bin directory (alternative location)
            $vendorPath = $this->buildPath(__DIR__ . "/../../../../{$binaryDir}", $binaryFile);
            if ($vendorPath) {
                $paths[] = $vendorPath;
            }
        }

        // Add system paths
        $paths = array_merge($paths, $this->getSystemPaths($platform, $binaryFile));

        return array_filter($paths);
    }

    /**
     * Build a real path to a binary file if base dir exists
     */
    private function buildPath(string $baseDir, string $file): ?string
    {
        $realDir = realpath($baseDir);

        return $realDir ? $realDir . DIRECTORY_SEPARATOR . $file : null;
    }

    /**
     * Get system paths for different platforms
     *
     * @return list<string>
     */
    private function getSystemPaths(string $platform, string $binaryFile): array
    {
        return match ($platform) {
            PlatformDetector::PLATFORM_LINUX => ["/usr/local/bin/{$binaryFile}", $binaryFile],
            PlatformDetector::PLATFORM_MACOS => ["/usr/local/bin/{$binaryFile}", "/opt/homebrew/bin/{$binaryFile}", $binaryFile],
            PlatformDetector::PLATFORM_WINDOWS => [$binaryFile],
            default => [$binaryFile],
        };
    }

    /**
     * Check if the path is an absolute file path
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    /**
     * Check if a path is a command name (not absolute)
     */
    private function isCommandName(string $path): bool
    {
        return ! $this->isAbsolutePath($path);
    }

    /**
     * Determine if the path is a usable executable
     */
    private function isUsableExecutable(string $path, string $platform): bool
    {
        if (! $this->isAbsolutePath($path) || ! file_exists($path)) {
            return false;
        }

        if ($platform !== PlatformDetector::PLATFORM_WINDOWS && ! is_executable($path)) {
            return false;
        }

        // Verify this is actually curl-impersonate, not regular curl
        return $this->isCurlImpersonate($path, $platform);
    }

    /**
     * Verify that the binary is actually curl-impersonate
     */
    private function isCurlImpersonate(string $path, string $platform): bool
    {
        if (isset(self::$verifiedBinaries[$path])) {
            return self::$verifiedBinaries[$path];
        }

        // Since PHP 8.0 a name in disable_functions behaves as undefined and
        // throws an Error, which `@` cannot suppress — so ask first. A host that
        // has disabled it cannot run the executable engine anyway; report the
        // binary as unusable and let the caller fall back or say so plainly,
        // rather than dying with "call to undefined function".
        if (! function_exists('shell_exec')) {
            return self::$verifiedBinaries[$path] = false;
        }

        $errorRedirect = $platform === PlatformDetector::PLATFORM_WINDOWS ? '2>nul' : '2>/dev/null';
        $versionCommand = escapeshellarg($path) . ' --version ' . $errorRedirect;

        $output = shell_exec($versionCommand);

        // Check if the output contains "IMPERSONATE" which indicates curl-impersonate
        return self::$verifiedBinaries[$path] = ($output && str_contains($output, 'IMPERSONATE'));
    }

    /**
     * Find binary in system PATH
     */
    private function findInPath(string $command, string $platform): ?string
    {
        // See isCurlImpersonate(): a disabled shell_exec throws rather than
        // returning false, and `@` does not stop it.
        if (! function_exists('shell_exec')) {
            return null;
        }

        $default = $platform === PlatformDetector::PLATFORM_WINDOWS ? 'where' : 'which';
        $configured = Configuration::get('which_command');

        // This string is interpolated into a shell command, and the platform
        // config is publicly settable, so accept only a bare command name/path.
        $whichCommand = is_string($configured) && preg_match('#^[\w.:/\\\\-]+$#', $configured)
            ? $configured
            : $default;

        $errorRedirect = $platform === PlatformDetector::PLATFORM_WINDOWS ? '2>nul' : '2>/dev/null';

        $result = shell_exec("$whichCommand " . escapeshellarg($command) . " $errorRedirect");

        // Windows "where" prints one path per line when there are multiple matches
        $lines = preg_split('/\r?\n/', trim((string) $result)) ?: [];
        $resolvedPath = trim($lines[0] ?? '');

        return ($resolvedPath && file_exists($resolvedPath)) ? $resolvedPath : null;
    }
}
