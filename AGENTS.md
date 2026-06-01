# AGENTS.md

Guidance for coding agents working in this repository.

## Project Shape

WP Flame is a WordPress APM plugin. It targets PHP 7.4+ and WordPress 6.0+, uses PSR-4 autoloading for the `WPFlame\` namespace, and keeps runtime dependencies minimal.

Important areas:

- `wp-flame.php`: plugin bootstrap, activation/deactivation hooks, request lifecycle registration, shutdown persistence, admin hooks, settings, privacy hooks, and WP-CLI registration.
- `src/`: core PHP implementation. Prefer extending the existing `Collector`, `Span`, `Trace`, `Instrumentor`, `Storage`, `Score`, `InsightEngine`, and rule-class patterns over adding parallel abstractions.
- `mu-plugin/wp-flame-early-hooks.php`: early lifecycle capture copied into `wp-content/mu-plugins/` on activation. Keep its version and behavior in sync with the main plugin.
- `assets/js/` and `assets/css/`: vanilla admin UI assets. Do not introduce a frontend framework unless explicitly requested.
- `tests/Unit` and `tests/Integration`: PHPUnit coverage. Unit tests run without WordPress loaded through stubs in `tests/bootstrap.php`; integration tests require the WordPress test suite.
- `readme.txt`, `README.md`, `CHANGELOG.md`: WordPress.org/user-facing metadata and release notes.

## Working Rules

- Check `git status --short` before editing. This repo may have user-owned dirty files, generated zips, local WordPress environment files, or dependency folders. Do not revert, delete, or restage unrelated changes.
- Keep runtime dependency-free unless the task explicitly calls for a new dependency. Existing Composer dev dependencies are for PHPUnit and test polyfills.
- Preserve WordPress security conventions: capability checks, nonces, escaping on output, sanitization on input, `wp_unslash()` for request data, prepared SQL for values, safe redirects, ABSPATH guards, and least-privilege admin access.
- Preserve privacy and cleanup behavior. Be careful with IP address capture, user metadata, SQL query text, GDPR export/erasure hooks, cron cleanup, uninstall cleanup, and trace retention.
- Keep instrumentation low overhead. Avoid request-time file I/O, network calls, expensive reflection in hot paths without caching, and self-instrumentation loops.
- Keep public plugin behavior compatible with managed hosts where the mu-plugin copy can fail; degraded mode should continue to work.

## Implementation Conventions

- PHP source should stay in the `WPFlame` namespace with strict types where already used.
- Use existing extension points where practical: `wp_flame_instrumentors`, `wp_flame_trace_meta`, `wp_flame_should_store_trace`, `wp_flame_trace_stored`, `wp_flame_insights`, and score factor filters.
- For storage changes, update schema versioning and migration behavior in `Storage`, and keep uninstall cleanup accurate.
- For settings changes, register options with sanitize callbacks and use WordPress Settings API conventions already present in `Settings`.
- For trace serialization changes, preserve backward compatibility through `Trace::fromArray()` and the trace schema version.
- For admin UI changes, keep output escaped and nonce-protected. Keep flame graph rendering DOM-safe; avoid raw `innerHTML` with trace-controlled data.
- Add focused tests near the behavior changed. Prefer unit tests for value objects, scoring, insights, source resolution, and pure instrumentation logic; use integration tests for WordPress database/admin behavior.

## Commands

Install dependencies:

```bash
composer install
```

Run the full PHPUnit suite configured in `phpunit.xml`:

```bash
composer test
```

Run PHP syntax checks across plugin and test files:

```bash
composer lint
```

Run unit tests only:

```bash
composer test:unit
```

Prepare the WordPress integration test framework when needed:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Run integration tests after the WordPress test framework is available:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration
```

Build a distributable zip only for release work:

```bash
bin/build-zip.sh [version]
```

`bin/build-zip.sh` assembles a temporary production Composer install, validates release contents, and creates `wp-flame-<version>.zip` without changing the working tree dependencies. Treat the generated zip as a release artifact, not routine development output.

Compatibility smoke testing uses the local `@wordpress/env` setup:

```bash
npm ci
npm run compat:smoke
```

This requires Docker. `.wp-env.json` intentionally uses the latest production WordPress release for plugin-stack smoke tests; the PHPUnit integration matrix covers the WordPress 6.0 lower bound separately.

## Release Notes

When changing the plugin version, keep these in sync:

- `wp-flame.php` plugin header and `WP_FLAME_VERSION`
- `mu-plugin/wp-flame-early-hooks.php` `WP_FLAME_MU_VERSION`
- `readme.txt` stable tag and tested metadata when relevant
- `CHANGELOG.md`
- Release zip name from `bin/build-zip.sh`

For docs-only changes like this file, no application test run is required. Confirm the resulting diff is limited to the intended documentation file.
