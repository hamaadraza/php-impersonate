# Changelog

All notable changes to `php-impersonate` will be documented in this file.

## v1.1.0 - 2025-08-11

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

### Technical Details
- Windows binaries are stored in `bin/windows/` directory
- Linux binaries remain in `bin/linux/` directory
- Automatic platform detection and binary selection
- Backward compatible with existing Linux installations
- **Updated Binary Source**: Now using curl-impersonate binaries from https://github.com/lexiforest/curl-impersonate
- **Enhanced CommandBuilder**: Added support for both generic and curl-specific command building with proper option formatting

## v1.0.0 - 2025-02-26

Release v1.0.0
