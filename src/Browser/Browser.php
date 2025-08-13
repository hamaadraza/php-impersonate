<?php

namespace Raza\PHPImpersonate\Browser;

use Raza\PHPImpersonate\Config\Configuration;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use RuntimeException;

class Browser implements BrowserInterface
{
    private string $executablePath;
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
        $binaryDir = PlatformDetector::getBinaryDir();

        // Look for the main curl-impersonate binary
        $binaryFile = $platform === PlatformDetector::PLATFORM_WINDOWS ? 'curl.exe' : 'curl-impersonate';

        $paths = array_filter([
            // Package bin directory
            $this->buildPath(__DIR__ . "/../../{$binaryDir}", $binaryFile),
            // Vendor bin directory
            $this->buildPath(__DIR__ . "/../../../../{$binaryDir}", $binaryFile),
            // Platform-specific global paths
            ...$this->getSystemPaths($platform, $binaryFile),
        ]);

        foreach ($paths as $path) {
            if ($this->isUsableExecutable($path, $platform)) {
                $this->executablePath = $path;

                return;
            }

            // If it's a name only, try resolving via "which" / "where"
            if ($this->isCommandName($path)) {
                $resolved = $this->findInPath($path, $platform);
                if ($resolved) {
                    $this->executablePath = $resolved;

                    return;
                }
            }
        }

        throw new RuntimeException(sprintf(
            "curl-impersonate binary not found on %s. Checked paths: %s",
            $platform,
            implode(', ', $paths)
        ));
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
     */
    private function getSystemPaths(string $platform, string $binaryFile): array
    {
        return match ($platform) {
            PlatformDetector::PLATFORM_LINUX => ["/usr/local/bin/{$binaryFile}", $binaryFile],
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
        return $this->isAbsolutePath($path)
            && file_exists($path)
            && ($platform === PlatformDetector::PLATFORM_WINDOWS || is_executable($path));
    }

    /**
     * Find binary in system PATH
     */
    private function findInPath(string $command, string $platform): ?string
    {
        $whichCommand = Configuration::get('which_command') ?? ($platform === PlatformDetector::PLATFORM_WINDOWS ? 'where' : 'which');
        $errorRedirect = $platform === PlatformDetector::PLATFORM_WINDOWS ? '2>nul' : '2>/dev/null';

        $result = shell_exec("$whichCommand " . escapeshellarg($command) . " $errorRedirect");
        $resolvedPath = trim((string) $result);

        return ($resolvedPath && file_exists($resolvedPath)) ? $resolvedPath : null;
    }
}
