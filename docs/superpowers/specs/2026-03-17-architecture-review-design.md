# WP Flame Architecture Review — Pre-Launch Refactoring Spec

**Date:** 2026-03-17
**Scope:** Comprehensive architectural review of WP Flame with prioritized recommendations for pre-launch restructuring.
**Constraint:** PHP 7.4+ compatibility required for all changes.

---

## Context

WP Flame is a self-hosted WordPress APM plugin (~5,000 lines PHP, pure SVG flame graphs, zero JS dependencies). The plugin is pre-launch with no existing users, giving full freedom to restructure.

The roadmap includes: site profiles + onboarding, plugin knowledge base, N+1 query detection, performance coaching, testing mode, LLM trace analysis, and anonymous telemetry aggregation. Each of these requires extensibility, data model evolution, and UI expansion that the current architecture does not cleanly support.

This spec documents 35 findings across four dimensions (architecture, data model, instrumentation, frontend/security) and organizes them into three implementation tiers.

---

## Tier 1: Do Before Launch

These are architectural foundations that are cheap to add now and expensive to retrofit once users have stored data and third-party integrations depend on the internal structure.

### 1.1 Add Core Hooks/Filters

**Problem:** Zero `do_action` or `apply_filters` calls exist in `src/`. The plugin is entirely self-contained with no extension surface. This blocks the knowledge base (can't annotate insights), site profiles (can't filter traces), third-party integrations (can't add spans or rules), and telemetry (can't forward stored traces).

**Change:** Add five hooks at strategic integration points:

```php
// In wp_flame_shutdown(), before Storage::save_trace()
$should_store = apply_filters('wp_flame_should_store_trace', true, $trace);

// In wp_flame_shutdown(), before Collector::get_trace()
$request_meta = apply_filters('wp_flame_trace_meta', $request_meta);

// After Storage::save_trace() completes
do_action('wp_flame_trace_stored', $trace, $score_result);

// In Admin (and anywhere Insights::analyze() is called), after analysis
$insights = apply_filters('wp_flame_insights', $insights, $trace);

// In wp_flame_init(), instrumentor registration
$instrumentors = apply_filters('wp_flame_instrumentors', $instrumentors);
```

**Files affected:** `wp-flame.php` (shutdown handler, init function), `src/Admin.php` (insight rendering).

---

### 1.2 Extract Config Class

**Problem:** Every settings consumer calls `get_option()` directly. Site profiles need to override settings per-request ("on WooCommerce checkout, sample 100% regardless of admin setting"). There is no single override point.

**Change:** Create `src/Config.php`:

```php
class Config {
    /** @var array<string, mixed> */
    private $overrides = [];

    /**
     * @param string $key Option name (e.g. 'wp_flame_sample_rate')
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = false) {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }
        return get_option($key, $default);
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    public function set_override($key, $value) {
        $this->overrides[$key] = $value;
    }
}
```

All `get_option('wp_flame_*')` calls in `wp-flame.php`, `src/Admin.php`, `src/Settings.php`, and the mu-plugin are replaced with `$config->get()`. The `Config` instance is constructed in `wp_flame_init()` and passed to consumers.

**Files affected:** All files that call `get_option('wp_flame_*')`.

---

### 1.3 Schema Version Tracking

**Problem:** `Storage::create_table()` relies on `dbDelta()` with no version tracking. When roadmap features add columns (e.g., `profile_id`, `test_session_id`), sites that don't re-activate the plugin never get them.

**Change:** Add a `wp_flame_schema_version` option. Add `Storage::maybe_upgrade()` called on `plugins_loaded`:

```php
const SCHEMA_VERSION = 1;

public function maybe_upgrade() {
    $current = (int) get_option('wp_flame_schema_version', 0);
    if ($current >= self::SCHEMA_VERSION) {
        return;
    }
    // Run create_table() which uses dbDelta (additive, safe to re-run)
    $this->create_table();
    // Future: add migration methods per version
    // if ($current < 2) { $this->migrate_to_v2(); }
    update_option('wp_flame_schema_version', self::SCHEMA_VERSION);
}
```

**Files affected:** `src/Storage.php`, `wp-flame.php` (call `maybe_upgrade()` at init).

---

### 1.4 Stop Storing IP/User ID in Trace Meta

**Problem:** IP and user_id are stored in both the indexed DB column AND inside the `trace_data` JSON blob (via `$trace->meta`). This creates a GDPR erasure problem: deleting the column value leaves the data in the JSON, which requires re-serialization or full row deletion.

**Change:** In `wp_flame_shutdown()`, remove `ip_address` and `user_id` from `$request_meta` before passing to `$collector->get_trace($request_meta)`. These values are already passed as separate arguments to `$storage->save_trace()` and stored in dedicated indexed columns.

**Files affected:** `wp-flame.php` (shutdown handler, lines ~375-405).

---

### 1.5 Add JSON Schema Version to Trace Data

**Problem:** Neither `Trace::toArray()` nor `Span::toArray()` contains a version field. When the data model evolves (N+1 grouping, new meta keys, new span types), existing stored traces deserialize through the new `fromArray()` without those fields. Silent defaults (`?? []`, `?? 0`) mean traces pass type checks but are semantically incomplete.

**Change:** Add `'v' => 1` to `Trace::toArray()`. In `Trace::fromArray()`:

```php
public static function fromArray($data) {
    $version = isset($data['v']) ? (int) $data['v'] : 1;
    // Future: if ($version < 2) { $data = self::migrate_v1_to_v2($data); }
    // ... existing construction logic
}
```

**Files affected:** `src/Trace.php`.

---

### 1.6 Check wp_json_encode() Return Value

**Problem:** `Storage::save_trace()` passes `wp_json_encode($trace->toArray())` directly to insert. `wp_json_encode()` returns `false` if data contains non-UTF-8 bytes (common in user-agent strings and raw SQL). The `false` gets coerced to an empty string, creating a permanently unreadable trace row.

**Change:**

```php
$json = wp_json_encode($trace->toArray());
if ($json === false) {
    // Retry with UTF-8 sanitization
    $json = wp_json_encode(
        $trace->toArray(),
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        error_log('WP Flame: Failed to encode trace ' . $trace->id);
        return;
    }
}
```

Note: `JSON_INVALID_UTF8_SUBSTITUTE` requires PHP 7.2+, which is within our 7.4+ floor.

**Files affected:** `src/Storage.php` (`save_trace` method).

---

### 1.7 Check save_trace() Insert Return Value

**Problem:** `$this->wpdb->insert()` returns `false` on error. The return value is discarded. Silent data loss on insert failure.

**Change:**

```php
$result = $this->wpdb->insert($this->table, $data);
if ($result === false) {
    error_log('WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error);
}
```

**Files affected:** `src/Storage.php` (`save_trace` method).

---

### 1.8 Register GDPR Data Export/Erasure Hooks

**Problem:** The plugin stores IP addresses and user IDs but does not implement WordPress's `wp_privacy_personal_data_exporters` or `wp_privacy_personal_data_erasers` hooks. Sites cannot generate complete GDPR data exports or erasures through the standard privacy tools.

**Change:** Register both hooks:

- **Exporter:** Query `wp_flame_traces` by `user_id`, return trace summaries (URL, duration, timestamp, IP) as export items. Do not export full trace data (too large, contains other users' query text).
- **Eraser:** Delete all rows matching `user_id`. This is simpler and more complete than trying to redact individual fields. With the Tier 1.4 fix (no IP/user_id in JSON), the column-level data is the only personal data.

**Files affected:** New file `src/Privacy.php`, wired from `wp-flame.php`.

---

### 1.9 Fix http_response Not Firing for WP_Error

**Problem:** WordPress core only fires the `http_response` filter when the response is not a `WP_Error`. Failed HTTP requests (timeouts, DNS failures) leave orphaned spans that get swept by `close_open_spans()` with the full remaining request time as duration — massively overstating HTTP time.

**Change:** Additionally hook `http_api_debug` (fires for both success and failure) as a cleanup path:

```php
add_action('http_api_debug', [$this, 'on_http_debug'], 9999, 5);

public function on_http_debug($response, $context, $class, $parsed_args, $url) {
    $key = md5($url . ($parsed_args['method'] ?? 'GET'));
    if (!isset($this->pending_spans[$key])) {
        return;
    }
    // Close the span that on_response() didn't catch
    $span_id = $this->pending_spans[$key];
    unset($this->pending_spans[$key]);
    $meta = ['url' => $url, 'method' => $parsed_args['method'] ?? 'GET'];
    if (is_wp_error($response)) {
        $meta['status'] = 0;
        $meta['http_error'] = $response->get_error_message();
    }
    $this->collector->add_span_meta($span_id, $meta);
    $this->collector->end_span($span_id);
}
```

**Files affected:** `src/Http.php`.

---

### 1.10 Extract Shared SourceResolver

**Problem:** `get_caller_source()` is copy-pasted across `DB.php`, `Http.php`, and `GraphQL.php` — three identical implementations with a hardcoded backtrace depth of 15 frames. The depth may be insufficient for deeply nested stacks, causing misattribution to `'wordpress'`.

**Change:** Create `src/SourceResolver.php`:

```php
class SourceResolver {
    /**
     * @param Collector $collector
     * @param int       $skip    Frames to skip (caller-specific)
     * @param int       $depth   Max backtrace depth
     * @return string Source attribution string
     */
    public static function from_backtrace(Collector $collector, $skip = 0, $depth = 25) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth);
        // ... shared logic currently in DB::get_caller_source()
    }
}
```

Replace all three `get_caller_source()` implementations with calls to `SourceResolver::from_backtrace()`.

**Files affected:** `src/DB.php`, `src/Http.php`, `src/GraphQL.php`, new file `src/SourceResolver.php`.

---

## Tier 2: Do Soon After Launch (Before Site Profiles)

These changes enable the first major roadmap features. They're larger refactors that benefit from the Tier 1 foundations.

### 2.1 Define Instrumentor Interface

**Problem:** `DB`, `Http`, `GraphQL`, and callback wrapping each follow a different initialization pattern. Adding new instrumentation types requires editing `wp_flame_init()`, which is already 200+ lines.

**Change:** Define `src/Instrumentor.php`:

```php
interface Instrumentor {
    /**
     * Whether this instrumentor should activate for the current request.
     * @return bool
     */
    public function is_applicable();

    /**
     * Register hooks and start instrumentation.
     * @param Collector $collector
     */
    public function register(Collector $collector);
}
```

Refactor `DB`, `Http`, `GraphQL`, and callback wrapping into classes implementing this interface. In `wp_flame_init()`:

```php
$instrumentors = [
    new DbInstrumentor($wpdb, $config),
    new HttpInstrumentor(),
    new GraphQLInstrumentor($wpdb),
    new CallbackInstrumentor($config),
];
$instrumentors = apply_filters('wp_flame_instrumentors', $instrumentors);
foreach ($instrumentors as $inst) {
    if ($inst->is_applicable()) {
        $inst->register($collector);
    }
}
```

Future WooCommerce, block editor, or asset instrumentation becomes a new class appended to the array — zero changes to existing code.

**Files affected:** `wp-flame.php`, `src/DB.php`, `src/Http.php`, `src/GraphQL.php`, new `src/Instrumentor.php`, new `src/CallbackInstrumentor.php`.

---

### 2.2 Split Admin.php into View Classes

**Problem:** `Admin.php` is 979 lines with 6+ responsibilities: list view, flame graph, dashboard, filter handling, deletion, asset enqueuing. The dashboard alone decodes 50 full trace blobs in PHP. Adding site profiles, coaching panels, or new widgets means growing an already-oversized file.

**Change:** Split into:

- `src/Admin.php` — Menu registration, asset loading, page routing (~80-100 lines)
- `src/Admin/ListView.php` — Trace list with filters and pagination
- `src/Admin/DashboardView.php` — Stats, charts, rankings
- `src/Admin/FlameGraphView.php` — Flame graph, insights, route comparison

Each view receives a storage instance (via interface, see 2.4) and renders its own template partial from a `templates/` directory.

Move the dashboard's in-PHP analysis (time breakdown by type, slowest callbacks) to SQL aggregate methods in `Storage` — eliminates the 50-trace JSON deserialization.

**Files affected:** `src/Admin.php` (split), new `src/Admin/` directory, new `templates/` directory.

---

### 2.3 Define InsightRule Interface

**Problem:** Seven hardcoded static methods with a flat `['severity', 'title', 'detail']` output. Missing: rule IDs (knowledge base linkage), affected span IDs (flame graph highlighting), remediation actions (coaching). No way for site profiles to add conditional rules.

**Change:**

```php
interface InsightRule {
    /** @return string Unique rule identifier for KB linkage */
    public function id();

    /**
     * @param Trace $trace
     * @return Insight[] Array of findings
     */
    public function analyze(Trace $trace);
}
```

```php
class Insight {
    /** @var string */
    public $id;
    /** @var string 'warning'|'info' */
    public $severity;
    /** @var string */
    public $title;
    /** @var string */
    public $detail;
    /** @var string[] Span IDs this insight relates to */
    public $affected_span_ids;
    /** @var string|null Source attribution (e.g. 'woocommerce-addon') */
    public $source;
    /** @var array|null Remediation action metadata */
    public $remediation;
}
```

An `InsightEngine` takes registered `InsightRule` instances, runs each, and applies `apply_filters('wp_flame_insights', $results, $trace)`. The existing seven rules become seven small classes. Profile-specific rules register via the filter.

`analyze_dashboard` becomes a separate `DashboardInsightRule` interface receiving aggregate data instead of a single `Trace`.

**Files affected:** `src/Insights.php` (refactored), new `src/InsightRule.php`, new `src/Insight.php`, new `src/InsightEngine.php`, new `src/Rules/` directory for individual rules.

---

### 2.4 Move Dashboard Analytics to SQL

**Problem:** `render_dashboard()` fetches 50 full JSON blobs (potentially 5-50MB) and computes time-breakdown-by-type and slowest-callbacks in PHP. `get_response_time_distribution()` issues 7 separate COUNT queries.

**Change:**

- Add `Storage::get_time_breakdown_by_type(int $days, int $limit)` — SQL aggregate query returning `[['type' => 'db', 'total_ms' => 1234], ...]`. This requires a denormalized approach since span data is in JSON. Two options:
  1. Add summary columns to the traces table (`db_time_ms`, `http_time_ms`, `plugin_time_ms`) populated at save time. Simplest, works with current schema.
  2. Defer to the spans table (Tier 3). More correct, larger effort.

  Recommend option 1 for now.

- Add `Storage::get_slowest_callbacks(int $limit, int $days)` — Same approach: extract at save time, store as a summary column or separate lightweight table.

- Consolidate `get_response_time_distribution()` into a single conditional aggregation query:

```sql
SELECT
  SUM(total_ms < 50) AS b0,
  SUM(total_ms >= 50 AND total_ms < 100) AS b1,
  SUM(total_ms >= 100 AND total_ms < 200) AS b2,
  SUM(total_ms >= 200 AND total_ms < 500) AS b3,
  SUM(total_ms >= 500 AND total_ms < 1000) AS b4,
  SUM(total_ms >= 1000 AND total_ms < 1500) AS b5,
  SUM(total_ms >= 1500) AS b6
FROM {$table}
WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
```

**Files affected:** `src/Storage.php`, `src/Admin.php` (or `src/Admin/DashboardView.php` after split).

---

### 2.5 Store url_path as Indexed Column

**Problem:** `get_slowest_pages()` and `get_route_stats()` use `SUBSTRING_INDEX(url, '?', 1)` in GROUP BY / WHERE. MySQL cannot use the `url` index for this expression — full table scan on every call.

**Change:** Add a `url_path` column (varchar(2048), indexed) to the traces table. Strip the query string before INSERT in `save_trace()`:

```php
$url_path = strtok($trace->url, '?');
```

Update `get_slowest_pages()` and `get_route_stats()` to query `url_path` directly. Add composite indexes for common sort patterns: `(created_at, total_ms)`, `(created_at, query_count)`.

This is a schema change, which is why Tier 1.3 (schema versioning) must land first.

**Files affected:** `src/Storage.php`.

---

### 2.6 Add Third Callback Wrapping Pass

**Problem:** Callbacks registered at `init` priority 2+ are never instrumented. On WooCommerce sites, this is the majority of WC callbacks (registered at `init` priority 10+).

**Change:** Add wrapping passes at:
- `template_redirect` priority 0 (frontend)
- `admin_init` priority 0 (admin)
- `rest_api_init` priority 0 (REST)

The existing `instanceof CallbackWrapper` guard at line 441 prevents double-wrapping.

**Files affected:** `wp-flame.php` (or `src/CallbackInstrumentor.php` after Tier 2.1).

---

### 2.7 Branch Phase Map by Request Type

**Problem:** The phase map hardcodes frontend page-load transitions. For CLI, cron, AJAX, REST, and admin requests, phases like "Routing" and "Render" are misleading — they stay open until `close_open_spans()` sweeps them with inflated durations.

**Change:** Detect request type and register appropriate phase hooks:

```php
function wp_flame_detect_request_type() {
    if (defined('WP_CLI') && WP_CLI) return 'cli';
    if (defined('DOING_CRON') && DOING_CRON) return 'cron';
    if (defined('DOING_AJAX') && DOING_AJAX) return 'ajax';
    if (defined('REST_REQUEST') && REST_REQUEST) return 'rest';
    if (is_admin()) return 'admin';
    return 'frontend';
}
```

Phase maps per type:
- **frontend:** current map (correct)
- **REST:** replace `template_redirect` → Render with `rest_api_init` → REST Dispatch
- **AJAX:** replace `wp` onwards with `admin_init` → AJAX Dispatch
- **CLI:** only Bootstrap → Plugin Load → Init → Command Execution
- **cron:** only Bootstrap → Plugin Load → Init → Cron Execution
- **admin:** replace `template_redirect` → Render with `admin_init` → Admin Render

Note: Some constants (`REST_REQUEST`, `DOING_AJAX`) are not defined at mu-plugin time. The mu-plugin registers the common early phases (Bootstrap, Plugin Load, Theme Setup, Init), and `wp_flame_init()` registers the later phases based on detected request type.

**Files affected:** `mu-plugin/wp-flame-early-hooks.php`, `wp-flame.php`.

---

### 2.8 Normalize DB Queries for N+1 Fingerprinting

**Problem:** `DB.php` truncates query text to 200 chars. True N+1 patterns (`WHERE post_id = 123` vs `WHERE post_id = 456`) are never detected because the literal values differ. The duplicate-query insight groups by raw text, not structural pattern.

**Change:** Add query normalization in `DB.php`:

```php
private function normalize_query($query) {
    // Replace numeric literals
    $normalized = preg_replace('/\b\d+\b/', '?', $query);
    // Replace string literals
    $normalized = preg_replace("/'[^']*'/", '?', $normalized);
    // Replace IN lists
    $normalized = preg_replace('/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'IN (?)', $normalized);
    // Collapse whitespace
    $normalized = preg_replace('/\s+/', ' ', trim($normalized));
    return $normalized;
}
```

Store both the original (truncated or full) query AND the normalized hash in span meta:

```php
$meta = [
    'query' => $this->truncate_query($query),
    'query_hash' => md5($this->normalize_query($query)),
];
```

Update `Insights::duplicate_db_queries()` to group by `query_hash` instead of raw query text.

**Files affected:** `src/DB.php`, `src/Insights.php`.

---

### 2.9 Extract Shared WHERE Clause Builder in Storage

**Problem:** `count_traces()` duplicates all filter-building logic from `list_traces()` verbatim — the canonical "copy-paste to get a COUNT" antipattern.

**Change:** Extract a private method:

```php
/**
 * @param array $filters
 * @return array{0: string, 1: array} [$where_clause, $params]
 */
private function build_where_clause(array $filters) {
    $where = [];
    $params = [];
    // ... shared filter logic
    return [implode(' AND ', $where), $params];
}
```

Call from both `list_traces()` and `count_traces()`.

**Files affected:** `src/Storage.php`.

---

## Tier 3: Nice to Have

These are correctness improvements and performance optimizations that don't block the roadmap but improve quality.

### 3.1 Immutable Trace/Span Properties

**Problem:** All properties on `Trace` and `Span` are public and mutable. Analysis passes can accidentally mutate the objects being serialized.

**Change:** Since PHP 7.4 does not support `readonly`, use private properties with getter methods. For construction, use either a constructor with all parameters or a static `fromArray()` factory:

```php
class Span {
    /** @var string */
    private $id;
    // ...

    /** @return string */
    public function id() { return $this->id; }
    // ...
}
```

This is a larger refactor touching every consumer of Span/Trace properties. Alternatively, document the convention "never mutate after construction" and defer the refactor until the PHP floor is raised to 8.1.

**Recommendation:** Defer to a PHP 8.1+ migration. Document the immutability contract with `@internal Do not mutate after construction` PHPDoc annotations for now.

---

### 3.2 Lazy Reflection in CallbackWrapper

**Problem:** Every callback gets `ReflectionFunction`/`ReflectionMethod` at wrapping time, even though 80-90% never fire on any given request. On complex sites (10k+ callbacks), the wrapping pass adds 10-50ms.

**Change:** Store the raw callable in `CallbackWrapper`. Only call `CallbackResolver::resolve_name()` and `resolve_source()` inside `__invoke()` (when the callback fires). Cache the resolved values on the wrapper instance so subsequent invocations of the same callback don't re-resolve.

**Trade-off:** Spans from callbacks that never fire won't have names pre-computed, but those spans are never created anyway. The flame graph output is identical. The only behavioral difference: the self-exclusion check (skipping wp-flame callbacks) must use a different mechanism, since the source isn't known at wrapping time. Solution: check the callback's file path at wrapping time (cheap `ReflectionFunction::getFileName()` without full resolution) and skip only on path match.

**Files affected:** `src/CallbackWrapper.php`, `src/CallbackResolver.php`, `wp-flame.php` (wrapping function).

---

### 3.3 Remove GraphQL ROOT_TYPES Guard

**Problem:** Only root-level resolvers get spans. Nested resolver costs (ACF fields, taxonomy terms, post author) are invisible in the flame graph.

**Change:** Remove the `ROOT_TYPES` check in `graphql_pre_resolve_field` and `graphql_resolve_field` hooks. Apply `end_span_filtered()` with `$min_ms` threshold to control span volume from nested resolvers.

**Files affected:** `src/GraphQL.php`.

---

### 3.4 Fix Batched GraphQL Operation Span

**Problem:** `$this->operation_span_id` is a single nullable value. Batched queries fire `graphql_process_request` multiple times, clobbering previous span IDs.

**Change:** Replace with an array stack:

```php
/** @var string[] */
private $operation_span_stack = [];

// In process_request:
$this->operation_span_stack[] = $span_id;

// In return_response:
$span_id = array_pop($this->operation_span_stack);
```

**Files affected:** `src/GraphQL.php`.

---

### 3.5 Chunk prune_old() Deletes

**Problem:** Single unbounded DELETE can lock the table for seconds on large accumulated backlogs.

**Change:**

```php
public function prune_old($days) {
    do {
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 500",
                $days
            )
        );
    } while ($deleted > 0);
}
```

**Files affected:** `src/Storage.php`.

---

### 3.6 Fix HTTP Pending Spans Collision

**Problem:** `pending_spans` keyed by `md5($url . $method)` collides when the same URL is requested twice in one request.

**Change:** Use a stack per key:

```php
// on_pre_request:
$this->pending_spans[$key][] = $span_id;

// on_response / on_http_debug:
$span_id = array_pop($this->pending_spans[$key]);
```

**Files affected:** `src/Http.php`.

---

### 3.7 Guard SAVEQUERIES Definition

**Problem:** `define('SAVEQUERIES', true)` fires on URL-path-based GraphQL detection, even if WPGraphQL isn't installed. False positives cause all queries to be stored in memory for the entire request.

**Change:**

```php
if ($is_likely_graphql && class_exists('WPGraphQL')) {
    if (!defined('SAVEQUERIES')) {
        define('SAVEQUERIES', true);
    }
}
```

**Files affected:** `wp-flame.php`.

---

### 3.8 Flame Graph getDepth() Memoization

**Problem:** Recursive parent traversal with no memoization — O(n^2) worst case for linear call stacks with 1000+ spans.

**Change:** Compute depth during the initial tree-build loop and store as a property on each node:

```javascript
// During tree build:
nodes.forEach(node => {
    let depth = 0;
    let current = node;
    while (current.parent_id) {
        depth++;
        current = nodeMap[current.parent_id];
    }
    node.depth = depth;
});
```

Or better: compute depth top-down during tree construction (parent depth + 1).

**Files affected:** `assets/js/flame-graph.js`.

---

### 3.9 DB Instrumentation Fallback for Custom wpdb Subclasses

**Problem:** `DB::can_replace()` returns `false` on managed hosting (HyperDB, LudicrousDB). DB spans silently don't appear — no warning, no fallback.

**Change:**
1. When `can_replace()` returns false, log a debug message naming the actual class.
2. Fall back to `SAVEQUERIES` + `log_query_custom_data` hook (same pattern used for GraphQL Tier 2) to capture query timing without class replacement.

**Files affected:** `src/DB.php`, `wp-flame.php`.

---

### 3.10 Configurable Trusted Proxy Headers

**Problem:** `get_client_ip()` trusts `HTTP_X_FORWARDED_FOR` unconditionally. Any client can spoof their IP, making abuse-detection insights unreliable.

**Change:** Add a `wp_flame_trusted_ip_headers` option (default: `['REMOTE_ADDR']`). Only check forwarded headers if explicitly configured (e.g., `['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR']` for Cloudflare).

**Files affected:** `wp-flame.php` (`wp_flame_get_client_ip()`), `src/Settings.php`.

---

### 3.11 mu-plugin Early Bailout When Disabled

**Problem:** The mu-plugin unconditionally starts the Collector, opens a Bootstrap span, and registers six phase closures even when the plugin is disabled.

**Change:** Add early check at the top of the mu-plugin after autoloader loads:

```php
if (!get_option('wp_flame_enabled', true)) {
    return;
}
```

`get_option()` at mu-plugin time is safe because `$wpdb` is available before mu-plugins load.

**Files affected:** `mu-plugin/wp-flame-early-hooks.php`.

---

### 3.12 Self-Exclusion via Directory Constant

**Problem:** Self-exclusion in callback wrapping checks for `'wp-flame'` string in source. Fragile if installed under a different directory name.

**Change:** Compare against `WP_FLAME_DIR` constant:

```php
if (strpos($source_file, WP_FLAME_DIR) === 0) {
    continue;
}
```

Remove the leftover `'wordpress-apm-plugin'` check.

**Files affected:** `wp-flame.php` (`wp_flame_wrap_callbacks()`).

---

### 3.13 Capture DB Query Errors in Span Meta

**Problem:** When a query fails, the span shows normal duration with no indication the query returned an error.

**Change:** After `parent::query()`, check `$this->last_error`:

```php
if ($this->last_error) {
    $this->collector->add_span_meta($span_id, ['db_error' => $this->last_error]);
}
```

**Files affected:** `src/DB.php`.

---

### 3.14 Full Query Text Privacy Warning

**Problem:** The `wp_flame_full_query_text` setting description says "may increase storage usage" but doesn't mention that full query text may contain user emails, passwords (from login queries), and other PII embedded in query values.

**Change:** Update the setting description to include a privacy warning:

> "Store complete SQL query text in traces. May increase storage usage. Warning: full query text may contain personal data (email addresses, usernames, etc.) embedded in query values. Enable only in development or with appropriate data handling policies."

**Files affected:** `src/Settings.php`.

---

## Dependency Graph

```
Tier 1.3 (schema versioning) ──► Tier 2.5 (url_path column)
Tier 1.1 (hooks) ──► Tier 2.1 (Instrumentor interface)
Tier 1.1 (hooks) ──► Tier 2.3 (InsightRule interface)
Tier 1.2 (Config) ──► Tier 2.1 (Instrumentor uses Config)
Tier 2.1 (Instrumentor) ──► Tier 2.6 (third wrapping pass)
Tier 2.2 (Admin split) ──► Tier 2.4 (SQL aggregates)
```

All Tier 1 items are independent of each other and can be parallelized.
Tier 2 items have the dependencies shown above.
All Tier 3 items are independent and can be done in any order.

---

## Out of Scope

The following were noted during review but are deferred:

- **Spans table for cross-trace analytics** — Important for N+1 detection at scale, but a significant data model change. Design separately when the N+1 roadmap item begins.
- **Admin template system** — A full template engine (Blade, Twig) is overkill. Simple PHP partials in a `templates/` directory are sufficient.
- **StorageInterface abstraction** — Useful for testing and remote telemetry, but the consumer surface is large (12+ methods). Design when the telemetry SaaS tier begins.
- **Request-type-specific phase labels** — Covered in Tier 2.7. The mu-plugin changes require careful testing across all WordPress request types.
