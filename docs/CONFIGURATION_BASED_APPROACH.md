# Configuration-Based Browser Impersonation

This document describes the new configuration-based approach for browser impersonation in PHP Impersonate, which replaces the individual browser scripts with a centralized configuration system.

## Overview

Instead of having individual browser scripts (like `curl_chrome99`, `curl_firefox133`, etc.), the library now uses a centralized `BrowserConfig` class that contains all browser configurations as PHP arrays. This approach provides better maintainability, consistency, and ease of use.

## How It Works

### 1. BrowserConfig Class

The `BrowserConfig` class contains all browser configurations in a structured format:

```php
use Raza\PHPImpersonate\Browser\BrowserConfig;

// Get all available browser configurations
$configs = BrowserConfig::getAllConfigs();

// Get configuration for a specific browser
$chromeConfig = BrowserConfig::getConfig('chrome99');

// Get list of available browsers
$browsers = BrowserConfig::getAvailableBrowsers();

// Check if a browser is supported
if (BrowserConfig::hasConfig('firefox133')) {
    // Browser is supported
}
```

### 2. Configuration Structure

Each browser configuration contains:

- **ciphers**: TLS cipher suite configuration
- **curves**: Elliptic curve configuration (optional)
- **signature-hashes**: Signature hash algorithms (optional)
- **headers**: HTTP headers including User-Agent, Accept, etc.
- **options**: curl-impersonate specific options

Example configuration:
```php
'chrome99' => [
    'ciphers' => 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:...',
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9...',
        'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="99", "Google Chrome";v="99"',
        // ... more headers
    ],
    'options' => [
        'http2' => true,
        'http2-settings' => '1:65536;3:1000;4:6291456;6:262144',
        'compressed' => true,
        'tlsv1.2' => true,
        // ... more options
    ],
]
```

### 3. Usage

The usage remains the same as before:

```php
use Raza\PHPImpersonate\PHPImpersonate;

// Make a request with Chrome 99
$response = PHPImpersonate::get(
    'https://example.com',
    [],
    30,
    'chrome99'
);

// Make a request with Firefox 133
$response = PHPImpersonate::get(
    'https://example.com',
    [],
    30,
    'firefox133'
);
```

## Benefits

### 1. **Centralized Configuration**
- All browser configurations are in one place
- Easier to maintain and update
- Consistent structure across all browsers

### 2. **No File System Overhead**
- No need for individual browser script files
- Reduced disk space usage
- Faster initialization

### 3. **Better Maintainability**
- Easy to add new browser configurations
- Simple to modify existing configurations
- Version control friendly

### 4. **Improved Testing**
- Configurations can be easily validated
- Unit tests for configuration structure
- Better error handling

### 5. **Platform Independence**
- Works the same on Linux and Windows
- No platform-specific script files needed
- Consistent behavior across platforms

## Adding New Browser Configurations

To add a new browser configuration:

1. **Extract the configuration** from the existing browser script:
   ```bash
   # Example: Extract Chrome 120 configuration
   cat bin/linux/curl_chrome120
   ```

2. **Add to BrowserConfig class**:
   ```php
   'chrome120' => [
       'ciphers' => '...',
       'headers' => [
           'User-Agent' => '...',
           // ... other headers
       ],
       'options' => [
           'http2' => true,
           // ... other options
       ],
   ],
   ```

3. **Update tests** to include the new browser

## Migration from Script-Based Approach

The migration is seamless - existing code will continue to work without changes:

```php
// This still works exactly the same
$response = PHPImpersonate::get('https://example.com', [], 30, 'chrome99');
```

The only difference is that the library now uses the centralized configuration instead of looking for individual script files.

## Available Browsers

The following browsers are currently supported:

- `chrome99` - Google Chrome 99 (Windows)
- `chrome99_android` - Google Chrome 99 (Android)
- `chrome110` - Google Chrome 110 (Windows)
- `chrome120` - Google Chrome 120 (macOS)
- `edge99` - Microsoft Edge 99 (Windows)
- `firefox133` - Mozilla Firefox 133 (macOS)
- `safari153` - Safari 15.3 (macOS)
- `safari172_ios` - Safari 17.2 (iOS)
- `safari260` - Safari 26.0 (macOS)

## Technical Details

### Browser Class Changes

The `Browser` class now:
1. Validates browser names against `BrowserConfig`
2. Resolves the main `curl-impersonate` binary path
3. Provides access to browser configuration

### PHPImpersonate Class Changes

The `PHPImpersonate` class now:
1. Merges browser configuration with request options
2. Applies browser-specific headers and TLS settings
3. Maintains backward compatibility

### Command Building

The command building process:
1. Starts with base curl options (method, output files, etc.)
2. Merges browser-specific configuration
3. Applies custom curl options
4. Builds the final command using `CommandBuilder`

## Future Enhancements

Potential improvements for the configuration-based approach:

1. **Dynamic Configuration Loading**: Load configurations from external files
2. **Configuration Validation**: Validate configurations at runtime
3. **Custom Browser Support**: Allow users to define custom browser configurations
4. **Configuration Versioning**: Support for different configuration versions
5. **Performance Optimization**: Cache compiled configurations

## Conclusion

The configuration-based approach provides a more maintainable, efficient, and user-friendly way to handle browser impersonation. It eliminates the need for individual script files while maintaining full functionality and backward compatibility.
