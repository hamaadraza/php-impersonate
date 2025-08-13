<?php

namespace Raza\PHPImpersonate\Browser;

interface BrowserInterface
{
    /**
     * Get the browser executable path
     *
     * @return string
     */
    public function getExecutablePath(): string;

    /**
     * Get the browser name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the browser configuration
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array;
}
