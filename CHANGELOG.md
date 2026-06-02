# Changelog

All notable changes to WP Flame are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Privacy redaction for stored request URLs and normalized SQL fingerprints by default
- Instrumentation modes: Safe, Standard, and Deep for compatibility-sensitive sites
- Span count and trace JSON size limits to bound runtime memory and storage use
- Derived `self_ms` timing in trace JSON for exclusive-time aggregate reporting
- CI workflow covering PHP unit tests, WordPress integration tests, and package builds
- Privacy toggles for logged-in user IDs and browser user-agent strings
- `composer lint` PHP syntax check for production and test files

### Changed
- User ID, IP address, and user-agent tracking now default to disabled for new installs
- Callback wrapping is limited to Deep mode instead of running by default
- Release zip builds now happen in a temporary directory and verify version metadata before packaging
- The mu-plugin loader now uses the stored plugin basename instead of assuming a `wp-flame` folder
- The WordPress test installer can fetch PHPUnit fixtures from the official `WordPress/wordpress-develop` GitHub mirror when Subversion is unavailable
- Compatibility smoke tests now fail early with explicit Docker/wp-env readiness checks
- The mu-plugin now bails before autoloading and span allocation when tracing is disabled or a sampled request misses
- IP tracking now trusts only `REMOTE_ADDR` by default; proxy headers require an explicit `wp_flame_client_ip_headers` filter
- Failed HTTP request spans now omit transport error messages unless full HTTP URL capture is explicitly enabled
- Public documentation now describes bounded overhead and current custom-table/vanilla-JS architecture without fixed overhead claims
- Sample rate, retention, span count, and trace JSON settings are now clamped to bounded operational ranges in both settings and runtime paths
- Stored request URL redaction now also renames unsafe query parameter keys, not only query values
- The mu-plugin now skips anonymous requests before autoloading when the selected audience requires logged-in/admin users and no cookie/auth signal is present
- HTTP span metadata now bounds captured URL and host strings before storing them in trace data
- HTTP instrumentation hooks now register at the practical last WordPress priority to reduce open spans when plugins short-circuit requests late
- Release builds now detect unreleased changelog bullets unless an explicit package-smoke override is set
- Admin dashboard aggregate charts now fetch a smaller bounded trace sample and skip oversized trace JSON blobs before decoding
- CI now runs PHP syntax linting, admin JavaScript syntax checks, and a zip integrity check
- WordPress integration test installer now runs with stricter shell error handling and quoted paths for CI portability
- Release zip builder now rejects malformed version arguments before creating artifact names
- Compatibility smoke tests now run against the latest production WordPress release while the integration matrix retains WordPress 6.0 lower-bound coverage
- Full SQL and GraphQL text opt-ins now still bound captured query payload sizes before trace storage
- Score factor extension output now bounds display labels, values, keys, and weights before rendering
- Flame graph rendering now handles malformed span IDs, cyclic parent references, and oversized span lists defensively
- Privacy export payloads now bound trace IDs, URLs, methods, dates, IP addresses, durations, and legacy user-agent strings
- WP-CLI trace commands now bound displayed row fields and handle missing or oversized trace IDs defensively
- Stored trace payloads now normalize top-level fields and trace metadata before JSON encoding so extension metadata cannot bloat persistence
- Stored numeric columns and span rows now clamp pathological timing, memory, source, and metadata values before persistence
- Live trace and span construction now applies the same field, metadata, and span-count bounds as legacy trace hydration
- Force-trace cookie cleanup now uses matched hardened cookie attributes, including `HttpOnly` and `SameSite=Strict`
- Flame graph rendering now caps pathological parent-chain depth to avoid unbounded recursive rendering
- Source attribution now guards plugin, mu-plugin, theme, and core directory roots before path matching

### Fixed
- `composer test` no longer fatals when the WordPress integration framework is absent
- `composer test:integration` now fails loudly when `/tmp/wordpress-tests-lib` is missing
- The WordPress test installer now rejects partial WordPress/test-suite directories instead of treating them as complete installs
- Test bootstrap now avoids unit-test WordPress stubs during integration runs and polyfills PHP 8 string helpers for the PHP 7.4 CI lane
- Release packaging now verifies required runtime files and rejects dev-only paths before creating the zip
- Uninstall cleanup now removes WP Flame tables, options, transients, and cron hooks across multisite installs
- Oversized trace trimming now removes leaf spans first so stored flame graphs do not contain orphaned child spans
- Boolean settings now submit explicit false values when checkboxes are unchecked
- Stored trace hydration now preserves aggregate query counts and query time even when detailed spans were trimmed
- Mu-plugin version drift repair now uses the same installer path as activation
- Storage now bounds indexed URL, method, IP, pagination, and retention inputs before SQL use
- Admin dashboard trace decoding now skips malformed stored span fragments
- Source attribution path checks now require directory boundaries
- DB wrapper creation now skips uninitialized typed properties on compatible `wpdb` subclasses
- WordPress integration test installer now tolerates an already-created test database
- Object-cache metadata capture now avoids non-public cache drop-in properties
- Insight rules now tolerate malformed stored HTTP URLs
- Boolean settings now sanitize posted `"0"` values as false, so admins can reliably turn privacy-sensitive capture options back off
- Runtime boolean option reads now treat common string false values such as `"false"` and `"off"` as disabled instead of relying on PHP truthiness
- DB and GraphQL SQL instrumentation now tolerate non-string query values without failing before WordPress can handle them
- WP-CLI pruning and aggregate storage queries now share the same retention bounds as the admin settings
- Admin-bar force tracing now requires a valid per-user nonce instead of accepting any non-empty cookie value
- Deep-mode callback wrapping now skips by-reference callbacks and non-callable hook entries to reduce compatibility risk with complex plugin stacks
- Stored trace and span hydration now tolerates non-scalar legacy JSON fields without PHP warnings in the admin UI
- HTTP instrumentation now normalizes malformed filter inputs without adding TypeErrors or warnings
- The DB wrapper now records defensive metadata for malformed query values while delegating the original value to `wpdb::query()`
- Collector request starts now clear any previous open or completed spans before recording a new request
- Safe mode now skips GraphQL-specific DB capture so the documented lifecycle-plus-HTTP compatibility mode remains low risk
- Compatibility smoke tests now assert mode behavior, including no DB spans in Safe mode and DB/callback spans in deeper modes
- Release zips now omit Composer metadata after generating the production autoloader, keeping the distributable package runtime-only
- Main plugin loading now always includes the bundled autoloader when present, even if the mu-plugin already loaded one WP Flame class
- Network-active multisite installs now provision WP Flame tables, defaults, and cron cleanup for newly created sites
- Early mu-plugin request gating now normalizes malformed option values without PHP conversion warnings before the main autoloader is loaded
- Admin DB-layer notices now respect Safe mode and tolerate malformed `$wpdb` globals without fatal errors
- Deep-mode callback wrapping now skips callbacks that return by reference because generic wrappers cannot preserve reference-return semantics
- Request URL redaction now bounds query-string bytes and parameter count before parsing to reduce memory use on pathological URLs
- Admin trace-list filters now ignore malformed non-scalar request values without PHP warnings
- Storage trace-list filters now normalize scalar values and ignore malformed filter values before preparing SQL
- Trace creation now normalizes malformed request URI and method server values before redaction
- GraphQL endpoint detection now normalizes malformed request URI and operation values before use
- Shutdown now stops collection before storage-decision filters and only shows force-trace notices for traces allowed to store
- Request-type detection and user-agent capture now normalize malformed server globals before use
- Admin trace detail views and request handlers now bound legacy route, user, cache, IP, user-agent, nonce, and trace-ID strings before rendering or lookup
- Runtime integer bounds, including early mu-plugin sampling bounds, now reject non-finite numeric values instead of letting casts collapse protective limits
- Stored trace reads now refuse oversized legacy JSON blobs before decoding to keep admin detail views bounded
- Dashboard abuse insights now ignore malformed aggregate rows and non-scalar pagination parameters without PHP warnings
- Trace insight rules now normalize malformed span/cache metadata before parsing or formatting
- Flame graph cache summary now normalizes malformed cache metadata before rendering
- Admin dashboard decoded-span aggregations now ignore malformed callback and timing fields without warnings or invalid array keys
- Shutdown now stops collection before score filters run, preventing scoring extensions from adding self-instrumentation overhead after the trace snapshot
- Deep-mode callback wrapping now tolerates malformed hook `accepted_args` values without PHP conversion warnings
- Score factor filters now reject non-finite numeric values before computing or displaying factor breakdowns
- WordPress integration test installer now refreshes existing `wp-tests-config.php` files so repeated runs do not keep stale DB or core paths
- Trace and span value objects now clamp negative timing and memory values so malformed stored JSON cannot distort reports
- Storage now clamps persisted scores to 0-100 and normalizes time-breakdown reporting so PHP time cannot go negative
- Lifecycle phase transitions now tolerate missing or malformed current-phase globals instead of emitting warnings or type errors
- Source attribution now skips malformed backtrace frame paths before resolving plugin/theme ownership
- Numeric settings sanitizers now ignore malformed non-scalar values without PHP conversion warnings
- README.md now reflects the current PSR-4/custom-table/vanilla-JS architecture instead of the original planning outline
- Admin destructive-action confirmations now use the enqueued admin script instead of inline `onclick` handlers
- Admin trace ID request handling now ignores malformed non-scalar values before sanitization
- Settings purge nonce handling now ignores malformed non-scalar POST values before nonce verification
- Trusted IP header resolution now skips malformed non-scalar server values without PHP conversion warnings
- WPGraphQL resolver hooks now ignore malformed type or field values without disrupting GraphQL execution
- Privacy exports now include captured user-agent data for a user's traces when that privacy-sensitive setting is enabled
- Settings fields now render bounded defaults for malformed option values instead of casting arrays, objects, or non-finite numbers
- Trace detail request-context rendering now normalizes malformed stored user, IP, and user-agent metadata
- Bootstrap schema checks and budget notices now normalize malformed option values before comparing or building admin URLs
- Stored trace hydration, admin filters, and aggregate helpers now reject non-finite duration values before reporting or preparing SQL
- Admin trace list and WP-CLI trace output now normalize malformed storage rows before rendering
- Trace detail route comparison and cache summary rendering now bound malformed aggregate values before display
- Storage aggregate helpers now clamp malformed database aggregate fields before returning dashboard metrics
- Trace loading now normalizes row-level user, IP, score, and timestamp metadata before attaching it to trace details
- Storage count and purge result handling now bounds unexpected database driver return values
- Object-cache counters and admin notice transients now normalize malformed cache/drop-in values before display or incrementing
- Privacy export and erasure hooks now ignore malformed user IDs and bound delete counts before reporting results
- User-trace deletion now bounds database delete counts before returning GDPR erasure totals
- Uninstall cleanup now guards mu-plugin deletion when the mu-plugin directory constant is unavailable
- Request sampling now normalizes precomputed sample rolls before applying the cached instrumentation decision
- Low-cache-hit insights now avoid integer overflow while preserving accurate ratios for pathological cache counters
- The early mu-plugin now requires a logged-in cookie or non-cookie auth signal before a force-trace cookie can bypass early anonymous/sampling guards
- WPGraphQL DB timing capture now ignores malformed timing filter values instead of casting them directly
- WPGraphQL operation and resolver labels are now bounded before being stored in span names, metadata, or resolver-stack keys
- WPGraphQL hook activation is now idempotent to avoid duplicate resolver and operation spans if activation is triggered more than once
- Dashboard slow-callback aggregation now bounds callback labels before using them as array keys
- Admin insight normalization now caps insight count and message length before rendering extension or trace-derived output
- Deep-mode callback wrapping now skips malformed hook-table buckets and bounds hook/callback labels before creating spans
- Request total duration is now frozen before trace-meta filters, scoring, and persistence run so extension work cannot inflate reported request time
- Trace serialization now preserves duplicate or malformed span IDs instead of dropping spans before storage or rendering
- Settings select sanitizers now normalize malformed or stringable values consistently before applying defaults
- Multisite activation, deactivation, and mu-plugin retention checks now always restore blog context while scanning sites, and tolerate keyed or list-shaped active plugin options
- Uninstall multisite cleanup now restores blog context with `finally`, and the early mu-plugin uses the same keyed/list-shaped plugin-option detection for site and network activation checks
- HTTP-during-early-phase insights now guard cyclic parent references in malformed stored traces instead of looping during analysis
- Dashboard pagination-abuse analysis now bounds input rows, endpoint groups, and per-IP endpoint detail to keep report generation memory predictable
- Duplicate-query insights now bound legacy SQL metadata, grouping cardinality, and retained affected-span IDs while preserving duplicate counts
- Slow HTTP request insights now redact legacy full URL query values before rendering request targets in reports
- Slow callback insights now bound callback labels, hook labels, source labels, retained span IDs, and per-rule output count for malformed legacy traces
- Failed HTTP span metadata now bounds captured error codes and opt-in error messages before storing them on the in-memory trace
- Admin-bar force-trace cookies now expire after five minutes instead of lingering as browser-session cookies
- Admin list/dashboard rendering now bounds request filters, legacy row strings, and user labels before building admin links or output
- Legacy trace hydration now bounds top-level trace strings, span strings, metadata, and span count before detail rendering

## [1.2.0] - 2026-03-18

### Added
- Request-type-aware phase maps for frontend, REST, admin, AJAX, CLI, and cron requests
- Third callback wrapping pass at `template_redirect`/`admin_init`/`rest_api_init` for deeper coverage
- Extensibility: `wp_flame_instrumentors` filter with Instrumentor interface
- Extensibility: `InsightRule` interface with `InsightEngine` for modular insight rules
- Indexed `url_path` column for faster URL-based queries
- N+1 query detection via normalized `query_hash` fingerprinting
- Core extension hooks: `trace_meta`, `should_store_trace`, `trace_stored`, `insights`
- `Config` class for centralized settings with per-request overrides
- GDPR data export and erasure hooks
- `wp_flame_score_factors` filter for site-profile scoring overrides
- Schema version tracking with `maybe_upgrade()` migration runner
- JSON schema version (`v:1`) in trace serialization
- mu-plugin version drift detection and auto-update

### Changed
- Dashboard analytics moved to SQL with denormalized summary columns for performance
- Admin split into `ListView` and `FlameGraphView` classes
- Shared `SourceResolver` extracted from DB, Http, and GraphQL instrumentors
- Shared WHERE clause builder extracted from list/count trace queries

### Fixed
- Handle `WP_Error` HTTP responses via `http_api_debug` fallback
- Stop storing IP/user_id in trace meta; read from DB columns instead
- Check `wp_json_encode` and `wpdb->insert` return values in `save_trace`
- Clean up transients and cron schedule on uninstall
- Prevent wrapping mu-plugin phase transition callbacks

## [1.1.1] - 2026-03-17

### Added
- Clear button next to Filter on the trace list to reset all filters

### Changed
- Color legend moved below score breakdown, just above the flame graph

### Fixed
- Route comparison banner text running into route stats (added spacing)

## [1.1.0] - 2026-03-17

### Added
- Request identity tracking: user ID, IP address, and user agent captured on every trace
- User and IP columns in trace list (clickable to filter)
- Grade, user, and IP filter dropdowns on the trace list
- Request context section on flame graph view (User, IP, User Agent)
- Top Users by Load and Top IPs by Requests dashboard rankings
- 3 abuse detection insight rules (high request rate, resource hog, API pagination scraping)
- IP tracking toggle in settings for GDPR compliance
- Per-route comparison banner on flame graph (now displayed above the stats bar)
- Response time histogram expanded to 7 buckets (split 500ms+ into 500-1000, 1000-1500, 1500+)
- Score column is now sortable in the trace list (click to sort by performance score)
- Full GraphQL instrumentation: WPGraphQL native hooks for resolver timing, DB queries via log_query_custom_data, operation-level spans
- Three-tier detection: full WPGraphQL instrumentation (Tier 1), lightweight fallback (Tier 2), unchanged normal requests (Tier 3)
- Stellate compatibility: zero conflict with GraphQL CDN plugins (no $wpdb replacement or callback wrapping for GraphQL)
- GraphQL type filter in trace list
- Limited instrumentation badge for non-WPGraphQL GraphQL traces

### Fixed
- Duplicate database query insights now consolidated when many distinct queries share the same count and type (e.g. "5 duplicate SELECT queries detected (×17 distinct queries)")
- API scraping insights consolidated to one card per IP instead of one per endpoint
- Histogram label wrapping for the 1000-1500ms bucket

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
- 150 unit tests with 396 assertions
- Test coverage for: Span, Trace, Collector, Storage (schema), CallbackResolver, CallbackWrapper, Http, Insights, Score
- Dual test suites: `unit` (pure PHP) and `integration` (WordPress test framework)
- i18n function stubs in test bootstrap
- `Collector::reset()` for test isolation across singleton instances
