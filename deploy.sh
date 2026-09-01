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

# Releases are cut from main. Tagging whatever happened to be checked out while
# unconditionally pushing main published a tag pointing at a commit that was
# never on the released branch — Packagist takes the tag, so that ships.
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo "Error: releases are cut from main, but HEAD is on '$CURRENT_BRANCH'."
    echo "       Check out main (and merge your work into it) before releasing."
    exit 1
fi

# Fetch first, so the duplicate-tag check below sees tags that exist only on the
# remote, and so the divergence check has something current to compare against.
echo "🔄 Fetching from origin"
git fetch --tags --quiet origin

if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    echo "Error: local main and origin/main have diverged."
    echo "       Pull or push first — otherwise the tag is created locally and the"
    echo "       subsequent 'git push origin main' aborts, leaving it stranded."
    exit 1
fi

# Check if tag already exists, locally or on the remote. `git tag -l` alone saw
# only local tags, and the unescaped dots matched more than intended.
if git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null ||
   git ls-remote --exit-code --tags origin "refs/tags/v$VERSION" >/dev/null 2>&1; then
    echo "Error: Tag v$VERSION already exists (locally or on origin)"
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

# Push the branch BEFORE tagging: if the push is going to be rejected, nothing
# has been created yet. Tagging first left an annotated tag behind on failure,
# and the re-run then died on the duplicate-tag check above.
echo "📤 Pushing main to origin"
git push origin main

echo "🏷️  Creating git tag v$VERSION"
git tag -a "v$VERSION" -m "Release version $VERSION"

echo "📤 Pushing tag to origin"
if ! git push origin "v$VERSION"; then
    echo "Error: pushing the tag failed; removing the local tag so a re-run works."
    git tag -d "v$VERSION"
    exit 1
fi

echo "🎉 Deployment completed successfully!"
echo "📋 Summary:"
echo "   - Tag v$VERSION created and pushed to origin"
echo "   - Packagist will pick up v$VERSION from the tag"
