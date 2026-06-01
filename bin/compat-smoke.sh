#!/usr/bin/env bash
set -euo pipefail

# Smoke-test WP Flame against common plugin stacks in wp-env.
#
# This is intentionally lightweight: it verifies activation and representative
# frontend/REST/GraphQL requests across safe, standard, and deep instrumentation
# modes. It is not a replacement for full browser or plugin-specific E2E tests.

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

if [[ ! -f vendor/autoload.php ]]; then
    echo "Missing vendor/autoload.php. Run composer install first." >&2
    exit 1
fi

if [[ ! -x node_modules/.bin/wp-env ]]; then
    echo "Missing node_modules/.bin/wp-env. Run npm install first." >&2
    exit 1
fi

WP_ENV=(node_modules/.bin/wp-env)
BASE_URL="${WP_FLAME_BASE_URL:-http://localhost:8888}"
PLUGIN_SLUG="${WP_FLAME_PLUGIN_SLUG:-wp-flame}"
SMOKE_PLUGINS=(woocommerce elementor bbpress wp-graphql)

export WP_ENV_HOME="${WP_FLAME_WP_ENV_HOME:-$REPO_DIR/.wp-env-home}"
mkdir -p "$WP_ENV_HOME"

wp_env() {
    "${WP_ENV[@]}" "$@"
}

wp_cli() {
    wp_env run cli wp "$@" --allow-root
}

ensure_docker_ready() {
    if ! command -v docker >/dev/null 2>&1; then
        echo "Docker is required for wp-env compatibility smoke tests." >&2
        exit 1
    fi

    if ! docker info >/dev/null 2>&1; then
        echo "Docker is installed but the daemon is not reachable. Start Docker Desktop or another compatible Docker daemon." >&2
        exit 1
    fi
}

ensure_wp_env_ready() {
    if ! wp_cli core is-installed >/dev/null 2>&1; then
        echo "wp-env did not initialize a runnable WordPress site. Check the wp-env and Docker output above." >&2
        exit 1
    fi
}

fetch() {
    local url="$1"
    curl -fsS --max-time 30 "$url" >/dev/null
}

post_graphql() {
    curl -fsS --max-time 30 \
        -H 'Content-Type: application/json' \
        --data '{"query":"query WPFlameSmoke { generalSettings { title } }"}' \
        "$BASE_URL/graphql" >/dev/null
}

trace_count() {
    local prefix
    prefix="$(wp_cli db prefix | tr -d '\r')"
    wp_cli db query "SELECT COUNT(*) FROM ${prefix}flame_traces" --skip-column-names | tr -d '[:space:]'
}

trace_max_row_id() {
    local prefix
    prefix="$(wp_cli db prefix | tr -d '\r')"
    wp_cli db query "SELECT COALESCE(MAX(id), 0) FROM ${prefix}flame_traces" --skip-column-names | tr -d '[:space:]'
}

trace_count_after() {
    local after_id="$1"
    local prefix
    prefix="$(wp_cli db prefix | tr -d '\r')"
    wp_cli db query "SELECT COUNT(*) FROM ${prefix}flame_traces WHERE id > ${after_id}" --skip-column-names | tr -d '[:space:]'
}

trace_like_count_after() {
    local after_id="$1"
    local pattern="$2"
    local prefix
    prefix="$(wp_cli db prefix | tr -d '\r')"
    wp_cli db query "SELECT COUNT(*) FROM ${prefix}flame_traces WHERE id > ${after_id} AND trace_data LIKE '%${pattern}%'" --skip-column-names | tr -d '[:space:]'
}

post_url() {
    local post_id="$1"
    wp_cli eval "echo get_permalink((int) ${post_id});" | tr -d '\r'
}

ensure_docker_ready

echo "Starting wp-env..."
if ! wp_env start; then
    echo "wp-env failed to start." >&2
    exit 1
fi
ensure_wp_env_ready

echo "Installing compatibility plugins..."
wp_cli plugin install "${SMOKE_PLUGINS[@]}" --activate
wp_cli plugin activate "$PLUGIN_SLUG"
wp_cli plugin is-active "$PLUGIN_SLUG" >/dev/null

echo "Configuring WP Flame smoke settings..."
wp_cli option update wp_flame_enabled 1
wp_cli option update wp_flame_trace_audience everyone
wp_cli option update wp_flame_sample_rate 1
wp_cli option update wp_flame_full_query_text 0
wp_cli option update wp_flame_full_http_url 0
wp_cli option update wp_flame_full_graphql_query 0
wp_cli option update wp_flame_track_users 0
wp_cli option update wp_flame_track_ips 0
wp_cli option update wp_flame_track_user_agent 0
wp_cli option update wp_flame_max_spans 2500
wp_cli option update wp_flame_min_callback_ms 0

echo "Creating representative content..."
PRODUCT_ID="$(wp_cli post create --post_type=product --post_title='WP Flame Smoke Product' --post_status=publish --porcelain)"
wp_cli post meta update "$PRODUCT_ID" _regular_price 9.99
wp_cli post meta update "$PRODUCT_ID" _price 9.99

FORUM_ID="$(wp_cli post create --post_type=forum --post_title='WP Flame Smoke Forum' --post_status=publish --porcelain)"

PAGE_ID="$(wp_cli post create --post_type=page --post_title='WP Flame Elementor Smoke' --post_status=publish --porcelain)"
wp_cli post meta update "$PAGE_ID" _elementor_edit_mode builder
wp_cli post meta update "$PAGE_ID" _elementor_version 3.0.0
wp_cli post meta update "$PAGE_ID" _elementor_data '[{"id":"wpflame","elType":"section","settings":{},"elements":[{"id":"wpflametext","elType":"widget","widgetType":"heading","settings":{"title":"WP Flame smoke"},"elements":[]}]}]'

PRODUCT_URL="$(post_url "$PRODUCT_ID")"
FORUM_URL="$(post_url "$FORUM_ID")"
PAGE_URL="$(post_url "$PAGE_ID")"

for mode in safe standard deep; do
    echo "Smoke testing instrumentation mode: $mode"
    wp_cli option update wp_flame_instrumentation_mode "$mode"

    before="$(trace_max_row_id)"
    fetch "$BASE_URL/?wp_flame_smoke=$mode"
    fetch "$BASE_URL/wp-json/"
    fetch "$PRODUCT_URL"
    fetch "$FORUM_URL"
    fetch "$PAGE_URL"
    post_graphql
    after_count="$(trace_count_after "$before")"

    if [[ "$after_count" -le 0 ]]; then
        echo "Expected WP Flame trace count to increase in $mode mode, after_id=$before new_traces=$after_count" >&2
        exit 1
    fi

    db_span_count="$(trace_like_count_after "$before" '"type":"db"')"
    if [[ "$mode" == "safe" && "$db_span_count" -ne 0 ]]; then
        echo "Safe mode should not record DB spans, found $db_span_count traces with DB spans." >&2
        exit 1
    fi
    if [[ "$mode" != "safe" && "$db_span_count" -le 0 ]]; then
        echo "Expected $mode mode to record DB spans, found none." >&2
        exit 1
    fi

    if [[ "$mode" == "deep" ]]; then
        callback_span_count="$(trace_like_count_after "$before" '"hook":')"
        if [[ "$callback_span_count" -le 0 ]]; then
            echo "Expected deep mode to record callback hook spans, found none." >&2
            exit 1
        fi
    fi
done

echo "Compatibility smoke passed."
