<?php

namespace Raza\PHPImpersonate\Support;

use Raza\PHPImpersonate\Exception\RequestException;

/**
 * Locates a CA certificate bundle. curl-impersonate uses BoringSSL, which does
 * not auto-discover the system trust store on Linux, so an explicit path is
 * needed there; macOS ships one at a well-known path; Windows uses the native
 * store (no file path).
 */
final class CaBundle
{
    /**
     * Common CA bundle locations across Linux distributions.
     *
     * @var list<string>
     */
    private const LINUX_PATHS = [
        '/etc/ssl/certs/ca-certificates.crt',                 // Debian/Ubuntu/Gentoo
        '/etc/pki/tls/certs/ca-bundle.crt',                   // RHEL/CentOS/Fedora
        '/etc/ssl/ca-bundle.pem',                             // openSUSE
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',  // CentOS/RHEL 7+
        '/etc/ssl/certs/ca-bundle.crt',                       // Some distros
        '/var/lib/ca-certificates/ca-bundle.pem',             // Some distros
        '/etc/ssl/cert.pem',                                  // Alpine/BSD-like
        '/usr/local/share/certs/ca-root-nss.crt',             // FreeBSD
        '/etc/pki/tls/cert.pem',                              // Fedora/RHEL alternative
    ];

    /**
     * Environment overrides, in curl's own order of precedence.
     *
     * @var list<string>
     */
    private const ENV_VARS = ['CURL_CA_BUNDLE', 'SSL_CERT_FILE'];

    /**
     * Resolve a readable CA bundle file path, or null if none is found (Windows,
     * or a system where the native trust store should be used instead).
     *
     * The environment is consulted FIRST: setting CURL_CA_BUNDLE or
     * SSL_CERT_FILE is how an operator points the client at a specific bundle,
     * and it must not be silently ignored just because the distro also ships one.
     * An override that is set but unusable therefore throws rather than falling
     * back — see the check below. ({@see directory()} still falls back, because
     * SSL_CERT_DIR supplements a bundle rather than replacing it.)
     *
     * @throws RequestException If an override is set but is not a readable file.
     */
    public static function path(): ?string
    {
        foreach (self::ENV_VARS as $var) {
            $value = getenv($var);
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (is_file($value) && is_readable($value)) {
                return $value;
            }

            // Set but unusable: fail rather than fall through to the system
            // store. An operator who points this at a corporate CA is usually
            // NARROWING what to trust, so silently substituting the distro
            // bundle widens it back — the opposite of the instruction, and
            // invisible. curl fails closed on an unreadable CA file too.
            throw new RequestException(sprintf(
                '%s is set to "%s", which is not a readable file. Fix the path or unset '
                . 'the variable to use the system trust store.',
                $var,
                $value
            ));
        }

        foreach (self::LINUX_PATHS as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Resolve a readable CA directory from SSL_CERT_DIR, or null when unset.
     *
     * Complements {@see path()}: OpenSSL-style setups may provide a hashed
     * directory instead of (or alongside) a single bundle file.
     */
    public static function directory(): ?string
    {
        $value = getenv('SSL_CERT_DIR');

        if (is_string($value) && $value !== '' && is_dir($value) && is_readable($value)) {
            return $value;
        }

        return null;
    }
}
