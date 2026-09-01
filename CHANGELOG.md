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
- **A request header now replaces the browser profile's header of the same name** (matched case-insensitively) instead of being appended next to it. Previously the executable engine emitted both, so a custom `User-Agent` went out as two `User-Agent` lines — a bot signal in its own right, and a divergence from the FFI engine, where libcurl replaces by name.
- **Loose values for boolean curl options are interpreted identically on both engines.** `['insecure' => 'no']` now means "off" everywhere; the executable engine used to render it as `--insecure no`, which both enabled the flag and handed curl `no` as an extra URL, corrupting the response body.
- **CA bundle environment variables now take precedence** over the system locations, and are honoured on both engines: `CURL_CA_BUNDLE`, then `SSL_CERT_FILE`, plus `SSL_CERT_DIR` for a CA directory. Previously `SSL_CERT_FILE` was consulted last and so was ignored on any system that ships a bundle.
- **`Response::headers()` no longer contains a synthetic `HTTP_STATUS` entry.** The status line is not a header; read it with `status()`, or `ResponseHeaderParser::statusLine()` for the raw line.
- **Malformed headers are rejected instead of silently dropped.** `normalizeHeaders()` now throws `InvalidArgumentException` for a list entry without a colon, an empty name, or a non-string/non-numeric value, matching the existing behaviour of `assertHeaderIsSafe()`.
- **A custom `BrowserInterface` no longer has its configuration silently ignored.** Because the FFI engine impersonates by name and never sees `getConfig()`, an instance carrying a non-built-in config now selects the executable engine under `'auto'` (and is rejected outright when `'ffi'` is requested explicitly).
- **A binary found on `PATH` is verified** to be curl-impersonate before use. On Windows the searched name is `curl.exe` and Windows ships a stock one, which was previously accepted in place of reporting the bundled binary as missing.

### Fixed

- **The FFI engine no longer spawns a process per request.** `LibResolver::resolve()` ran on every request and probed the libc through `ldd`, which accounted for roughly 57% of a local request — undoing the point of an in-process engine. Library resolution and libc detection are now memoised (`LibResolver::clearCache()` clears it).
- Response bodies recovered from stdout no longer lose lines that are exactly `"0"` or blank.

### Removed

- The deprecated shell-string command builders (`CommandBuilder::buildCommand()`, `buildCurlCommand()`, `escapePath()`) and `PlatformDetector::getCommandSeparator()`. Nothing has built a shell command string since the executable engine moved to `proc_open` array mode; the argv builders (`buildCommandArgs()`, `buildCurlCommandArgs()`) are the supported API.
- Unused Windows CLI tools and build scripts from `bin/windows-x86_64/` (`bssl.exe`, `zstd.exe`, `brotli.exe`, `wcurl`, `curl-config`) — about 4 MB that `curl.exe` never loads.
- The `version` field in `composer.json`; Packagist derives the version from the git tag, which is now the single source of truth.

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
