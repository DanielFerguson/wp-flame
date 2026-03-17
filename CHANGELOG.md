# Changelog

All notable changes to WP Flame are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Score column is now sortable in the trace list (click to sort by performance score)

### Changed
- Version updated to 1.0.0
- Author set to Chepstowe Consulting (https://www.chepstowe.consulting)

## [1.0.0] - 2026-03-17

### Added

#### Core Instrumentation
- Span-based tracing engine with singleton Collector managing an in-memory span stack
- Immutable Span and Trace value objects with JSON serialization
- mu-plugin (`wp-flame-early-hooks.php`) for capturing timing before any other plugin loads
- Lifecycle phase instrumentation: Bootstrap, Plugin Load, Theme Setup, Init, Routing, Main Query, Render
- Graceful degraded mode when mu-plugin cannot be installed (managed hosting)

#### Database Instrumentation
- wpdb extension (`DB` class) wrapping every SQL query with span timing
- Source attribution via backtrace — identifies which plugin/theme triggered each query
- Query text capture (first 200 chars by default, full text opt-in)
- Conflict detection: skips replacement if another plugin already extends wpdb

#### Per-Callback Instrumentation
- `CallbackWrapper` wraps individual WordPress hook callbacks with span timing
- `CallbackResolver` resolves human-readable callback names via reflection (cached)
- Support for all callback types: functions, methods, closures, invocable objects, string statics
- `end_span_filtered()` on Collector discards sub-threshold spans while preserving parent/child trees
- Two wrapping passes at `plugins_loaded` and `init` (priority 1) for maximum coverage
- Self-instrumentation prevention via source file detection
- Exception safety with `try/finally` in CallbackWrapper

#### HTTP Request Instrumentation
- `Http` class hooks `pre_http_request` and `http_response`
- Captures URL, HTTP method, response status code, and duration
- Source attribution via backtrace
- `add_span_meta()` on Collector for post-creation metadata updates

#### Flame Graph
- Interactive SVG flame graph renderer (vanilla JS, no dependencies)
- Color-coded spans: Core (steel blue), Plugins (purple), Theme (green), Database (red), HTTP (amber)
- Click-to-zoom into span subtrees with breadcrumb navigation
- Hover tooltips with span name, duration, source, and percentage of total
- "Full request" overview bar at the top
- Time axis with ms labels and vertical guide lines
- Color legend for span types
- Responsive: re-renders on window resize
- DOMParser-based SVG construction (XSS-safe)
- Fixed-position tooltips with viewport overflow detection

#### Admin UI
- Trace list under Tools > WP Flame with custom flame chart icon
- Sortable columns: Duration, Queries, Memory, Date (click to toggle ASC/DESC)
- Filters: URL search, HTTP method dropdown, type filter (All/Cron/AJAX/REST API), min duration
- Pagination with proper WordPress admin styling
- Flame graph detail view with summary stat cards (Total Time, DB Queries, Peak Memory, Cache, Score)
- Score breakdown section with per-factor progress bars
- Delete traces with per-trace nonce verification
- Settings link in the toolbar
- Admin notices for mu-plugin status and wpdb conflicts

#### Dashboard Analytics
- Aggregate stat cards: Avg Load Time (with trend vs previous period), Traces count, Slowest Page, Avg Queries, Avg Score
- Slowest Pages ranking (top 5, grouped by URL path, with hit count)
- Slowest Callbacks ranking (top 5, extracted from recent trace data)
- Time Breakdown bar showing Core/Plugin/Theme/DB/HTTP time distribution
- Response Time Distribution histogram with clickable bars that filter the trace table
- Trend indicator: green/red arrows for performance changes vs previous period

#### Performance Score
- 0-100 score per trace with 5-factor weighted algorithm
- Factors: Response Time (35%), External HTTP (20%), DB Query Count (15%), DB Time Ratio (15%), Slow Callbacks (15%)
- Grade mapping: A (90+), B (80+), C (70+), D (60+), F (<60) with color coding
- Score stored in database for efficient querying and sorting
- Displayed on flame graph view (with factor breakdown), trace list (colored badges), and dashboard (average)
- Edge case handling: auto-100 for DB ratio when total < 100ms, graceful handling of missing data

#### Insights Panel
- 7 automatic performance analysis rules:
  - Slow HTTP requests (>100ms)
  - Duplicate database queries
  - High query count (>50 or >100)
  - Slow callbacks (>50ms)
  - HTTP requests during early lifecycle phases (Init, Plugin Load, Theme Setup)
  - No persistent object cache detected (when misses > 20)
  - Low cache hit ratio (<80%)
- Warning and info severity levels with styled cards

#### Object Cache Integration
- `meta` array on Trace for extensible request-level metadata
- Cache hit/miss/backend stats read from `$wp_object_cache` at shutdown
- Cache stat card on flame graph view showing hit ratio and backend type
- `property_exists()` guard for compatibility with custom cache implementations

#### Settings Page
- Full Settings UI under Settings > WP Flame
- 8 configurable options:
  - Enable/disable tracing
  - Trace audience (admins only / logged-in / everyone)
  - Sampling rate (1 in N requests)
  - Data retention (days)
  - Minimum callback duration threshold (ms)
  - Full SQL query text capture toggle
  - Performance budget: max page load time (ms)
  - Performance budget: max query count
- Storage stats display (trace count, total size in MB)
- Purge All Traces button with nonce verification
- All settings validated with `sanitize_callback`

#### Performance Budget Alerts
- Configurable thresholds for max response time and max query count
- Violation tracking via transients (batched, 1-hour window)
- Admin notice: "X requests exceeded your performance budget. [View slow traces]"
- Links directly to filtered trace list

#### Cron & Background Job Support
- Cron requests (`DOING_CRON`) bypass audience and sampling checks — always traced
- Type filter in trace list: All / Cron / AJAX / REST API

#### Template Detection
- `template_include` filter captures which template file WordPress selected
- Template filename stored in the Render phase span metadata

#### Admin Bar Integration
- "Trace This Page" button on the frontend admin bar (admins only)
- CSP-safe: separate JS file with `addEventListener` (no inline handlers)
- Cookie-based trigger with `SameSite=Strict` and conditional `Secure` flag
- Force-trace authenticated and gated behind `manage_options` capability
- Admin notice with direct link to the captured trace

#### WP-CLI Commands
- `wp flame list` — list recent traces with `--limit`, `--url`, `--format` options
- `wp flame show <trace_id>` — display full trace as JSON
- `wp flame prune` — prune old traces with `--days`, `--all`, `--yes` options

#### Storage & Data Management
- Custom database table (`{prefix}flame_traces`) with indexed columns
- Auto-creation via `dbDelta()` on activation
- Daily cron job for automatic pruning based on retention setting
- Clean uninstall: drops table, deletes all options, removes mu-plugin

#### WordPress.org Compliance
- ABSPATH guards on all PHP files
- `sanitize_callback` on all registered settings
- `wp_unslash()` on all `$_GET`/`$_POST`/`$_COOKIE` access
- `check_admin_referer()` for form submissions
- `esc_html__()`/`esc_attr__()` on all user-facing strings (62+ i18n strings)
- Per-trace nonces on delete actions
- `$wpdb->prepare()` for all dynamic SQL
- Output escaping: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- `UTC_TIMESTAMP()` for consistent timezone handling
- Transient-based notices (no reflected URL parameter injection)

#### Testing
- 115 unit tests with 294 assertions
- Test coverage for: Span, Trace, Collector, Storage (schema), CallbackResolver, CallbackWrapper, Http, Insights, Score
- Dual test suites: `unit` (pure PHP) and `integration` (WordPress test framework)
- i18n function stubs in test bootstrap
- `Collector::reset()` for test isolation across singleton instances
