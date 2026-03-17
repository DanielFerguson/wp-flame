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

In `wp_flame_init()`, after collector initialization:

```php
$is_graphql = defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST;
$has_wpgraphql = function_exists('graphql');

if ($is_graphql && $has_wpgraphql) {
    // Tier 1: WPGraphQL native instrumentation
} elseif ($is_graphql) {
    // Tier 2: Lightweight fallback
} else {
    // Tier 3: Normal request (existing code)
}
```

`GRAPHQL_REQUEST` is defined by WPGraphQL and commonly adopted by other GraphQL implementations. `function_exists('graphql')` confirms WPGraphQL specifically (its main API function).

### New File: `src/GraphQL.php`

A single class encapsulating all GraphQL-specific instrumentation, following the same pattern as `Http.php`.

```php
namespace WPFlame;

class GraphQL {
    private Collector $collector;
    private array $resolver_spans = [];

    public function __construct(Collector $collector) {
        $this->collector = $collector;
        $this->register_hooks();
    }
}
```

#### WPGraphQL Lifecycle Hooks (Tier 1)

**Operation-level span:**

Hook `graphql_execute` (action, fires during execution) to capture the operation name and query string. A top-level "GraphQL: {operationName}" span wraps the entire execution.

Since `graphql_execute` fires during execution (not before/after), we instead use:
- Start the operation span when the first `graphql_pre_resolve_field` fires (or on `graphql_process_request` if available)
- End the operation span on `graphql_return_response`

**Root field resolver spans:**

WPGraphQL fires two filters per field resolution:

- `graphql_pre_resolve_field` — 9 args: `$default, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver`
- `graphql_resolve_field` — 9 args: `$result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver`

Root fields are identified by `$type_name` matching `RootQuery`, `RootMutation`, or `RootSubscription` (case-insensitive comparison).

For root fields only (default behavior):
1. `graphql_pre_resolve_field`: start a span named `"{TypeName}.{field_key}"` with type `TYPE_PLUGIN` and source `"wpgraphql"`. Store span ID keyed by `"{type_name}.{field_key}"`. Always return `$default` unchanged.
2. `graphql_resolve_field`: end the matching span. Always return `$result` unchanged.

Span metadata includes:
- `type_name` — the GraphQL type
- `field_key` — the field being resolved
- `operation_type` — query/mutation/subscription

**Operation metadata:**

Extract from `$info` (GraphQL\Type\Definition\ResolveInfo):
- `$info->operation->operation` — query, mutation, or subscription
- `$info->operation->name->value` — operation name (if named)

Store as span meta on the operation-level span.

#### DB Query Capture (Tiers 1 & 2)

Instead of replacing `$wpdb`, use WordPress's built-in query logging:

1. Conditionally enable `SAVEQUERIES` for GraphQL requests (if not already defined):
   ```php
   if (!defined('SAVEQUERIES')) {
       define('SAVEQUERIES', true);
   }
   ```
   This must happen early — in the detection block before any queries run.

2. Hook `log_query_custom_data` filter (fires after each query when SAVEQUERIES is on):
   ```php
   add_filter('log_query_custom_data', function($query_data, $query, $query_time, $query_callstack, $query_start) {
       // Create a DB span with the query text, timing, and source
       // Use CallbackResolver::resolve_source_from_backtrace() for attribution
       return $query_data;
   }, 10, 5);
   ```

This provides the same data as our `$wpdb` replacement (query text, execution time, source attribution) without modifying the `$wpdb` object.

**Note:** `SAVEQUERIES` causes `$wpdb` to store all queries in memory. For typical GraphQL requests (10-50 queries), this is negligible. If memory becomes a concern for very large queries, we can periodically flush `$wpdb->queries`.

#### Lightweight Fallback (Tier 2)

For non-WPGraphQL GraphQL implementations:
- Lifecycle phase spans (already captured by existing code)
- HTTP outbound tracking (already captured by `Http` class)
- DB queries via `log_query_custom_data` (same as Tier 1)
- Identity tracking: user ID, IP, user agent (already captured in `wp_flame_shutdown`)
- Object cache stats (already captured in `wp_flame_shutdown`)

No resolver-level spans — no hooks to attach to.

### Score Handling

**Tier 1:** Same 5-factor weighted algorithm. The "Slow Callbacks" factor (15%) is reinterpreted as "Slow Resolvers" — counts root-field resolver spans exceeding the configured threshold (default 50ms). No changes to `Score::calculate()` needed; it already counts spans by type. GraphQL resolver spans use `TYPE_PLUGIN` with source `"wpgraphql"`, and we add `meta['hook']` to match the existing slow callback detection pattern.

**Tier 2:** Use existing `Score::calculate_from_basic()` fallback, which handles partial data gracefully (assumes excellent scores for missing factors).

### Insights

All 7 existing insight rules work without modification:

| Rule | Tier 1 | Tier 2 |
|------|--------|--------|
| Slow HTTP requests | Works | Works |
| Duplicate DB queries | Works (via log_query_custom_data) | Works |
| High query count | Works | Works |
| Slow callbacks / resolvers | Works (resolver spans have `meta['hook']`) | No data |
| HTTP during early phases | Works | Works |
| No persistent cache | Works | Works |
| Low cache hit ratio | Works | Works |

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

The `wp_flame_init()` function changes from:

```php
// Current: skip everything for GraphQL
$is_graphql = defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST;
global $wpdb;
if (!$is_graphql && WPFlame\DB::can_replace($wpdb)) { ... }
// ...
if (!$is_graphql) { /* callback wrapping */ }
```

To:

```php
$is_graphql = defined('GRAPHQL_REQUEST') && GRAPHQL_REQUEST;
global $wpdb;

if ($is_graphql) {
    // GraphQL: use native instrumentation (no $wpdb replace, no callback wrapping)
    new WPFlame\GraphQL($collector, function_exists('graphql'));
} else {
    // Normal: existing DB replacement + callback wrapping
    if (WPFlame\DB::can_replace($wpdb)) {
        $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb($wpdb, $collector);
    }
    // ... existing callback wrapping code ...
}
```

Everything else (HTTP instrumentation, template detection, shutdown handler, admin UI, settings) remains unchanged and runs for all request types.

## Files to Create

- `src/GraphQL.php` — GraphQL instrumentation class

## Files to Modify

- `wp-flame.php` — replace `$is_graphql` skip-guards with tiered dispatch to `GraphQL` class
- `src/Admin.php` — add "GraphQL" to type filter dropdown
- `src/Admin.php` — add limited-instrumentation badge on flame graph view for Tier 2 traces

## Testing

### Unit Tests

- `GraphQL::__construct()` registers correct hooks based on tier
- Root field detection: only `RootQuery`/`RootMutation`/`RootSubscription` type names trigger spans
- `graphql_pre_resolve_field` always returns `$default` unchanged
- `graphql_resolve_field` always returns `$result` unchanged
- DB spans created via `log_query_custom_data` with correct timing and source
- Operation-level span captures operation name and type
- Tier 2 mode: no resolver hooks registered, DB hooks still active
- Score calculation works correctly with resolver spans as "slow callback" proxies

### Integration Verification

- Normal (non-GraphQL) requests: behavior completely unchanged
- WPGraphQL request without Stellate: full flame graph with resolver spans and DB queries
- GraphQL request without WPGraphQL: lifecycle + HTTP + DB spans, no resolvers, info badge shown
- Stellate site: no 500 error, traces captured with full data

## Non-Goals

- Full resolver tree depth (every nested field) — root fields only by default
- Custom GraphQL implementations beyond WPGraphQL for Tier 1
- GraphQL subscription/websocket instrumentation
- Query complexity scoring (future work)
