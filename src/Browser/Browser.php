<?php

namespace Raza\PHPImpersonate\Browser;

use Raza\PHPImpersonate\Config\Configuration;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use RuntimeException;

class Browser implements BrowserInterface
{
    private string $executablePath;

    /**
     * @param string $name Browser name (e.g., 'chrome99_android')
     * @throws RuntimeException If the browser is not found
     */
    public function __construct(
        private string $name
    ) {
        $this->resolveExecutablePath();
    }

    /**
     * @inheritDoc
     */
    public function getExecutablePath(): string
    {
        return $this->executablePath;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Resolve the executable path for the browser
     *
     * @throws RuntimeException If the browser is not found
     */
    private function resolveExecutablePath(): void
    {
        $platform = PlatformDetector::getPlatform();
        $extension = PlatformDetector::getFileExtension();
        $binaryDir = PlatformDetector::getBinaryDir();

        $paths = [
            // Package bin directory with platform-specific path
            realpath(__DIR__."/../../{$binaryDir}") . "/curl_{$this->name}{$extension}",
            // Vendor bin directory with platform-specific path
            realpath(__DIR__ . "/../../../../{$binaryDir}") . "/curl_{$this->name}{$extension}",
        ];

        // Add platform-specific system paths
        if ($platform === PlatformDetector::PLATFORM_LINUX) {
            $paths[] = "/usr/local/bin/curl_{$this->name}";
            $paths[] = "curl_{$this->name}";
        } elseif ($platform === PlatformDetector::PLATFORM_WINDOWS) {
            $paths[] = "curl_{$this->name}{$extension}";
        }

        foreach ($paths as $path) {
            // For absolute paths, check if file exists
            if (strpos($path, '/') === 0 || strpos($path, '\\') === 0) {
                if (file_exists($path) && ($platform === PlatformDetector::PLATFORM_WINDOWS || is_executable($path))) {
                    $this->executablePath = $path;

                    return;
                }
            }

            // For PATH-based executables, use platform-specific commands
            if (strpos($path, '/') !== 0 && strpos($path, '\\') !== 0) {
                $result = null;
                $whichCommand = Configuration::get('which_command');
                $errorRedirect = $platform === PlatformDetector::PLATFORM_WINDOWS ? '2>nul' : '2>/dev/null';

                $result = shell_exec("$whichCommand $path $errorRedirect");

                if ($result && trim($result) && file_exists(trim($result))) {
                    $this->executablePath = trim($result);

                    return;
                }
            }
        }

        throw new RuntimeException(
            "Browser '{$this->name}' not supported on {$platform} - executable not found. " .
            "Checked paths: " . implode(", ", $paths)
        );
    }
}
