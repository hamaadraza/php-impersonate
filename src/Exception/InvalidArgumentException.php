<?php

namespace Raza\PHPImpersonate\Exception;

/**
 * Thrown when a caller hands the library something it cannot use: an unusable
 * URL, a timeout outside the permitted range, an unknown engine or browser
 * name, an unsupported curl option, or data that cannot be encoded as a body.
 *
 * Extends PHP's own InvalidArgumentException, so existing
 * `catch (\InvalidArgumentException $e)` keeps working exactly as before, and
 * implements {@see PHPImpersonateException} so a caller can also catch every
 * error from this library with a single type.
 */
class InvalidArgumentException extends \InvalidArgumentException implements PHPImpersonateException
{
}
