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
