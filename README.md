# WP Flame

WP Flame is a self-hosted WordPress APM plugin that shows where a request spends its time. It records bounded, sampled traces in WordPress, then renders them as an interactive flame graph in wp-admin.

It targets PHP 7.4+ and WordPress 6.0+, has no production Composer package dependencies, and uses vanilla JavaScript/CSS for the admin UI.

## What It Captures

- WordPress lifecycle phases such as Bootstrap, Plugin Load, Theme Setup, Routing, Main Query, Render, REST, AJAX, CLI, and cron.
- Database spans in Standard and Deep modes when the active `$wpdb` is the core WordPress class.
- WPGraphQL operation, resolver, and DB spans when WPGraphQL hooks are available.
- External HTTP spans with host, method, status, duration, and source attribution.
- Optional Deep-mode callback spans for WordPress hooks.
- Object-cache hit/miss summary from public cache drop-in counters.
- Aggregate dashboard data such as slowest pages, slowest callbacks, response-time distribution, top users, and top IPs when those privacy options are enabled.

## Compatibility Modes

WP Flame has three instrumentation modes:

- **Safe:** lifecycle and external HTTP spans only. Use this first on compatibility-sensitive production sites.
- **Standard:** Safe mode plus database spans where `$wpdb` can be safely replaced. This is the default.
- **Deep:** Standard mode plus callback wrapping for focused debugging. Deep mode skips by-reference callbacks, return-by-reference callbacks, non-callables, GraphQL callback wrapping, and known self-instrumentation paths.

For major plugin stacks such as WooCommerce, Elementor, bbPress, WPGraphQL, custom DB drop-ins, and managed hosts, start with Safe or Standard mode and use sampling. Deep mode is intended for short debugging sessions, not always-on production monitoring.

## Privacy Defaults

WP Flame defaults are intentionally conservative:

- Request URLs are stored with sensitive query values redacted.
- SQL is stored as normalized fingerprints/labels by default.
- External HTTP spans store host/method/status by default, not full URLs.
- GraphQL spans store operation metadata and query length by default, not full query text.
- User IDs, IP addresses, and user-agent strings are off by default.
- IP tracking uses only `REMOTE_ADDR` by default. Trusted proxy headers require the `wp_flame_client_ip_headers` filter.

Settings can opt into full SQL text, full HTTP URLs, full GraphQL query text, user IDs, IP addresses, and user-agent strings when the site operator has an appropriate data handling policy.

## Installation

1. Upload the `wp-flame` folder to `wp-content/plugins/`.
2. Activate WP Flame in wp-admin.
3. On activation, WP Flame creates its custom trace table and tries to copy `mu-plugin/wp-flame-early-hooks.php` to `wp-content/mu-plugins/wp-flame-early-hooks.php`.
4. Browse the site as an admin or use the admin-bar "Trace This Page" button.
5. View traces under Tools > WP Flame.

If the mu-plugin cannot be copied, WP Flame runs in degraded mode. It cannot capture the earliest Bootstrap/Plugin Load timing, but normal admin, settings, storage, privacy, and later instrumentation behavior still works.

## Project Structure

```text
wp-flame.php                         Main plugin bootstrap and request lifecycle wiring
src/                                 PSR-4 PHP source under WPFlame\
src/Collector.php                    In-memory span collection and trace assembly
src/Span.php, src/Trace.php          Trace value objects and JSON serialization
src/Instrumentation.php              Request eligibility, identity, cache, and instrumentor selection helpers
src/Redactor.php                     Privacy redaction for URLs and SQL
src/DB.php, src/DbInstrumentor.php   Optional wpdb instrumentation
src/GraphQL.php                      WPGraphQL and GraphQL endpoint instrumentation
src/Http.php                         WordPress HTTP API instrumentation
src/CallbackInstrumentor.php         Deep-mode WordPress hook callback wrapping
src/Storage.php                      Custom table storage, retention, and aggregate queries
src/Admin/                           Trace list and flame graph admin views
src/Settings.php                     WordPress Settings API registration and sanitizers
src/Privacy.php                      WordPress privacy export/erasure hooks
mu-plugin/wp-flame-early-hooks.php   Early lifecycle capture copied to mu-plugins
assets/js/, assets/css/              Vanilla admin UI assets
tests/Unit, tests/Integration        PHPUnit tests
bin/build-zip.sh                     Release zip builder
bin/compat-smoke.sh                  wp-env compatibility smoke test
```

## Development

Install PHP dependencies:

```bash
composer install
```

Run all PHPUnit suites configured in `phpunit.xml`:

```bash
composer test
```

Run PHP syntax checks:

```bash
composer lint
```

Run unit tests only:

```bash
composer test:unit
```

Install the WordPress integration test framework:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Run integration tests after the WordPress test framework is installed:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration
```

Optional wp-env compatibility smoke testing requires Node dependencies and Docker:

```bash
npm install
npm run compat:smoke
```

## Release Build

Build a release zip:

```bash
bin/build-zip.sh [version]
```

The build script:

- Refuses tracked dirty builds unless `WP_FLAME_ALLOW_DIRTY_BUILD=1` is set.
- Checks version sync across `wp-flame.php`, `WP_FLAME_VERSION`, `readme.txt`, `CHANGELOG.md`, and the mu-plugin version.
- Builds in a temporary directory.
- Installs an optimized production Composer autoloader.
- Removes dev-only paths such as tests, CI, npm files, Composer metadata, and build scripts from the zip.

## Operational Notes

- Use sampling for production monitoring. The default audience is admins only.
- The mu-plugin has early gates for disabled tracing, sampling misses, and anonymous requests when the audience requires logged-in/admin users.
- Stored traces are automatically pruned according to the retention setting.
- Span count and trace JSON size limits protect memory and storage on large WooCommerce, Elementor, REST, GraphQL, and admin requests.
- Uninstall removes plugin tables, options, transients, cron hooks, and the copied mu-plugin across multisite installs.

## Extension Points

Important filters/actions:

- `wp_flame_instrumentors`
- `wp_flame_trace_meta`
- `wp_flame_should_store_trace`
- `wp_flame_trace_stored`
- `wp_flame_insights`
- `wp_flame_score_factors`
- `wp_flame_client_ip_headers`

Keep extension callbacks lightweight. They run on request or shutdown paths and can affect production overhead.
