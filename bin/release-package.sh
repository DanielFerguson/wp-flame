#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

VERSION=${1:-$(grep -m1 '^ \* Version:' wp-flame.php | sed 's/.*Version:[[:space:]]*//')}
EXPECTED_TAG="v${VERSION}"
DIST_DIR="$REPO_DIR/dist/$VERSION"

if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
    echo "Release packaging requires a clean worktree." >&2
    exit 1
fi

ACTUAL_TAG=$(git describe --tags --exact-match HEAD 2>/dev/null || true)
if [[ "$ACTUAL_TAG" != "$EXPECTED_TAG" ]]; then
    echo "Release packaging requires HEAD to have exact tag $EXPECTED_TAG (found: ${ACTUAL_TAG:-none})." >&2
    exit 1
fi

COMMIT=$(git rev-parse HEAD)
SOURCE_EPOCH=$(git show -s --format=%ct HEAD)
ARTIFACT="wp-flame-${VERSION}.zip"
FIRST_BUILD=$(mktemp)
trap 'rm -f "$FIRST_BUILD"' EXIT

SOURCE_DATE_EPOCH="$SOURCE_EPOCH" bin/build-zip.sh "$VERSION"
cp "$ARTIFACT" "$FIRST_BUILD"
SOURCE_DATE_EPOCH="$SOURCE_EPOCH" bin/build-zip.sh "$VERSION"

if ! cmp -s "$FIRST_BUILD" "$ARTIFACT"; then
    echo "Repeated builds from the same tag were not byte-identical." >&2
    exit 1
fi

mkdir -p "$DIST_DIR"
cp "$ARTIFACT" "$DIST_DIR/$ARTIFACT"
CHECKSUM=$(shasum -a 256 "$DIST_DIR/$ARTIFACT" | awk '{print $1}')
printf '%s  %s\n' "$CHECKSUM" "$ARTIFACT" > "$DIST_DIR/$ARTIFACT.sha256"

WP_FLAME_RELEASE_VERSION="$VERSION" \
WP_FLAME_RELEASE_TAG="$EXPECTED_TAG" \
WP_FLAME_RELEASE_COMMIT="$COMMIT" \
WP_FLAME_RELEASE_EPOCH="$SOURCE_EPOCH" \
WP_FLAME_RELEASE_ARTIFACT="$ARTIFACT" \
WP_FLAME_RELEASE_SHA256="$CHECKSUM" \
php -r '
$data = [
    "schema" => "wp-flame-release-provenance.v1",
    "version" => getenv("WP_FLAME_RELEASE_VERSION"),
    "tag" => getenv("WP_FLAME_RELEASE_TAG"),
    "commit" => getenv("WP_FLAME_RELEASE_COMMIT"),
    "source_date_epoch" => (int) getenv("WP_FLAME_RELEASE_EPOCH"),
    "artifact" => getenv("WP_FLAME_RELEASE_ARTIFACT"),
    "sha256" => getenv("WP_FLAME_RELEASE_SHA256"),
    "repeated_build_identical" => true,
];
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
' > "$DIST_DIR/provenance.json"

echo "Release artifact: $DIST_DIR/$ARTIFACT"
echo "SHA-256: $CHECKSUM"
