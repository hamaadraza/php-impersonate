<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Platform;

use Raza\PHPImpersonate\Exception\InvalidArgumentException;

/**
 * Builds commands as argv arrays for proc_open()'s array mode.
 *
 * Array mode executes the program directly — no cmd.exe on Windows, no sh on
 * Unix — so values need no shell escaping and cannot be used for injection.
 * The library therefore never builds a shell command string.
 *
 * @internal Not part of the public API; used by {@see \Raza\PHPImpersonate\Process\CurlProcess}.
 */
final class CommandBuilder
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_CURL = 'curl';

    private const ALLOWED_TYPES = [self::TYPE_GENERIC, self::TYPE_CURL];

    /**
     * Build a command as an argv array for proc_open()'s array mode.
     *
     * @param string $executable The command executable
     * @param array<array-key,mixed> $arguments Positional arguments (appended last)
     * @param array<array-key,mixed> $options Command options
     * @param string $type Command type (generic or curl)
     * @return list<string> argv array, executable first
     * @throws InvalidArgumentException If parameters are invalid
     */
    public static function buildCommandArgs(
        string $executable,
        array $arguments = [],
        array $options = [],
        string $type = self::TYPE_GENERIC
    ): array {
        self::validateInputs($executable, $type);

        $args = [$executable];

        foreach ($options as $option => $value) {
            if (! is_string($option)) {
                continue; // Skip invalid option keys
            }

            $flag = self::getOptionPrefix($option, $type) . $option;

            if (is_bool($value)) {
                if ($value) {
                    $args[] = $flag;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item !== null) {
                        $args[] = $flag;
                        $args[] = (string)$item;
                    }
                }
            } elseif ($value !== null) {
                $args[] = $flag;
                $args[] = (string)$value;
            }
        }

        foreach ($arguments as $arg) {
            if ($arg !== null) {
                $args[] = (string)$arg;
            }
        }

        return $args;
    }

    /**
     * Build a curl command as an argv array for proc_open()'s array mode.
     *
     * @param string $executable The curl executable
     * @param array<array-key,mixed> $arguments Positional arguments (appended last)
     * @param array<array-key,mixed> $options Curl options
     * @return list<string> argv array, executable first
     */
    public static function buildCurlCommandArgs(
        string $executable,
        array $arguments = [],
        array $options = []
    ): array {
        return self::buildCommandArgs($executable, $arguments, $options, self::TYPE_CURL);
    }

    /**
     * Validate input parameters
     *
     * @throws InvalidArgumentException
     */
    private static function validateInputs(string $executable, string $type): void
    {
        if (trim($executable) === '') {
            throw new InvalidArgumentException('Executable cannot be empty');
        }

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid command type "%s". Allowed types: %s', $type, implode(', ', self::ALLOWED_TYPES))
            );
        }
    }

    /**
     * Get the appropriate option prefix based on type and option length
     */
    private static function getOptionPrefix(string $option, string $type): string
    {
        if ($type === self::TYPE_CURL && strlen($option) === 1) {
            return '-';
        }

        return '--';
    }
}
