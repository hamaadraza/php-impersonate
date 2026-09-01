<?php

namespace Raza\PHPImpersonate\Support;

/**
 * Parses a raw HTTP response header block into a name => values map.
 *
 * Shared by both clients so header handling is identical. Per RFC 9110 §5.3
 * repeated fields are valid, and Set-Cookie in particular must never be
 * comma-joined (RFC 6265 §4.1.1), so values are always kept as string[] lists.
 * On redirect chains only the final response's header block is retained.
 *
 * The status line is returned separately from the header map: it is not a
 * header, so callers iterating headers() never have to filter out a synthetic
 * entry that was never on the wire.
 */
final class ResponseHeaderParser
{
    /**
     * The response headers, without the status line.
     *
     * @return array<string, string[]>
     */
    public static function parse(string $headersContent): array
    {
        return self::parseAll($headersContent)['headers'];
    }

    /**
     * The final response's status line (e.g. "HTTP/2 200"), or null when the
     * block contains none.
     */
    public static function statusLine(string $headersContent): ?string
    {
        return self::parseAll($headersContent)['status'];
    }

    /**
     * Parse the block into its final status line and its header map.
     *
     * @return array{status: string|null, headers: array<string, string[]>}
     */
    public static function parseAll(string $headersContent): array
    {
        if (trim($headersContent) === '') {
            return ['status' => null, 'headers' => []];
        }

        /** @var array<string, string[]> $headers */
        $headers = [];
        $status = null;

        // Handle multiple HTTP responses (redirects) — keep only the final block.
        $sections = preg_split('/\r?\n\r?\n/', trim($headersContent));

        if (! $sections) {
            return ['status' => null, 'headers' => []];
        }

        $lines = explode("\n", (string) end($sections));

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'HTTP/')) {
                $status = $line;

                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));

                if ($name !== '') {
                    $headers[$name][] = $value;
                }
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }
}
