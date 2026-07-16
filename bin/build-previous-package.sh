#!/usr/bin/env bash
set -euo pipefail

# Reconstruct the authentic pre-RC package used by the upgrade/rollback gate.
# Usage: bin/build-previous-package.sh [git-ref] [output-zip]

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

PREVIOUS_REF=${1:-e58bef570e8e6b53925796de693937957765b581}
PREVIOUS_VERSION=$(git show "$PREVIOUS_REF:wp-flame.php" | grep -m1 '^ \* Version:' | sed 's/.*Version:[[:space:]]*//')
OUTPUT=${2:-build/previous/wp-flame-${PREVIOUS_VERSION}.zip}

case "$OUTPUT" in
    /*) ;;
    *) OUTPUT="$REPO_DIR/$OUTPUT" ;;
esac

if ! git cat-file -e "$PREVIOUS_REF^{commit}" 2>/dev/null; then
    echo "Previous package commit is unavailable: $PREVIOUS_REF" >&2
    echo "Fetch full history before running the lifecycle gate." >&2
    exit 1
fi

BUILD_DIR=$(mktemp -d)
trap 'rm -rf "$BUILD_DIR"' EXIT

git archive "$PREVIOUS_REF" | tar -x -C "$BUILD_DIR"

(
    cd "$BUILD_DIR"
    WP_FLAME_ALLOW_UNRELEASED_CHANGELOG=1 bash bin/build-zip.sh "$PREVIOUS_VERSION"
)

mkdir -p "$(dirname "$OUTPUT")"
cp "$BUILD_DIR/wp-flame-${PREVIOUS_VERSION}.zip" "$OUTPUT"
unzip -t "$OUTPUT" >/dev/null

echo "Authentic previous package: $OUTPUT"
