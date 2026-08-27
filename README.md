# PHP-Impersonate

[![Tests](https://img.shields.io/github/actions/workflow/status/hamaadraza/php-impersonate/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hamaadraza/php-impersonate/actions/workflows/run-tests.yml)

A PHP library for making HTTP requests with browser impersonation. This library uses curl-impersonate to mimic various browsers' network signatures, making it useful for accessing websites that may detect and block automated requests.

## Installation

Install via Composer:

```bash
composer require hamaadraza/php-impersonate
```

## System Requirements

- PHP 8.0 or higher

## Supported platforms

The package ships prebuilt `curl-impersonate` binaries (and FFI libraries) for
the most common platforms, so they work with no extra steps:

- Linux x86_64 (glibc)
- macOS ARM64 (Apple Silicon)
- Windows x86_64

On any other platform — Linux musl/Alpine, Linux ARM64, or Intel macOS (x86_64)
— run the installer once after `composer require` to download the matching
binary and library:

```bash
php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install
```

To wire it into your own project so it runs automatically, add it to your root
`composer.json` (dependency scripts do not run on their own):

```json
{
  "scripts": {
    "post-install-cmd": ["@php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install"],
    "post-update-cmd":  ["@php vendor/hamaadraza/php-impersonate/bin/php-impersonate-install"]
  }
}
```

The installer is a no-op when the current platform is already bundled. Pass
`--no-libs` to skip the (larger) FFI library, or `--force` to re-download.

## Transports: executable vs. FFI

By default PHP-Impersonate runs the bundled `curl-impersonate` **executable** —
it works everywhere with no extra setup. There is also an optional **FFI**
transport backed by the `libcurl-impersonate` shared library that spawns no
process and reuses keep-alive connections between requests, so it is markedly
faster when you make many requests.

The `libcurl-impersonate` shared library ships **inside the package** for the
common platforms (see [Supported platforms](#supported-platforms)), so the FFI
transport works out of the box there; on other platforms the installer above
fetches it. Use `ClientFactory` to get the fastest transport that's available,
with an automatic fallback to the executable:

```php
use Raza\PHPImpersonate\ClientFactory;

$client = ClientFactory::create('firefox147');   // FfiClient if available, else PHPImpersonate
$response = $client->sendGet('https://example.com');
```

`ClientFactory::create()` returns a `ClientInterface`, so the rest of your code
is identical regardless of transport. Both implement `send*`, return the same
`Response`, and share the same validation/header handling.

The FFI transport is used automatically whenever the `ffi` extension is usable
(always on the CLI; other SAPIs additionally need `ffi.enable` set). When it is
not — e.g. FFI disabled on a shared host — `ClientFactory` transparently falls
back to the executable transport, so your code keeps working either way. To
point at a custom library build, set the `PHP_IMPERSONATE_LIB` environment
variable.

Check what would be used:

```php
use Raza\PHPImpersonate\FfiClient;
use Raza\PHPImpersonate\ClientFactory;

FfiClient::isAvailable();          // bool
ClientFactory::preferredDriver();  // 'ffi' or 'process'
```

Note: custom `$curlOptions` are only honoured by the executable transport, so
passing any keeps `ClientFactory` on the process driver.

## Basic Usage

```php
<?php
require 'vendor/autoload.php';

use Raza\PHPImpersonate\PHPImpersonate;

// Simple GET request
$response = PHPImpersonate::get('https://example.com');
echo $response->body();

// POST request with data
$response = PHPImpersonate::post('https://example.com/api', [
    'username' => 'johndoe',
    'email' => 'john@example.com'
]);

// Check the response
if ($response->isSuccess()) {
    $data = $response->json();
    echo "User created with ID: " . $data['id'];
} else {
    echo "Error: " . $response->status();
}
```

## API Reference

### Static Methods

The library provides convenient static methods for making requests:

```php
// GET request with optional headers and timeout
PHPImpersonate::get(string $url, array $headers = [], int $timeout = 30): Response

// POST request with optional data, headers and timeout
PHPImpersonate::post(string $url, ?array $data = null, array $headers = [], int $timeout = 30): Response

// PUT request with optional data, headers and timeout
PHPImpersonate::put(string $url, ?array $data = null, array $headers = [], int $timeout = 30): Response

// PATCH request with optional data, headers and timeout 
PHPImpersonate::patch(string $url, ?array $data = null, array $headers = [], int $timeout = 30): Response

// DELETE request with optional headers and timeout
PHPImpersonate::delete(string $url, array $headers = [], int $timeout = 30): Response

// HEAD request with optional headers and timeout
PHPImpersonate::head(string $url, array $headers = [], int $timeout = 30): Response
```

### Instance Methods

You can also create an instance of the client for more configuration options:

```php
// Create a client with specific browser and timeout
$client = new PHPImpersonate('chrome107', 30);

// Instance methods
$client->sendGet(string $url, array $headers = []): Response
$client->sendPost(string $url, ?array $data = null, array $headers = []): Response
$client->sendPut(string $url, ?array $data = null, array $headers = []): Response
$client->sendPatch(string $url, ?array $data = null, array $headers = []): Response
$client->sendDelete(string $url, array $headers = []): Response
$client->sendHead(string $url, array $headers = []): Response

// Generic send method
$client->send(Request $request): Response
```

### Response Methods

The `Response` class provides several methods for working with HTTP responses:

```php
// Get the HTTP status code
$response->status(): int

// Get the response body as string
$response->body(): string

// Check if the response was successful (status code 200-299)
$response->isSuccess(): bool

// Parse the response body as JSON (throws \JsonException on failure)
$response->json(bool $associative = true, int $depth = 512, int $flags = 0): mixed

// Check whether a header is present (case-insensitive)
$response->hasHeader(string $name): bool

// Get the first value of a header (case-insensitive), or $default when absent
$response->header(string $name, ?string $default = null): ?string

// Get ALL values of a header — use this for Set-Cookie and other repeatable headers
$response->headerAll(string $name): string[]

// Get all headers as a map of name → list of values
$response->headers(): array<string, string[]>

// Serialise to a plain array
$response->toArray(): array

// Dump response details to a string (for logging)
$response->dump(): string

// Print response details and return self (for debugging)
$response->debug(): Response
```

### Working with Headers

Most headers have a single value, so `header()` is all you need:

```php
$contentType = $response->header('Content-Type');         // 'application/json'
$etag        = $response->header('ETag', 'none');         // fallback to 'none'

if ($response->hasHeader('X-Rate-Limit-Remaining')) {
    $remaining = $response->header('X-Rate-Limit-Remaining');
}
```

Some headers are legitimately repeated by the server — most commonly `Set-Cookie`.
Per [RFC 6265 §4.1.1](https://www.rfc-editor.org/rfc/rfc6265#section-4.1.1), cookie values
must **not** be folded into a single comma-separated string, so `header('Set-Cookie')` would
silently drop all but the first cookie. Use `headerAll()` instead:

```php
$cookies = $response->headerAll('Set-Cookie');
// ['sessionid=abc; Path=/; HttpOnly', 'csrftoken=xyz; Path=/; SameSite=Lax']

foreach ($cookies as $cookie) {
    echo $cookie . "\n";
}
```

`headers()` returns the full map when you need to inspect everything at once:

```php
$allHeaders = $response->headers();
// [
//     'Content-Type' => ['application/json'],
//     'Set-Cookie'   => ['sessionid=abc; Path=/; HttpOnly', 'csrftoken=xyz; Path=/'],
//     'Cache-Control'=> ['no-cache, no-store'],
// ]

foreach ($response->headers() as $name => $values) {
    // $values is always an array, even for single-value headers
    foreach ($values as $value) {
        echo "$name: $value\n";
    }
}
```

**Key point:** Each header name maps to an **array of values** (`string[]`), not a single string. This correctly handles HTTP responses where headers like `Set-Cookie` can appear multiple times.

## Browser Options

PHP-Impersonate supports mimicking various browsers:

- `chrome99_android`
- `chrome99`
- `chrome100`
- `chrome101`
- `chrome104`
- `chrome107`
- `chrome110`
- `chrome116`
- `chrome119`
- `chrome120`
- `chrome123`
- `chrome124`
- `chrome131`
- `chrome131_android`
- `chrome133a`
- `chrome136`
- `chrome142`
- `chrome145`
- `chrome146`
- `chrome150`
- `edge99`
- `edge101`
- `firefox133`
- `firefox135`
- `firefox144`
- `firefox147` (default)
- `safari153`
- `safari155`
- `safari170`
- `safari172_ios`
- `safari180`
- `safari180_ios`
- `safari184`
- `safari184_ios`
- `safari260`
- `safari260_ios`
- `safari2601`
- `tor145`
- `okhttp4_android`

The authoritative list is always `Raza\PHPImpersonate\Browser\BrowserName::getAll()`.

Example:
```php
// Create a client that mimics Firefox
$client = new PHPImpersonate('firefox135');
$response = $client->sendGet('https://example.com');
```

### Keeping browsers and binaries up to date

New browser profiles and binaries are pulled from upstream
[curl-impersonate](https://github.com/lexiforest/curl-impersonate) automatically —
no manual editing:

```bash
composer update-impersonate          # refresh binaries + add any new browsers
composer update-impersonate -- --dry-run   # preview first
```

See [scripts/README.md](scripts/README.md) for details and per-step commands.

## Timeouts

You can configure request timeouts:

```php
// Set a 5-second timeout for this request
$response = PHPImpersonate::get('https://example.com', [], 5);

// Or when creating a client instance
$client = new PHPImpersonate('chrome107', 10); // 10-second timeout
```

## Proxy Configuration

You can route requests through a proxy server using the `curlOptions` parameter:

### Basic Proxy Usage

```php
use Raza\PHPImpersonate\PHPImpersonate;

$client = new PHPImpersonate(
    browser: 'chrome136',
    timeout: 30,
    curlOptions: [
        'proxy' => 'http://127.0.0.1:8080',  // HTTP proxy
        'proxy-user' => 'user:password',    // optional authentication
    ]
);

$response = $client->sendGet('https://api.ipify.org?format=json');

echo $response->body();
```

### Proxy Options

The following proxy-related curl options are supported:

| Option | Description | Example |
|--------|-------------|---------|
| `proxy` | Proxy server address | `'http://127.0.0.1:8080'` or `'http://proxy.example.com:3128'` |
| `proxy-user` | Proxy authentication credentials | `'username:password'` |

### SOCKS Proxy

You can also use SOCKS proxies by specifying the protocol:

```php
$client = new PHPImpersonate(
    browser: 'chrome136',
    timeout: 30,
    curlOptions: [
        'proxy' => 'socks5://127.0.0.1:1080',    // SOCKS5 proxy
    ]
);
```

### Using Proxy with Static Methods

For one-off requests with a proxy, create an instance and use the instance methods:

```php
$client = new PHPImpersonate(
    browser: 'chrome136',
    timeout: 30,
    curlOptions: [
        'proxy' => 'http://proxy.example.com:8080',
        'proxy-user' => 'user:pass',
    ]
);

// GET request through proxy
$response = $client->sendGet('https://example.com');

// POST request through proxy
$response = $client->sendPost('https://example.com/api', [
    'key' => 'value'
]);
```

## Advanced Examples

### JSON API Request

```php
// Data will be automatically converted to JSON with correct Content-Type
$data = [
    'title' => 'New Post',
    'body' => 'This is the content',
    'userId' => 1
];

$response = PHPImpersonate::post(
    'https://jsonplaceholder.typicode.com/posts',
    $data,
    ['Content-Type' => 'application/json']
);

$post = $response->json();
echo "Created post with ID: {$post['id']}\n";
```

### Error Handling

```php
try {
    $response = PHPImpersonate::get('https://example.com/nonexistent', [], 5);
    
    if (!$response->isSuccess()) {
        echo "Error: HTTP {$response->status()}\n";
        echo $response->body();
    }
} catch (\Raza\PHPImpersonate\Exception\RequestException $e) {
    echo "Request failed: " . $e->getMessage();
}
```

## Data Formats for POST, PUT and PATCH Requests

PHP-Impersonate supports sending data in different formats:

### Form Data

By default, data is sent as form data (`application/x-www-form-urlencoded`):

```php
// This will be sent as form data
$response = PHPImpersonate::post('https://example.com/api', [
    'username' => 'johndoe',
    'email' => 'john@example.com'
]);

// Explicitly specify form data
$response = PHPImpersonate::post('https://example.com/api',
    [
        'username' => 'johndoe',
        'email' => 'john@example.com'
    ],
    ['Content-Type' => 'application/x-www-form-urlencoded']
);
```

### JSON Data

You can send data as JSON by specifying the `Content-Type` header:

```php
// Send data as JSON
$response = PHPImpersonate::post('https://example.com/api',
    [
        'username' => 'johndoe',
        'email' => 'john@example.com'
    ],
    ['Content-Type' => 'application/json']
);
```

For PUT and PATCH requests, JSON is used as the default format.

## Testing

Run the test suite:

```bash
composer test
```

## License

This project is licensed under the MIT License - see the LICENSE file for details.
