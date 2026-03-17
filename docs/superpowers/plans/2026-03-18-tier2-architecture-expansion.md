# Tier 2: Architecture Expansion — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 9 Tier 2 architectural changes that enable site profiles, N+1 detection, and the plugin knowledge base — the first major roadmap features.

**Architecture:** Introduces clean abstractions (Instrumentor, InsightRule, Config) across the codebase, splits the monolithic Admin class, moves analytics to SQL, and adds N+1 query fingerprinting. Each task produces a working, testable commit.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, PHPUnit (existing 162-test suite)

**Spec:** `docs/superpowers/specs/2026-03-17-architecture-review-design.md` (Tier 2 section)

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `src/Storage.php` | WHERE builder extraction, url_path column, response time distribution consolidation, new aggregate methods |
| Modify | `src/DB.php` | Query normalization, Instrumentor interface |
| Modify | `src/Http.php` | Instrumentor interface |
| Modify | `src/GraphQL.php` | Instrumentor interface |
| Modify | `src/Insights.php` | Refactor to InsightRule-based engine |
| Modify | `src/Admin.php` | Split into router + view classes |
| Modify | `wp-flame.php` | Instrumentor wiring, third wrapping pass, phase map branching |
| Modify | `mu-plugin/wp-flame-early-hooks.php` | Reduce to early-only phases |
| Create | `src/Instrumentor.php` | Interface for instrumentation providers |
| Create | `src/DbInstrumentor.php` | Thin wrapper making DB implement Instrumentor |
| Create | `src/CallbackInstrumentor.php` | Callback wrapping as Instrumentor |
| Create | `src/Insight.php` | Insight value object |
| Create | `src/InsightRule.php` | Interface for insight rules |
| Create | `src/InsightEngine.php` | Rule runner with filter hook |
| Create | `src/Rules/SlowHttpRequests.php` | Extracted rule |
| Create | `src/Rules/DuplicateDbQueries.php` | Extracted rule |
| Create | `src/Rules/HighQueryCount.php` | Extracted rule |
| Create | `src/Rules/SlowCallbacks.php` | Extracted rule |
| Create | `src/Rules/HttpDuringEarlyPhases.php` | Extracted rule |
| Create | `src/Rules/NoPersistentCache.php` | Extracted rule |
| Create | `src/Rules/LowCacheHitRatio.php` | Extracted rule |
| Create | `src/Admin/ListView.php` | Trace list with filters |
| ~~Create~~ | ~~`src/Admin/DashboardView.php`~~ | ~~Deferred: dashboard is part of list view page, splitting adds complexity without benefit~~ |
| Create | `src/Admin/FlameGraphView.php` | Flame graph, insights, route comparison |

---

## Execution Order

```
Task 1  (2.9 — WHERE clause builder)        ← standalone, smallest
Task 2  (2.5 — url_path column)             ← standalone, uses schema versioning
Task 3  (2.8 — query normalization)          ← standalone
Task 4  (2.1 — Instrumentor interface)       ← prerequisite for Task 5
Task 5  (2.6 — third wrapping pass)          ← depends on Task 4
Task 6  (2.7 — phase map branching)          ← standalone
Task 7  (2.3 — InsightRule interface)        ← standalone
Task 8  (2.2 — Admin split)                  ← prerequisite for Task 9
Task 9  (2.4 — dashboard analytics to SQL)   ← depends on Task 8
```

---

## Critical Implementer Warnings

These issues were identified during adversarial review. Every implementer MUST read these before starting their task.

### W1: DB/GraphQL Mutual Exclusion (Task 4)
The instrumentor loop runs ALL instrumentors through `is_applicable()`/`register()` independently. But `DbInstrumentor` and `GraphQL` are mutually exclusive — if both activate, you get double DB instrumentation (wpdb replacement AND `log_query_custom_data` hook). The loop MUST have an explicit guard:

```php
$graphql_applicable = false;
foreach ( $instrumentors as $inst ) {
    if ( ! ( $inst instanceof Instrumentor ) ) {
        continue; // Type safety for third-party filter additions
    }
    if ( $inst instanceof WPFlame\GraphQL && $inst->is_applicable() ) {
        $graphql_applicable = true;
        $inst->register( $collector );
    } elseif ( $inst instanceof WPFlame\DbInstrumentor && $graphql_applicable ) {
        // Skip DB — GraphQL handles queries via log_query_custom_data
        continue;
    } elseif ( $inst->is_applicable() ) {
        $inst->register( $collector );
    }
}
```

The `$GLOBALS['wp_flame_skip_callback_wrapping']` flag must also be set when GraphQL is applicable, or callbacks will be double-instrumented alongside GraphQL resolver spans.

### W2: Http/GraphQL Constructor Changes Break ALL Tests (Task 4)
Changing Http and GraphQL constructors to no-arg breaks `HttpTest.php` (7 tests) and `GraphQLTest.php` (15+ tests). ALL tests that call `new Http($collector)` or `new GraphQL($collector, ...)` must be updated to `$obj = new Http(); $obj->register($collector);`. This is a COMPLETE test suite failure if missed.

### W3: REST_REQUEST Is NOT Defined at init (Task 6)
`REST_REQUEST` is defined at `rest_api_init`, NOT `init`. REST detection must use URL pattern matching (`/wp-json/` in REQUEST_URI) at `plugins_loaded`, similar to GraphQL detection. Do NOT rely on the `REST_REQUEST` constant for early detection.

### W4: strtok() Is Stateful (Task 2)
Do NOT use `strtok()` for URL path extraction. Use `explode('?', $url, 2)[0]` instead. `strtok()` sets an internal PHP pointer that affects subsequent `strtok()` calls elsewhere in the request.

### W5: 50-Blob Decode Cannot Be Fully Eliminated (Task 9)
Task 9 adds `db_time_ms` and `http_time_ms` columns but NOT `plugin_time_ms`/`theme_time_ms`/`core_time_ms`. The Time Breakdown Bar shows 5 types. The slowest callbacks ranking also requires span-level data. `get_recent_trace_data()` MUST be kept — only the db/http portions of the breakdown can use SQL. Attempting to remove the decode loop entirely will break the dashboard.

### W6: Rule Classes Need Helper Methods (Task 7)
`Insights.php` has private helper methods used by rules:
- `extract_query_type()` — used by `duplicate_db_queries()`
- `find_early_phase_ancestor()` logic — used by `http_during_early_phases()`

These must be copied into the respective rule classes as private methods, or extracted to a shared `InsightHelpers` utility class.

### W7: Admin.php Methods That Must STAY (Task 8)
When splitting Admin.php, these methods must remain in the router class:
- `register()`, `add_menu()`, `render_page()`, `handle_delete()` — routing
- `enqueue_assets()` — asset loading (the `wp_add_inline_script()` call moves to `FlameGraphView` but the `wp_enqueue_*` calls stay here)
- `render_notices()` — uses `global $wpdb; $wpdb instanceof DB` check

Do NOT move these to view classes.

### W8: mu-plugin Transition Gap (Task 6)
On first request after plugin update (before mu-plugin auto-updates), the OLD mu-plugin registers late phases (`init`, `wp`, `template_redirect`) AND the new `wp_flame_init()` registers request-type-specific phases. Double phase registration produces garbled spans. Guard late phase registration with a mu-plugin version check:

```php
if ( defined( 'WP_FLAME_MU_VERSION' ) && WP_FLAME_MU_VERSION === WP_FLAME_VERSION ) {
    // New mu-plugin — register request-type-specific late phases
} else {
    // Old mu-plugin or no mu-plugin — use degraded mode (existing behavior)
}
```

### W9: GraphQL query_hash Gap (Task 3)
Task 3 adds `query_hash` to DB.php spans but NOT to GraphQL.php's `register_db_hooks()` closure. Traces captured via the GraphQL path will never have `query_hash` in span meta. The `DuplicateDbQueries` rule must fall back to raw query text for these spans. Also consider adding `normalize_query` + `query_hash` to `GraphQL::register_db_hooks()` to close this gap.

---

## Task 1: Extract Shared WHERE Clause Builder (Spec 2.9)

**Files:**
- Modify: `src/Storage.php` — `list_traces()` (lines 136-212) and `count_traces()` (lines 214-276)

The filter-building logic is duplicated verbatim between these two methods (~95 lines each).

- [ ] **Step 1: Extract private build_where_clause() method**

Read `src/Storage.php`. Identify the shared filter logic in `list_traces()` and `count_traces()`. Extract into:

```php
/**
 * Build WHERE clause and parameters from filters.
 *
 * @param array $filters
 * @return array{0: string, 1: array} [where_clause, params]
 */
private function build_where_clause( array $filters ): array
{
    $where  = [];
    $params = [];

    // Port ALL filter conditions from list_traces():
    // url LIKE, min/max duration, after/before dates, method, user_id, ip_address, min/max score
    // Each condition pushes to $where[] and $params[]

    // Return the clause WITHOUT the leading WHERE keyword.
    // Callers prepend "WHERE " themselves.
    // Always starts with "1=1" so callers can unconditionally append.
    $where_sql = '1=1' . ( $where ? ' AND ' . implode( ' AND ', $where ) : '' );
    return [ $where_sql, $params ];
}
```

Then simplify both `list_traces()` and `count_traces()` to call this method:

```php
public function list_traces( array $filters ): array
{
    list( $where_sql, $params ) = $this->build_where_clause( $filters );
    // ... ordering, pagination, and query execution
}

public function count_traces( array $filters ): int
{
    list( $where_sql, $params ) = $this->build_where_clause( $filters );
    $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";
    // ...
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 3: Commit**

```bash
git add src/Storage.php
git commit -m "refactor: extract shared WHERE clause builder from list/count traces"
```

---

## Task 2: Store url_path as Indexed Column (Spec 2.5)

**Files:**
- Modify: `src/Storage.php` — `create_table()`, `save_trace()`, `get_slowest_pages()`, `get_route_stats()`

- [ ] **Step 1: Add url_path column to schema**

In `create_table()`, add a `url_path` column after `url`:

```sql
url_path varchar(2048) NOT NULL DEFAULT '',
```

Add an index:
```sql
KEY url_path (url_path(191)),
```

Also add composite indexes for sort performance:
```sql
KEY created_total (created_at, total_ms),
KEY created_queries (created_at, query_count),
```

- [ ] **Step 2: Bump SCHEMA_VERSION**

Change `const SCHEMA_VERSION = 1;` to `const SCHEMA_VERSION = 2;` so `maybe_upgrade()` runs `dbDelta` on existing installs.

- [ ] **Step 3: Populate url_path in save_trace()**

In `save_trace()`, before building `$data`, strip the query string:

```php
$url_path = explode( '?', $trace->url, 2 )[0];
```

Add to the `$data` array: `'url_path' => $url_path,`
Add to `$formats`: `'%s'` in the matching position.

- [ ] **Step 4: Update get_slowest_pages()**

Replace `SUBSTRING_INDEX(url, '?', 1) as page_url` with `url_path as page_url` and `GROUP BY url_path`.

- [ ] **Step 5: Add backfill in maybe_upgrade()**

In `Storage::maybe_upgrade()`, add a migration block for version 2:

```php
if ( $current < 2 ) {
    $this->create_table(); // Adds url_path column via dbDelta
    // Backfill url_path for existing rows
    $this->wpdb->query( "UPDATE {$this->table} SET url_path = SUBSTRING_INDEX(url, '?', 1) WHERE url_path = ''" );
}
```

- [ ] **Step 6: Update get_route_stats()**

Replace `WHERE SUBSTRING_INDEX(url, '?', 1) = %s` with `WHERE url_path = %s`.

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 7: Commit**

```bash
git add src/Storage.php
git commit -m "feat: add indexed url_path column, replace SUBSTRING_INDEX queries"
```

---

## Task 3: Normalize DB Queries for N+1 Fingerprinting (Spec 2.8)

**Files:**
- Modify: `src/DB.php` — add `normalize_query()`, store `query_hash` in span meta
- Modify: `src/Insights.php` — update `duplicate_db_queries()` to group by hash

- [ ] **Step 1: Add normalize_query() to DB.php**

```php
/**
 * Normalize a query by replacing literal values with placeholders.
 *
 * @param string $query
 * @return string Normalized query suitable for fingerprinting.
 */
private function normalize_query( string $query ): string
{
    // Replace string literals
    $normalized = preg_replace( "/'[^']*'/", '?', $query );
    // Replace numeric literals
    $normalized = preg_replace( '/\b\d+\b/', '?', $normalized );
    // Replace IN lists
    $normalized = preg_replace( '/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized );
    // Collapse whitespace
    $normalized = preg_replace( '/\s+/', ' ', trim( $normalized ) );
    return $normalized;
}
```

- [ ] **Step 2: Add query_hash to span meta in query()**

In the `query()` method, update the meta array to include the normalized hash:

```php
$meta = [
    'query'      => $this->truncate_query( $query ),
    'query_hash' => md5( $this->normalize_query( $query ) ),
];
```

- [ ] **Step 3: Update Insights::duplicate_db_queries()**

In `src/Insights.php`, modify `duplicate_db_queries()` to group by `query_hash` instead of raw query text. When `query_hash` is present in meta, use it as the grouping key. Fall back to query text for backward compatibility with old traces.

- [ ] **Step 4: Add unit test for normalize_query**

Since `normalize_query` is private, test it indirectly through the span meta. Or add a test in a new `tests/Unit/DBTest.php` using reflection.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 6: Commit**

```bash
git add src/DB.php src/Insights.php
git commit -m "feat: normalize DB queries for N+1 fingerprinting via query_hash"
```

---

## Task 4: Define Instrumentor Interface (Spec 2.1)

**Files:**
- Create: `src/Instrumentor.php` — interface
- Create: `src/CallbackInstrumentor.php` — wraps callback wrapping logic
- Modify: `src/DB.php` — implement Instrumentor
- Modify: `src/Http.php` — implement Instrumentor
- Modify: `src/GraphQL.php` — implement Instrumentor
- Modify: `wp-flame.php` — refactor init to use instrumentor loop + `wp_flame_instrumentors` filter

- [ ] **Step 1: Create Instrumentor interface**

Create `src/Instrumentor.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

interface Instrumentor
{
    /**
     * Whether this instrumentor should activate for the current request.
     */
    public function is_applicable(): bool;

    /**
     * Register hooks and start instrumentation.
     */
    public function register( Collector $collector ): void;
}
```

- [ ] **Step 2: Create DbInstrumentor wrapper**

The DB class extends `wpdb` and can't cleanly implement Instrumentor directly. Create `src/DbInstrumentor.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class DbInstrumentor implements Instrumentor
{
    /** @var \wpdb */
    private $wpdb;
    /** @var bool */
    private $full_query_text;

    public function __construct( \wpdb $wpdb, bool $full_query_text = false )
    {
        $this->wpdb            = $wpdb;
        $this->full_query_text = $full_query_text;
    }

    public function is_applicable(): bool
    {
        return DB::can_replace( $this->wpdb );
    }

    public function register( Collector $collector ): void
    {
        $GLOBALS['wpdb'] = DB::from_wpdb( $this->wpdb, $collector, $this->full_query_text );
    }
}
```

Add `src/DbInstrumentor.php` to the file map.

- [ ] **Step 3: Make Http implement Instrumentor**

Add `implements Instrumentor` to Http. Split the constructor:
- The constructor should ONLY store dependencies (no hook registration).
- Move `add_filter`/`add_action` hook registrations into `register()`.
- Add `is_applicable()` returning `true`.

```php
class Http implements Instrumentor
{
    private $collector;

    public function __construct() {} // No-op — or remove and construct in register

    public function is_applicable(): bool { return true; }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;
        add_filter( 'pre_http_request', [ $this, 'on_pre_request' ], 1, 3 );
        add_filter( 'http_response', [ $this, 'on_response' ], 9999, 3 );
        add_action( 'http_api_debug', [ $this, 'on_http_debug' ], 9999, 5 );
    }
    // ... rest unchanged
}
```

**Important:** Update `tests/Unit/HttpTest.php` — tests currently instantiate `Http($collector)` directly. They must be updated to call `$http = new Http(); $http->register($collector);` instead.

- [ ] **Step 4: Make GraphQL implement Instrumentor**

The GraphQL two-phase detection currently lives in `wp_flame_init()`. Move it into the instrumentor:

```php
class GraphQL implements Instrumentor
{
    // ... existing properties
    /** @var \wpdb */
    private $wpdb;

    public function __construct( bool $full_query_text = false, \wpdb $wpdb = null )
    {
        $this->full_query_text = $full_query_text;
        $this->wpdb            = $wpdb;
    }

    public function is_applicable(): bool
    {
        // Phase 1: URL-based detection (called at plugins_loaded)
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        return self::is_graphql_endpoint(
            $uri,
            apply_filters( 'graphql_endpoint', 'graphql' )
        );
    }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;
        $this->register_db_hooks();

        // Phase 2: Confirm at init with GRAPHQL_REQUEST constant
        add_action( 'init', function () use ( $collector ) {
            if ( defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST ) {
                $this->activate_wpgraphql_hooks();
            } else {
                // False positive — deactivate GraphQL, activate DB instrumentation
                $this->deactivate();
                if ( $this->wpdb && DB::can_replace( $this->wpdb ) ) {
                    $GLOBALS['wpdb'] = DB::from_wpdb( $this->wpdb, $collector, $this->full_query_text );
                }
                $GLOBALS['wp_flame_skip_callback_wrapping'] = false;
            }
        }, 0 );
    }
}
```

The GraphQL constructor now accepts `$wpdb` to enable DB instrumentation fallback on false-positive detection. The `$_SERVER['REQUEST_URI']` access uses `isset()` to avoid PHP notices in CLI/test environments.

- [ ] **Step 5: Create CallbackInstrumentor**

Create `src/CallbackInstrumentor.php` that wraps the `wp_flame_wrap_callbacks()` function logic:

```php
class CallbackInstrumentor implements Instrumentor
{
    private $config;

    public function __construct( Config $config )
    {
        $this->config = $config;
    }

    public function is_applicable(): bool
    {
        return true; // Always instrument callbacks.
    }

    public function register( Collector $collector ): void
    {
        $min_ms = (float) $this->config->get( 'wp_flame_min_callback_ms', 0.5 );
        // Register wrapping at plugins_loaded and init priority 1
        // Port the logic from wp_flame_wrap_callbacks()
    }
}
```

- [ ] **Step 6: Refactor wp_flame_init() to use instrumentor loop**

Replace the ad-hoc instantiation with:

```php
$full_query_text = (bool) $config->get( 'wp_flame_full_query_text', false );
$instrumentors = [
    new WPFlame\DbInstrumentor( $wpdb, $full_query_text ),
    new WPFlame\Http(),
    new WPFlame\GraphQL( $full_query_text, $wpdb ),
    new WPFlame\CallbackInstrumentor( $config ),
];
$instrumentors = apply_filters( 'wp_flame_instrumentors', $instrumentors );

// DB and GraphQL are mutually exclusive — see W1 warning.
$graphql_active = false;
foreach ( $instrumentors as $inst ) {
    if ( ! ( $inst instanceof WPFlame\Instrumentor ) ) {
        continue;
    }
    if ( $inst instanceof WPFlame\GraphQL ) {
        if ( $inst->is_applicable() ) {
            $graphql_active = true;
            $inst->register( $collector );
        }
    } elseif ( $inst instanceof WPFlame\DbInstrumentor ) {
        if ( ! $graphql_active && $inst->is_applicable() ) {
            $inst->register( $collector );
        }
    } else {
        if ( $inst->is_applicable() ) {
            $inst->register( $collector );
        }
    }
}

// If GraphQL is active, skip callback wrapping to avoid double instrumentation.
if ( $graphql_active ) {
    $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
}
```

**Important:** The GraphQL instrumentor's `register()` must handle both phases internally. It registers DB hooks immediately, then at `init` priority 0 either activates WPGraphQL hooks (confirmed GraphQL) or deactivates and creates a `DbInstrumentor` on the fly (false positive). The GraphQL constructor now receives `$wpdb` and `$full_query_text` to enable the fallback. Review the existing GraphQL detection in `wp_flame_init()` lines 174-194 and 315-340 before implementing.

- [ ] **Step 7: Remove wp_flame_wrap_callbacks() function**

After moving its logic into `CallbackInstrumentor`, remove the standalone function.

- [ ] **Step 8: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Some tests may need updating for constructor changes.

- [ ] **Step 9: Commit**

```bash
git add src/Instrumentor.php src/CallbackInstrumentor.php src/DB.php src/Http.php src/GraphQL.php wp-flame.php
git commit -m "refactor: introduce Instrumentor interface with wp_flame_instrumentors filter"
```

---

## Task 5: Add Third Callback Wrapping Pass (Spec 2.6)

**Files:**
- Modify: `src/CallbackInstrumentor.php` — add later wrapping hooks

- [ ] **Step 1: Add late wrapping passes**

In `CallbackInstrumentor::register()`, in addition to the existing `plugins_loaded` and `init` passes, register:

```php
add_action( 'template_redirect', [ $this, 'wrap_callbacks' ], 0 ); // Frontend
add_action( 'admin_init', [ $this, 'wrap_callbacks' ], 0 );        // Admin
add_action( 'rest_api_init', [ $this, 'wrap_callbacks' ], 0 );     // REST API
```

The existing `instanceof CallbackWrapper` guard prevents double-wrapping.

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 3: Commit**

```bash
git add src/CallbackInstrumentor.php
git commit -m "feat: add third callback wrapping pass at template_redirect/admin_init/rest_api_init"
```

---

## Task 6: Branch Phase Map by Request Type (Spec 2.7)

**Files:**
- Modify: `mu-plugin/wp-flame-early-hooks.php` — reduce to early phases only
- Modify: `wp-flame.php` — add request type detection and late phase registration

- [ ] **Step 1: Add request type detection to wp-flame.php**

```php
/**
 * Detect the current request type for phase map selection.
 *
 * IMPORTANT: REST_REQUEST constant is NOT defined at plugins_loaded or init.
 * REST detection uses URL pattern matching instead (same approach as GraphQL detection).
 */
function wp_flame_detect_request_type(): string
{
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return 'cli';
    }
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return 'cron';
    }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return 'ajax';
    }
    // REST detection via URL pattern — REST_REQUEST is not defined until rest_api_init.
    $rest_prefix = rest_get_url_prefix(); // Returns 'wp-json' by default
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( false !== strpos( $request_uri, '/' . $rest_prefix . '/' ) ) {
        return 'rest';
    }
    if ( is_admin() ) {
        return 'admin';
    }
    return 'frontend';
}
```

**Note:** `rest_get_url_prefix()` is available at `plugins_loaded` (defined in `wp-includes/rest-api.php` which is loaded during WordPress bootstrap). This is the same early-detection approach used for GraphQL endpoints.

- [ ] **Step 2: Modify mu-plugin to only register early phases**

The mu-plugin currently registers ALL phases (Bootstrap through Render). Change it to only register the universally-applicable early phases:

- `muplugins_loaded` → end Bootstrap, start Plugin Load
- `plugins_loaded` → end Plugin Load, start Theme Setup
- `after_setup_theme` → end Theme Setup, start Init

Remove the `init`, `wp`, and `template_redirect` hooks from the mu-plugin — these will be registered by `wp_flame_init()` based on request type.

**Also:** Update `WP_FLAME_MU_VERSION` to `'1.1.2'` to trigger auto-update.

- [ ] **Step 3: Register request-type-specific late phases**

**Timing note:** All detection now happens at `plugins_loaded` (in `wp_flame_init()`). CLI, cron, AJAX use constants. REST uses URL pattern matching (see W3 warning). All late phases are registered immediately based on detection result.

Register late phases using the same closure pattern as the mu-plugin. After instrumentors are registered (and after the W8 mu-plugin version guard):

```php
$request_type = wp_flame_detect_request_type();

switch ( $request_type ) {
    case 'frontend':
        // init → Routing, wp → Main Query, template_redirect → Render
        break;
    case 'rest':
        // init → Routing, rest_api_init → REST Dispatch
        break;
    case 'admin':
        // init → Admin Init, admin_init → Admin Render
        break;
    case 'ajax':
        // init → AJAX Dispatch
        break;
    case 'cli':
        // init → Command Execution
        break;
    case 'cron':
        // init → Cron Execution
        break;
}
```

Each phase transition uses the same pattern: `end_span` for the current phase, `start_span` for the next.

- [ ] **Step 4: Update WP_FLAME_VERSION in wp-flame.php**

Bump to `'1.1.2'` to match the mu-plugin version.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 6: Commit**

```bash
git add mu-plugin/wp-flame-early-hooks.php wp-flame.php
git commit -m "feat: branch phase map by request type (frontend/REST/admin/AJAX/CLI/cron)"
```

---

## Task 7: Define InsightRule Interface (Spec 2.3)

**Files:**
- Create: `src/Insight.php` — value object
- Create: `src/InsightRule.php` — interface
- Create: `src/InsightEngine.php` — rule runner
- Create: `src/Rules/SlowHttpRequests.php` — extracted rule
- Create: `src/Rules/DuplicateDbQueries.php`
- Create: `src/Rules/HighQueryCount.php`
- Create: `src/Rules/SlowCallbacks.php`
- Create: `src/Rules/HttpDuringEarlyPhases.php`
- Create: `src/Rules/NoPersistentCache.php`
- Create: `src/Rules/LowCacheHitRatio.php`
- Modify: `src/Insights.php` — delegate to InsightEngine (Admin.php unchanged — `to_array()` preserves backward compat)

- [ ] **Step 1: Create Insight value object**

Create `src/Insight.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Insight
{
    /** @var string Unique rule identifier */
    public $id;
    /** @var string 'warning'|'info' */
    public $severity;
    /** @var string */
    public $title;
    /** @var string */
    public $detail;
    /** @var string[] Span IDs this insight relates to */
    public $affected_span_ids;
    /** @var string|null Source attribution */
    public $source;

    /** @var array|null Remediation action metadata */
    public $remediation;

    public function __construct(
        string $id,
        string $severity,
        string $title,
        string $detail,
        array $affected_span_ids = [],
        ?string $source = null,
        ?array $remediation = null
    ) {
        $this->id                = $id;
        $this->severity          = $severity;
        $this->title             = $title;
        $this->detail            = $detail;
        $this->affected_span_ids = $affected_span_ids;
        $this->source            = $source;
        $this->remediation       = $remediation;
    }

    /**
     * Convert to legacy array format for backward compatibility.
     * @return array{severity: string, title: string, detail: string}
     */
    public function to_array(): array
    {
        return [
            'severity' => $this->severity,
            'title'    => $this->title,
            'detail'   => $this->detail,
        ];
    }
}
```

- [ ] **Step 2: Create InsightRule interface**

Create `src/InsightRule.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

interface InsightRule
{
    /** @return string Unique rule identifier */
    public function id(): string;

    /**
     * Analyze a trace and return findings.
     * @param Trace $trace
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array;
}
```

- [ ] **Step 3: Create InsightEngine**

Create `src/InsightEngine.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class InsightEngine
{
    /** @var InsightRule[] */
    private $rules = [];

    /**
     * @param InsightRule[] $rules
     */
    public function __construct( array $rules = [] )
    {
        $this->rules = $rules;
    }

    /**
     * @param InsightRule $rule
     */
    public function add_rule( InsightRule $rule ): void
    {
        $this->rules[] = $rule;
    }

    /**
     * Run all rules against a trace.
     *
     * @param Trace $trace
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $insights = [];
        foreach ( $this->rules as $rule ) {
            $results = $rule->analyze( $trace );
            foreach ( $results as $insight ) {
                $insights[] = $insight;
            }
        }
        return $insights;
    }
}
```

- [ ] **Step 4: Extract each rule into its own class**

Create 7 files in `src/Rules/`, each implementing `InsightRule`. Port the logic from the corresponding private static method in `Insights.php`. Each rule class should:

1. Implement `InsightRule`
2. Return `id()` as a descriptive string (e.g., `'slow_http_requests'`)
3. Port the exact logic from `Insights.php`
4. Return `Insight` objects instead of arrays

Example for `src/Rules/SlowHttpRequests.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Span;
use WPFlame\Trace;

class SlowHttpRequests implements InsightRule
{
    public function id(): string
    {
        return 'slow_http_requests';
    }

    public function analyze( Trace $trace ): array
    {
        // Port logic from Insights::slow_http_requests()
        // Return Insight objects with affected_span_ids populated
    }
}
```

Follow the same pattern for all 7 rules.

- [ ] **Step 5: Update Insights.php to delegate to InsightEngine**

Keep `Insights::analyze()` and `Insights::analyze_dashboard()` as the public API for backward compatibility, but internally delegate to `InsightEngine`:

```php
public static function analyze( Trace $trace ): array
{
    $engine = new InsightEngine( [
        new Rules\SlowHttpRequests(),
        new Rules\DuplicateDbQueries(),
        new Rules\HighQueryCount(),
        new Rules\SlowCallbacks(),
        new Rules\HttpDuringEarlyPhases(),
        new Rules\NoPersistentCache(),
        new Rules\LowCacheHitRatio(),
    ] );

    $insights = $engine->analyze( $trace );

    // Convert to legacy array format for backward compatibility
    return array_map( function ( Insight $i ) {
        return $i->to_array();
    }, $insights );
}
```

The `analyze_dashboard()` method stays as-is for now (dashboard rules have a different interface — they receive aggregate data, not a Trace). **Spec gap note:** The spec calls for a `DashboardInsightRule` interface. This is deliberately deferred — the 3 dashboard rules are only used in one place and converting them provides minimal benefit until site profiles add dashboard-specific rules.

**Filter integration:** The `wp_flame_insights` filter hook already exists in `Admin.php` (added in Tier 1, Task 13). The `InsightEngine` does NOT apply the filter internally — this keeps the engine a pure rule runner and lets the caller (Admin) control when filtering happens. This matches the current architecture.

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
All existing InsightsTest tests should pass since the public API returns the same array format.

- [ ] **Step 7: Commit**

```bash
git add src/Insight.php src/InsightRule.php src/InsightEngine.php src/Rules/ src/Insights.php
git commit -m "refactor: extract insight rules into InsightRule interface with InsightEngine"
```

---

## Task 8: Split Admin.php into View Classes (Spec 2.2)

**Files:**
- Modify: `src/Admin.php` — reduce to router + shared logic
- Create: `src/Admin/ListView.php` — trace list with filters and pagination
- Create: `src/Admin/DashboardView.php` — stats, charts, rankings
- Create: `src/Admin/FlameGraphView.php` — flame graph, insights, route comparison

This is the largest task. The current Admin.php is ~980 lines with 3 major render methods.

- [ ] **Step 1: Create Admin/ListView.php**

Extract `render_list_view()` and its filter logic from Admin.php into `src/Admin/ListView.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\Storage;

class ListView
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function render(): void
    {
        // Port render_list_view() from Admin.php
        // Also port the dashboard rendering (render_dashboard)
        // since the list view and dashboard share the same page
    }
}
```

**Important:** Read Admin.php carefully. The `render_list_view()` method (lines 75-387) renders BOTH the filter form AND the trace table. The `render_dashboard()` method (lines 389-688) renders the stats dashboard. Both are shown on the same page (list view shows the dashboard above the list). The view class should contain both.

- [ ] **Step 2: Create Admin/FlameGraphView.php**

Extract `render_flame_graph_view()` from Admin.php:

```php
<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\Storage;

class FlameGraphView
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function render( string $trace_id ): void
    {
        // Port render_flame_graph_view() from Admin.php
    }
}
```

- [ ] **Step 3: Reduce Admin.php to a router**

Admin.php should only contain:
- Menu registration
- Asset enqueuing
- Page routing (delegates to ListView or FlameGraphView)
- Delete handling
- Admin notices

```php
public function render_page(): void
{
    $this->handle_delete();

    $trace_id = isset( $_GET['trace_id'] )
        ? sanitize_text_field( wp_unslash( $_GET['trace_id'] ) )
        : '';

    if ( $trace_id ) {
        $view = new Admin\FlameGraphView( $this->storage );
        $view->render( $trace_id );
    } else {
        $view = new Admin\ListView( $this->storage );
        $view->render();
    }
}
```

Remove all private render helper methods that are now in view classes.

- [ ] **Step 4: Move get_slowest_callbacks() helper**

The private `get_slowest_callbacks()` method (lines 696-719) processes decoded traces to find slowest callbacks. Move it into `Admin/ListView.php` (it's only used by the dashboard section).

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 6: Commit**

```bash
git add src/Admin.php src/Admin/
git commit -m "refactor: split Admin into ListView and FlameGraphView classes"
```

---

## Task 9: Move Dashboard Analytics to SQL (Spec 2.4)

**Files:**
- Modify: `src/Storage.php` — add new aggregate methods, consolidate distribution query, bump schema version
- Modify: `src/Admin/ListView.php` (or `src/Admin/DashboardView.php`) — use new Storage methods instead of PHP analysis

- [ ] **Step 1: Consolidate get_response_time_distribution() into one query**

Replace the 7-query loop with a single conditional aggregation:

```php
public function get_response_time_distribution( int $days = 7 ): array
{
    $row = $this->wpdb->get_row(
        $this->wpdb->prepare(
            "SELECT
                SUM(total_ms < 50) AS b0,
                SUM(total_ms >= 50 AND total_ms < 100) AS b1,
                SUM(total_ms >= 100 AND total_ms < 200) AS b2,
                SUM(total_ms >= 200 AND total_ms < 500) AS b3,
                SUM(total_ms >= 500 AND total_ms < 1000) AS b4,
                SUM(total_ms >= 1000 AND total_ms < 1500) AS b5,
                SUM(total_ms >= 1500) AS b6
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        )
    );

    // Convert to the same bucket format the admin expects
    // ...
}
```

- [ ] **Step 2: Add denormalized summary columns to schema**

Add columns to `create_table()`:
```sql
db_time_ms float NOT NULL DEFAULT 0,
http_time_ms float NOT NULL DEFAULT 0,
```

Bump `SCHEMA_VERSION` to 3.

Update `maybe_upgrade()` to handle incremental migrations:

```php
public function maybe_upgrade(): void
{
    $current = (int) get_option( 'wp_flame_schema_version', 0 );
    if ( $current >= self::SCHEMA_VERSION ) {
        return;
    }

    $this->create_table(); // dbDelta adds new columns

    if ( $current < 2 ) {
        // Backfill url_path for existing rows (from Task 2)
        $this->wpdb->query( "UPDATE {$this->table} SET url_path = SUBSTRING_INDEX(url, '?', 1) WHERE url_path = ''" );
    }

    // Version 3: db_time_ms and http_time_ms columns added via dbDelta.
    // No backfill needed — old rows use 0 defaults, COALESCE handles nulls.

    update_option( 'wp_flame_schema_version', self::SCHEMA_VERSION );
}
```

Populate in `save_trace()` by computing from the trace's spans before insert:

```php
$db_time_ms   = 0.0;
$http_time_ms = 0.0;
foreach ( $trace->spans as $span ) {
    if ( $span->type === Span::TYPE_DB ) {
        $db_time_ms += $span->duration_ms;
    } elseif ( $span->type === Span::TYPE_HTTP ) {
        $http_time_ms += $span->duration_ms;
    }
}
```

- [ ] **Step 3: Add Storage::get_time_breakdown() method**

```php
/**
 * Get average time breakdown by span type (DB, HTTP, PHP/other).
 *
 * @param int $days
 * @return array{avg_db_ms: float, avg_http_ms: float, avg_php_ms: float}|null
 */
public function get_time_breakdown( int $days = 7 ): ?array
{
    $row = $this->wpdb->get_row(
        $this->wpdb->prepare(
            "SELECT
                COALESCE(AVG(db_time_ms), 0) AS avg_db_ms,
                COALESCE(AVG(http_time_ms), 0) AS avg_http_ms,
                COALESCE(AVG(total_ms - COALESCE(db_time_ms, 0) - COALESCE(http_time_ms, 0)), 0) AS avg_php_ms
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        ),
        ARRAY_A
    );

    return $row ?: null;
}
```

**Note:** No `AND db_time_ms > 0` filter — that would exclude cached pages with zero DB queries and skew averages. All traces are included. `COALESCE` handles NULL values from old rows that predate the new columns.

- [ ] **Step 4: Update dashboard to use SQL aggregates**

In the ListView/DashboardView, replace the `get_recent_trace_data(50)` + PHP decode loop for time breakdown with a call to `$this->storage->get_time_breakdown()`.

Remove or reduce the `get_recent_trace_data()` usage. Keep it only if still needed for other dashboard sections.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 6: Commit**

```bash
git add src/Storage.php src/Admin/
git commit -m "perf: move dashboard analytics to SQL with denormalized summary columns"
```

---

## Verification

After all 9 tasks are complete:

- [ ] **Run full test suite**

```bash
./vendor/bin/phpunit --testsuite unit
```

- [ ] **Verify Instrumentor wiring**

```bash
grep -rn "implements Instrumentor" src/
```

Expected: DB.php (or DbInstrumentor), Http.php, GraphQL.php, CallbackInstrumentor.php

- [ ] **Verify InsightRule implementations**

```bash
grep -rn "implements InsightRule" src/Rules/
```

Expected: 7 rule files

- [ ] **Verify no SUBSTRING_INDEX usage**

```bash
grep -rn "SUBSTRING_INDEX" src/
```

Expected: 0 results

- [ ] **Verify Admin.php is reduced**

```bash
wc -l src/Admin.php src/Admin/*.php
```

Expected: Admin.php under 200 lines, view classes split the rest.

- [ ] **Verify extension hooks**

```bash
grep -rn "wp_flame_instrumentors" wp-flame.php
```

Expected: `apply_filters('wp_flame_instrumentors', ...)` present.
