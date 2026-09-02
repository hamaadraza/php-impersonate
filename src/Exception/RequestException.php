<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Exception;

use RuntimeException;

class RequestException extends RuntimeException implements PHPImpersonateException
{
    /**
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string|null $command The command that failed (if applicable)
     * @param array<string> $output Command output (if applicable)
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private ?string $command = null,
        private array $output = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the command that failed
     *
     * @return string|null
     */
    public function getCommand(): ?string
    {
        return $this->command;
    }

    /**
     * Get the command output
     *
     * @return array<string>
     */
    public function getOutput(): array
    {
        return $this->output;
    }
}
