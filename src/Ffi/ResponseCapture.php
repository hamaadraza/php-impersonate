<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Ffi;

/**
 * The buffers libcurl's write callbacks append to, shared between one engine
 * and the two closures it registers once.
 *
 * A separate object rather than engine properties, on purpose: PHP allocates
 * a libffi trampoline for every closure assigned to a C function-pointer
 * field and never frees it, so those closures live for the rest of the
 * process. A closure that captured the engine itself would keep every
 * evicted engine — and its curl handle and connections — alive forever.
 * Capturing this small object instead costs a few bytes per engine, and its
 * buffers are emptied after every request.
 *
 * @internal Not part of the public API.
 */
final class ResponseCapture
{
    public string $body = '';

    public string $headers = '';

    /** An exception raised inside a callback, to be rethrown once libcurl returns. */
    public ?\Throwable $error = null;

    public function reset(): void
    {
        $this->body = '';
        $this->headers = '';
        $this->error = null;
    }
}
