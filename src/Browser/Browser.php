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
     * Whether a candidate could not be verified because this host permits
     * neither proc_open() nor shell_exec(). Remembered so the final error names
     * the real cause instead of reporting the binary as absent.
     */
    private bool $verificationUnavailable = false;

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
        // The executable engine cannot run at all without proc_open(); say so
        // now, in one sentence, rather than after a successful-looking
        // resolution — and rather than with the bare \Error PHP raises for a
        // name in disable_functions.
        if (! function_exists('proc_open')) {
            throw new RuntimeException(
                'The executable engine needs proc_open(), which this host disables '
                . '(see the disable_functions ini setting). Enable it, or use the FFI engine, which spawns no process.'
            );
        }

        $platform = PlatformDetector::getPlatform();
        $binaryFile = $this->getBinaryFileName($platform);

        // This package's own bin/ directories first. A binary there shipped
        // with the package (or was put there by its installer), so it is used
        // as-is — running `--version` on it proved nothing a checksum did not,
        // and cost a process spawn per PHP process, which under PHP-FPM is per
        // web request.
        foreach ($this->getBundledPaths($binaryFile) as $path) {
            if ($this->isExecutableFile($path, $platform)) {
                $this->executablePath = $path;

                return;
            }
        }

        // Anything found elsewhere IS verified to be curl-impersonate: on
        // Windows the searched name is "curl.exe" and Windows 10+ ships a
        // stock one in System32, and on Linux a plain curl can sit at
        // /usr/local/bin under the impersonate name.
        $paths = $this->getSystemPaths($platform, $binaryFile);

        foreach ($paths as $path) {
            if ($this->isAbsolutePath($path)) {
                if ($this->isExecutableFile($path, $platform) && $this->isCurlImpersonate($path, $platform)) {
                    $this->executablePath = $path;

                    return;
                }

                continue;
            }

            // A bare name: look it up on PATH.
            $resolved = $this->findInPath($path, $platform);

            if ($resolved !== null && $this->isCurlImpersonate($resolved, $platform)) {
                $this->executablePath = $resolved;

                return;
            }
        }

        $checked = array_merge($this->getBundledPaths($binaryFile), $paths);

        if ($this->verificationUnavailable) {
            throw new RuntimeException(sprintf(
                'A curl-impersonate candidate was found for %s but could not be verified: this host disables both '
                . 'proc_open() and shell_exec(), and only a binary inside this package\'s bin/ directory is trusted '
                . 'without running it. Install it there with `php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install`, '
                . 'or use the FFI engine. Checked paths: %s',
                PlatformDetector::getPlatformDescription(),
                implode(', ', $checked)
            ));
        }

        throw new RuntimeException(sprintf(
            "curl-impersonate binary not found for %s. This platform is not bundled; "
            . "run `php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install` "
            . "(or `composer install-binaries` from this package) to download it. Checked paths: %s",
            PlatformDetector::getPlatformDescription(),
            implode(', ', $checked)
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
     * Candidate paths inside this package's own bin/ directory, in order of
     * preference (platform-arch[-musl], then the legacy platform-only dir).
     *
     * @return list<string>
     */
    private function getBundledPaths(string $binaryFile): array
    {
        $paths = [];

        foreach (Configuration::getBinaryDirFallbacks() as $binaryDir) {
            $packagePath = $this->buildPath(__DIR__ . "/../../{$binaryDir}", $binaryFile);
            if ($packagePath !== null) {
                $paths[] = $packagePath;
            }
        }

        return $paths;
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
     * Whether the path is an existing file this process may execute.
     */
    private function isExecutableFile(string $path, string $platform): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return $platform === PlatformDetector::PLATFORM_WINDOWS || is_executable($path);
    }

    /**
     * Verify that the binary is actually curl-impersonate by running
     * `--version` and looking for the IMPERSONATE marker.
     *
     * Through proc_open() in array mode — no shell — so a host that disables
     * shell_exec() but not proc_open() (the combination the executable engine
     * actually needs) still resolves its binary. shell_exec() is only the
     * fallback for the reverse, unusual combination.
     */
    private function isCurlImpersonate(string $path, string $platform): bool
    {
        if (isset(self::$verifiedBinaries[$path])) {
            return self::$verifiedBinaries[$path];
        }

        $output = $this->runVersion($path, $platform);

        if ($output === null) {
            $this->verificationUnavailable = true;

            return false;
        }

        return self::$verifiedBinaries[$path] = str_contains($output, 'IMPERSONATE');
    }

    /**
     * The output of `<path> --version`, or null when no way to run it exists.
     */
    private function runVersion(string $path, string $platform): ?string
    {
        if (function_exists('proc_open')) {
            $process = @proc_open(
                [$path, '--version'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (! is_resource($process)) {
                return '';
            }

            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return $output;
        }

        // Since PHP 8.0 a name in disable_functions behaves as undefined and
        // throws an Error, which `@` cannot suppress — so ask first.
        if (function_exists('shell_exec')) {
            $errorRedirect = $platform === PlatformDetector::PLATFORM_WINDOWS ? '2>nul' : '2>/dev/null';

            return (string) shell_exec(escapeshellarg($path) . ' --version ' . $errorRedirect);
        }

        return null;
    }

    /**
     * Find a bare command name on PATH.
     *
     * A native scan of the PATH directories comes first: it needs no
     * subprocess and no shell. The configured `which`/`where` command remains
     * as a fallback for the odd layout the scan does not understand (it is
     * interpolated into a shell command, hence the allow-list on its value).
     */
    private function findInPath(string $command, string $platform): ?string
    {
        $scanned = $this->scanPath($command, $platform);
        if ($scanned !== null) {
            return $scanned;
        }

        // See runVersion(): a disabled shell_exec throws rather than returning
        // false, and `@` does not stop it.
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

    /**
     * The first PATH directory holding an executable of this name, or null.
     */
    private function scanPath(string $command, string $platform): ?string
    {
        $pathEnv = getenv('PATH');
        if (! is_string($pathEnv) || $pathEnv === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }

            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $command;

            if ($this->isExecutableFile($candidate, $platform)) {
                return $candidate;
            }
        }

        return null;
    }
}
