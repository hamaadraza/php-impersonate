<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

/**
 * Fetches and parses the upstream curl-impersonate patch that defines every
 * impersonation target (the `impersonate_opts impersonations[]` C table).
 */
final class UpstreamPatch
{
    private const PATCH_URL = 'https://raw.githubusercontent.com/lexiforest/curl-impersonate/%s/patches/curl.patch';

    /**
     * Download the curl.patch for a given git ref.
     */
    public static function download(string $ref): string
    {
        $url = sprintf(self::PATCH_URL, rawurlencode($ref));
        fwrite(STDOUT, "Downloading $url\n");
        $content = Http::get($url);
        if (! str_contains($content, 'impersonate_opts impersonations[]')) {
            throw new \RuntimeException('Downloaded patch does not contain the impersonation table (unexpected format).');
        }

        return $content;
    }

    /**
     * Parse all targets and their fields from the patch.
     *
     * @return array<string, array<string, mixed>> target name => fields
     */
    public static function parseTargets(string $patch): array
    {
        // The patch is a unified diff; added lines start with '+'. Reduce to the
        // added source so brace-matching mirrors the real C file.
        $lines = [];
        foreach (explode("\n", $patch) as $line) {
            if ($line === '') {
                $lines[] = '';
            } elseif ($line[0] === '+') {
                $lines[] = substr($line, 1);
            } else {
                // Context/removed lines are irrelevant to the added table.
                $lines[] = '';
            }
        }
        $src = implode("\n", $lines);

        $table = self::extractArrayBody($src, 'impersonate_opts impersonations[]');

        $targets = [];
        foreach (self::splitTopLevelEntries($table) as $entry) {
            if (! preg_match('/\.target\s*=\s*"([^"]+)"/', $entry, $m)) {
                continue;
            }
            $targets[$m[1]] = self::parseEntry($entry);
        }

        return $targets;
    }

    /**
     * Return the text between the outermost braces of `... <marker> ... = { BODY };`.
     */
    private static function extractArrayBody(string $src, string $marker): string
    {
        $pos = strpos($src, $marker);
        if ($pos === false) {
            throw new \RuntimeException("Marker not found: $marker");
        }
        $brace = strpos($src, '{', $pos);
        if ($brace === false) {
            throw new \RuntimeException("Opening brace not found after: $marker");
        }

        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace + 1, $i - $brace - 1);
                }
            }
        }

        throw new \RuntimeException("Unbalanced braces for: $marker");
    }

    /**
     * Split a struct-array body into its top-level `{ ... }` entries.
     *
     * @return list<string>
     */
    private static function splitTopLevelEntries(string $body): array
    {
        $entries = [];
        $depth = 0;
        $start = null;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i + 1;
                }
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $entries[] = substr($body, $start, $i - $start);
                    $start = null;
                }
            }
        }

        return $entries;
    }

    /**
     * Extract every field we care about from a single struct entry.
     *
     * @return array<string, mixed>
     */
    private static function parseEntry(string $entry): array
    {
        return [
            'target' => self::stringField($entry, 'target'),
            'httpversion' => self::rawScalar($entry, 'httpversion'),
            'ssl_version' => self::rawScalar($entry, 'ssl_version'),
            'ciphers' => self::concatString($entry, 'ciphers'),
            'curves' => self::concatString($entry, 'curves'),
            'sig_hash_algs' => self::concatString($entry, 'sig_hash_algs'),
            'headers' => self::headerList($entry),
            'http2_settings' => self::concatString($entry, 'http2_settings'),
            'http2_window_update' => self::rawScalar($entry, 'http2_window_update'),
            'http2_stream_weight' => self::rawScalar($entry, 'http2_stream_weight'),
            'http2_stream_exclusive' => self::rawScalar($entry, 'http2_stream_exclusive'),
            'http2_pseudo_headers_order' => self::concatString($entry, 'http2_pseudo_headers_order'),
            'http2_no_priority' => self::boolField($entry, 'http2_no_priority'),
            'alps' => self::boolField($entry, 'alps'),
            'tls_use_new_alps_codepoint' => self::boolField($entry, 'tls_use_new_alps_codepoint'),
            'tls_permute_extensions' => self::boolField($entry, 'tls_permute_extensions'),
            'tls_extension_order' => self::concatString($entry, 'tls_extension_order'),
            'tls_delegated_credentials' => self::concatString($entry, 'tls_delegated_credentials'),
            'cert_compression' => self::concatString($entry, 'cert_compression'),
            'tls_record_size_limit' => self::rawScalar($entry, 'tls_record_size_limit'),
            'tls_key_shares_limit' => self::rawScalar($entry, 'tls_key_shares_limit'),
            'tls_session_ticket_false' => (bool)preg_match('/\.tls_session_ticket\s*=\s*false/', $entry),
            'tls_grease' => self::boolField($entry, 'tls_grease'),
            'tls_signed_cert_timestamps' => self::boolField($entry, 'tls_signed_cert_timestamps'),
            'ech' => self::stringField($entry, 'ech'),
        ];
    }

    private static function stringField(string $entry, string $name): ?string
    {
        if (preg_match('/\.' . preg_quote($name, '/') . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $entry, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Join adjacent C string literals (used for long values split across lines).
     */
    private static function concatString(string $entry, string $name): ?string
    {
        if (! preg_match('/\.' . preg_quote($name, '/') . '\s*=\s*((?:\s*"(?:[^"\\\\]|\\\\.)*")+)/', $entry, $m)) {
            return null;
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $parts);

        return implode('', $parts[1]);
    }

    private static function rawScalar(string $entry, string $name): ?string
    {
        if (preg_match('/\.' . preg_quote($name, '/') . '\s*=\s*([^,\n}]+)/', $entry, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private static function boolField(string $entry, string $name): bool
    {
        return (bool)preg_match('/\.' . preg_quote($name, '/') . '\s*=\s*true/', $entry);
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function headerList(string $entry): array
    {
        if (! preg_match('/\.http_headers\s*=\s*\{(.*?)\n\s*\}/s', $entry, $m)) {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $matches);

        $headers = [];
        foreach ($matches[1] as $raw) {
            // C escapes embedded quotes; unescape for the header value.
            $header = str_replace('\\"', '"', $raw);
            $sep = strpos($header, ': ');
            if ($sep === false) {
                continue;
            }
            $headers[] = [
                'name' => substr($header, 0, $sep),
                'value' => substr($header, $sep + 2),
            ];
        }

        return $headers;
    }
}
