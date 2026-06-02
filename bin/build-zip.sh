#!/usr/bin/env bash
set -euo pipefail

# Build a distributable WordPress plugin zip for WP Flame.
# Usage: bin/build-zip.sh [version]
# If version is omitted, it's read from wp-flame.php header.

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

# Read version from plugin header if not supplied
if [[ -n "${1:-}" ]]; then
    VERSION="$1"
else
    VERSION=$(grep -m1 '^ \* Version:' wp-flame.php | sed 's/.*Version:[[:space:]]*//')
fi

if [[ ! "$VERSION" =~ ^[0-9]+(\.[0-9]+){2}([-.][0-9A-Za-z][0-9A-Za-z.-]*)?$ ]]; then
    echo "Invalid release version: $VERSION" >&2
    exit 1
fi

OUTFILE="wp-flame-${VERSION}.zip"
BUILD_DIR=$(mktemp -d)
PLUGIN_DIR="$BUILD_DIR/wp-flame"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "Building $OUTFILE ..."

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [[ "${WP_FLAME_ALLOW_DIRTY_BUILD:-}" != "1" ]] && [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        echo "Refusing to build from a dirty tracked worktree. Commit or stash changes first, or set WP_FLAME_ALLOW_DIRTY_BUILD=1 for a local test build." >&2
        exit 1
    fi

    UNTRACKED_RUNTIME_FILES=$(
        git ls-files --others --exclude-standard -- \
            wp-flame.php uninstall.php readme.txt README.md CHANGELOG.md composer.json composer.lock \
            src assets mu-plugin
    )
    if [[ "${WP_FLAME_ALLOW_DIRTY_BUILD:-}" != "1" && -n "$UNTRACKED_RUNTIME_FILES" ]]; then
        echo "Refusing to build with untracked files in packaged runtime paths:" >&2
        echo "$UNTRACKED_RUNTIME_FILES" >&2
        echo "Commit, remove, or move these files first, or set WP_FLAME_ALLOW_DIRTY_BUILD=1 for a local test build." >&2
        exit 1
    fi
fi

PLUGIN_VERSION=$(grep -m1 '^ \* Version:' wp-flame.php | sed 's/.*Version:[[:space:]]*//')
PLUGIN_CONSTANT_VERSION=$(grep -m1 "WP_FLAME_VERSION" wp-flame.php | sed "s/.*'\([^']*\)'.*/\1/")
README_VERSION=$(grep -m1 '^Stable tag:' readme.txt | sed 's/Stable tag:[[:space:]]*//')
MU_VERSION=$(grep -m1 "WP_FLAME_MU_VERSION" mu-plugin/wp-flame-early-hooks.php | sed "s/.*'\([^']*\)'.*/\1/")

if [[ "$VERSION" != "$PLUGIN_VERSION" ]]; then
    echo "Version mismatch: requested $VERSION but wp-flame.php is $PLUGIN_VERSION" >&2
    exit 1
fi
if [[ "$VERSION" != "$PLUGIN_CONSTANT_VERSION" ]]; then
    echo "Version mismatch: WP_FLAME_VERSION is $PLUGIN_CONSTANT_VERSION" >&2
    exit 1
fi
if [[ "$VERSION" != "$README_VERSION" ]]; then
    echo "Version mismatch: readme.txt stable tag is $README_VERSION" >&2
    exit 1
fi
if [[ "$VERSION" != "$MU_VERSION" ]]; then
    echo "Version mismatch: mu-plugin version is $MU_VERSION" >&2
    exit 1
fi
if ! grep -q "^## \\[$VERSION\\]" CHANGELOG.md; then
    echo "Version mismatch: CHANGELOG.md has no entry for $VERSION" >&2
    exit 1
fi
UNRELEASED_BULLET=$(awk '
    /^## \[Unreleased\]/ { inside = 1; next }
    /^## \[/ { inside = 0 }
    inside && /^- / { print; exit }
' CHANGELOG.md)
if [[ "${WP_FLAME_ALLOW_DIRTY_BUILD:-}" != "1" && "${WP_FLAME_ALLOW_UNRELEASED_CHANGELOG:-}" != "1" && -n "$UNRELEASED_BULLET" ]]; then
    echo "CHANGELOG.md has unreleased bullet entries. Move them under $VERSION before making a clean release build." >&2
    exit 1
fi

# Assemble plugin into temp directory
mkdir -p "$PLUGIN_DIR"
cp wp-flame.php uninstall.php readme.txt README.md CHANGELOG.md composer.json composer.lock "$PLUGIN_DIR/"
cp -r src assets mu-plugin "$PLUGIN_DIR/"

# Install production autoloader in the temp build, leaving the working tree untouched.
(cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader --quiet)
rm -rf "$PLUGIN_DIR/vendor/bin"
rm -f "$PLUGIN_DIR/composer.json" "$PLUGIN_DIR/composer.lock"

# Validate release contents before zipping. Keep dev/test/local tooling out of
# the distributed plugin while ensuring required runtime files are present.
required_paths=(
    "wp-flame.php"
    "uninstall.php"
    "readme.txt"
    "vendor/autoload.php"
    "src/Collector.php"
    "src/Instrumentation.php"
    "src/Redactor.php"
    "mu-plugin/wp-flame-early-hooks.php"
    "assets/js/flame-graph.js"
)
for required_path in "${required_paths[@]}"; do
    if [[ ! -e "$PLUGIN_DIR/$required_path" ]]; then
        echo "Build missing required runtime file: $required_path" >&2
        exit 1
    fi
done

for forbidden_path in tests node_modules .github .wp-env.json package.json package-lock.json composer.json composer.lock bin; do
    if [[ -e "$PLUGIN_DIR/$forbidden_path" ]]; then
        echo "Build contains dev-only path: $forbidden_path" >&2
        exit 1
    fi
done

# Create zip
rm -f "$OUTFILE"
(cd "$BUILD_DIR" && zip -rq "$REPO_DIR/$OUTFILE" wp-flame/)

ZIP_MANIFEST=$(unzip -Z1 "$OUTFILE")
if echo "$ZIP_MANIFEST" | grep -Eq '(^|/)(tests|node_modules|\.github|bin)/'; then
    echo "Build artifact contains dev-only directories." >&2
    exit 1
fi
if echo "$ZIP_MANIFEST" | grep -Eq '(^|/)(\.DS_Store|\.gitignore|\.phpunit\.result\.cache|\.wp-env\.json|package\.json|package-lock\.json|composer\.json|composer\.lock)$'; then
    echo "Build artifact contains dev-only files." >&2
    exit 1
fi

echo "Done: $OUTFILE ($(du -h "$OUTFILE" | cut -f1))"
