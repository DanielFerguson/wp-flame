#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

PREVIOUS_ZIP=${1:-}
CANDIDATE_ZIP=${2:-}
ENV_HOME="$REPO_DIR/.wp-env-home"
STAGE="$REPO_DIR/build/package-lifecycle"
PLUGIN_SLUG="wp-flame-lifecycle"

if [[ ! -f "$PREVIOUS_ZIP" || ! -f "$CANDIDATE_ZIP" ]]; then
    echo "Usage: bin/package-lifecycle-smoke.sh <previous.zip> <candidate.zip>" >&2
    exit 2
fi

PREVIOUS_ZIP="$(cd "$(dirname "$PREVIOUS_ZIP")" && pwd)/$(basename "$PREVIOUS_ZIP")"
CANDIDATE_ZIP="$(cd "$(dirname "$CANDIDATE_ZIP")" && pwd)/$(basename "$CANDIDATE_ZIP")"
PREVIOUS_VERSION=$(unzip -p "$PREVIOUS_ZIP" 'wp-flame/wp-flame.php' | grep -m1 '^ \* Version:' | sed 's/.*Version:[[:space:]]*//')
CANDIDATE_VERSION=$(unzip -p "$CANDIDATE_ZIP" 'wp-flame/wp-flame.php' | grep -m1 '^ \* Version:' | sed 's/.*Version:[[:space:]]*//')

wp_env() {
    WP_ENV_HOME="$ENV_HOME" npx wp-env "$@"
}

wp_cli() {
    wp_env run tests-cli wp "$@" --allow-root
}

stage_zip() {
    local zip_file=$1
    local name=$2
    rm -rf "$STAGE/$name"
    mkdir -p "$STAGE/$name"
    unzip -q "$zip_file" -d "$STAGE/$name"
    test -f "$STAGE/$name/wp-flame/wp-flame.php"
}

switch_package() {
    local name=$1
    if [[ "$name" != "previous" && "$name" != "candidate" ]]; then
        echo "Unknown package stage: $name" >&2
        exit 1
    fi
    wp_env run tests-cli bash -c "rm -rf '/var/www/html/wp-content/plugins/$PLUGIN_SLUG' && cp -R '/var/www/html/wp-content/plugins/wp-flame/build/package-lifecycle/$name/wp-flame' '/var/www/html/wp-content/plugins/$PLUGIN_SLUG'"
}

assert_value() {
    local label=$1
    local expected=$2
    local actual=$3
    if [[ "$actual" != "$expected" ]]; then
        echo "$label: expected '$expected', got '$actual'" >&2
        exit 1
    fi
}

assert_table_state() {
    local expected=$1
    local actual
    actual=$(wp_cli eval 'global $wpdb; $table = $wpdb->prefix . "flame_traces"; echo $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table ? "present" : "absent";')
    assert_value "trace table" "$expected" "$actual"
}

cleanup() {
    wp_cli plugin deactivate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
    wp_env run tests-cli bash -c "rm -rf '/var/www/html/wp-content/plugins/$PLUGIN_SLUG'" >/dev/null 2>&1 || true
    wp_cli plugin activate wp-flame >/dev/null 2>&1 || true
}
trap cleanup EXIT

rm -rf "$STAGE"
mkdir -p "$STAGE"
stage_zip "$PREVIOUS_ZIP" previous
stage_zip "$CANDIDATE_ZIP" candidate

wp_env start

# Use wp-env's separate tests database. Remove the source-tree plugin's local
# data without deleting its bind-mounted directory, then exercise only the
# staged package symlink below.
wp_cli plugin deactivate wp-flame >/dev/null 2>&1 || true
wp_env run tests-cli bash -c "rm -f '/var/www/html/wp-content/mu-plugins/wp-flame-early-hooks.php'"
wp_cli eval 'if (!defined("WP_UNINSTALL_PLUGIN")) { define("WP_UNINSTALL_PLUGIN", "wp-flame/wp-flame.php"); } require WP_PLUGIN_DIR . "/wp-flame/uninstall.php";'
if wp_cli plugin is-installed "$PLUGIN_SLUG" >/dev/null 2>&1; then
    wp_cli plugin uninstall "$PLUGIN_SLUG" --deactivate >/dev/null 2>&1 || true
fi

# Clean candidate install, deactivate/reactivate, and uninstall.
stage_zip "$CANDIDATE_ZIP" candidate
switch_package candidate
wp_cli plugin deactivate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
wp_cli plugin activate "$PLUGIN_SLUG"
assert_value "clean install version" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo WP_FLAME_VERSION;')"
assert_value "clean install early-loader version" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo defined("WP_FLAME_MU_VERSION") ? WP_FLAME_MU_VERSION : "missing";')"
assert_value "clean install schema" "6" "$(wp_cli option get wp_flame_schema_version)"
assert_table_state present
wp_cli plugin deactivate "$PLUGIN_SLUG"
assert_value "deactivation mu-plugin cleanup" "absent" "$(wp_cli eval 'echo file_exists(WPMU_PLUGIN_DIR . "/wp-flame-early-hooks.php") ? "present" : "absent";')"
wp_cli plugin activate "$PLUGIN_SLUG"
wp_cli plugin uninstall "$PLUGIN_SLUG" --deactivate
assert_table_state absent
assert_value "uninstall schema option" "absent" "$(wp_cli eval 'echo get_option("wp_flame_schema_version", "absent");')"

# Forward upgrade, application-package rollback, and return to the candidate.
stage_zip "$PREVIOUS_ZIP" previous
stage_zip "$CANDIDATE_ZIP" candidate
switch_package previous
wp_cli plugin activate "$PLUGIN_SLUG"
wp_cli option update wp_flame_package_lifecycle_marker retained
assert_value "previous install version" "$PREVIOUS_VERSION" "$(wp_cli eval 'echo WP_FLAME_VERSION;')"
assert_value "previous install early-loader version" "$PREVIOUS_VERSION" "$(wp_cli eval 'echo defined("WP_FLAME_MU_VERSION") ? WP_FLAME_MU_VERSION : "missing";')"
switch_package candidate
wp_cli flame migrate --until-complete
assert_value "forward upgrade version" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo WP_FLAME_VERSION;')"
assert_value "forward upgrade legacy early-loader migration" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo defined("WP_FLAME_MU_VERSION") ? WP_FLAME_MU_VERSION : "missing";')"
assert_value "forward upgrade data" "retained" "$(wp_cli option get wp_flame_package_lifecycle_marker)"
assert_table_state present
switch_package previous
assert_value "rollback version" "$PREVIOUS_VERSION" "$(wp_cli eval 'echo WP_FLAME_VERSION;')"
assert_value "rollback early-loader version" "$PREVIOUS_VERSION" "$(wp_cli eval 'echo defined("WP_FLAME_MU_VERSION") ? WP_FLAME_MU_VERSION : "missing";')"
assert_value "rollback data" "retained" "$(wp_cli option get wp_flame_package_lifecycle_marker)"
assert_table_state present
switch_package candidate
wp_cli flame migrate --until-complete
assert_value "candidate restored" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo WP_FLAME_VERSION;')"
assert_value "restored candidate early-loader version" "$CANDIDATE_VERSION" "$(wp_cli eval 'echo defined("WP_FLAME_MU_VERSION") ? WP_FLAME_MU_VERSION : "missing";')"
wp_cli plugin uninstall "$PLUGIN_SLUG" --deactivate
assert_table_state absent

echo "Package lifecycle passed: clean install, forward upgrade, compatible package rollback, deactivate, reactivate, and uninstall."
