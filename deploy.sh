#!/bin/bash

# Deployment script for php-impersonate
# Usage: ./deploy.sh <version>
# Example: ./deploy.sh 1.0.4

set -e  # Exit on any error

# Check if version argument is provided
if [ $# -eq 0 ]; then
    echo "Error: Version number is required"
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.4"
    exit 1
fi

VERSION=$1

# Validate version format (basic semver check)
if ! [[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Invalid version format. Expected format: x.y.z (e.g., 1.0.4)"
    exit 1
fi

echo "🚀 Starting deployment for version $VERSION"

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "Error: Not in a git repository"
    exit 1
fi

# Check if working directory is clean
if ! git diff-index --quiet HEAD --; then
    echo "Error: Working directory is not clean. Please commit or stash your changes."
    exit 1
fi

# Check if tag already exists
if git tag -l | grep -q "^v$VERSION$"; then
    echo "Error: Tag v$VERSION already exists"
    exit 1
fi

# The package version is NOT stored in composer.json: Packagist derives it from
# the git tag, and a hardcoded field silently drifts out of sync with reality
# (composer validate warns about it too). The tag below is the single source of
# truth for the released version.
if grep -q '"version"' composer.json; then
    echo "Error: composer.json declares a \"version\" field. Remove it — the git tag is the source of truth."
    exit 1
fi

# Create and push tag
echo "🏷️  Creating git tag v$VERSION"
git tag -a "v$VERSION" -m "Release version $VERSION"

echo "📤 Pushing tag to origin"
git push origin main
git push origin "v$VERSION"

echo "🎉 Deployment completed successfully!"
echo "📋 Summary:"
echo "   - Tag v$VERSION created and pushed to origin"
echo "   - Packagist will pick up v$VERSION from the tag"
