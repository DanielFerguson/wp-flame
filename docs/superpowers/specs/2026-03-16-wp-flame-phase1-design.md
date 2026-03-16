# WP Flame Phase 1 Design Spec

## Overview

Phase 1 delivers a working WordPress plugin that captures request timing data and displays it as an interactive flame graph in wp-admin. It instruments lifecycle phases, database queries, and renders an SVG-based flame graph — genuinely useful on day one.

## Constraints

- PHP 7.4+ / WordPress 6.0+
- Vanilla JS (no framework), SVG-based flame graph
- Composer with PSR-4 autoloading, PHPUnit dev dependency
- Full TDD — all PHP classes test-driven
- Zero runtime third-party dependencies
- Target <5ms overhead per request with instrumentation active

## Project Structure

```
wp-flame/
├── composer.json                     # PSR-4 autoload (WPFlame\), dev deps
├── phpunit.xml                       # Test configuration
├── wp-flame.php                      # Main plugin file (bootstrap)
├── uninstall.php                     # Clean removal
├── src/
│   ├── Collector.php                 # Span stack management (singleton)
│   ├── Span.php                      # Span value object (immutable)
│   ├── Trace.php                     # Trace value object
│   ├── Storage.php                   # Persistence (custom table)
│   ├── DB.php                        # DB query instrumentation (extends wpdb)
│   └── Admin.php                     # Admin pages, list table, flame graph
├── mu-plugin/
│   └── wp-flame-early-hooks.php      # Copied to mu-plugins/ on activation
├── assets/
│   ├── js/
│   │   ├── flame-graph.js            # SVG flame graph renderer
│   │   └── admin.js                  # List page interactions
│   └── css/
│       └── admin.css                 # Admin styles
└── tests/
    ├── bootstrap.php                 # WordPress test framework bootstrap
    ├── Unit/
    │   ├── CollectorTest.php
    │   ├── SpanTest.php
    │   ├── TraceTest.php
    │   ├── StorageTest.php
    │   └── DBTest.php
    └── Integration/
        └── AdminTest.php
```

## Component Designs

### Span (value object, immutable)

Constructed only at `end_span()` time — never exists in an incomplete state.

**Fields:**
- `id` — string, auto-generated UUID
- `parent_id` — string|null
- `name` — string (e.g. "WooCommerce init")
- `type` — string, one of class constants: `TYPE_CORE`, `TYPE_PLUGIN`, `TYPE_THEME`, `TYPE_DB`, `TYPE_HTTP`, `TYPE_PHP`
- `source` — string (e.g. "woocommerce/woocommerce.php")
- `start_ms` — float, relative to request start
- `duration_ms` — float
- `meta` — array, flexible (query text, memory delta, etc.)

**Methods:**
- `toArray(): array` — serialization for JSON storage
- `static fromArray(array $data): self` — deserialization

**Type constants (PHP 7.4 compatible, no native enums):**
```php
public const TYPE_CORE   = 'core';
public const TYPE_PLUGIN = 'plugin';
public const TYPE_THEME  = 'theme';
public const TYPE_DB     = 'db';
public const TYPE_HTTP   = 'http';
public const TYPE_PHP    = 'php';
```

### Trace (value object)

Represents one complete request with all its spans.

**Fields:**
- `id` — string, UUID v4
- `url` — string, request URI
- `method` — string, HTTP method
- `timestamp` — string, ISO 8601 UTC
- `total_ms` — float
- `peak_memory` — int, bytes from `memory_get_peak_usage(true)`
- `php_version` — string
- `wp_version` — string
- `query_count` — int
- `total_query_ms` — float
- `spans` — array of Span objects

**Methods:**
- `toArray(): array`
- `static fromArray(array $data): self`

### Collector (singleton)

The runtime engine. Manages an internal span stack during the request. No I/O until shutdown.

**Public API:**
- `static instance(): self` — singleton accessor
- `start_request(float $microtime)` — records the zero-point timestamp
- `start_span(string $name, string $type, string $source, array $meta = []): string` — pushes lightweight entry onto internal stack, auto-assigns parent from stack top, returns span ID
- `end_span(?string $span_id = null): void` — pops stack top, calculates duration, creates immutable Span, stores in flat array. If `$span_id` provided, validates it matches stack top (logs warning if not).
- `get_trace(): Trace` — builds Trace from completed spans + request metadata
- `is_initialized(): bool` — for graceful self-bootstrapping check

**Internals:**
- `$request_start` — float, microtime from `start_request()`
- `$span_stack` — array of lightweight associative arrays (id, name, type, source, start_ms, meta, parent_id). NOT Span objects.
- `$spans` — flat array of completed Span objects
- `$source_cache` — static array mapping file path → source attribution result

**Source attribution:**
- `get_source_from_file(string $file_path): array` — returns `['type' => 'plugin', 'source' => 'woocommerce/woocommerce.php']`
- Matches against `WP_PLUGIN_DIR`, `get_template_directory()`, `WPMU_PLUGIN_DIR`, `ABSPATH`
- Results cached in `$source_cache`

### Storage (instance class)

Persistence layer for traces. Receives `$wpdb` via constructor for testability.

**Constructor:** `__construct(\wpdb $wpdb)`

**Table: `{prefix}flame_traces`**

| Column | Type | Index |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | PRIMARY |
| `trace_id` | CHAR(36) | UNIQUE |
| `url` | VARCHAR(2048) | — |
| `method` | VARCHAR(10) | — |
| `total_ms` | FLOAT | — |
| `query_count` | INT UNSIGNED | — |
| `peak_memory` | BIGINT UNSIGNED | — |
| `created_at` | DATETIME | INDEX |
| `trace_data` | LONGTEXT | — |

**Methods:**
- `create_table(): void` — uses `dbDelta()`, called on activation
- `save_trace(Trace $trace): void` — JSON-serializes trace, inserts row
- `get_trace(string $trace_id): ?Trace` — fetches single, deserializes JSON back into Trace/Span objects
- `list_traces(array $args): array` — paginated list with filters (url LIKE, min duration, date range). Returns lightweight rows without `trace_data` column.
- `count_traces(array $filters): int` — total count for pagination
- `delete_trace(string $trace_id): void` — single delete
- `prune_old(int $days): void` — `DELETE WHERE created_at < NOW() - INTERVAL $days DAY`

All queries use `$wpdb->prepare()`.

**Size estimate:** ~15-30KB per trace (100 spans). 1000 traces = 15-30MB. Daily cron prune keeps this bounded.

### DB (extends wpdb)

Database query instrumentation. Wraps `$wpdb->query()` with span timing.

**Factory:** `static from_wpdb(\wpdb $original, Collector $collector): self`
- Creates new instance without connecting
- Copies ALL properties from original via `get_object_vars($original)` — future-proof, includes connection handle
- Stores reference to Collector

**Conflict detection:** Before replacing `$wpdb`, check `get_class($wpdb) !== 'wpdb'`. If another plugin already replaced it, skip and show admin notice.

**Overridden method:**
```php
public function query($query) {
    $span_id = $this->collector->start_span(
        $this->extract_query_type($query),  // SELECT, INSERT, UPDATE, DELETE
        Span::TYPE_DB,
        $this->get_caller_source(),
        ['query' => substr($query, 0, 200)]
    );
    $result = parent::query($query);
    $this->collector->end_span($span_id);
    return $result;
}
```

**Source attribution:** `get_caller_source()` uses `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)` to find the first file outside `wp-includes/` and the wp-flame plugin directory. Result passed through `Collector::get_source_from_file()` and cached by caller file path.

**Query text:** First 200 characters stored by default. `wp_flame_full_query_text` option (default false) controls full capture.

### Admin (instance class)

Admin UI: trace list and flame graph view. Server-rendered, no REST API.

**Trace list (`Tools → WP Flame`):**
- Uses `WP_List_Table` base class
- Columns: URL, Method, Duration (ms), Queries, Peak Memory, Date
- Sortable by duration, query count, date
- Filterable: URL search box, minimum duration input
- Pagination via `?paged=N`
- Row actions: View (links to flame graph), Delete (POST form with nonce)
- Color-coding for slow requests (>500ms)

**Flame graph view (`tools.php?page=wp-flame&trace_id={uuid}`):**
- PHP renders page shell + summary stats bar
- Trace data passed to JS via `wp_localize_script('wp-flame-graph', 'wpFlameTrace', $trace->toArray())`
- Summary stats: Total time, DB time, Query count, Peak memory

**Admin notices:**
- mu-plugin missing: "WP Flame is running in limited mode — plugin load timing is unavailable."
- `$wpdb` conflict: "DB query instrumentation is disabled — another plugin is modifying the database layer."

## Flame Graph Renderer (vanilla JS, SVG)

**Rendering:**
- X-axis: time (0 to total request duration in ms)
- Y-axis: span depth (nesting level)
- Each span is an SVG `<rect>` with width proportional to duration
- Minimum width of 2px so tiny spans remain visible/clickable
- Color by type: core (grey), plugin (blue), theme (green), db (orange), http (red), php (purple)
- Text label inside rect (truncated to fit, hidden if too narrow)

**Interactions:**
- Hover: tooltip showing span name, duration, source, % of total
- Click: zoom into span's subtree (rescale X-axis to span's time range)
- Breadcrumb bar above SVG for navigating back up after zoom
- Reset button to return to full view

**Data flow:**
- Reads `window.wpFlameTrace` (set by `wp_localize_script`)
- Builds span tree from flat array using `parent_id` references
- Calculates x position and width from `start_ms` and `duration_ms` relative to the visible time range
- Row height fixed at ~20px per level

## mu-plugin Design

**File: `wp-flame-early-hooks.php`** — copied to `wp-content/mu-plugins/` on activation.

```php
$wp_flame_request_start = microtime(true);

$wp_flame_autoload = WP_PLUGIN_DIR . '/wp-flame/vendor/autoload.php';
if ( ! file_exists( $wp_flame_autoload ) ) {
    return; // Main plugin missing — graceful no-op
}
require_once $wp_flame_autoload;

$collector = WPFlame\Collector::instance();
$collector->start_request( $wp_flame_request_start );
$collector->start_span( 'Bootstrap', WPFlame\Span::TYPE_CORE, 'wordpress' );
```

**Lifecycle phase transitions** registered by the mu-plugin:

| Hook | Priority | Action |
|------|----------|--------|
| `muplugins_loaded` | 0 | Close Bootstrap, open Plugin Load |
| `plugins_loaded` | 0 | Close Plugin Load, open Theme Setup |
| `after_setup_theme` | 0 | Close Theme Setup, open Init |
| `init` | 0 | Close Init, open Routing |
| `wp` | 0 | Close Routing, open Main Query |
| `template_redirect` | 0 | Close Main Query, open Render |

Render phase is closed by the shutdown handler.

**Degraded mode (no mu-plugin):** Main plugin initializes Collector at `plugins_loaded` priority 0 with `microtime(true)` as start time. Registers phase transitions from Theme Setup onward. Bootstrap and Plugin Load phases are not captured. Admin notice displayed.

## Plugin Bootstrap & Lifecycle

### On load (`plugins_loaded`, priority 0)

1. Require Composer autoloader
2. Check `Collector::is_initialized()` — if false, start in degraded mode
3. Instantiate Storage with global `$wpdb`
4. Replace `$wpdb` with DB instance (if standard class, skip if already subclassed)
5. Register remaining lifecycle phase hooks (if in degraded mode)
6. Register shutdown handler (priority 9999)

### Shutdown handler (priority 9999)

1. Close any open spans (safety net)
2. Check `current_user_can('manage_options')` — if false, discard and return
3. Check `wp_flame_enabled` option — if false, return
4. Build Trace from Collector
5. Pass to `Storage::save_trace()`

### Activation hook

1. `Storage::create_table()` via `dbDelta()`
2. Copy `mu-plugin/wp-flame-early-hooks.php` to `wp-content/mu-plugins/`
3. Schedule daily cron `wp_flame_prune_traces`
4. Set default options: `wp_flame_retention_days` = 7, `wp_flame_enabled` = true

### Deactivation hook

1. Remove mu-plugin from `wp-content/mu-plugins/`
2. Clear cron schedule
3. Leave data and options intact

### Uninstall (uninstall.php)

1. Drop `{prefix}flame_traces` table
2. Delete all `wp_flame_*` options
3. Remove mu-plugin file

## Settings (Phase 1 — minimal)

Stored in `wp_options`, no settings page UI. Defaults set on activation:

- `wp_flame_enabled` — bool, default true
- `wp_flame_retention_days` — int, default 7
- `wp_flame_full_query_text` — bool, default false

## Dropped from Original Phase 1 Spec

- **P1.3 (Individual plugin load timing)** — deferred to Phase 2. The WP_Hook wrapper provides per-callback granularity which is more useful than per-file load timing. Phase 1 captures the aggregate Plugin Load phase duration.
- **P1.6 (REST API)** — unnecessary for Phase 1. Server-side rendering with `wp_localize_script()` for flame graph data. REST API added in Phase 2 when client-side interactivity justifies it.

## Testing Strategy

Full TDD. Tests split into Unit (no WordPress dependency) and Integration (WordPress test framework).

**Unit tests (no WordPress):**
- `SpanTest` — construction, immutability, `toArray()`/`fromArray()` round-trip, type constants
- `TraceTest` — construction, serialization, span aggregation
- `CollectorTest` — `start_span`/`end_span` nesting, parent assignment, `get_trace()` assembly, `is_initialized()`, unclosed span handling, source cache
- `StorageTest` — requires WordPress test framework for `$wpdb` (technically integration, but tests the class in isolation)
- `DBTest` — requires WordPress test framework for `$wpdb`

**Integration tests:**
- `AdminTest` — menu registration, page rendering, nonce verification on delete

**Test environment:**
- `wp-env` or `wordpress/env` Docker-based setup via Composer dev dependency
- `phpunit.xml` configured with WordPress test bootstrap
