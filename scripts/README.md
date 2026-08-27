# Maintenance scripts

Automation for keeping this package in sync with upstream
[lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate).
You no longer hand-edit browser configs or download binaries manually.

## One command (recommended)

```bash
composer update-impersonate
```

This runs both steps below in the correct order (binaries first, so new
configs such as post-quantum Chrome are backed by a capable binary), then
tells you to run `composer format && composer test`.

Useful flags (forwarded to the underlying scripts):

| Flag | Effect |
| --- | --- |
| `--dry-run` | Show what would change; download/write nothing. |
| `--binaries-only` | Only refresh `bin/` binaries. |
| `--configs-only` | Only sync browser configs. |
| `--version=vX.Y.Z` | Install a specific release instead of the latest. |
| `--ref=BRANCH` | Read configs from a specific curl-impersonate git ref (default `main`). |

## What each step does

### `composer update-browsers`

Parses the authoritative `impersonate_opts` table in upstream
`patches/curl.patch` and **adds any target not already present** to:

- `src/Browser/BrowserConfig.php` — the full config (ciphers, curves,
  signature-hashes, headers, HTTP/2 + TLS options).
- `src/Browser/BrowserName.php` — a `CONST` and a `getAll()` entry.
- The `@phpstan-type BrowserName` unions in `BrowserName.php`,
  `PHPImpersonate.php`, and `PHPImpersonateFactory.php`.

It is **append-only and idempotent**: existing, validated configs are never
modified, and re-running when nothing is new is a no-op.

### `composer update-binaries`

Downloads the latest release binaries and refreshes `bin/<platform>/`:

| Package dir | Upstream triple | Installed as |
| --- | --- | --- |
| `linux-x86_64` | `x86_64-linux-gnu` | `curl-impersonate` |
| `linux-x86_64-musl` | `x86_64-linux-musl` | `curl-impersonate` |
| `linux-aarch64` | `aarch64-linux-gnu` | `curl-impersonate` |
| `linux-aarch64-musl` | `aarch64-linux-musl` | `curl-impersonate` |
| `macos-x86_64` | `x86_64-macos` | `curl-impersonate` |
| `macos-aarch64` | `arm64-macos` | `curl-impersonate` |
| `windows-x86_64` | `x86_64-win32` | `curl.exe` |

The installed version is recorded in `bin/VERSION`. The host-platform binary is
verified by running `--version` (it must report `IMPERSONATE`); cross-platform
binaries can't be executed on the host, so verify those on their target OS.

**Only the common platforms are committed** (`linux-x86_64`, `macos-aarch64`,
`windows-x86_64`); the others are git-ignored and fetched on demand. End users on
a non-bundled platform (musl, Linux ARM64, Intel macOS) run the user-facing
installer once — see [bin/php-impersonate-install](../bin/php-impersonate-install)
or `composer install-binaries`. When refreshing the committed set for a new
release, run `composer update-binaries -- --libs` and re-commit only those three
directories.

#### Shared libraries for the FFI client (`--libs`)

The FFI engine ([CurlImpersonate](../src/Ffi/CurlImpersonate.php)) uses the
`libcurl-impersonate` shared library. These libraries are **committed to the
package** (`bin/<platform>/libcurl-impersonate.{so,dylib,dll}`) so end users get
them automatically on `composer require` — no install step. Re-fetch them when
bumping the curl-impersonate version:

```bash
composer update-libraries                          # all platforms
composer update-binaries  -- --libs                # executables + libraries together
composer update-binaries  -- --libs-only --only=macos-aarch64
```

`PHPImpersonate` auto-discovers the bundled library for its FFI engine (or one pointed to by the
`PHP_IMPERSONATE_LIB` environment variable).

## After running

```bash
composer format   # style the generated PHP
composer test     # the suite makes live requests through the new binary/configs
```

## Notes & caveats

- **Windows** ships a self-contained `curl.exe`; the older auxiliary DLLs left
  in `bin/windows-x86_64/` are unused by it. Test on Windows before releasing.
- **Header casing** for a target's `User-Agent` is normalised to `User-Agent`
  (HTTP/2 lowercases header names on the wire, so this doesn't change the
  fingerprint); other header names are kept exactly as upstream.
- **`ech`** is emitted as `'grease'` to match this package's convention.
- Requires the `curl` CLI (or PHP streams) for downloads and `tar` (or the
  `phar` extension) for extraction — all standard on a dev machine.
