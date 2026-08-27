<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

/**
 * Minimal HTTP downloader for the update scripts. Prefers the curl CLI (present
 * on any dev box that builds this package) and falls back to PHP streams.
 */
final class Http
{
    public static function get(string $url): string
    {
        $body = self::viaCurl($url, null);
        if ($body !== null) {
            return $body;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'follow_location' => 1,
            'timeout' => 120,
            'header' => "User-Agent: php-impersonate-updater\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException("Download failed: $url");
        }

        return $body;
    }

    /**
     * Stream a URL directly to a destination file (for large binary archives).
     */
    public static function download(string $url, string $destPath): void
    {
        $body = self::viaCurl($url, $destPath);
        if ($body !== null) {
            return;
        }

        $in = @fopen($url, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Download failed: $url");
        }
        $out = fopen($destPath, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    /**
     * Returns the body (when $destPath is null) or empty string on success
     * writing to a file; null if the curl CLI is unavailable so the caller
     * can fall back.
     */
    private static function viaCurl(string $url, ?string $destPath): ?string
    {
        $curl = self::findCurl();
        if ($curl === null) {
            return null;
        }

        $cmd = escapeshellarg($curl) . ' -sSL --fail --retry 3 --max-time 300';
        if ($destPath !== null) {
            $cmd .= ' -o ' . escapeshellarg($destPath);
        }
        $cmd .= ' ' . escapeshellarg($url) . ' 2>/dev/null';

        $out = [];
        $code = 0;
        exec($cmd . ($destPath === null ? '' : ' && echo OK'), $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Download failed (curl exit $code): $url");
        }

        return $destPath === null ? implode("\n", $out) : '';
    }

    private static function findCurl(): ?string
    {
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
        $out = @shell_exec("$which curl 2>/dev/null");
        $path = $out ? trim(strtok($out, "\n")) : '';

        return $path !== '' ? $path : null;
    }
}
