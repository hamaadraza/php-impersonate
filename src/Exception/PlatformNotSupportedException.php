<?php

namespace Raza\PHPImpersonate\Exception;

use Exception;

class PlatformNotSupportedException extends Exception
{
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
