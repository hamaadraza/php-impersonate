# PHP-Impersonate

[![Tests](https://img.shields.io/github/actions/workflow/status/hamaadraza/php-impersonate/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hamaadraza/php-impersonate/actions/workflows/run-tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/hamaadraza/php-impersonate?style=flat-square)](https://packagist.org/packages/hamaadraza/php-impersonate)
[![Total Downloads](https://img.shields.io/packagist/dt/hamaadraza/php-impersonate?style=flat-square)](https://packagist.org/packages/hamaadraza/php-impersonate)
[![PHP Version](https://img.shields.io/packagist/php-v/hamaadraza/php-impersonate?style=flat-square)](https://packagist.org/packages/hamaadraza/php-impersonate)
[![License](https://img.shields.io/packagist/l/hamaadraza/php-impersonate?style=flat-square)](LICENSE.md)

Make HTTP requests that look like a **real browser**. PHP-Impersonate wraps
[curl-impersonate](https://github.com/lexiforest/curl-impersonate) to reproduce
the exact TLS and HTTP/2 fingerprints of Chrome, Firefox, Safari, Edge and Tor —
so requests sail past the anti-bot checks that block ordinary HTTP clients.

```php
use Raza\PHPImpersonate\PHPImpersonate;

$response = PHPImpersonate::get('https://tls.peet.ws/api/all');
echo $response->json()['tls']['ja4']; // a genuine browser fingerprint
```

---

## Contents

- [Why](#why) · [Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start)
- [**Two engines, one class**](#two-engines-one-class)
- [Supported platforms](#supported-platforms)
- [Making requests](#making-requests) · [Responses](#responses) · [Headers](#working-with-headers)
- [Browsers](#browsers) · [Curl options](#curl-options) · [Request bodies](#request-bodies) · [Timeouts](#timeouts) · [Errors](#error-handling)
- [Security considerations](#security-considerations) · [Keeping up to date](#keeping-up-to-date) · [Testing](#testing) · [License](#license)

---

## Why

Websites increasingly fingerprint the TLS handshake and HTTP/2 frames to tell
real browsers from scripts. cURL, Guzzle and friends have a distinctive
signature that is trivial to block. PHP-Impersonate sends byte-for-byte the same
handshake as a chosen browser version, so your request looks like it came from
that browser.

- 🎭 **39 browser profiles** — Chrome, Firefox, Safari (desktop + iOS), Edge, Tor, OkHttp.
- 🔒 **Real TLS/JA4 + HTTP/2 fingerprints**, not just a `User-Agent` string.
- ⚡ **Two engines, one class** — a zero-setup executable, and an optional in-process FFI
  path that reuses connections for serious throughput.
- 📦 **Batteries included** — binaries ship in the package for common platforms.
- 🧩 **One entry point** — everything runs through the single `PHPImpersonate` class.

## Requirements

- PHP **8.2+** (8.0 and 8.1 are past end-of-life and cannot run the test suite)
- Optional, for the faster [FFI engine](#two-engines-one-class): the `ffi`
  extension (bundled with PHP; always usable on the CLI).

## Installation

```bash
composer require hamaadraza/php-impersonate
```

That's it on common platforms — the binaries are bundled. On other platforms run
a one-time [installer](#supported-platforms).

## Quick start

```php
<?php
require 'vendor/autoload.php';

use Raza\PHPImpersonate\PHPImpersonate;

$response = PHPImpersonate::get('https://example.com');

echo $response->status();      // 200
echo $response->body();        // the HTML
$data = $response->json();     // decoded JSON (throws on invalid JSON)
```

---

## Two engines, one class

`PHPImpersonate` is the only class you use. Under the hood it has two engines for
reaching **curl-impersonate**, and by default it transparently picks the faster
one that works in your environment — you never touch a second class.

| | **FFI engine** | **Executable engine** |
|---|---|---|
| How | calls `libcurl-impersonate` in-process via PHP FFI | runs the bundled `curl-impersonate` binary per request |
| Performance | no process spawn; **keep-alive connections reused** across requests | one short-lived process per request |
| Requirements | the `ffi` extension usable + the shared library (bundled on common platforms) | none — works everywhere |
| Curl options | ✅ same curated set | ✅ same curated set |

> [!TIP]
> Just use `PHPImpersonate` — the default `'auto'` engine chooses **FFI** when
> it's usable, otherwise the **executable**. Both accept the same options and
> produce the same fingerprints, so your code is identical either way. They
> draw the fingerprint from one place — the impersonation table built into
> curl-impersonate itself (`curl_easy_impersonate()` on FFI, `--impersonate`
> on the executable) — so the two cannot drift apart.

```php
use Raza\PHPImpersonate\PHPImpersonate;

$response = PHPImpersonate::get('https://example.com');   // auto engine
```

**Static helpers** — one-liners for one-off requests:

```php
PHPImpersonate::get(string $url, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = [], string $engine = 'auto'): Response;
PHPImpersonate::post(string $url, ?array $data = null, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = [], string $engine = 'auto'): Response;
PHPImpersonate::put(/* … same shape as post … */): Response;
PHPImpersonate::patch(/* … same shape as post … */): Response;
PHPImpersonate::delete(string $url, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = [], string $engine = 'auto'): Response;
PHPImpersonate::head(/* … same shape as delete … */): Response;

$response = PHPImpersonate::get('https://example.com', [], 30, 'chrome146');
```

**Instance** — reuse configuration across requests (the FFI engine keeps its
connection alive across calls on the same instance):

```php
$client = new PHPImpersonate(
    browser: 'firefox147',   // default; any name from BrowserName::getAll()
    timeout: 30,             // seconds
    curlOptions: [],         // e.g. ['proxy' => 'http://127.0.0.1:8080']
    engine:  PHPImpersonate::ENGINE_AUTO,   // default
);

foreach ($urls as $url) {
    $response = $client->sendGet($url);
}
```

All request methods share one signature and return the same [`Response`](#responses):

```php
$client->sendGet(string $url, array $headers = []): Response;
$client->sendPost(string $url, ?array $data = null, array $headers = []): Response;
$client->sendPut(string $url, ?array $data = null, array $headers = []): Response;
$client->sendPatch(string $url, ?array $data = null, array $headers = []): Response;
$client->sendDelete(string $url, array $headers = []): Response;
$client->sendHead(string $url, array $headers = []): Response;
$client->send(Raza\PHPImpersonate\Request $request): Response;
```

### Choosing the engine

Pass `engine:` to force one, and inspect the choice when you need to:

```php
new PHPImpersonate('chrome146', engine: PHPImpersonate::ENGINE_FFI);      // FFI, or throw if unusable
new PHPImpersonate('chrome146', engine: PHPImpersonate::ENGINE_PROCESS);  // always the executable

PHPImpersonate::ffiAvailable();                 // is the FFI engine usable here?
PHPImpersonate::ffiUnavailableReason();         // …and if not, why (null when it is)
```

If the FFI engine misbehaves in a way no exception can describe (a crash), run
`php vendor/hamaadraza/php-impersonate/scripts/ffi-diagnose.php`: it executes
each stage of the engine in a separate process and reports which one failed,
along with the PHP build and extensions it ran under — paste that into a bug
report.

```php
(new PHPImpersonate('chrome146'))->engine();    // 'ffi' or 'process' — what was chosen
```

Under `'auto'`, the FFI engine is used whenever `PHPImpersonate::ffiAvailable()`
is true; otherwise the executable engine runs. Both engines accept the exact same
[curl options](#curl-options), so the choice never changes behaviour.
`ffiAvailable()` is true only when the `ffi` extension is usable — always on the
CLI; other SAPIs also need `ffi.enable` — and the shared library loads on this
platform (bundled for [common platforms](#supported-platforms);
`PHP_IMPERSONATE_LIB` overrides it).

> [!NOTE]
> The FFI engine is **POSIX-only** (Linux and macOS). On Windows the executable
> engine is always used — `auto` handles this transparently, so nothing changes
> for you; the fingerprints are identical either way.

> [!WARNING]
> **A libcurl compiled into the `php` binary** (ext-curl built in rather than
> loaded as a module: the official Docker images, some source builds) sits
> ahead of the bundled `libcurl-impersonate` in the dynamic linker's symbol
> lookup, so the library's own internal calls could land in that stock libcurl
> — which answers `CURLE_UNKNOWN_OPTION` and corrupts the handle. On **glibc**
> the library is loaded with `RTLD_DEEPBIND`, which makes it bind to itself, and
> the FFI engine works. On **musl (Alpine)** there is no such flag:
> `ffiAvailable()` detects the situation (it checks that the default profile
> actually applies, not just that the library loads), `auto` uses the executable
> engine, and `ffiUnavailableReason()` spells it out. Nothing crashes either way.

---

## Supported platforms

Prebuilt binaries **and** FFI libraries ship inside the package for the most
common platforms, so everything works with no extra steps:

| Platform | Executable | FFI library |
|---|:---:|:---:|
| Linux x86_64 (glibc) | bundled | bundled |
| macOS ARM64 (Apple Silicon) | bundled | bundled |
| Windows x86_64 | bundled | n/a — no shared library is shipped for Windows |
| Linux x86_64 (musl / Alpine) | on demand | on demand |
| Linux ARM64 (glibc & musl) | on demand | on demand |
| macOS x86_64 (Intel) | on demand | on demand |

On Alpine, note that the official `php:*-alpine` images do not enable the
`ffi` extension; add `apk add libffi-dev && docker-php-ext-install ffi` to use
the FFI engine there, otherwise the executable engine is used.

On an “on demand” platform, run the installer once after `composer require` to
download the matching binary and library:

```bash
php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install
```

To run it automatically, add it to **your project's** `composer.json` (a
dependency's own scripts don't run on install):

```json
{
  "scripts": {
    "post-install-cmd": ["@php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install"],
    "post-update-cmd":  ["@php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install"]
  }
}
```

The installer is a no-op when the platform is already bundled. Flags:
`--no-libs` (skip the FFI library), `--force` (re-download), `--version=TAG`.

---

## Making requests

### GET

```php
$response = PHPImpersonate::get('https://example.com', [
    'Accept-Language' => 'en-US,en;q=0.9',
]);
```

### POST / PUT / PATCH

Pass an array of data. It is form-encoded by default, or JSON when you set a JSON
`Content-Type` (see [Request bodies](#request-bodies)).

```php
$response = PHPImpersonate::post('https://example.com/api', [
    'username' => 'johndoe',
    'email'    => 'john@example.com',
]);
```

### DELETE / HEAD

```php
$deleted = PHPImpersonate::delete('https://example.com/api/1');
$head    = PHPImpersonate::head('https://example.com');   // headers only, empty body
```

## Responses

Every request returns a `Response`:

| Method | Returns | Description |
|---|---|---|
| `status()` | `int` | HTTP status code |
| `isSuccess()` | `bool` | `true` for 2xx |
| `body()` | `string` | Raw response body |
| `json($assoc = true, $depth = 512, $flags = 0)` | `mixed` | Decoded JSON — throws `\JsonException` on invalid JSON |
| `header($name, $default = null)` | `?string` | First value of a header (case-insensitive) |
| `headerAll($name)` | `string[]` | **All** values of a header (use for `Set-Cookie`) |
| `hasHeader($name)` | `bool` | Whether a header is present (case-insensitive) |
| `headers()` | `array<string, string[]>` | All headers as name → list of values |
| `effectiveUrl()` | `?string` | The URL the transfer ended on, after redirects |
| `setCookieHeaders()` | `string[]` | Every `Set-Cookie` from every hop (see [Cookies](#cookies)) |
| `toArray()` | `array` | `['body' => …, 'statusCode' => …, 'headers' => …]` |
| `dump()` | `string` | Human-readable summary (for logging) |
| `debug()` | `self` | Echo `dump()` and return `$this` |

```php
$response = PHPImpersonate::get('https://api.example.com/user');

if ($response->isSuccess()) {
    $user = $response->json();
    echo $user['name'];
}
```

## Working with headers

Each header name maps to an **array of values** (`string[]`), because headers
like `Set-Cookie` legitimately appear multiple times.

```php
// Single-value headers — header() is all you need
$type = $response->header('Content-Type');        // 'application/json'
$etag = $response->header('ETag', 'none');        // default when absent
```

> [!IMPORTANT]
> `Set-Cookie` must not be folded into one comma-joined string
> ([RFC 6265 §4.1.1](https://www.rfc-editor.org/rfc/rfc6265#section-4.1.1)), so
> `header('Set-Cookie')` would drop all but the first cookie. Use `headerAll()`:

```php
foreach ($response->headerAll('Set-Cookie') as $cookie) {
    echo $cookie . "\n";
}

// Or inspect everything — every value is an array:
foreach ($response->headers() as $name => $values) {
    foreach ($values as $value) {
        echo "$name: $value\n";
    }
}
```

`headers()` contains only headers that were on the wire — the status line is not
mixed in; read it with `$response->status()`.

### Cookies

Within one request, cookies behave as they do in a browser: a cookie set on a
redirect hop (the classic `POST /login` → `302` + `Set-Cookie` → `GET /home`)
is sent on the follow-up automatically, on both engines. Nothing persists
*between* requests — there is no cookie jar yet — so to keep a session, read
the cookies every response in the chain set and send them back yourself:

```php
$login = $client->sendPost('https://example.com/login', ['user' => 'me', 'pass' => '…']);

// Every Set-Cookie received on ANY response in the redirect chain, in order —
// headers() and headerAll() describe the final response only, and a session
// cookie is usually set on the 302, not on the page it redirects to.
$cookies = [];
foreach ($login->setCookieHeaders() as $setCookie) {
    [$pair] = explode(';', $setCookie, 2);
    [$name, $value] = explode('=', $pair, 2);
    $cookies[$name] = $value;
}

$page = $client->sendGet('https://example.com/account', [
    'Cookie' => http_build_query($cookies, '', '; ', PHP_QUERY_RFC3986),
]);
```

### Overriding a profile header

A header you pass **replaces** the browser profile's header of the same name
(matched case-insensitively) rather than being sent alongside it. This is how
you change the `User-Agent` without breaking the TLS fingerprint:

```php
$response = PHPImpersonate::get('https://example.com', [
    'User-Agent' => 'MyApp/1.0',   // replaces the profile's User-Agent
    'X-Request-Id' => 'abc123',    // added to it
]);
```

> [!NOTE]
> Malformed headers are rejected with an `InvalidArgumentException` rather than
> silently dropped, so a typo cannot leave a header quietly unsent.

## Browsers

Pass any of these names as the `$browser` argument (default **`firefox147`**):

<details>
<summary><strong>All 39 supported browsers</strong></summary>

**Chrome** — `chrome99`, `chrome99_android`, `chrome100`, `chrome101`, `chrome104`,
`chrome107`, `chrome110`, `chrome116`, `chrome119`, `chrome120`, `chrome123`,
`chrome124`, `chrome131`, `chrome131_android`, `chrome133a`, `chrome136`,
`chrome142`, `chrome145`, `chrome146`, `chrome150`

**Edge** — `edge99`, `edge101`

**Firefox** — `firefox133`, `firefox135`, `firefox144`, `firefox147` *(default)*

**Safari** — `safari153`, `safari155`, `safari170`, `safari172_ios`, `safari180`,
`safari180_ios`, `safari184`, `safari184_ios`, `safari260`, `safari260_ios`, `safari2601`

**Other** — `tor145`, `okhttp4_android`

</details>

The authoritative list is always `Raza\PHPImpersonate\Browser\BrowserName::getAll()`.

A profile is one specific browser *release*, and releases age: anti-bot vendors
flag versions that have been out of support for years, so a 2022 Chrome is a
signal in itself even when its handshake is byte-perfect. The profiles of
releases older than 2024 (`chrome99`–`chrome120`, `edge99`/`edge101`,
`safari153`–`safari172_ios`) are marked `@deprecated` and listed in
`BrowserName::DEPRECATED`; they still work. For a current identity that keeps
up as profiles are added, ask for the newest of a family:

```php
use Raza\PHPImpersonate\Browser\BrowserName;

BrowserName::latest('chrome');       // 'chrome150' today
BrowserName::latest('firefox');      // 'firefox147'
BrowserName::latest('safari_ios');   // 'safari260_ios' — a desktop family never answers with a mobile profile
```

Pin the result if the identity must stay the same across releases of this
package.

> [!NOTE]
> `okhttp4_android` reproduces upstream's profile of the same name, and that
> profile carries a **desktop Safari 17 `User-Agent`**, not an Android one — its
> TLS fingerprint is OkHttp's, but it will not read as an Android client. Pass
> your own `User-Agent` header if you need one that matches.

```php
$client = new PHPImpersonate('safari184');
$response = $client->sendGet('https://example.com');
```

## Curl options

Pass extra curl options as the third constructor argument. The **same curated,
validated set** works on both engines — the shared registry lives in
`Raza\PHPImpersonate\Support\CurlOptions`:

```php
$client = new PHPImpersonate('chrome146', 30, [
    'proxy'      => 'http://127.0.0.1:8080',   // or socks5://127.0.0.1:1080
    'proxy-user' => 'user:password',
]);

$response = $client->sendGet('https://api.ipify.org?format=json');
```

| Option | Type | Description |
|---|---|---|
| `proxy` | string | Proxy address (`http://`, `https://`, `socks5://`, …) |
| `proxy-user` | string | Proxy credentials, `user:password` |
| `noproxy` | string | Comma-separated hosts that bypass the proxy |
| `referer` | string | `Referer` header value |
| `cacert` | string | Path to a custom CA bundle file |
| `capath` | string | Path to a custom CA directory |
| `max-redirs` | int | Maximum redirects to follow (default 50) |
| `max-filesize` | int | Largest response body accepted, in bytes (default 256 MiB); a larger one throws `RequestException` with code 63 |
| `insecure` | bool | Skip TLS certificate verification (use with care) |

> [!NOTE]
> Any option not in this list is rejected with a clear error. Options that would
> alter the browser fingerprint — `ciphers`, `curves`, `tls-*`, the HTTP version,
> `user-agent` — are intentionally **not** configurable: overriding them would
> silently defeat the impersonation. Set a custom `User-Agent` as a request
> header instead — it replaces the profile's, see
> [Overriding a profile header](#overriding-a-profile-header).

> [!NOTE]
> Responses are buffered in full before they are returned, so `max-filesize`
> is what stands between you and a server that streams forever. The default
> is deliberately finite; raise it for known-large downloads.

> [!TIP]
> Without an explicit `cacert`/`capath`, the CA bundle is taken from
> `CURL_CA_BUNDLE`, then `SSL_CERT_FILE`, then the usual system locations
> (`SSL_CERT_DIR` supplies a CA directory). Setting one of those variables
> overrides the system bundle on both engines.

## Request bodies

For `POST`, `PUT` and `PATCH`, the array you pass is encoded based on the
`Content-Type` header (matched case-insensitively):

```php
// Form-encoded (application/x-www-form-urlencoded) — the POST default
PHPImpersonate::post('https://example.com/api', ['name' => 'John']);

// JSON — set a JSON Content-Type
PHPImpersonate::post('https://example.com/api',
    ['name' => 'John'],
    ['Content-Type' => 'application/json']
);

// multipart/form-data — the boundary is generated and added to the header
PHPImpersonate::post('https://example.com/api',
    ['name' => 'John', 'tags' => ['a', 'b']],
    ['Content-Type' => 'multipart/form-data']
);
```

> [!NOTE]
> `PUT` and `PATCH` default to **JSON**; `POST` defaults to **form-encoded**.
> An explicit `Content-Type` always wins.

Nested arrays are flattened to `parent[child]` field names in both the
form-encoded and multipart encodings, `null` values are dropped and booleans
become `1`/`0` — so the two carry the same data and only the framing differs.
Supply your own `boundary=` in the `Content-Type` to control it; otherwise one
is generated for you. File uploads are not supported: pass a pre-encoded body
via `Request` if you need them.

## Security considerations

**This library performs no SSRF filtering.** Any `http`/`https` URL is fetched:
loopback, RFC 1918 ranges, link-local addresses, cloud metadata endpoints
(`169.254.169.254`), decimal or hex IP spellings — and a public URL may
redirect to any of them, since redirects are followed (to `http`/`https`
only). That policy belongs in your application, which knows its network; what
the library gives you to enforce it:

- Validate the URL *and resolve its host yourself* before calling. A hostname
  check alone is not enough (DNS rebinding can change the answer between your
  check and curl's); resolving it and pinning the request to an address is
  the only robust defence, and needs a proxy or `--resolve`-style pinning,
  which this library does not yet expose.
- Pass `['max-redirs' => 0]` to stop at the first response and vet each
  `Location` yourself before following it — every hop then goes through your
  policy.
- Check `$response->effectiveUrl()` afterwards to see where a followed chain
  actually ended.

**Environment.** `PHP_IMPERSONATE_LIB` loads an arbitrary shared object into
the PHP process; `CURL_CA_BUNDLE`, `SSL_CERT_FILE` and `SSL_CERT_DIR` change
what is trusted. None of them should be settable by untrusted configuration.

**FFI in web SAPIs.** Setting `ffi.enable=On` for PHP-FPM or Apache lets *any*
PHP code in that SAPI call arbitrary C functions, not just this library. If
that is unacceptable, leave FFI off there — the executable engine is used
automatically — or run the library from CLI workers and queues, where FFI is
always available.

## What is not taken from the environment

The executable engine runs curl with `-q`, so a `~/.curlrc` (or
`$CURL_HOME`/`$XDG_CONFIG_HOME`) on the machine is **ignored** — the FFI
engine never read one, and a curlrc could otherwise add headers, a proxy or
`--insecure` to every request. What both engines *do* honour, like every
libcurl program, are the `http_proxy`/`HTTPS_PROXY`/`NO_PROXY` variables;
pass `'proxy' => ''` to go direct regardless.

## Timeouts

```php
PHPImpersonate::get('https://example.com', [], 5);   // 5-second timeout
$client = new PHPImpersonate('chrome146', 10);       // 10-second default for this client
```

## Error handling

The library raises two kinds of error:

- **`RequestException`** — a transport failure: DNS, connection, timeout, a
  broken transfer, a non-loadable library.
- **`InvalidArgumentException`** — a caller mistake: an unusable URL, a timeout
  outside 1–3600, an unknown browser or engine, an unsupported curl option, data
  that cannot be encoded as a body.

HTTP error statuses do **not** throw — check `isSuccess()`.

Both implement **`PHPImpersonateException`**, so one catch covers everything the
library can raise. Reach for that unless you want to tell the two apart:

```php
use Raza\PHPImpersonate\Exception\PHPImpersonateException;

try {
    $response = PHPImpersonate::get('https://example.com/maybe', [], 5);

    if (! $response->isSuccess()) {
        echo "HTTP {$response->status()}\n";
    }
} catch (PHPImpersonateException $e) {
    echo "Request failed: {$e->getMessage()}";
}
```

Catching `RequestException` alone is **not** enough to be safe against bad
input: `InvalidArgumentException` extends `LogicException`, so the two share no
built-in parent, and `PHPImpersonate::get('not a url')` would escape it.

```php
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\Exception\InvalidArgumentException;

try {
    $response = PHPImpersonate::get($url);
} catch (InvalidArgumentException $e) {
    // the request was never sent — fix the arguments
} catch (RequestException $e) {
    // it went out and failed — worth retrying
}
```

## Keeping up to date

New browser profiles and binaries come straight from upstream
[curl-impersonate](https://github.com/lexiforest/curl-impersonate) — no manual
editing:

```bash
composer update-impersonate            # refresh binaries + add any new browsers
composer update-impersonate -- --dry-run   # preview first
```

See [scripts/README.md](scripts/README.md) for per-step commands and details.

## Testing

```bash
composer test
```

The behaviour tests talk to an [httpbin](https://httpbin.org) instance. For a
fast, reliable, offline run, start one locally instead of hitting the public
service:

```bash
composer test-server-up                          # docker compose up -d httpbin
HTTPBIN_URL=http://localhost:8080 composer test
composer test-server-down                        # when you're done
```

Any test whose service is unreachable **skips** rather than fails, so an outage
never breaks the suite. The TLS-fingerprint tests use an external service
(`tls.peet.ws`, override with `TLS_FINGERPRINT_URL`) since httpbin can't report
JA3/JA4; they skip too when it's unavailable.

## License

MIT — see [LICENSE.md](LICENSE.md). The bundled `curl-impersonate` binaries
are built from curl, BoringSSL, nghttp2, brotli, zstd and libidn2, each under
its own licence; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md), which
also explains how every bundled file can be re-derived from upstream's release
(`bin/UPSTREAM-CHECKSUMS`).
