<?php

namespace Raza\PHPImpersonate\Support;

/**
 * Parses a raw HTTP response header block into a name => values map.
 *
 * Shared by both clients so header handling is identical. Per RFC 9110 §5.3
 * repeated fields are valid, and Set-Cookie in particular must never be
 * comma-joined (RFC 6265 §4.1.1), so values are always kept as string[] lists.
 * On redirect chains only the final response's header block is retained.
 */
final class ResponseHeaderParser
{
    /**
     * @return array<string, string[]>
     */
    public static function parse(string $headersContent): array
    {
        if (empty(trim($headersContent))) {
            return [];
        }

        /** @var array<string, string[]> $headers */
        $headers = [];

        // Handle multiple HTTP responses (redirects) — keep only the final block.
        $sections = preg_split('/\r?\n\r?\n/', trim($headersContent));

        if (! $sections) {
            return [];
        }

        $lastSection = end($sections);
        $lines = explode("\n", $lastSection);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Capture HTTP status line as a single-element list for type consistency.
            if (str_starts_with($line, 'HTTP/')) {
                $headers['HTTP_STATUS'] = [$line];

                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));

                if (! empty($name)) {
                    $headers[$name][] = $value;
                }
            }
        }

        return $headers;
    }
}
