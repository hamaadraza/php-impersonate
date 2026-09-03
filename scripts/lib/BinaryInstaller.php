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
    private const LIB_ASSET = 'libcurl-impersonate-%s.%s.tar.gz';

    /**
     * Committed record of what every bundled artifact should hash to.
     *
     * Upstream publishes no checksums and no signatures — 41 assets on the
     * latest release, not one of them a digest — so there is nothing to verify a
     * download against at the source. What we can do is pin: the maintainer
     * records a digest for each artifact at update time, and every later install
     * checks against it. That turns an unverified download into a
     * trust-on-first-use pin, and it catches the case that is reachable without
     * any attacker at all — a truncated or corrupted download being installed
     * and shipped, which nothing detected for the six platforms that cannot be
     * executed on the host.
     */
    private const CHECKSUM_FILE = 'CHECKSUMS';

    public function __construct(private string $binDir)
    {
    }

    public function checksumPath(): string
    {
        return $this->binDir . '/' . self::CHECKSUM_FILE;
    }

    public function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException("Could not hash $path");
        }

        return $hash;
    }

    /**
     * Read the committed manifest as "bin-relative path => sha256".
     *
     * @return array<string, string>
     */
    public function readChecksums(): array
    {
        $file = $this->checksumPath();
        if (! is_file($file)) {
            return [];
        }

        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // "<sha256>  <relative/path>", the sha256sum(1) format.
            if (preg_match('/^([0-9a-f]{64})\s+(\S.*)$/', $line, $m)) {
                $out[$m[2]] = $m[1];
            }
        }

        return $out;
    }

    /**
     * Rewrite the manifest, merging new entries over whatever is recorded.
     *
     * Merged rather than replaced so a partial run — `--only=…`, `--libs-only` —
     * updates just the artifacts it touched and leaves the rest intact.
     *
     * @param array<string, string> $entries bin-relative path => sha256
     */
    public function writeChecksums(array $entries, string $version): void
    {
        $all = array_merge($this->readChecksums(), $entries);
        ksort($all);

        $lines = [
            '# sha256 digests of the bundled curl-impersonate artifacts.',
            '# Written by scripts/update-binaries.php; verified by bin/php-impersonate-install.',
            '# Upstream ships no checksums, so these are a trust-on-first-use pin.',
            '# Last updated for release: ' . $version,
        ];
        foreach ($all as $path => $hash) {
            $lines[] = sprintf('%s  %s', $hash, $path);
        }

        file_put_contents($this->checksumPath(), implode("\n", $lines) . "\n");
    }

    /**
     * Check a freshly installed artifact against the committed manifest.
     *
     * A path absent from the manifest is accepted — that is the maintainer
     * adding an artifact for the first time, and the digest is recorded on the
     * way out. A path present but DIFFERENT is refused.
     *
     * @param array<string, string> $manifest
     * @throws \RuntimeException on mismatch
     */
    public function assertMatchesManifest(array $manifest, string $relPath, string $absPath): void
    {
        if (! isset($manifest[$relPath])) {
            return;
        }

        $actual = $this->sha256($absPath);
        if (! hash_equals($manifest[$relPath], $actual)) {
            throw new \RuntimeException(sprintf(
                "Checksum mismatch for %s.\n  expected %s\n  actual   %s\n"
                . 'The download does not match the digest committed in bin/%s. Refusing to install it. '
                . 'If this is an intentional upgrade, run scripts/update-binaries.php to re-pin.',
                $relPath,
                $manifest[$relPath],
                $actual,
                self::CHECKSUM_FILE
            ));
        }
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
     * @param array<string, string> $manifest Committed digests to check against.
     * @return array{message: string, verified: bool, path: string, sha256: string}
     */
    public function install(string $version, string $dir, array $spec, array $manifest = []): array
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

            $this->stripSymbols($dest, $dir);

            // Checked AFTER stripping, because stripping is what the committed
            // digest was taken over.
            $relPath = $dir . '/' . $spec['dest'];
            $this->assertMatchesManifest($manifest, $relPath, $dest);

            $verified = false;
            $note = 'installed';

            // Gated on the HOST being able to run it, not on the 'executable'
            // flag — that flag only means "needs chmod", and it is false for
            // windows-x86_64, so a Windows host never ran the one check that
            // would catch a corrupt curl.exe while every other host did.
            if ($this->isHostPlatform($dir)) {
                $verified = $this->verify($dest);
                if (! $verified) {
                    throw new \RuntimeException("Installed binary failed --version IMPERSONATE check: $dest");
                }
                $note = 'installed + verified (' . $this->versionString($dest) . ')';
            }

            return [
                'message' => $note,
                'verified' => $verified,
                'path' => $relPath,
                'sha256' => $this->sha256($dest),
            ];
        } finally {
            $this->rmrf($work);
        }
    }

    /**
     * The upstream provenance of one artifact: the sha256 of the release
     * archive and of the raw member inside it, plus — when this host can run
     * the same strip step the installer applies — the sha256 of that member
     * once stripped, which is what bin/CHECKSUMS records for Linux.
     *
     * bin/CHECKSUMS alone is a self-attested pin over ALREADY-STRIPPED files, so
     * nothing in it can be compared with upstream. This is what makes a bundled
     * binary re-derivable from upstream's own release.
     *
     * @param array{triple: string, member: string, dest: string, executable: bool} $spec
     * @return array{asset_sha256: string, member_sha256: string, stripped_sha256: string|null}
     */
    public function upstreamDigests(string $version, string $dir, array $spec, bool $lib): array
    {
        $asset = $lib ? $this->libAssetName($version, $spec) : $this->assetName($version, $spec);
        $url = sprintf('https://github.com/%s/releases/download/%s/%s', self::REPO, $version, $asset);

        $work = $this->makeTempDir();

        try {
            $archive = $work . '/' . $asset;
            Http::download($url, $archive);
            $assetHash = $this->sha256($archive);

            $extractDir = $work . '/x';
            @mkdir($extractDir);
            $this->extract($archive, $extractDir);

            $member = $lib ? $this->findRealSharedObject($extractDir, $dir) : $this->findMember($extractDir, $spec['member']);
            if ($member === null) {
                throw new \RuntimeException("member not found inside $asset");
            }
            $memberHash = $this->sha256($member);

            $stripped = null;
            if ($this->isHostPlatform($dir) && ! str_starts_with($dir, 'windows') && Http::which('strip') !== null) {
                $copy = $work . '/stripped';
                copy($member, $copy);
                $this->stripSymbols($copy, $dir);
                $stripped = $this->sha256($copy);
            }

            return ['asset_sha256' => $assetHash, 'member_sha256' => $memberHash, 'stripped_sha256' => $stripped];
        } finally {
            $this->rmrf($work);
        }
    }

    public function writeVersionFile(string $version): void
    {
        file_put_contents($this->binDir . '/VERSION', $version . "\n");
    }

    /**
     * The libcurl-impersonate shared-library asset name for a platform.
     *
     * @param array{triple: string, member: string, dest: string, executable: bool} $spec
     */
    public function libAssetName(string $version, array $spec): string
    {
        return sprintf(self::LIB_ASSET, $version, $spec['triple']);
    }

    /**
     * Whether the FFI shared library is of any use on this platform.
     *
     * Every platform upstream ships one for, Windows included since the FFI
     * engine stopped depending on open_memstream (which no Windows build
     * exports) and started capturing responses through libcurl's own write
     * callbacks. Kept as the single place to say otherwise, should upstream
     * ever publish an executable for a target it has no library for.
     */
    public function libIsUsable(string $dir): bool
    {
        return true;
    }

    /**
     * The shared-library filename this package's FfiClient looks for in bin/<dir>.
     */
    public function libDestName(string $dir): string
    {
        if (str_starts_with($dir, 'windows')) {
            return 'libcurl-impersonate.dll';
        }
        if (str_starts_with($dir, 'macos')) {
            return 'libcurl-impersonate.dylib';
        }

        return 'libcurl-impersonate.so';
    }

    /**
     * Download and install one platform's libcurl-impersonate shared library
     * (for the optional FFI client). Symlinks are resolved to the real object.
     *
     * @param array{triple: string, member: string, dest: string, executable: bool} $spec
     * @param array<string, string> $manifest Committed digests to check against.
     * @return array{message: string, path: string, sha256: string}
     */
    public function installLib(string $version, string $dir, array $spec, array $manifest = []): array
    {
        $asset = $this->libAssetName($version, $spec);
        $url = sprintf('https://github.com/%s/releases/download/%s/%s', self::REPO, $version, $asset);

        $work = $this->makeTempDir();

        try {
            $archive = $work . '/' . $asset;
            Http::download($url, $archive);

            $extractDir = $work . '/x';
            @mkdir($extractDir);
            $this->extract($archive, $extractDir);

            $lib = $this->findRealSharedObject($extractDir, $dir);
            if ($lib === null) {
                throw new \RuntimeException("shared library not found inside $asset");
            }

            $destDir = $this->binDir . '/' . $dir;
            if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                throw new \RuntimeException("Cannot create $destDir");
            }
            $dest = $destDir . '/' . $this->libDestName($dir);
            if (! copy($lib, $dest)) {
                throw new \RuntimeException("Failed to copy library into $dest");
            }
            @chmod($dest, 0644);

            $this->stripSymbols($dest, $dir);

            $relPath = $dir . '/' . $this->libDestName($dir);
            $this->assertMatchesManifest($manifest, $relPath, $dest);

            return [
                'message' => 'library installed (' . $this->humanSize(filesize($dest) ?: 0) . ')',
                'path' => $relPath,
                'sha256' => $this->sha256($dest),
            ];
        } finally {
            $this->rmrf($work);
        }
    }

    /**
     * Find the real (non-symlink, largest) shared object of the platform family.
     */
    private function findRealSharedObject(string $dir, string $platformDir): ?string
    {
        if (str_starts_with($platformDir, 'windows')) {
            $pattern = '/^libcurl-impersonate\.dll$/i';
        } elseif (str_starts_with($platformDir, 'macos')) {
            $pattern = '/^libcurl-impersonate(\.\d+)*\.dylib$/';
        } else {
            $pattern = '/^libcurl-impersonate\.so(\.\d+)*$/';
        }

        $best = null;
        $bestSize = -1;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue; // skip symlinks; we want the real object
            }
            if (preg_match($pattern, $file->getFilename())) {
                $size = $file->getSize();
                if ($size > $bestSize) {
                    $bestSize = $size;
                    $best = $file->getPathname();
                }
            }
        }

        return $best;
    }

    /**
     * Drop debug symbols from a freshly installed artifact.
     *
     * Upstream's Linux builds ship unstripped: the x86_64 executable and shared
     * library are about 29 MB each, and roughly 5 MB once stripped — so this is
     * most of the package's download size, for symbols nothing here uses.
     * Verified not to change behaviour or the TLS/HTTP2 fingerprints.
     *
     * Only the host platform is touched, because `strip` cannot be relied on to
     * understand another platform's object format; the macOS and Windows
     * artifacts arrive stripped already. Best-effort: a missing `strip` leaves a
     * larger but perfectly working binary.
     */
    private function stripSymbols(string $path, string $dir): void
    {
        if (! $this->isHostPlatform($dir) || str_starts_with($dir, 'windows')) {
            return;
        }

        $strip = Http::which('strip');
        if ($strip === null) {
            return;
        }

        $flag = str_starts_with($dir, 'macos') ? ' -x ' : ' --strip-unneeded ';
        @exec(escapeshellarg($strip) . $flag . escapeshellarg($path) . ' 2>' . Http::nullDevice());
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }

    private function extract(string $archive, string $destDir): void
    {
        // Prefer the tar CLI (fast, present on Linux/macOS/Win10+); fall back to
        // PharData so the script still works where tar is missing.
        $tar = $this->findTar();
        if ($tar !== null) {
            $cmd = escapeshellarg($tar) . ' -xzf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg($destDir) . ' 2>' . Http::nullDevice();
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
        $out = $this->runVersion($path);

        return $out !== null && str_contains($out, 'IMPERSONATE');
    }

    /**
     * `<path> --version` through proc_open in array mode: no shell, so an
     * install path containing `%`, `!`, spaces or quotes needs no escaping
     * (escapeshellarg() replaces `%` and `!` with spaces on Windows).
     */
    private function runVersion(string $path): ?string
    {
        $process = @proc_open([$path, '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    private function versionString(string $path): string
    {
        $out = $this->runVersion($path);
        if ($out === null) {
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
        return Http::which('tar');
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
