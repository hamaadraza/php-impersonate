# How browser impersonation is configured

A contributor's map of where a browser profile lives, which engine reads it, and
how to change one. For usage, see the README — this describes the internals.

> Superseded content: this file used to document the migration away from
> per-browser shell scripts (`curl_chrome99`, …). That migration is long done and
> those scripts no longer exist, so the historical framing has been removed.

## The part that surprises people first

There are two engines, and **they do not both read `BrowserConfig`**:

| Engine | Class | Where the fingerprint comes from |
| --- | --- | --- |
| Executable | `Process\CurlProcess` | `Browser\BrowserConfig` — this repo's PHP arrays, rendered into curl flags |
| FFI | `Ffi\CurlImpersonate` | The shared library's own built-in profile, applied by name via `curl_easy_impersonate()` |

`'auto'` prefers FFI, so **on a default install `BrowserConfig` is not what goes
on the wire**. Editing a profile and seeing no change is the usual first
confusion: force `engine: 'process'` to exercise it, and note that a name absent
from the bundled library fails under FFI even though `BrowserConfig` has it.

Keeping the two in step is what `tests/EngineParityTest.php` and
`tests/FingerprintBaselineTest.php` exist for.

## BrowserConfig

```php
use Raza\PHPImpersonate\Browser\BrowserConfig;

BrowserConfig::getAllConfigs();          // every profile
BrowserConfig::getConfig('chrome146');   // one profile (throws if unknown)
BrowserConfig::getAvailableBrowsers();   // the names
BrowserConfig::hasConfig('firefox147');  // membership test
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
  order. A caller's header replaces the profile's *in place* rather than being
  prepended — `CurlProcess::collectHeaderLines()` reproduces libcurl's
  `Curl_http_merge_headers` so both engines frame headers identically.
- **`headers` is not merged into `options`.** The profile's headers reach curl
  through one `-H @file`, so that a single list preserves their order and no
  credential lands in argv.

## The available browsers

Deliberately not listed here — a hardcoded list is exactly what drifts. The
source of truth is `BrowserConfig::getAvailableBrowsers()`, mirrored by the
`Browser\BrowserName` constants:

```bash
php -r 'require "vendor/autoload.php";
  echo implode("\n", Raza\PHPImpersonate\Browser\BrowserConfig::getAvailableBrowsers()), "\n";'
```

## Adding or updating a profile

Profiles are generated from upstream rather than hand-written:

```bash
composer update-impersonate      # binaries + configs, in that order
# or individually:
composer update-binaries         # refresh bin/ and bin/VERSION
composer update-browsers         # append profiles new to lexiforest/curl-impersonate
```

`scripts/update-browsers.php` parses upstream's `impersonate_opts` table and is
append-only: it never rewrites a profile already present, so re-running is safe.
It also adds the matching `BrowserName` constant and updates the
`@phpstan-type BrowserName` unions.

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

Fingerprint-affecting options (ciphers, curves, `tls-*`, HTTP version,
`User-Agent`) are intentionally absent from `CurlOptions`: making them
configurable would let a caller silently break the impersonation the library
exists to provide.
