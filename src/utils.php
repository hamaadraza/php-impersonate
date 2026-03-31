<?php

namespace Raza\PHPImpersonate;

function isRunningAsPackage(): bool
{
    // Check if this package is inside another project's vendor directory
    return strpos(__DIR__, '/vendor/') !== false && ! file_exists(__DIR__ . '/../composer.json');
}
