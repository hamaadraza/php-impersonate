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

echo "📝 Updating composer.json version to $VERSION"

# Update version in composer.json using jq if available, otherwise use sed
if command -v jq >/dev/null 2>&1; then
    # Use jq for more reliable JSON manipulation
    jq --arg version "$VERSION" '.version = $version' composer.json > composer.json.tmp && mv composer.json.tmp composer.json
    echo "✅ Updated composer.json using jq"
else
    # Fallback to sed (less reliable but works for simple cases)
    sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"$VERSION\"/" composer.json
    echo "✅ Updated composer.json using sed"
fi

# Verify the version was updated correctly
ACTUAL_VERSION=$(grep '"version"' composer.json | sed 's/.*"version": "\([^"]*\)".*/\1/')
if [ "$ACTUAL_VERSION" != "$VERSION" ]; then
    echo "Error: Failed to update version in composer.json. Expected: $VERSION, Got: $ACTUAL_VERSION"
    exit 1
fi

echo "✅ Version updated successfully in composer.json"

# Commit the version change
echo "📦 Committing version change"
git add composer.json
git commit -m "chore: bump version to $VERSION"

# Create and push tag
echo "🏷️  Creating git tag v$VERSION"
git tag -a "v$VERSION" -m "Release version $VERSION"

echo "📤 Pushing changes and tag to origin"
git push origin main
git push origin "v$VERSION"

echo "🎉 Deployment completed successfully!"
echo "📋 Summary:"
echo "   - Version updated to $VERSION in composer.json"
echo "   - Changes committed and pushed to main"
echo "   - Tag v$VERSION created and pushed to origin"
