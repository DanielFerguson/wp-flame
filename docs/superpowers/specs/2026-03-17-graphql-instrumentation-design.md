# GraphQL Instrumentation Design

## Problem

WP Flame causes a 500 internal server error on sites using Stellate (a GraphQL CDN for WPGraphQL). The conflict stems from two invasive instrumentation mechanisms:

1. **DB class replacement** — replacing `$wpdb` with an instrumented subclass
2. **Callback wrapping** — mutating `$wp_filter` to wrap every registered hook callback

Both mechanisms modify global state that Stellate (and potentially other GraphQL plugins) depend on. The current quick-fix skips all heavy instrumentation for GraphQL requests, but this leaves GraphQL traffic with no DB visibility, no resolver timing, inflated performance scores, and incomplete insights.

## Goal

Full GraphQL instrumentation — abuse detection, performance visibility, accurate scoring, and data integrity — without conflicting with Stellate or any other GraphQL plugin.

## Design

### Tiered Instrumentation

Three tiers, selected at runtime based on what's available:

**Tier 1: WPGraphQL detected** — full native instrumentation using WPGraphQL's own hooks. No `$wpdb` replacement, no callback wrapping.

**Tier 2: GraphQL detected, no WPGraphQL** — lightweight fallback. Lifecycle phases, HTTP tracking, identity, DB queries via `log_query_custom_data`. No resolver-level spans.

**Tier 3: Normal WordPress request** — existing behavior, unchanged.

### Detection Logic

**Problem:** `GRAPHQL_REQUEST` is defined by WPGraphQL inside `graphql_process_http_request()`, which fires on the `init` hook or later. Our `wp_flame_init()` runs at `plugins_loaded` priority 0 — too early for `GRAPHQL_REQUEST` to exist.

**Solution:** Two-phase detection using URL heuristic at `plugins_loaded`, confirmed by `GRAPHQL_REQUEST` on `init`.

**Phase 1 — `plugins_loaded` (priority 0):** Use a URL-based heuristic to detect likely GraphQL requests early, before `$wpdb` replacement or callback wrapping would normally occur:

```php
$graphql_endpoint = apply_filters('graphql_endpoint', 'graphql');
$request_path = isset($_SERVER['REQUEST_URI'])
    ? parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
    : '';
$is_likely_graphql = $request_path !== ''
    && ($request_path === '/' . $graphql_endpoint
        || substr($request_path, -strlen('/' . $graphql_endpoint)) === '/' . $graphql_endpoint);
```

This matches the exact endpoint path (e.g., `/graphql` or `/wp/graphql`) without false positives from URLs like `/my-page/graphql-tools`. Uses `parse_url()` to strip query strings before comparison.

If `$is_likely_graphql` is true: skip `$wpdb` replacement and callback wrapping, enable `SAVEQUERIES`, instantiate `GraphQL` class in "pending" mode.

If false: proceed with normal Tier 3 instrumentation.

**Phase 2 — `init` (priority 0):** Confirm the detection:

```php
add_action('init', function () {
    $confirmed = defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST;
    $has_wpgraphql = function_exists('graphql');

    if ($confirmed && $has_wpgraphql) {
        // Tier 1: activate WPGraphQL native hooks
    } elseif ($confirmed) {
        // Tier 2: lightweight fallback (already active)
    } else {
        // False positive: URL matched but not actually GraphQL
        // Deactivate GraphQL DB hooks, start normal instrumentation
    }
}, 0);
```

**False positive handling:** If the URL heuristic matched but `GRAPHQL_REQUEST` is not defined at `init`, we must:

1. Call `$graphql_inst->deactivate()` to remove the `log_query_custom_data` hook (prevents double-counting DB spans alongside `$wpdb` replacement)
2. Start `$wpdb` replacement via `DB::from_wpdb()`
3. Set `$GLOBALS['wp_flame_skip_callback_wrapping'] = false` so Pass 2 callback wrapping runs at `init:1`

Note: `SAVEQUERIES` cannot be un-defined (it's a PHP constant), so false-positive requests have a small residual memory overhead from `$wpdb->queries` accumulating. This is harmless.

### New File: `src/GraphQL.php`

A single class encapsulating all GraphQL-specific instrumentation, following the same pattern as `Http.php`.

```php
namespace WPFlame;

class GraphQL {
    private Collector $collector;
    private bool $full_query_text;
    private array $resolver_span_stacks = []; // Stack per field key for alias handling
    private ?string $operation_span_id = null;
    private ?callable $db_hook_callback = null; // Stored for remove_filter() in deactivate()

    public function __construct(Collector $collector) {
        $this->collector = $collector;
        // Cache option value once — avoid get_option() inside per-query filter (re-entrancy risk)
        $this->full_query_text = (bool) get_option('wp_flame_full_query_text', false);
        $this->register_db_hooks(); // DB capture via log_query_custom_data — active for both tiers
    }

    /**
     * Activate WPGraphQL-specific resolver and operation hooks (Tier 1 only).
     * Called from init:0 after GRAPHQL_REQUEST and function_exists('graphql') are confirmed.
     */
    public function activate_wpgraphql_hooks(): void {
        $this->register_operation_hooks();
        $this->register_resolver_hooks();
    }

    /**
     * Remove all hooks registered by this instance.
     * Called on false-positive detection to avoid double DB span creation
     * when normal $wpdb replacement takes over.
     */
    public function deactivate(): void {
        if ($this->db_hook_callback !== null) {
            remove_filter('log_query_custom_data', $this->db_hook_callback, 10);
            $this->db_hook_callback = null;
        }
    }
}
```

**Re-entrancy prevention:** The `full_query_text` option is cached in the constructor, not read per-query. This avoids calling `get_option()` inside `log_query_custom_data`, which could trigger a DB query on cache miss, which would fire `log_query_custom_data` again, causing infinite recursion. This matches the pattern used by `DB.php:31`.

#### WPGraphQL Lifecycle Hooks (Tier 1)

**Operation-level span:**

Use `graphql_process_request` action to start the operation span (fires reliably before any field resolution begins, including for requests that fail validation). Use `graphql_return_response` filter to end it.

```php
add_action('graphql_process_request', function ($wp_graphql) {
    try {
        $query = $wp_graphql->get_query();
        $operation_name = $wp_graphql->get_operation_name() ?: 'anonymous';
        $this->operation_span_id = $this->collector->start_span(
            "GraphQL: {$operation_name}",
            Span::TYPE_CORE,
            'wpgraphql'
        );
        $this->collector->add_span_meta($this->operation_span_id, [
            'graphql_operation' => $operation_name,
            'graphql_query' => substr($query, 0, 500),
        ]);
    } catch (\Throwable $e) {
        // Don't break GraphQL processing
    }
}, 10, 1);

add_filter('graphql_return_response', function ($response) {
    try {
        if ($this->operation_span_id !== null) {
            $this->collector->end_span($this->operation_span_id);
            $this->operation_span_id = null;
        }
    } catch (\Throwable $e) {
        // Don't break GraphQL response
    }
    return $response;
}, 10, 1);
```

**Error safety:** Both hooks use `try/catch(\Throwable)` to ensure we never break GraphQL processing or fail to return `$response`. This is critical — `graphql_return_response` is a filter, and failing to return `$response` would break the GraphQL response entirely.

If `graphql_return_response` fires without an operation span (e.g., very early error before `graphql_process_request`), the null check makes it a no-op — safe.

**Operation span type:** Uses `TYPE_CORE` because the GraphQL operation represents the primary work of the request, analogous to lifecycle phases like "Render" for normal requests. This gives it steel blue coloring in the flame graph, visually distinguishing it from individual resolver spans (which use `TYPE_PLUGIN` / purple).

**Root field resolver spans:**

WPGraphQL fires two filters per field resolution:

- `graphql_pre_resolve_field` — 9 args: `$default, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver`
- `graphql_resolve_field` — 9 args: `$result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver`

Root fields are identified by `$type_name` matching `RootQuery`, `RootMutation`, or `RootSubscription` (case-insensitive comparison).

For root fields only (default behavior):
1. `graphql_pre_resolve_field`: start a span named `"{TypeName}.{field_key}"` with type `TYPE_PLUGIN` and source `"wpgraphql"`. Push span ID onto `$this->resolver_span_stacks["{type_name}.{field_key}"]`. Always return `$default` unchanged.
2. `graphql_resolve_field`: pop the span ID from the matching stack, end the span. Always return `$result` unchanged.

Both filter callbacks must use `try/catch(\Throwable)` and always return the first argument (`$default` or `$result`) unchanged.

**Aliased field handling:** GraphQL allows the same field to be queried multiple times with aliases (e.g., `first: posts(...) { ... }` and `second: posts(...) { ... }`). Both resolve `RootQuery.posts`. Using a stack per field key (`$this->resolver_span_stacks["RootQuery.posts"][] = $span_id`) with `array_pop()` on resolution handles this correctly. In practice, WPGraphQL resolves fields sequentially (pre/resolve are always paired), so the stack is a safety measure for any future async execution model.

Span metadata includes:
- `type_name` — the GraphQL type (e.g., `"RootQuery"`)
- `field_key` — the field being resolved (e.g., `"posts"`)
- `hook` — set to `"graphql:{type_name}.{field_key}"` (e.g., `"graphql:RootQuery.posts"`) to match the existing slow callback detection pattern in `Score.php` and `Insights.php`

**Operation metadata:**

Extract from `$info` (GraphQL\Type\Definition\ResolveInfo):
- `$info->operation->operation` — query, mutation, or subscription
- `$info->operation->name->value` — operation name (if named)

Store as span meta on the operation-level span.

#### DB Query Capture (Tiers 1 & 2)

Instead of replacing `$wpdb`, use WordPress's built-in query logging:

1. Conditionally enable `SAVEQUERIES` for GraphQL requests (if not already defined). This happens in Phase 1 at `plugins_loaded`:
   ```php
   if (!defined('SAVEQUERIES')) {
       define('SAVEQUERIES', true);
   }
   ```

2. Hook `log_query_custom_data` filter (fires after each query when SAVEQUERIES is on):
   ```php
   $this->db_hook_callback = function($query_data, $query, $query_time, $query_callstack, $query_start) {
       // Source attribution via debug_backtrace (same pattern as DB::get_caller_source())
       $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
       $source = $this->get_caller_source($backtrace);

       // Query text truncation (uses cached option value — no get_option() call here)
       $query_text = $this->full_query_text ? $query : substr($query, 0, 200);

       // Create a completed DB span with pre-computed absolute timing
       // add_completed_span converts to relative ms internally using $request_start
       $this->collector->add_completed_span(
           $this->extract_query_type($query),
           Span::TYPE_DB,
           $source,
           $query_start,      // absolute microtime(true) when query started
           $query_time,        // duration in seconds
           ['query' => $query_text]
       );

       return $query_data;
   };
   add_filter('log_query_custom_data', $this->db_hook_callback, 10, 5);
   ```

The callback is stored in `$this->db_hook_callback` so `deactivate()` can call `remove_filter()` on false-positive recovery. The `extract_query_type()` method follows the same pattern as `DB::extract_query_type()` — extracts the SQL verb (SELECT, INSERT, etc.) for the span name.

**New method on Collector:** `add_completed_span(string $name, string $type, string $source, float $abs_start, float $duration_sec, array $meta = []): string` — creates a span with pre-computed timing. Accepts absolute `microtime(true)` start and duration in seconds (matching the values provided by `log_query_custom_data`). Internally converts to relative milliseconds using the private `$request_start` property: `$start_ms = ($abs_start - $this->request_start) * 1000` and `$duration_ms = $duration_sec * 1000`. This keeps `$request_start` encapsulated within `Collector`.

**Parent-child assignment:** `add_completed_span()` reads the current `$this->span_stack` to determine `parent_id`, matching the behavior of `start_span()`. This works correctly because `log_query_custom_data` fires during query execution — when a query runs inside a resolver, the resolver span is still on the stack, so the DB span becomes a child of the resolver span. In Tier 2 (no resolver spans), DB spans become children of the active lifecycle phase span (e.g., "Init" or "Render").

**Source attribution:** Uses `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)` inside the filter handler, following the same file-path-based pattern as `DB::get_caller_source()`. Does NOT use the `$query_callstack` parameter (4th arg) because it's a pre-formatted comma-separated string from `wp_debug_backtrace_summary()`, which is harder to parse for file-path-based source attribution.

**Bootstrap query gap:** Queries executed before `plugins_loaded` (WordPress core bootstrap queries) will not be captured via `log_query_custom_data`, because `SAVEQUERIES` is not defined until our plugin loads. This is acceptable because all GraphQL-relevant queries (resolver queries, connection queries) happen post-`init`. The missed queries are WordPress core operations (option loading, user session checks, etc.) that are captured by the lifecycle phase spans.

**Memory:** `SAVEQUERIES` causes `$wpdb` to store all queries in `$wpdb->queries`. For typical GraphQL requests (10-50 queries), this is negligible. Complex queries (hundreds of DB queries) could accumulate ~350KB+. If this becomes an issue in practice, we can periodically flush `$wpdb->queries` (e.g., after every 100 queries) inside the `log_query_custom_data` handler since we've already captured the span data.

**Known limitation: `SAVEQUERIES` already defined as `false`.** If a hosting environment or `wp-config.php` explicitly sets `define('SAVEQUERIES', false)`, our `if (!defined('SAVEQUERIES'))` check won't trigger (it IS defined), and `log_query_custom_data` never fires. GraphQL requests silently lose DB query instrumentation in this scenario. This is an uncommon edge case — most sites either don't define `SAVEQUERIES` at all or set it to `true` for debugging. The remaining instrumentation (lifecycle phases, HTTP, identity, cache stats) still works. A future enhancement could add a notice in the settings page when this condition is detected.

#### Lightweight Fallback (Tier 2)

For non-WPGraphQL GraphQL implementations:
- Lifecycle phase spans (already captured by existing code)
- HTTP outbound tracking (already captured by `Http` class)
- DB queries via `log_query_custom_data` (same as Tier 1)
- Identity tracking: user ID, IP, user agent (already captured in `wp_flame_shutdown`)
- Object cache stats (already captured in `wp_flame_shutdown`)

No resolver-level spans — no hooks to attach to.

### Score Handling

**Both Tiers 1 & 2:** Use `Score::calculate()` (the full algorithm), not `calculate_from_basic()`.

Tier 1 traces have all 5 factors: response time, HTTP spans, DB spans (via `log_query_custom_data`), DB time ratio, and slow resolvers (resolver spans with `meta['hook']` matching the existing slow callback detection).

Tier 2 traces have 4 of 5 factors: response time, HTTP spans, DB spans, and DB time ratio. Only "slow callbacks/resolvers" is missing — this factor scores 100 automatically (no spans with `meta['hook']`). This inflates only 1 factor (15% weight) rather than 3 factors (45% weight) if we used `calculate_from_basic()`.

### Insights

All 7 existing insight rules work without modification:

| Rule | Tier 1 | Tier 2 |
|------|--------|--------|
| Slow HTTP requests | Works | Works |
| Duplicate DB queries | Works (via log_query_custom_data) | Works |
| High query count | Works | Works |
| Slow callbacks / resolvers | Works (resolver spans have `meta['hook']`) | No data (acceptable) |
| HTTP during early phases | Works | Works |
| No persistent cache | Works | Works |
| Low cache hit ratio | Works | Works |

### Span Type Decision

Resolver spans use `TYPE_PLUGIN` with source `"wpgraphql"` rather than introducing a new `TYPE_GRAPHQL` constant. Rationale:

- Avoids changes to `Span.php`, `Score.php`, `Insights.php`, and the flame graph color mapping
- GraphQL resolvers *are* plugin code (WPGraphQL is a plugin) — `TYPE_PLUGIN` is semantically accurate
- The `source` field (`"wpgraphql"`) and `meta['hook']` prefix (`"graphql:"`) provide sufficient differentiation for any future filtering or grouping needs
- If a dedicated type becomes necessary later, it's a straightforward addition

### Batched GraphQL Requests

WPGraphQL supports batched queries (`[{query: "..."}, {query: "..."}]`). Each query in the batch triggers its own `graphql_process_request` / `graphql_return_response` cycle, so our operation-level span naturally creates one span per query in the batch. The overall request timing (lifecycle phases, total_ms) wraps the entire batch. This is the correct behavior — each operation is a distinct unit of work within the request.

### UI Changes

#### Trace List Type Filter

Add "GraphQL" to the existing type dropdown in `Admin.php`:

```php
'graphql' => '/graphql',  // Default WPGraphQL endpoint
```

Detection by URL pattern, same as existing cron/ajax/rest filters.

#### Flame Graph — Limited Instrumentation Badge

For Tier 2 traces (GraphQL without WPGraphQL), show an info notice on the flame graph view:

```html
<div class="notice notice-info">
    <p>Limited instrumentation — resolver detail requires WPGraphQL.</p>
</div>
```

Detection: check if the trace URL matches `/graphql` but has no resolver spans (no spans with `meta['type_name']`).

Tier 1 traces look identical to normal traces — no special treatment.

### Integration with `wp-flame.php`

The `wp_flame_init()` function changes to a two-phase approach:

**Phase 1 (at `plugins_loaded` priority 0) — existing location:**

```php
// URL-based GraphQL heuristic (GRAPHQL_REQUEST not available yet)
$graphql_endpoint = apply_filters('graphql_endpoint', 'graphql');
$request_path = isset($_SERVER['REQUEST_URI'])
    ? parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
    : '';
$is_likely_graphql = $request_path !== ''
    && ($request_path === '/' . $graphql_endpoint
        || substr($request_path, -strlen('/' . $graphql_endpoint)) === '/' . $graphql_endpoint);

global $wpdb;
$graphql_inst = null;

if ($is_likely_graphql) {
    // Enable query logging for GraphQL DB capture
    if (!defined('SAVEQUERIES')) {
        define('SAVEQUERIES', true);
    }
    // Instantiate GraphQL instrumentation (DB hooks register immediately)
    $graphql_inst = new WPFlame\GraphQL($collector);
    // Skip callback wrapping for now — confirmed or recovered at init:0
    $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
} else {
    // Normal: existing DB replacement + callback wrapping
    if (WPFlame\DB::can_replace($wpdb)) {
        $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb($wpdb, $collector);
    }
    $GLOBALS['wp_flame_skip_callback_wrapping'] = false;
    // ... existing callback wrapping (Pass 1 at plugins_loaded:1) ...
}
```

**Callback wrapping registration (at `plugins_loaded` priority 0, always):**

Both Pass 1 and Pass 2 are always registered but guarded by the flag:

```php
$min_callback_ms = (float) get_option('wp_flame_min_callback_ms', 0.5);

// Pass 1: wrap callbacks registered before plugins_loaded
add_action('plugins_loaded', function () use ($collector, $min_callback_ms) {
    if (!empty($GLOBALS['wp_flame_skip_callback_wrapping'])) {
        return;
    }
    wp_flame_wrap_callbacks($collector, $min_callback_ms);
}, 1);

// Pass 2: wrap callbacks registered between plugins_loaded and init
add_action('init', function () use ($collector, $min_callback_ms) {
    if (!empty($GLOBALS['wp_flame_skip_callback_wrapping'])) {
        return;
    }
    wp_flame_wrap_callbacks($collector, $min_callback_ms);
}, 1);
```

**Phase 2 (at `init` priority 0) — new:**

```php
add_action('init', function () use ($collector, &$graphql_inst, $is_likely_graphql) {
    if (!$is_likely_graphql) {
        return; // Normal request — nothing to do at init:0
    }

    $confirmed = defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST;
    $has_wpgraphql = function_exists('graphql');

    if ($confirmed && $has_wpgraphql) {
        // Tier 1: activate WPGraphQL resolver hooks
        $graphql_inst->activate_wpgraphql_hooks();
    } elseif ($confirmed) {
        // Tier 2: DB hooks already active, nothing more to do
    } else {
        // False positive: deactivate GraphQL hooks, start normal instrumentation
        $graphql_inst->deactivate();
        $graphql_inst = null;

        global $wpdb;
        if (WPFlame\DB::can_replace($wpdb)) {
            $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb($wpdb, $collector);
        }

        // Allow Pass 2 callback wrapping to run at init:1
        $GLOBALS['wp_flame_skip_callback_wrapping'] = false;
    }
}, 0);
```

Everything else (HTTP instrumentation, template detection, shutdown handler, admin UI, settings) remains unchanged and runs for all request types.

## Files to Create

- `src/GraphQL.php` — GraphQL instrumentation class

## Files to Modify

- `wp-flame.php` — replace `$is_graphql` skip-guards with two-phase tiered dispatch
- `src/Collector.php` — add `add_completed_span()` method for pre-computed timing with parent-child support
- `src/Admin.php` — add "GraphQL" to type filter dropdown
- `src/Admin.php` — add limited-instrumentation badge on flame graph view for Tier 2 traces

## Testing

### Unit Tests

- `GraphQL::__construct()` registers DB hooks immediately, resolver hooks only when `activate_wpgraphql_hooks()` is called
- `GraphQL::deactivate()` removes `log_query_custom_data` hook (no more DB spans after deactivation)
- Root field detection: only `RootQuery`/`RootMutation`/`RootSubscription` type names trigger spans
- Aliased fields: two spans for the same field key resolve correctly via stack
- `graphql_pre_resolve_field` always returns `$default` unchanged, even on error
- `graphql_resolve_field` always returns `$result` unchanged, even on error
- DB spans created via `log_query_custom_data` with correct pre-computed timing and source
- DB spans have correct parent_id (resolver span in Tier 1, lifecycle span in Tier 2)
- DB query text uses cached `full_query_text` option (no `get_option()` per query)
- Operation-level span captures operation name and type
- Operation span ends cleanly even if no fields resolve (validation error)
- Tier 2 mode: no resolver hooks registered, DB hooks still active
- Score calculation works correctly with resolver spans as "slow callback" proxies
- `Collector::add_completed_span()` creates spans with specified timing and correct parent_id from stack
- False-positive detection: `deactivate()` called, normal instrumentation starts, no double DB spans
- False-positive: callback wrapping flag cleared so Pass 2 runs at init:1
- URL heuristic: `/graphql` matches, `/my-page/graphql-tools` does not, `/wp/graphql` matches

### Integration Verification

- Normal (non-GraphQL) requests: behavior completely unchanged
- WPGraphQL request without Stellate: full flame graph with resolver spans and DB queries
- GraphQL request without WPGraphQL: lifecycle + HTTP + DB spans, no resolvers, info badge shown
- Stellate site: no 500 error, traces captured with full data
- Batched GraphQL requests: one operation span per query in the batch
- False positive URL match: normal instrumentation resumes cleanly (no double counting)
- SAVEQUERIES already false: GraphQL trace captured without DB spans (no errors)

## Non-Goals

- Full resolver tree depth (every nested field) — root fields only by default
- Custom GraphQL implementations beyond WPGraphQL for Tier 1
- GraphQL subscription/websocket instrumentation
- Query complexity scoring (future work)
- Dedicated `TYPE_GRAPHQL` span type (see Span Type Decision section for rationale)
