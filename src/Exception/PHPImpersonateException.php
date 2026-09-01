<?php

namespace Raza\PHPImpersonate\Exception;

use Throwable;

/**
 * Implemented by every exception this library throws.
 *
 * The library raises two kinds of error, and the distinction is worth keeping:
 * a {@see RequestException} is a runtime failure (DNS, TLS, a broken transfer),
 * while an {@see InvalidArgumentException} is a caller mistake (an unusable URL,
 * a timeout out of range, an unsupported option). Because the second extends
 * PHP's LogicException rather than RuntimeException, no single built-in parent
 * covers both, and the documented `catch (RequestException $e)` silently missed
 * every argument error — `PHPImpersonate::get('not a url')`, thrown from inside
 * send(), escaped the exact catch the README teaches.
 *
 * This interface gives callers one type that covers everything:
 *
 *     try {
 *         $response = PHPImpersonate::get($url);
 *     } catch (PHPImpersonateException $e) {
 *         // any failure originating in this library
 *     }
 *
 * Catching the concrete classes still works unchanged, so nothing existing
 * breaks: the exceptions keep their previous parents.
 */
interface PHPImpersonateException extends Throwable
{
}
