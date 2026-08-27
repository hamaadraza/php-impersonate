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
- [Browsers](#browsers) · [Proxies](#proxies) · [Request bodies](#request-bodies) · [Timeouts](#timeouts) · [Errors](#error-handling)
- [Keeping up to date](#keeping-up-to-date) · [Testing](#testing) · [License](#license)

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

- PHP **8.0+**
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
| Proxies | ✅ | ✅ |
| Other raw curl options | ❌ proxy options only | ✅ any curl flag |

> [!TIP]
> Just use `PHPImpersonate` — the default `'auto'` engine chooses **FFI** when
> it's usable and can apply your options, otherwise the **executable**, so your
> code is identical either way.

```php
use Raza\PHPImpersonate\PHPImpersonate;

$response = PHPImpersonate::get('https://example.com');   // auto engine
```

**Static helpers** — one-liners for one-off requests:

```php
PHPImpersonate::get(string $url, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = []): Response;
PHPImpersonate::post(string $url, ?array $data = null, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = []): Response;
PHPImpersonate::put(/* … same shape as post … */): Response;
PHPImpersonate::patch(/* … same shape as post … */): Response;
PHPImpersonate::delete(string $url, array $headers = [], int $timeout = 30, string $browser = 'firefox147', array $curlOptions = []): Response;
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
(new PHPImpersonate('chrome146'))->engine();    // 'ffi' or 'process' — what was chosen
```

Under `'auto'`, the FFI engine is used when `PHPImpersonate::ffiAvailable()` is
true **and** every supplied curl option is one it supports (`proxy`,
`proxy-user`, `noproxy`); otherwise the executable engine runs. `ffiAvailable()`
is true only when the `ffi` extension is usable — always on the CLI; other SAPIs
also need `ffi.enable` — and the shared library loads on this platform (bundled
for [common platforms](#supported-platforms); `PHP_IMPERSONATE_LIB` overrides it).

---

## Supported platforms

Prebuilt binaries **and** FFI libraries ship inside the package for the most
common platforms, so everything works with no extra steps:

| Platform | Bundled |
|---|:---:|
| Linux x86_64 (glibc) | ✅ |
| macOS ARM64 (Apple Silicon) | ✅ |
| Windows x86_64 | ✅ |
| Linux x86_64 (musl / Alpine) | on demand |
| Linux ARM64 (glibc & musl) | on demand |
| macOS x86_64 (Intel) | on demand |

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

```php
$client = new PHPImpersonate('safari184');
$response = $client->sendGet('https://example.com');
```

## Proxies

Configure a proxy through `curlOptions`. Proxy options work on **both engines**,
so `'auto'` can still use the fast FFI engine for proxied requests:

```php
$client = new PHPImpersonate('chrome146', 30, [
    'proxy'      => 'http://127.0.0.1:8080',   // or socks5://127.0.0.1:1080
    'proxy-user' => 'user:password',           // optional
]);

$response = $client->sendGet('https://api.ipify.org?format=json');
```

| Option | Description | Example |
|---|---|---|
| `proxy` | Proxy address (supports `http://`, `socks5://`, …) | `'http://proxy.example.com:3128'` |
| `proxy-user` | Proxy credentials | `'username:password'` |

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
```

> [!NOTE]
> `PUT` and `PATCH` default to **JSON**; `POST` defaults to **form-encoded**.
> An explicit `Content-Type` always wins.

## Timeouts

```php
PHPImpersonate::get('https://example.com', [], 5);   // 5-second timeout
$client = new PHPImpersonate('chrome146', 10);       // 10-second default for this client
```

## Error handling

Transport failures (DNS, connection, timeout, a non-loadable library, etc.)
throw `RequestException`. HTTP error statuses do **not** throw — check
`isSuccess()`:

```php
use Raza\PHPImpersonate\Exception\RequestException;

try {
    $response = PHPImpersonate::get('https://example.com/maybe', [], 5);

    if (! $response->isSuccess()) {
        echo "HTTP {$response->status()}\n";
    }
} catch (RequestException $e) {
    echo "Request failed: {$e->getMessage()}";
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

## License

MIT — see [LICENSE.md](LICENSE.md).
