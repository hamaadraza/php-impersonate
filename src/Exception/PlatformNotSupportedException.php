<?php

namespace Raza\PHPImpersonate\Exception;

use RuntimeException;

/**
 * Extends RuntimeException so that it sits under the same root as the rest of
 * the library's errors. As a bare Exception it fell outside every catch the
 * documentation advertises — including `catch (RequestException)`, which the
 * README's error-handling section shows around client construction, the one
 * place this is thrown from. An unsupported architecture is precisely the
 * situation that error handling exists for, so it must not be the one that
 * escapes it.
 */
class PlatformNotSupportedException extends RuntimeException implements PHPImpersonateException
{
    /**
     * @param list<string> $supportedPlatforms
     * @param list<string>|null $supportedArchitectures
     */
    public function __construct(
        string $platform,
        array $supportedPlatforms = [],
        ?string $architecture = null,
        ?array $supportedArchitectures = null
    ) {
        $message = "Platform '{$platform}'";

        if ($architecture !== null) {
            $message .= " with architecture '{$architecture}'";
        }

        $message .= " is not supported.";

        if (! empty($supportedPlatforms)) {
            $message .= " Supported platforms: " . implode(', ', $supportedPlatforms) . ".";
        }

        if (! empty($supportedArchitectures)) {
            $message .= " Supported architectures: " . implode(', ', $supportedArchitectures) . ".";
        }

        parent::__construct($message);
    }
}
