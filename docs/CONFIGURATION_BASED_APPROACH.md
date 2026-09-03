# How browser impersonation is configured

A contributor's map of where a browser profile lives, which engine reads it, and
how to change one. For usage, see the README — this describes the internals.

## The part that surprises people first

**`BrowserConfig` is not what goes on the wire.** Neither engine renders it for
a browser the bundled curl-impersonate already knows:

| Engine | Class | Where the fingerprint comes from |
| --- | --- | --- |
| Executable | `Process\CurlProcess` | The binary's own impersonation table, via `--impersonate <name>` |
| FFI | `Ffi\CurlImpersonate` | The same table inside the shared library, via `curl_easy_impersonate()` |

Both therefore reach the identical code upstream, which is the point: it is the
only arrangement in which the two engines cannot disagree. They once could. The
executable engine used to render `BrowserConfig` into `--ciphers`, `--curves`,
`--http2-settings` and a dozen other flags, and because that array is
hand-synced from upstream it drifted: `firefox133` and `firefox135` sent a TLS
`record_size_limit` of 4001 (a hex value pasted as decimal), `chrome116` sent an
ECH extension Chrome 116 never had, `safari170` lost its `Sec-Fetch-*` headers.
JA4 does not encode most of that, so every fingerprint test stayed green.

So **editing a `BrowserConfig` profile changes nothing** for a built-in browser,
on either engine. That is the usual first confusion. What the array still drives:

- **Validation.** An unknown name is rejected before any binary runs.
- **Custom profiles.** A `BrowserInterface` carrying a config that differs from
  the built-in one is rendered by hand, the old way — that path is what
  `CurlProcess::mergeBrowserConfig()` and `collectHeaderLines()` exist for now.
  Such a profile is also kept off the FFI engine, which can only impersonate by
  name (see `PHPImpersonate::carriesBuiltinConfig()`).
- **Cross-checking the wire.** `tests/FingerprintBaselineTest.php` compares what
  the library actually sent against what this array declares, which is how a
  drift between the two would now be caught rather than absorbed.

`BrowserConfig::matchesBuiltin()` is the single predicate both engines use to
decide which of those worlds a request is in.

## BrowserConfig

```php
use Raza\PHPImpersonate\Browser\BrowserConfig;

BrowserConfig::getAllConfigs();          // every profile
BrowserConfig::getConfig('chrome146');   // one profile (throws if unknown)
BrowserConfig::getAvailableBrowsers();   // the names
BrowserConfig::hasConfig('firefox147');  // membership test
BrowserConfig::matchesBuiltin($name, $config); // is this the built-in profile?
```

Each profile holds:

- **ciphers** — TLS cipher suite list
- **curves** — elliptic curves (optional)
- **signature-hashes** — signature algorithms (optional)
- **headers** — the profile's default request headers, in wire order
- **options** — curl-impersonate flags (HTTP/2 settings, TLS extension order, …)

```php
'chrome146' => [
    'ciphers' => 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:…',
    'headers' => [
        'sec-ch-ua' => '"Chromium";v="146", …',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; …) Chrome/146.0.0.0 Safari/537.36',
        // … in the order the browser sends them
    ],
    'options' => [
        'http2' => true,
        'http2-settings' => '1:65536;2:0;4:6291456;6:262144',
        'compressed' => true,
        'tlsv1.2' => true,
        // …
    ],
],
```

Two details that are easy to get wrong:

- **Header order is part of the fingerprint.** The array order is the wire
  order, and a caller's header replaces the profile's *in place* rather than
  being prepended. For a built-in profile libcurl does that merge itself; for a
  custom one `CurlProcess::collectHeaderLines()` reproduces its
  `Curl_http_merge_headers` so both paths frame headers the same way.
- **`headers` is not merged into `options`.** Headers reach curl through one
  `-H @file`, so a single list preserves their order and no credential lands in
  argv, where any local user could read it from `/proc/<pid>/cmdline`.

## The available browsers

Deliberately not listed here — a hardcoded list is exactly what drifts. The
source of truth is `BrowserConfig::getAvailableBrowsers()`, mirrored by the
`Browser\BrowserName` constants:

```bash
php -r 'require "vendor/autoload.php";
  echo implode("\n", Raza\PHPImpersonate\Browser\BrowserConfig::getAvailableBrowsers()), "\n";'
```

`BrowserName` also records which profiles are old enough to be a detection
signal in themselves: `BrowserName::DEPRECATED` lists the pre-2024 releases, and
`BrowserName::latest('chrome')` names the newest current profile of a family.

## Adding or updating a profile

Profiles are generated from upstream rather than hand-written:

```bash
composer update-impersonate      # binaries + configs, in that order
# or individually:
composer update-binaries         # refresh bin/ and bin/VERSION
composer update-browsers         # append profiles new to lexiforest/curl-impersonate
```

`scripts/update-browsers.php` parses upstream's `impersonate_opts` table. It is
**append-only**: it adds targets that are missing and never rewrites one already
present. That is what let the drifts above survive, so it is no longer trusted on
its own — `tests/BrowserConfigUpstreamTest.php` compares every profile against
the upstream patch and fails on any difference. Correcting a stale profile means
regenerating it, not editing it by hand.

That test reads a vendored copy of the patch, named for the release in
`bin/VERSION`. **After bumping the binaries, vendor the matching patch** or the
test will tell you it is missing:

```bash
curl -sSL https://raw.githubusercontent.com/lexiforest/curl-impersonate/$(cat bin/VERSION)/patches/curl.patch \
  | gzip -9 > tests/fixtures/curl-impersonate-$(cat bin/VERSION).patch.gz
```

After a binary update the fingerprints may legitimately change. Verify the new
ones are what a real browser sends, then re-pin the baseline:

```bash
composer update-fingerprint-baseline
```

Then `composer format && composer test`.

## Where a request is actually assembled

- `PHPImpersonate` — validation, engine selection, the shared FFI engine cache
- `Support\RequestPreparer` — URL and header validation, body encoding
- `Support\CurlOptions` — the typed allow-list of caller-supplied curl options
- `Process\CurlProcess` — builds the argv and runs the binary (**command
  building lives here**, not in `PHPImpersonate`)
- `Platform\CommandBuilder` — renders an options array into argv
- `Ffi\CurlImpersonate` — the in-process engine, via `curl_easy_setopt`
- `Ffi\ResponseCapture` — the buffer libcurl's write callbacks append to

Fingerprint-affecting options (ciphers, curves, `tls-*`, HTTP version,
`User-Agent`) are intentionally absent from `CurlOptions`: making them
configurable would let a caller silently break the impersonation the library
exists to provide.

## Two things in the FFI engine worth knowing before you touch it

- **The callbacks are process-wide.** PHP allocates a libffi trampoline for
  every closure assigned to a C function-pointer field and never frees it, so
  creating them per request leaked about 3.5 KB per request, and per engine
  1.79 KB per create/close cycle. They now live in a static keyed by library
  path, which is sound only because the engine serves one request at a time.
  `tests/SoakTest.php` guards this.
- **The library is loaded with `RTLD_DEEPBIND` on glibc.** When ext-curl is
  compiled into the `php` binary rather than loaded as a module, the
  executable's `curl_easy_setopt` would otherwise capture the impersonate
  library's own internal calls — which answers `CURLE_UNKNOWN_OPTION` and then
  segfaults in `curl_easy_cleanup()`. musl has no such flag, so there
  `ffiAvailable()` detects the condition and the executable engine is used
  instead; Windows cannot hit it at all. `tests/FfiForeignLibcurlTest.php`
  builds C fixtures that reproduce both halves.
