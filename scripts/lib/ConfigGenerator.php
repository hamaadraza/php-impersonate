<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

/**
 * Turns a parsed upstream target into a PHP config array entry, in the exact
 * shape and conventions BrowserConfig::getAllConfigs() already uses.
 */
final class ConfigGenerator
{
    /** Indentation of a config entry key inside getAllConfigs() (12 spaces). */
    private const INDENT = '            ';

    /**
     * @param array<string, mixed> $t Parsed target fields.
     */
    public static function toPhpArrayEntry(string $name, array $t): string
    {
        $i = self::INDENT;
        $lines = [];
        $lines[] = $i . self::str($name) . ' => [';

        // Emit the full upstream fingerprint verbatim. Post-quantum signature
        // algorithms (mldsa*) require a recent BoringSSL — keep the bundled
        // binaries current with scripts/update-binaries.php so configs match.
        $sig = $t['sig_hash_algs'];

        $lines[] = $i . '    ' . self::str('ciphers') . ' => ' . self::str((string)$t['ciphers']) . ',';
        if ($t['curves'] !== null) {
            $lines[] = $i . '    ' . self::str('curves') . ' => ' . self::str($t['curves']) . ',';
        }
        if ($sig !== null) {
            $lines[] = $i . '    ' . self::str('signature-hashes') . ' => ' . self::str($sig) . ',';
        }

        // Headers
        $lines[] = $i . '    ' . self::str('headers') . ' => [';
        foreach ($t['headers'] as $h) {
            // Normalise the User-Agent key casing to the package convention so
            // BrowserConfigTest's assertArrayHasKey('User-Agent') holds; other
            // header names are kept as upstream (HTTP/2 lowercases on the wire).
            $key = strcasecmp($h['name'], 'User-Agent') === 0 ? 'User-Agent' : $h['name'];
            $lines[] = $i . '        ' . self::str($key) . ' => ' . self::str($h['value']) . ',';
        }
        $lines[] = $i . '    ],';

        // Options
        $lines[] = $i . '    ' . self::str('options') . ' => [';
        foreach (self::buildOptions($t) as [$k, $v]) {
            $lines[] = $i . '        ' . self::str($k) . ' => ' . $v . ',';
        }
        $lines[] = $i . '    ],';

        $lines[] = $i . '],';

        return implode("\n", $lines);
    }

    /**
     * Build the ordered options list. Values are pre-rendered PHP literals
     * ('true' or a quoted string) so the caller just concatenates.
     *
     * @param array<string, mixed> $t
     * @return list<array{0: string, 1: string}>
     */
    private static function buildOptions(array $t): array
    {
        $opts = [];

        if (self::isHttp2($t['httpversion'])) {
            $opts[] = ['http2', 'true'];
        }
        self::pushStr($opts, 'http2-settings', $t['http2_settings']);
        self::pushStr($opts, 'http2-window-update', $t['http2_window_update']);
        self::pushStr($opts, 'http2-stream-weight', $t['http2_stream_weight']);
        self::pushStr($opts, 'http2-stream-exclusive', $t['http2_stream_exclusive']);
        self::pushStr($opts, 'http2-pseudo-headers-order', $t['http2_pseudo_headers_order']);
        if ($t['http2_no_priority']) {
            $opts[] = ['http2-no-priority', 'true'];
        }

        // curl-impersonate is always invoked with --compressed by upstream.
        $opts[] = ['compressed', 'true'];

        // ECH: upstream uses "true"/"grease"; this package's convention is
        // 'grease' (Chrome/Firefox send a GREASE ECH extension when no real
        // ECHConfig is available, which is the observed browser behaviour).
        if ($t['ech'] !== null && $t['ech'] !== 'false' && $t['ech'] !== '') {
            $opts[] = ['ech', self::str('grease')];
        }

        foreach (self::tlsVersionFlags($t['ssl_version']) as $flag) {
            $opts[] = [$flag, 'true'];
        }

        if ($t['alps']) {
            $opts[] = ['alps', 'true'];
        }
        if ($t['tls_use_new_alps_codepoint']) {
            $opts[] = ['tls-use-new-alps-codepoint', 'true'];
        }
        if ($t['tls_permute_extensions']) {
            $opts[] = ['tls-permute-extensions', 'true'];
        }
        self::pushStr($opts, 'tls-extension-order', $t['tls_extension_order']);
        self::pushStr($opts, 'tls-delegated-credentials', $t['tls_delegated_credentials']);
        self::pushStr($opts, 'cert-compression', $t['cert_compression']);
        self::pushStr($opts, 'tls-record-size-limit', $t['tls_record_size_limit']);
        self::pushStr($opts, 'tls-key-shares-limit', $t['tls_key_shares_limit']);
        if ($t['tls_session_ticket_false']) {
            $opts[] = ['no-tls-session-ticket', 'true'];
        }
        if ($t['tls_grease']) {
            $opts[] = ['tls-grease', 'true'];
        }
        if ($t['tls_signed_cert_timestamps']) {
            $opts[] = ['tls-signed-cert-timestamps', 'true'];
        }

        return $opts;
    }

    /**
     * @param list<array{0: string, 1: string}> $opts
     */
    private static function pushStr(array &$opts, string $key, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $opts[] = [$key, self::str($value)];
        }
    }

    private static function isHttp2(?string $httpversion): bool
    {
        // e.g. "CURL_HTTP_VERSION_2_0"; treat 2.0 (and the common default) as http2.
        return $httpversion === null || str_contains($httpversion, '2_0');
    }

    /**
     * Map the ssl_version enum to this package's tlsvX.Y option flags.
     *
     * @return list<string>
     */
    private static function tlsVersionFlags(?string $sslVersion): array
    {
        if ($sslVersion === null) {
            return ['tlsv1.2'];
        }
        if (str_contains($sslVersion, 'TLSv1_0')) {
            return ['tlsv1.0'];
        }
        if (str_contains($sslVersion, 'TLSv1_3')) {
            return ['tlsv1.3'];
        }

        return ['tlsv1.2'];
    }

    private static function str(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }
}
