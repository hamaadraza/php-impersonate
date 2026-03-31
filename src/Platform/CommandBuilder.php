<?php

namespace Raza\PHPImpersonate\Platform;

use RuntimeException;
use InvalidArgumentException;

class CommandBuilder
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_CURL = 'curl';

    private const ALLOWED_TYPES = [self::TYPE_GENERIC, self::TYPE_CURL];

    /**
     * Build a platform-specific command with proper escaping
     *
     * @param string $executable The command executable
     * @param array $arguments Command arguments
     * @param array $options Command options
     * @param string $type Command type (generic or curl)
     * @return string The escaped command string
     * @throws InvalidArgumentException If parameters are invalid
     * @throws RuntimeException If command building fails
     */
    public static function buildCommand(
        string $executable,
        array $arguments = [],
        array $options = [],
        string $type = self::TYPE_GENERIC
    ): string {
        self::validateInputs($executable, $type);

        try {
            $platform = PlatformDetector::getPlatform();

            return self::buildPlatformCommand($platform, $executable, $arguments, $options, $type);
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to build command: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build curl-specific command
     *
     * @param string $executable The curl executable
     * @param array $arguments Command arguments
     * @param array $options Curl options
     * @return string The escaped curl command
     */
    public static function buildCurlCommand(
        string $executable,
        array $arguments = [],
        array $options = []
    ): string {
        return self::buildCommand($executable, $arguments, $options, self::TYPE_CURL);
    }

    /**
     * Escape a path for the current platform
     *
     * @param string $path The path to escape
     * @return string The escaped path
     */
    public static function escapePath(string $path): string
    {
        if (empty($path)) {
            return $path;
        }

        $platform = PlatformDetector::getPlatform();

        if ($platform === PlatformDetector::PLATFORM_WINDOWS) {
            return self::escapeWindowsPath($path);
        }

        return self::escapeUnixPath($path);
    }

    /**
     * Validate input parameters
     *
     * @param string $executable
     * @param string $type
     * @throws InvalidArgumentException
     */
    private static function validateInputs(string $executable, string $type): void
    {
        if (empty(trim($executable))) {
            throw new InvalidArgumentException('Executable cannot be empty');
        }

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid command type "%s". Allowed types: %s', $type, implode(', ', self::ALLOWED_TYPES))
            );
        }
    }

    /**
     * Build command for specific platform
     *
     * @param string $platform
     * @param string $executable
     * @param array $arguments
     * @param array $options
     * @param string $type
     * @return string
     */
    private static function buildPlatformCommand(
        string $platform,
        string $executable,
        array $arguments,
        array $options,
        string $type
    ): string {
        $cmd = self::escapeExecutable($executable);
        $cmd .= self::formatOptions($options, $type);
        $cmd .= self::formatArguments($arguments);

        return $cmd;
    }

    /**
     * Escape the executable name
     *
     * @param string $executable
     * @return string
     * @throws RuntimeException
     */
    private static function escapeExecutable(string $executable): string
    {
        $escaped = escapeshellcmd($executable);

        if ($escaped === false) {
            throw new RuntimeException('Failed to escape executable');
        }

        return $escaped;
    }

    /**
     * Format all options based on command type
     *
     * @param array $options
     * @param string $type
     * @return string
     */
    private static function formatOptions(array $options, string $type): string
    {
        $formatted = '';

        foreach ($options as $option => $value) {
            if (! is_string($option)) {
                continue; // Skip invalid option keys
            }

            if (is_bool($value)) {
                if ($value) {
                    $formatted .= self::formatBooleanOption($option, $type);
                }
            } elseif (is_array($value)) {
                $formatted .= self::formatArrayOption($option, $value, $type);
            } elseif ($value !== null) {
                $formatted .= self::formatValueOption($option, $value, $type);
            }
        }

        return $formatted;
    }

    /**
     * Format boolean option (flag without value)
     *
     * @param string $option
     * @param string $type
     * @return string
     */
    private static function formatBooleanOption(string $option, string $type): string
    {
        $prefix = self::getOptionPrefix($option, $type);

        return " {$prefix}{$option}";
    }

    /**
     * Format array option (multiple values for same option)
     *
     * @param string $option
     * @param array $values
     * @param string $type
     * @return string
     */
    private static function formatArrayOption(string $option, array $values, string $type): string
    {
        $formatted = '';

        foreach ($values as $value) {
            if ($value !== null) {
                $formatted .= self::formatValueOption($option, $value, $type);
            }
        }

        return $formatted;
    }

    /**
     * Format option with value
     *
     * @param string $option
     * @param mixed $value
     * @param string $type
     * @return string
     */
    private static function formatValueOption(string $option, $value, string $type): string
    {
        $prefix = self::getOptionPrefix($option, $type);
        $escapedValue = self::escapeValue($value);

        return " {$prefix}{$option} {$escapedValue}";
    }

    /**
     * Get the appropriate option prefix based on type and option length
     *
     * @param string $option
     * @param string $type
     * @return string
     */
    private static function getOptionPrefix(string $option, string $type): string
    {
        if ($type === self::TYPE_CURL && strlen($option) === 1) {
            return '-';
        }

        return '--';
    }

    /**
     * Escape a single value
     *
     * @param mixed $value
     * @return string
     * @throws RuntimeException
     */
    private static function escapeValue($value): string
    {
        $stringValue = (string)$value;
        if (strlen($stringValue) > 8191) {
            throw new RuntimeException('Argument too long: ' . $stringValue);
        }

        $platform = PlatformDetector::getPlatform();

        if ($platform === PlatformDetector::PLATFORM_WINDOWS) {
            return self::escapeWindowsValue($stringValue);
        }

        return self::escapeUnixValue($stringValue);
    }

    /**
     * Escape value for Windows platform
     *
     * @param string $value
     * @return string
     */
    private static function escapeWindowsValue(string $value): string
    {
        // On Windows, use double quotes and escape internal quotes
        $value = str_replace('"', '\\"', $value);

        return '"' . $value . '"';
    }

    /**
     * Escape value for Unix platform (Linux/macOS)
     *
     * @param string $value
     * @return string
     */
    private static function escapeUnixValue(string $value): string
    {
        // Use PHP's built-in escapeshellarg for Unix platforms
        $escaped = escapeshellarg($value);

        if ($escaped === false) {
            throw new RuntimeException('Failed to escape Unix value');
        }

        return $escaped;
    }

    /**
     * Format all arguments
     *
     * @param array $arguments
     * @return string
     */
    private static function formatArguments(array $arguments): string
    {
        $formatted = '';

        foreach ($arguments as $arg) {
            if ($arg !== null) {
                $formatted .= ' ' . self::escapeValue($arg);
            }
        }

        return $formatted;
    }

    /**
     * Escape Windows path
     *
     * @param string $path
     * @return string
     */
    private static function escapeWindowsPath(string $path): string
    {
        // Handle UNC paths
        if (str_starts_with($path, '\\\\')) {
            return $path;
        }

        // Normalize path separators
        $path = str_replace('/', '\\', $path);

        // Escape backslashes and quotes
        $path = str_replace(['\\', '"'], ['\\\\', '\\"'], $path);

        // Handle paths with spaces
        if (str_contains($path, ' ')) {
            $path = '"' . $path . '"';
        }

        return $path;
    }

    /**
     * Escape Unix path
     *
     * @param string $path
     * @return string
     */
    private static function escapeUnixPath(string $path): string
    {
        // Use built-in escaping for Unix paths
        $escaped = escapeshellarg($path);

        if ($escaped === false) {
            throw new RuntimeException('Failed to escape Unix path');
        }

        return $escaped;
    }
}
