#!/bin/bash

# =============================================================================
# PHP-Impersonate Multi-Architecture Test Script
# =============================================================================
# Tests the library across all supported Linux architectures using Docker.
# macOS and Windows cannot be tested via Docker and require native runners.
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Test configurations: "name|platform|image"
TESTS=(
    "Linux x86_64 (glibc)|linux/amd64|php:8.3-cli"
    "Linux ARM64 (glibc)|linux/arm64|php:8.3-cli"
    "Linux x86_64 Alpine (musl)|linux/amd64|php:8.3-cli-alpine"
    "Linux ARM64 Alpine (musl)|linux/arm64|php:8.3-cli-alpine"
)

# Results tracking
declare -A RESULTS
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# =============================================================================
# Helper Functions
# =============================================================================

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_test_header() {
    echo -e "${CYAN}───────────────────────────────────────────────────────────────${NC}"
    echo -e "${CYAN}  Testing: $1${NC}"
    echo -e "${CYAN}  Platform: $2 | Image: $3${NC}"
    echo -e "${CYAN}───────────────────────────────────────────────────────────────${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# =============================================================================
# QEMU Setup
# =============================================================================

setup_qemu() {
    print_header "Setting up QEMU for cross-architecture emulation"
    
    # Check if QEMU is already set up by testing ARM64 execution
    if docker run --rm --platform linux/arm64 alpine:latest uname -m 2>/dev/null | grep -q "aarch64"; then
        print_success "QEMU is already configured"
        return 0
    fi
    
    print_info "Installing QEMU user-mode emulation..."
    if docker run --rm --privileged multiarch/qemu-user-static --reset -p yes > /dev/null 2>&1; then
        print_success "QEMU installed successfully"
    else
        print_error "Failed to install QEMU"
        echo "Please run manually: docker run --rm --privileged multiarch/qemu-user-static --reset -p yes"
        exit 1
    fi
}

# =============================================================================
# Run Single Test
# =============================================================================

run_test() {
    local name="$1"
    local platform="$2"
    local image="$3"
    
    print_test_header "$name" "$platform" "$image"
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    
    # Determine install command based on image
    local install_cmd
    if [[ "$image" == *"alpine"* ]]; then
        install_cmd="apk add --no-cache git unzip curl"
    else
        install_cmd="apt-get update && apt-get install -y git unzip curl libzip-dev && docker-php-ext-install zip"
    fi
    
    # Run the test
    local start_time=$(date +%s)
    
    if docker run --rm --platform "$platform" \
        -v "$PROJECT_DIR:/app" \
        -w /app \
        "$image" \
        sh -c "
            # Install dependencies
            $install_cmd > /dev/null 2>&1
            
            # Install composer
            curl -sS https://getcomposer.org/installer 2>/dev/null | php -- --install-dir=/usr/local/bin --filename=composer > /dev/null 2>&1
            
            # Install PHP dependencies
            composer install --prefer-dist --no-interaction --quiet 2>/dev/null
            
            # Show platform detection
            echo '=== Platform Detection ==='
            php -r \"
                require 'vendor/autoload.php';
                use Raza\PHPImpersonate\Platform\PlatformDetector;
                echo 'OS: ' . PHP_OS . PHP_EOL;
                echo 'Arch: ' . php_uname('m') . PHP_EOL;
                echo 'Platform: ' . PlatformDetector::getPlatform() . PHP_EOL;
                echo 'Architecture: ' . PlatformDetector::getArchitecture() . PHP_EOL;
                echo 'Libc: ' . PlatformDetector::getLibcType() . PHP_EOL;
                echo 'Binary Dir: ' . PlatformDetector::getBinaryDir() . PHP_EOL;
            \"
            echo '========================='
            echo ''
            
            # Run tests
            vendor/bin/pest --ci
        " 2>&1; then
        
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        
        print_success "PASSED ($name) - ${duration}s"
        RESULTS["$name"]="PASSED"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        
        print_error "FAILED ($name) - ${duration}s"
        RESULTS["$name"]="FAILED"
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi
    
    echo ""
}

# =============================================================================
# Print Summary
# =============================================================================

print_summary() {
    print_header "Test Summary"
    
    echo "Results:"
    echo ""
    
    for test_config in "${TESTS[@]}"; do
        IFS='|' read -r name platform image <<< "$test_config"
        local result="${RESULTS[$name]:-SKIPPED}"
        
        if [[ "$result" == "PASSED" ]]; then
            echo -e "  ${GREEN}✓${NC} $name"
        elif [[ "$result" == "FAILED" ]]; then
            echo -e "  ${RED}✗${NC} $name"
        else
            echo -e "  ${YELLOW}○${NC} $name (skipped)"
        fi
    done
    
    echo ""
    echo -e "${BLUE}───────────────────────────────────────────────────────────────${NC}"
    echo ""
    echo -e "  Total:  $TOTAL_TESTS"
    echo -e "  ${GREEN}Passed: $PASSED_TESTS${NC}"
    echo -e "  ${RED}Failed: $FAILED_TESTS${NC}"
    echo ""
    
    if [[ $FAILED_TESTS -eq 0 ]]; then
        echo -e "${GREEN}All tests passed! ✓${NC}"
        echo ""
        return 0
    else
        echo -e "${RED}Some tests failed! ✗${NC}"
        echo ""
        return 1
    fi
}

# =============================================================================
# Main
# =============================================================================

main() {
    print_header "PHP-Impersonate Multi-Architecture Tests"
    
    echo "Project directory: $PROJECT_DIR"
    echo ""
    
    # Check Docker is available
    if ! command -v docker &> /dev/null; then
        print_error "Docker is not installed or not in PATH"
        exit 1
    fi
    
    # Setup QEMU for cross-architecture support
    setup_qemu
    
    # Parse arguments
    local filter=""
    while [[ $# -gt 0 ]]; do
        case $1 in
            --filter=*)
                filter="${1#*=}"
                shift
                ;;
            --help|-h)
                echo "Usage: $0 [options]"
                echo ""
                echo "Options:"
                echo "  --filter=<pattern>  Only run tests matching pattern"
                echo "                      Examples: --filter=ARM64, --filter=Alpine"
                echo "  --help, -h          Show this help message"
                echo ""
                echo "Available tests:"
                for test_config in "${TESTS[@]}"; do
                    IFS='|' read -r name platform image <<< "$test_config"
                    echo "  - $name"
                done
                exit 0
                ;;
            *)
                print_warning "Unknown option: $1"
                shift
                ;;
        esac
    done
    
    # Run tests
    for test_config in "${TESTS[@]}"; do
        IFS='|' read -r name platform image <<< "$test_config"
        
        # Apply filter if specified
        if [[ -n "$filter" ]] && [[ ! "$name" =~ $filter ]]; then
            print_info "Skipping: $name (doesn't match filter: $filter)"
            continue
        fi
        
        run_test "$name" "$platform" "$image"
    done
    
    # Print summary
    print_summary
}

# Run main function
main "$@"
