<?php

namespace Raza\PHPImpersonate\Support;

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
     * Resolve a readable CA bundle file path, or null if none is found (Windows,
     * or a system where the native/native-store should be used instead).
     */
    public static function path(): ?string
    {
        foreach (self::LINUX_PATHS as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile !== false && file_exists($envCertFile) && is_readable($envCertFile)) {
            return $envCertFile;
        }

        return null;
    }
}
