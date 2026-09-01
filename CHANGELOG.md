# Changelog

All notable changes to `php-impersonate` will be documented in this file.

## Unreleased

### Added

- **In-process FFI engine**: `PHPImpersonate` can now call `libcurl-impersonate` directly via PHP FFI — no process spawn, and keep-alive connections are reused across requests. The default `'auto'` engine picks FFI when usable and falls back to the executable engine transparently; force one with `engine: PHPImpersonate::ENGINE_FFI` / `ENGINE_PROCESS`. New helpers: `PHPImpersonate::ffiAvailable()` and `$client->engine()`.
- **Bundled FFI shared libraries** for Linux x86_64 (glibc), macOS ARM64 and Windows x86_64, alongside the executables; the bundled upstream curl-impersonate version is pinned in `bin/VERSION` (currently v2.1.1 from [lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate)).
- **Installer for on-demand platforms**: `bin/php-impersonate-install` downloads the matching binary and library on Linux musl/Alpine, Linux ARM64 and macOS Intel (`composer install-binaries` from a clone).
- **8 new browser profiles**: `chrome142`, `chrome145`, `chrome146`, `chrome150`, `firefox144`, `firefox147`, `safari2601`, `okhttp4_android` — 39 profiles total.
- **Local test server**: `composer test-server-up` starts a Dockerised httpbin so the behaviour tests run offline instead of hitting public services.

### Changed

- **Default browser** is now `firefox147` (was `chrome99_android`).
- **Custom curl options are now a curated allow-list** shared by both engines: `proxy`, `proxy-user`, `noproxy`, `referer`, `cacert`, `capath`, `max-redirs`, `insecure`. Any other option is rejected with an `InvalidArgumentException` (previously arbitrary flags were passed through to the executable). Fingerprint-affecting options (ciphers, curves, `tls-*`, HTTP version, `user-agent`) are intentionally not configurable — set a `User-Agent` request header instead.
- **Engine internals restructured**: the executable path now lives in `Process\CurlProcess` and the FFI path in `Ffi\CurlImpersonate`; request validation, header parsing and option handling are shared via `Support\` classes so the two engines cannot diverge.
- **Hardening**: request URLs are restricted to `http`/`https`, header names/values are rejected on CRLF/NUL injection, the executable engine runs via `proc_open` array mode (no shell), and request bodies are sent verbatim with `--data-binary` from `0600` temp files.
- **Secrets no longer reach the command line.** The executable engine used to pass request headers as `-H` arguments (spilling to a file only past ~7000 characters) and proxy credentials as `--proxy-user`. While a request ran, its argv was readable by any local user through `/proc/<pid>/cmdline` and `ps`, so an `Authorization` token, a session `Cookie` or a proxy password was exposed. Every header now goes through a `0600` temp file (`-H @file`), and `proxy`/`proxy-user` through a `0600` curl config file (`--config`). The command stored on `RequestException` is additionally redacted, since it often reaches logs. The FFI engine was never affected.
- **A request header now replaces the browser profile's header of the same name** (matched case-insensitively) instead of being appended next to it. Previously the executable engine emitted both, so a custom `User-Agent` went out as two `User-Agent` lines — a bot signal in its own right, and a divergence from the FFI engine, where libcurl replaces by name.
- **Loose values for boolean curl options are interpreted identically on both engines.** `['insecure' => 'no']` now means "off" everywhere; the executable engine used to render it as `--insecure no`, which both enabled the flag and handed curl `no` as an extra URL, corrupting the response body.
- **CA bundle environment variables now take precedence** over the system locations, and are honoured on both engines: `CURL_CA_BUNDLE`, then `SSL_CERT_FILE`, plus `SSL_CERT_DIR` for a CA directory. Previously `SSL_CERT_FILE` was consulted last and so was ignored on any system that ships a bundle.
- **`Response::headers()` no longer contains a synthetic `HTTP_STATUS` entry.** The status line is not a header; read it with `status()`, or `ResponseHeaderParser::statusLine()` for the raw line.
- **Malformed headers are rejected instead of silently dropped.** `normalizeHeaders()` now throws `InvalidArgumentException` for a list entry without a colon, an empty name, or a non-string/non-numeric value, matching the existing behaviour of `assertHeaderIsSafe()`.
- **A custom `BrowserInterface` no longer has its configuration silently ignored.** Because the FFI engine impersonates by name and never sees `getConfig()`, an instance carrying a non-built-in config now selects the executable engine under `'auto'` (and is rejected outright when `'ffi'` is requested explicitly).
- **A binary found on `PATH` is verified** to be curl-impersonate before use. On Windows the searched name is `curl.exe` and Windows ships a stock one, which was previously accepted in place of reporting the bundled binary as missing.
- **The bundled binaries are stripped, cutting the package from about 79 MB to 30 MB.** Upstream's Linux x86_64 builds ship with debug symbols — roughly 29 MB each for the executable and the shared library, against about 5 MB each stripped — for symbols nothing here uses. `bin/php-impersonate-install` and `composer update-binaries` now strip host-platform artifacts on the way in, so this does not regress. Behaviour and the TLS/HTTP2 fingerprints are unchanged (verified byte-identical).
- **`PHPImpersonateFactory` is deprecated** in favour of the identical static methods on `PHPImpersonate`, and now forwards to them instead of repeating their bodies — two copies of the same defaults could drift apart unnoticed.
- **The `okhttp4_android` caveat is documented**: upstream's profile of that name carries a desktop Safari 17 `User-Agent`, so it will not read as an Android client even though its TLS fingerprint is OkHttp's.

### Fixed

- **The FFI engine no longer spawns a process per request.** `LibResolver::resolve()` ran on every request and probed the libc through `ldd`, which accounted for roughly 57% of a local request — undoing the point of an in-process engine. Library resolution and libc detection are now memoised (`LibResolver::clearCache()` clears it).
- Response bodies recovered from stdout no longer lose lines that are exactly `"0"` or blank.
- **A non-uppercase HTTP method no longer behaves differently on each engine.** `Request` normalises the method to upper case at construction. The FFI engine already uppercased before `CURLOPT_CUSTOMREQUEST`, but the executable engine passed `-X` through verbatim, so `new Request('get', …)` was sent as `GET` on one engine and as `get` — which most servers answer with a 400 — on the other.
- **The FFI engine no longer leaks a capture buffer when a request fails to start.** Both response sinks are now opened inside the guarded block, so if the second `open_memstream()` fails the first one's `FILE*` and its buffer are still released.
- **A negative FFI probe no longer outlives `LibResolver::clearCache()`.** The probe is cached against the library path it was taken from, so a library installed mid-process is probed afresh instead of being masked by the earlier "no library" result.
- **Equivalent curl options now share one FFI engine.** `CurlOptions::normalize()` canonicalises key order as well as values, so `['proxy' => …, 'insecure' => true]` and `['insecure' => true, 'proxy' => …]` no longer mint two engines, each pinning its own handle and connection pool.
- **The maintainer scripts work on Windows again.** `Http` and `BinaryInstaller` appended `2>/dev/null` even when using `where`, which cmd.exe reads as a redirect into a file named `\dev\null`; the null device is now chosen per platform, alongside the lookup command.
- **A failed download now falls back to PHP streams instead of aborting**, as documented — previously only a missing curl binary triggered the fallback, while any transfer failure threw. An unwritable destination also reports a readable error rather than a `TypeError`.
- **`Response::header()` no longer warns on a header with an empty value list.** It returns the default instead, as it already did for an absent header.
- **`scripts/update-browsers.php` fails loudly when a `@phpstan-type BrowserName` union cannot be found**, instead of reporting success while leaving the union stale. `Browser/BrowserName.php` was listed as carrying one but never did, so that rewrite silently did nothing.
- **`firefox133`, `firefox135` and `tor145` now match upstream exactly.** These three are hand-written rather than generated, and had drifted: the trailer header was spelled `TE` where upstream (and every generated profile) spells it `Te`, and the `tlsv1.2` flag was missing. Neither changed the fingerprints — verified unchanged, and all three are now covered by `EngineParityTest`.

### Removed

- The deprecated shell-string command builders (`CommandBuilder::buildCommand()`, `buildCurlCommand()`, `escapePath()`) and `PlatformDetector::getCommandSeparator()`. Nothing has built a shell command string since the executable engine moved to `proc_open` array mode; the argv builders (`buildCommandArgs()`, `buildCurlCommandArgs()`) are the supported API.
- Unused Windows CLI tools and build scripts from `bin/windows-x86_64/` (`bssl.exe`, `zstd.exe`, `brotli.exe`, `wcurl`, `curl-config`) — about 4 MB that `curl.exe` never loads.
- The `version` field in `composer.json`; Packagist derives the version from the git tag, which is now the single source of truth.
- Unread per-platform configuration keys (`file_extension`, `path_separator`, `executable_check`, `temp_dir`) from `Configuration`, along with `Configuration::getBinaryDir()`, `getSupportedPlatforms()`, `hasPlatformConfig()` and `PlatformDetector::getFileExtension()`. Nothing in the package read any of them; `which_command` and `getBinaryDirFallbacks()` are what `Configuration` is actually for.
- The unreachable Windows branch of `LibResolver::libraryNames()`. The FFI engine is POSIX-only — `CurlImpersonate::isSupported()` refuses Windows before resolution is ever reached — so the DLL names only implied support that does not exist.

## v1.0.9 - 2026-04-01

### What's Changed

* build(deps): bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/hamaadraza/php-impersonate/pull/22

**Full Changelog**: https://github.com/hamaadraza/php-impersonate/compare/v1.0.8...v1.0.9

## v1.0.8 - 2026-01-21

**Full Changelog**: https://github.com/hamaadraza/php-impersonate/compare/v1.0.7...v1.0.8

## v1.0.7 - 2026-01-12

### What's Changed

* build(deps): bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/hamaadraza/php-impersonate/pull/15

**Full Changelog**: https://github.com/hamaadraza/php-impersonate/compare/v1.0.5...v1.0.7

## v1.0.5 - 2026-01-12

**Full Changelog**: https://github.com/hamaadraza/php-impersonate/compare/v1.0.4...v1.0.5

## v1.0.4 - 2025-12-16

### What's Changed

* build(deps): bump stefanzweifel/git-auto-commit-action from 6 to 7 by @dependabot[bot] in https://github.com/hamaadraza/php-impersonate/pull/9
* build(deps): bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/hamaadraza/php-impersonate/pull/10

This release also shipped the cross-platform work previously listed here under an unreleased "v1.1.0" heading:

### Added

- **Cross-platform support**: Added Windows support with native Windows binaries
- **Platform detection**: New `PlatformDetector` class for automatic OS detection
- **Platform-specific configuration**: New `Configuration` class for platform-specific settings
- **Command builder**: New `CommandBuilder` class for platform-specific command construction
- **Platform exceptions**: New `PlatformNotSupportedException` for better error handling
- **Comprehensive tests**: Added platform detection tests

### Changed

- **Breaking change**: Removed Linux-only restriction, now supports Linux and Windows
- **Updated documentation**: README now reflects cross-platform support
- **Improved error messages**: More descriptive platform-related error messages
- **Updated Binary Source**: Now using curl-impersonate binaries from https://github.com/lexiforest/curl-impersonate
- **Enhanced CommandBuilder**: Added support for both generic and curl-specific command building with proper option formatting

**Full Changelog**: https://github.com/hamaadraza/php-impersonate/compare/v1.0.3...v1.0.4

## v1.0.0 - 2025-02-26

Release v1.0.0
