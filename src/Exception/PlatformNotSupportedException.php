<?php

namespace Raza\PHPImpersonate\Exception;

use Exception;

class PlatformNotSupportedException extends Exception
{
    public function __construct(string $platform, array $supportedPlatforms = [])
    {
        $message = "Platform '{$platform}' is not supported. ";

        if (! empty($supportedPlatforms)) {
            $message .= "Supported platforms: " . implode(', ', $supportedPlatforms);
        }

        parent::__construct($message);
    }
}
