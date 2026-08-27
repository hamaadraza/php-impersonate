<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Scripts;

/**
 * Downloads and installs upstream curl-impersonate release binaries into the
 * package's bin/<platform> directories.
 */
final class BinaryInstaller
{
    private const REPO = 'lexiforest/curl-impersonate';
    private const RELEASE_ASSET = 'curl-impersonate-%s.%s.tar.gz';

    public function __construct(private string $binDir)
    {
    }

    /**
     * Map each package platform dir to its upstream release triple, the binary
     * file inside the archive, and the destination name expected by Browser.php.
     *
     * @return array<string, array{triple: string, member: string, dest: string, executable: bool}>
     */
    public function platformMap(): array
    {
        return [
            'linux-x86_64' => ['triple' => 'x86_64-linux-gnu',  'member' => 'curl-impersonate',     'dest' => 'curl-impersonate', 'executable' => true],
            'linux-x86_64-musl' => ['triple' => 'x86_64-linux-musl', 'member' => 'curl-impersonate',     'dest' => 'curl-impersonate', 'executable' => true],
            'linux-aarch64' => ['triple' => 'aarch64-linux-gnu', 'member' => 'curl-impersonate',     'dest' => 'curl-impersonate', 'executable' => true],
            'linux-aarch64-musl' => ['triple' => 'aarch64-linux-musl', 'member' => 'curl-impersonate',    'dest' => 'curl-impersonate', 'executable' => true],
            'macos-x86_64' => ['triple' => 'x86_64-macos',      'member' => 'curl-impersonate',     'dest' => 'curl-impersonate', 'executable' => true],
            'macos-aarch64' => ['triple' => 'arm64-macos',       'member' => 'curl-impersonate',     'dest' => 'curl-impersonate', 'executable' => true],
            'windows-x86_64' => ['triple' => 'x86_64-win32',      'member' => 'curl-impersonate.exe', 'dest' => 'curl.exe',         'executable' => false],
        ];
    }

    public function latestReleaseTag(): string
    {
        $json = Http::get('https://api.github.com/repos/' . self::REPO . '/releases/latest');
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['tag_name'])) {
            throw new \RuntimeException('Could not determine latest release tag from GitHub API.');
        }

        return (string)$data['tag_name'];
    }

    /**
     * @param array{triple: string, member: string, dest: string, executable: bool} $spec
     */
    public function assetName(string $version, array $spec): string
    {
        return sprintf(self::RELEASE_ASSET, $version, $spec['triple']);
    }

    /**
     * Download, extract and install one platform's binary.
     *
     * @param array{triple: string, member: string, dest: string, executable: bool} $spec
     * @return array{message: string, verified: bool}
     */
    public function install(string $version, string $dir, array $spec): array
    {
        $asset = $this->assetName($version, $spec);
        $url = sprintf('https://github.com/%s/releases/download/%s/%s', self::REPO, $version, $asset);

        $work = $this->makeTempDir();

        try {
            $archive = $work . '/' . $asset;
            Http::download($url, $archive);

            $extractDir = $work . '/x';
            @mkdir($extractDir);
            $this->extract($archive, $extractDir);

            $binaryPath = $this->findMember($extractDir, $spec['member']);
            if ($binaryPath === null) {
                throw new \RuntimeException("'{$spec['member']}' not found inside $asset");
            }

            $destDir = $this->binDir . '/' . $dir;
            if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                throw new \RuntimeException("Cannot create $destDir");
            }
            $dest = $destDir . '/' . $spec['dest'];

            if (! copy($binaryPath, $dest)) {
                throw new \RuntimeException("Failed to copy binary into $dest");
            }
            if ($spec['executable']) {
                @chmod($dest, 0755);
            }

            $verified = false;
            $note = 'installed';
            if ($spec['executable'] && $this->isHostPlatform($dir)) {
                $verified = $this->verify($dest);
                if (! $verified) {
                    throw new \RuntimeException("Installed binary failed --version IMPERSONATE check: $dest");
                }
                $note = 'installed + verified (' . $this->versionString($dest) . ')';
            }

            return ['message' => $note, 'verified' => $verified];
        } finally {
            $this->rmrf($work);
        }
    }

    public function writeVersionFile(string $version): void
    {
        file_put_contents($this->binDir . '/VERSION', $version . "\n");
    }

    private function extract(string $archive, string $destDir): void
    {
        // Prefer the tar CLI (fast, present on Linux/macOS/Win10+); fall back to
        // PharData so the script still works where tar is missing.
        $tar = $this->findTar();
        if ($tar !== null) {
            $cmd = escapeshellarg($tar) . ' -xzf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg($destDir) . ' 2>/dev/null';
            exec($cmd, $out, $code);
            if ($code === 0) {
                return;
            }
        }

        try {
            $phar = new \PharData($archive);
            $phar->extractTo($destDir, null, true);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Extraction failed (no working tar or PharData): ' . $e->getMessage());
        }
    }

    private function findMember(string $dir, string $member): ?string
    {
        $target = basename($member);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === $target) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function verify(string $path): bool
    {
        $out = @shell_exec(escapeshellarg($path) . ' --version 2>/dev/null');

        return is_string($out) && str_contains($out, 'IMPERSONATE');
    }

    private function versionString(string $path): string
    {
        $out = @shell_exec(escapeshellarg($path) . ' --version 2>/dev/null');
        if (! is_string($out)) {
            return 'unknown';
        }

        // e.g. "curl 8.21.0-IMPERSONATE (Linux) ..."
        return preg_match('/curl\s+(\S+)/', $out, $m) ? $m[1] : 'unknown';
    }

    private function isHostPlatform(string $dir): bool
    {
        $os = PHP_OS;
        $machine = strtolower(php_uname('m'));
        $isArm = in_array($machine, ['aarch64', 'arm64', 'arm64e'], true);
        $isX64 = in_array($machine, ['x86_64', 'amd64', 'x64'], true);

        if (stripos($os, 'Darwin') !== false) {
            return $dir === ($isArm ? 'macos-aarch64' : 'macos-x86_64');
        }
        if (stripos($os, 'WIN') === 0) {
            return $dir === 'windows-x86_64';
        }
        // Linux: match arch and libc (musl vs gnu).
        $isMusl = file_exists('/etc/alpine-release')
            || (bool)preg_grep('/musl/', (array)@glob('/lib/ld-musl-*'));
        $arch = $isArm ? 'aarch64' : ($isX64 ? 'x86_64' : null);
        if ($arch === null) {
            return false;
        }

        return $dir === "linux-$arch" . ($isMusl ? '-musl' : '');
    }

    private function findTar(): ?string
    {
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
        $out = @shell_exec("$which tar 2>/dev/null");
        $path = $out ? trim(strtok($out, "\n")) : '';

        return $path !== '' ? $path : null;
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/php-impersonate-bin-' . bin2hex(random_bytes(6));
        if (! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new \RuntimeException("Cannot create temp dir $base");
        }

        return $base;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
