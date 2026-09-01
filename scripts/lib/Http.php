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

        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("Cannot write to $destPath");
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false) {
            throw new \RuntimeException("Download failed while writing $destPath");
        }
    }

    /**
     * Locate an executable on PATH, or null when it is not there.
     *
     * Both the lookup command and the null device are platform-specific:
     * `2>/dev/null` means nothing to cmd.exe, which treats it as a redirect into
     * a file called \dev\null and fails.
     */
    public static function which(string $command): ?string
    {
        $lookup = self::isWindows() ? 'where' : 'command -v';

        $out = @shell_exec($lookup . ' ' . escapeshellarg($command) . ' 2>' . self::nullDevice());
        $path = is_string($out) ? trim((string) strtok($out, "\n")) : '';

        return $path !== '' ? $path : null;
    }

    /**
     * The platform's null device, for redirecting away unwanted output.
     */
    public static function nullDevice(): string
    {
        return self::isWindows() ? 'nul' : '/dev/null';
    }

    private static function isWindows(): bool
    {
        return stripos(PHP_OS, 'WIN') === 0;
    }

    /**
     * Returns the body (when $destPath is null) or empty string on success
     * writing to a file; null when curl could not deliver it — either because
     * the CLI is unavailable or because the transfer itself failed — so the
     * caller falls back to PHP streams rather than giving up.
     */
    private static function viaCurl(string $url, ?string $destPath): ?string
    {
        $curl = self::which('curl');
        if ($curl === null) {
            return null;
        }

        $cmd = escapeshellarg($curl) . ' -sSL --fail --retry 3 --max-time 300';
        if ($destPath !== null) {
            $cmd .= ' -o ' . escapeshellarg($destPath);
        }
        $cmd .= ' ' . escapeshellarg($url) . ' 2>' . self::nullDevice();

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        if ($code !== 0) {
            return null;
        }

        return $destPath === null ? implode("\n", $out) : '';
    }
}
