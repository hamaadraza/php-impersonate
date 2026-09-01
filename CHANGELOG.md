# Changelog

All notable changes to `php-impersonate` will be documented in this file.

## Unreleased

### Fixed

- **A bodyless POST no longer sends the process's stdin as the request body.** With `CURLOPT_POST` set and no post-field size declared, libcurl reads the body from its default read callback — `fread()` on stdin — so `PHPImpersonate::post($url)` with no data transmitted whatever was piped into the calling process, and hung indefinitely when stdin was a pipe that stayed open (the transfer never starts, so `CURLOPT_TIMEOUT_MS` cannot fire).
- **The process engine no longer reports a failed transfer as a successful response.** It threw only when the exit code was non-zero *and* no status had been captured, but `-w '%{http_code}'` prints the status curl already received — so a partial file (18), a mid-body timeout (28), a receive error (56) or an HTTP/2 stream error (92) came back as an ordinary 200 with a silently truncated body, while the FFI engine threw for the same bytes. Both engines now excuse exactly one code, `CURLE_TOO_MANY_REDIRECTS`.
- **Response trailers no longer displace the real headers.** libcurl delivers trailer fields through the header callback, after the blank line that ends the header block, so on a trailered response the parser kept only the trailers — `Content-Type`, `Set-Cookie` and the status line all vanished.
- **Redirects are restricted to http/https on both engines.** The initial URL was allow-listed, but curl's redirect default also permits ftp/ftps, so a server could answer `Location: ftp://internal-host/` and reach a scheme this client refuses on input.
- **Request URLs no longer reach `argv`.** A URL can carry `user:password@` userinfo or a token in its query string, and as a positional argument it was readable from `/proc/<pid>/cmdline` for the life of the request. It now travels in the same 0600 config file as the proxy credentials.
- **The HTTP method is validated** as an RFC 9110 token. It was the one thing reaching the wire unchecked, and the bundled curl does not reject CR/LF in it either.
- **`-S` accompanies `-s`**, so a failed request names its cause instead of reporting `exit code 6: 000`.
- **`shell_exec` is guarded with `function_exists()`.** Since PHP 8.0 a name in `disable_functions` throws an `Error` that `@` cannot suppress, which fataled libc detection on hardened hosts — exactly where the subprocess-free FFI engine should still work.
- **obs-fold header continuations are rejoined** instead of being dropped or parsed as headers with nonsense names.
- **`+json` media types** (`application/merge-patch+json`, `application/vnd.api+json`, …) are encoded as JSON rather than form data.
- **An empty header value is sent, not silently deleted.** libcurl reads `Name:` as *remove this header*, so `['Accept-Language' => '']` stripped that line out of the browser profile; the `Name;` form is now used.
- **`'proxy' => ''`** is honoured — curl's documented way to bypass an environment proxy — instead of being dropped without a word.
- **An unusable `CURL_CA_BUNDLE`/`SSL_CERT_FILE` fails closed** rather than silently falling back to the system trust store and widening what is trusted.
- **A boolean curl option that curl would not recognise is rejected** instead of quietly meaning "off".
- **`boundary=""`** no longer produces a Content-Type carrying two conflicting boundary parameters.
- **HEAD with a body behaves the same on both engines** (the body is dropped, as the FFI engine already did, rather than failing at curl's argument parsing).
- **The subprocess output loop no longer busy-spins on Windows**, where `stream_select()` fails on `proc_open` pipes and returned immediately.
- **Unknown operating systems are reported as unknown.** FreeBSD, Solaris and Cygwin were all reported as Linux, so `isSupported()` answered true and resolution went looking for a Linux ELF that cannot run there.
- **`CurlImpersonate::isSupported()` honours an explicit `ffi.enable=0`** on the CLI, where it previously answered true while `FFI::cdef()` threw.
- **`PlatformNotSupportedException` extends `RuntimeException`**, so it is no longer the one error that escapes every documented catch.
- **`bin/VERSION` is only stamped by a complete run.** `--libs-only` and `--only` left it claiming a version no bundled binary was at.
- **`--version=TAG` is no longer ignored** by the installer when files already exist — it used to report the requested version as installed while the old one sat on disk.
- **`deploy.sh` refuses to release from a branch other than `main`**, checks the remote for the tag, and pushes the branch before tagging so a rejected push cannot strand a local tag.

### Added

- **`PHPImpersonateException`**: a marker interface on every exception the library throws. `catch (RequestException)` alone never covered argument errors — `InvalidArgumentException` extends `LogicException` — so one catch now covers everything without changing any existing parent.
- **`bin/CHECKSUMS`**: sha256 digests of every bundled artifact, written by `scripts/update-binaries.php` and verified by `bin/php-impersonate-install`. Upstream publishes neither checksums nor signatures, so this is a trust-on-first-use pin; previously a truncated or tampered download was installed unverified for the six platforms that cannot be executed on the host.
- **`Configuration::reset()`**, which can actually restore the built-in configuration — `setPlatformConfig()` merges, so it could never remove a key a test had added.
- **`REQUIRE_LIVE_SERVICES=1`** makes an unreachable test service a failure rather than a skip, so a rate-limited fingerprint run cannot go green having verified nothing.
- Tests for the client contract (timeout bounds, invalid browser names, the custom-profile guard that keeps a hand-built fingerprint off the by-name FFI engine), per-profile client-hint/User-Agent coherence across all 39 profiles, engine parity on full header *values* rather than names alone, and a connection-reuse test that can actually fail.

### Changed

- **Removed 7.2 MB of unusable Windows DLLs** (`libcurl-impersonate.dll`, `libcurl.dll`, `zlib.dll`). The FFI engine is POSIX-only so the first could never be loaded, and `curl.exe` is statically linked — its import table names only system DLLs.
- **`BrowserConfig` is built once per process** instead of reconstructing a ~1200-line literal on every `getConfig()`, `hasConfig()` and `getAvailableBrowsers()` call.
- **`scripts/update-browsers.php` defaults to the `bin/VERSION` tag** rather than upstream `main`, so a generated config is always backed by a binary that supports it.
- **CI**: the path filter now covers `tests/fixtures/**` (the fingerprint baseline previously triggered no run at all), `push` is limited to `main` so PR branches stop running the matrix twice, PHP 8.5 is tested, Composer downloads are cached, and the style fixer runs on pull requests with a pinned image so its commits cannot land on `main` untested.
- **Local test server** switched to `mccutchen/go-httpbin`, which publishes arm64 images and is maintained; `kennethreitz/httpbin` is amd64-only and unbuilt since 2018.

### Added

- **In-process FFI engine**: `PHPImpersonate` can now call `libcurl-impersonate` directly via PHP FFI — no process spawn, and keep-alive connections are reused across requests. The default `'auto'` engine picks FFI when usable and falls back to the executable engine transparently; force one with `engine: PHPImpersonate::ENGINE_FFI` / `ENGINE_PROCESS`. New helpers: `PHPImpersonate::ffiAvailable()` and `$client->engine()`.
- **Bundled FFI shared libraries** for Linux x86_64 (glibc and musl) and macOS ARM64, alongside the executables (the FFI engine is POSIX-only, so Windows ships the executable alone); the bundled upstream curl-impersonate version is pinned in `bin/VERSION` (currently v2.1.1 from [lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate)).
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

- **`Response::dump()` masks credential-bearing headers.** A dump is written to a log or pasted into a bug report far more often than it is read once and discarded, and a leaked `Set-Cookie` is a live session. `Set-Cookie`, `Authorization`, `X-Api-Key` and the other usual names now render as `***`; pass `dump(false)` / `debug(false)` for the verbatim output. The body is deliberately not masked — it cannot be, in general — so a dump still is not something to log unconsidered.
- **Tests that could not fail have been removed or repaired.** Two `ApiTest` cases captured their own output and asserted `expectOutputString($output)` against it, and `testBasicSetup` exercised `tempnam()`/`unlink()` — it would have passed with `src/` deleted. All three are gone (they duplicated real coverage elsewhere, and dropped three network round-trips with them). A temp-file assertion that globbed the shared system temp directory — which any concurrent test worker could change underneath it — now checks the engine's own record instead.
- **The security controls that had no tests now have them.** `CurlProcess::redactCommand()`, which masks proxy credentials and URL userinfo before they reach an exception message and then a log, and the `which_command` shell-injection guard in `Browser::findInPath()`, whose value is publicly settable through `Configuration`. Also newly covered: `PHPImpersonateFactory` (asserted signature-for-signature against `PHPImpersonate`, the anti-drift property it exists for), `Configuration`, and `PlatformNotSupportedException`.
- **The FFI engine cache documents that it is not reentrant.** Sharing an engine means sharing one curl easy handle, which is correct under sequential PHP but would corrupt state if two requests on the same key were interleaved under Fibers, Swoole or ext-parallel.
- **`docs/CONFIGURATION_BASED_APPROACH.md` rewritten.** It documented a migration away from per-browser shell scripts that no longer exist, listed 9 browsers where there are 39, never mentioned the FFI engine, and attributed command building to `PHPImpersonate` after it moved to `Process\CurlProcess`. It is now a current architecture map, leading with the detail that catches contributors out — `BrowserConfig` drives only the executable engine, while FFI impersonates by name from the shared library's own profiles — and it points at `getAvailableBrowsers()` rather than repeating a list that can drift again.
- **README static-helper signatures show the `$engine` argument**, and the CI path filters no longer watch `composer.lock`, which is git-ignored and untracked for a library.
- **The engines' header ORDER is now compared, not just their TLS fingerprint.** Nothing in the suite asserted header order, which is how a real divergence survived: the executable engine emitted the caller's headers ahead of the whole browser profile while the FFI engine placed them the way libcurl does, and because both produced identical JA4 the parity suite stayed green — TLS parity says nothing about HTTP. The parity suite now reads the actual HTTP/2 HEADERS frame and asserts both engines frame headers identically, plus the absolute shape a browser produces: pseudo-headers first, a caller-only header last, and an overriding header keeping its profile slot rather than jumping to the front. Reverting the earlier ordering fix turns it red.
- **A redirected POST now behaves the way browsers do.** Both engines pinned the method — `-X POST` on the executable, `CURLOPT_CUSTOMREQUEST` on FFI — and a pinned verb survives a redirect. On a 301/302/303, libcurl still applied its own rule and dropped the body while the pinned verb kept saying POST, so the redirect was followed with a **POST carrying no body at all**: neither the browser behaviour (switch to GET) nor the literal one (resend the POST). POST now reaches curl through `--data-binary` / `CURLOPT_POST` instead, so 301/302/303 become GET with the body dropped and 307/308 keep both, matching curl's own defaults on both engines. The other verbs still pin, since libcurl only rewrites POST on a redirect.
- **URLs that curl and browsers accept are no longer rejected.** Validation used `filter_var(FILTER_VALIDATE_URL)`, which predates internationalised domains: it refused IDN hosts (`https://münchen.de/` — which the bundled binary fetches successfully, reporting IDN support via libidn2), underscores in hostnames (legal in DNS and common on internal networks), and any non-ASCII character in a path or query, all with a bare "Invalid URL format". It was also no help where it mattered, passing `ftp://` and `file://` unchallenged. Validation is now the checks that earn their place: no control characters, a parseable scheme and host, and the http/https allow-list. A host that does not resolve is now curl's `RequestException` naming the real problem, rather than a format error for a URL that was never malformed.
- **Static analysis raised from PHPStan level 5 to level 7.** The gap was 28 missing array value types in docblocks and two genuine defects the stricter levels surfaced: `PlatformDetector::detectLibcType()` guarded `shell_exec()` with `!== null`, so a `false` return (the process could not be spawned) reached `stripos()` and coerced to `''`, quietly failing every libc probe below it; and `Response::json()` advertised a plain `int` `$depth` where `json_decode()` requires `int<1, max>`. Level 8 is left for later — it is almost entirely `CData|null` narrowing in the FFI engine, which would mean adding unreachable guards to the most delicate file in the package for no behavioural gain.
- **A rate-limited test service is no longer mistaken for a healthy one.** `TestServer::probe()` sets `ignore_errors`, so a 429 or 503 arrived as an ordinary response body and the probe reported the service as up; every test that gated on it then ran and failed on the rate-limit page rather than skipping. The probe now reads the HTTP status and treats anything outside 2xx as unusable, naming the status in the skip message (and saying outright when it is a rate limit). Measured against a local server returning 429, one fingerprint class alone went from 12 failures to 12 skips. A genuinely healthy 2xx service still runs the tests and still fails hard on bad data.
- **Browser fingerprints are now pinned to a recorded baseline.** Every fingerprint assertion in the suite was self-referential: the parity test proves the two engines agree with *each other*, and the TLS tests prove a fingerprint is well-formed — so neither noticed if both drifted together, which is precisely what `composer update-impersonate` does by refreshing the shared library and `BrowserConfig` in one go. `tests/fixtures/fingerprint-baseline.json` now records the raw JA4_r and the Akamai HTTP/2 fingerprint for four representative profiles, and the suite additionally cross-checks the observed ciphers and HTTP/2 settings against what `BrowserConfig` declares. Two fingerprints are pinned because one is not enough: `chrome99` and `chrome110` produce byte-identical JA4_r and are told apart only by their HTTP/2 SETTINGS. Regenerate with `composer update-fingerprint-baseline` after verifying a deliberate change.
- **`multipart/form-data` now produces a real multipart body.** Passing that `Content-Type` returned an `http_build_query()` string under a boundary-less multipart header — a combination no conforming parser can read. Against httpbin the body was discarded outright: the server answered 200 with an empty form, so the request silently did nothing and nothing reported it. Bodies are now encoded with proper parts and a generated boundary (written back into the `Content-Type`), or the caller's own `boundary=` when they supplied one. Nested arrays, `null`s and booleans render exactly as on the urlencoded path. Field names are percent-encoded the way browsers do, so a quote or CRLF in a name cannot forge an extra part. File uploads remain unsupported.
- **The cross-engine parity suite can no longer pass by disappearing.** Its `ja4()` helper turned every `Throwable` into `markTestSkipped()`, so the one failure it exists to catch — a bundled shared library older than this package's browser list, where the FFI engine throws "does not support target '<browser>'" — was reported as an unreachable service and skipped, leaving CI green. A missing JA4 was excused the same way, which also hid a body corrupted by an engine bug. Outages are now handled once by `TestServer::requireTls()`, as in every other TLS test, and anything after that fails. Verified by simulating a stale library: all nine browsers now fail with the real diagnostic instead of skipping.
- **An unreachable test service no longer fails the run it was supposed to skip.** `TestServer::probe()` relied on `@file_get_contents()`, but PHPUnit's error handler promotes the connection warning to a test warning regardless of the suppression operator, and `phpunit.xml.dist` sets `failOnWarning="true"` — so an outage failed CI rather than skipping, the opposite of the helper's purpose. The probe now swallows the warning properly.
- **A newline in a `proxy` or `proxy-user` value can no longer inject curl options.** The executable engine renders those two options into a curl config file, whose format is line-oriented — so a value containing a newline ended that option and curl parsed the remainder as another one. A proxy string from a rotating-proxy list or a tenant's settings could add `proxy` (redirecting the request, `Authorization` header included, to an endpoint the attacker chose), `insecure`, `cacert`, or `data = @/etc/passwd`, while the caller saw an ordinary 200. `CurlOptions::assertAllowed()` now rejects CR, LF and NUL in string option values — the same rule headers have always been held to — and `CurlProcess` re-checks at the point of writing the file. The FFI engine was never affected, as it passes values straight to `curl_easy_setopt()`.
- **Request headers whose names differ only in case are no longer sent twice.** Header names are case-insensitive (RFC 9110 §5.1), but `normalizeHeaders()` keyed on the spelling given, so `['User-Agent' => …, 'user-agent' => …]` — easily produced by merging header arrays from two sources — reached the wire as two `User-Agent` lines on both engines. They now fold into one, keeping the first spelling's position and the last value, which is what PHP's own array assignment already did when the spellings matched. This completes the de-duplication that previously covered only a caller header colliding with the browser profile's.
- **Both engines now send request headers in the same order.** Header order is itself a fingerprint. The executable engine emitted the caller's headers ahead of the whole browser profile, so a custom header landed before `sec-ch-ua` and an overridden one vacated its profile slot — an ordering no browser produces, and one the FFI engine never emitted. `collectHeaderLines()` now reproduces libcurl's `Curl_http_merge_headers` exactly: each profile header keeps its position, a caller header of the same name is substituted into that position, and caller-only headers follow at the end.
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
