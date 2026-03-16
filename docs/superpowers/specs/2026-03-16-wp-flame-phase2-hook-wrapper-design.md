# WP Flame Phase 2.1 Design Spec: WP_Hook Wrapper

## Overview

Add per-callback instrumentation to the flame graph by extending `WP_Hook` and wrapping each callback with span timing. This transforms the flame graph from showing only lifecycle phases and DB queries to showing individual plugin/theme callback execution within each hook — e.g., "Yoast's `wpseo_head` callback took 22ms within the `wp_head` action."

## Constraints

- Same as Phase 1: PHP 7.4+, WordPress 6.0+, full TDD
- Must not break existing Phase 1 functionality
- Must not crash the site if a callback is invalid or reflection fails
- Overhead target: <0.1ms total for below-threshold callbacks on a typical page (~500 callbacks)

## Component Designs

### Hook Class (`src/Hook.php`)

**Class:** `WPFlame\Hook` extends `\WP_Hook`

Overrides `apply_filters()` to wrap each callback invocation with span timing. The override copies WordPress's `WP_Hook::apply_filters()` implementation and adds `start_span()`/`end_span_filtered()` around the `call_user_func_array()` call. This is the same approach used by Query Monitor — proven on millions of sites since WordPress 4.7.

**Constructor properties (set via factory):**
- `private Collector $collector`
- `private string $hook_name` — the hook this instance handles (e.g., `init`, `wp_head`)
- `private float $min_duration_ms` — threshold below which spans are discarded (default 0.5)

**Factory:** `static from_wp_hook(\WP_Hook $original, string $hook_name, Collector $collector, float $min_ms): self`
- Creates a new `Hook` instance
- Copies all public properties from the original: `callbacks`, `iterations`, `current_priority`, `nesting_level`, `doing_action`
- Stores hook name, Collector reference, and threshold
- Returns the replacement instance

**`apply_filters($value, $args)` override:**

Copies the WordPress `WP_Hook::apply_filters()` implementation verbatim, adding timing around the `call_user_func_array()` call:

```php
// Before the callback:
$callback_id = $id; // WordPress's internal callback array key
$span_name = $this->get_callback_name($callback_id, $the_['function']);
$span_source = $this->get_callback_source($callback_id, $the_['function']);
$span_id = $this->collector->start_span(
    $span_name,
    $span_source['type'],
    $span_source['source'],
    ['hook' => $this->hook_name, 'priority' => $priority]
);

// Original callback invocation:
$value = call_user_func_array($the_['function'], $args);

// After the callback:
$this->collector->end_span_filtered($span_id, $this->min_duration_ms);
```

The `start_span()`/`end_span_filtered()` pattern ensures correct parent/child nesting. Any DB queries triggered inside the callback become children of the callback's span on the Collector's stack.

**Callback name resolution:** `private get_callback_name(string $callback_id, $callback): string`

Resolves a human-readable name from the callback. Cached in `private static array $name_cache` keyed by `$callback_id`.

| Callback type | Detection | Name format |
|---|---|---|
| Named function | `is_string($callback) && function_exists($callback)` | `function_name` |
| Array instance method | `is_array($callback) && is_object($callback[0])` | `ClassName::method` |
| Array static method | `is_array($callback) && is_string($callback[0])` | `ClassName::method` |
| String static method | `is_string($callback) && strpos($callback, '::') !== false` | `ClassName::method` |
| Closure | `$callback instanceof \Closure` | `{relative_filepath}:{line}` |
| Invocable object | `is_object($callback) && method_exists($callback, '__invoke')` | `ClassName::__invoke` |
| Invalid/unknown | Reflection throws or none of the above | `$callback_id` (fallback) |

All reflection is wrapped in `try/catch (\ReflectionException $e)`. Failures fall back to the WordPress callback ID string.

For closures, the filepath is made relative to `WP_PLUGIN_DIR`, `get_template_directory()`, or `ABSPATH` for readability.

**Callback source resolution:** `private get_callback_source(string $callback_id, $callback): array`

Returns `['type' => Span::TYPE_*, 'source' => '...']`. Uses the same reflection to get the filename, then delegates to `$this->collector->get_source_from_file($filename)` which handles caching internally.

Cached in `private static array $source_cache` keyed by `$callback_id`. On reflection failure, returns `['type' => Span::TYPE_PHP, 'source' => 'unknown']`.

**Two-level caching:** `Hook::$source_cache` is keyed by WordPress callback ID (from `_wp_filter_build_unique_id()` — avoids re-running reflection). `Collector::$source_cache` is keyed by file path (avoids re-running path matching). These serve different key spaces and both are needed. Both caches are `private static` arrays that live for the PHP process lifetime — this is correct for standard PHP-FPM where each request is a new process. Persistent runtimes (FrankenPHP, Swoole) would need a `reset()` mechanism, which is out of scope for Phase 2.

**Callback ID format:** WordPress generates callback IDs via `_wp_filter_build_unique_id()`. For named functions: the function name string. For methods: `spl_object_hash($object) . method_name`. For closures: `spl_object_hash($closure)`. The ID is unique per callback registration, not per closure object — the same closure registered on two hooks gets different IDs. This is correct for cache key purposes.

### Collector Changes (`src/Collector.php`)

Two additions. Existing method signatures and behaviour are unchanged, but `start_span()` gains one additional field in its stack entry.

**1. `span_count_at_start` in stack entries:**

`start_span()` adds `'span_count_at_start' => count($this->spans)` to the lightweight associative array pushed onto `$span_stack`. The updated push block:

```php
$this->span_stack[] = [
    'id'                  => $id,
    'name'                => $name,
    'type'                => $type,
    'source'              => $source,
    'start_ms'            => $start_ms,
    'meta'                => $meta,
    'parent_id'           => $parent_id,
    'span_count_at_start' => count($this->spans),
];
```

This tracks how many completed spans existed when this span was opened. Used by `end_span_filtered()` to detect whether child spans were created during this span's lifetime.

**2. New method: `end_span_filtered(?string $span_id, float $min_ms): void`**

Must return early (no-op) if `$this->stopped` is true or `$this->span_stack` is empty — matching the guard already present in `end_span()`.

Same stack-popping behavior as `end_span()`:
- Pops the top entry from `$span_stack`
- Validates `$span_id` if provided (logs warning on mismatch)
- Calculates `$duration_ms`

Conditional retention:
- If `$duration_ms >= $min_ms` → create Span, add to `$this->spans` (same as `end_span`)
- If `$duration_ms < $min_ms` AND `count($this->spans) > $entry['span_count_at_start']` → child spans were created during this callback, so keep the parent span to preserve tree structure
- If `$duration_ms < $min_ms` AND no children → discard (don't add to `$this->spans`)

The stack is always popped regardless of whether the span is kept, so nesting remains correct for subsequent spans.

### Hook Replacement (`wp-flame.php`)

**Two replacement passes** in `wp_flame_init()`:

**Pass 1: At `plugins_loaded` priority 0** (inside existing `wp_flame_init`):
```php
wp_flame_replace_hooks($collector, $min_callback_ms);
```

**Pass 2: At `init` priority 0** (new hook registration):
```php
add_action('init', function() use ($collector, $min_callback_ms) {
    wp_flame_replace_hooks($collector, $min_callback_ms);
}, 0);
```

**`wp_flame_replace_hooks(Collector $collector, float $min_ms): void`**

Iterates `$GLOBALS['wp_filter']` and replaces each `WP_Hook` instance that isn't already a `Hook` instance:

```php
foreach ($GLOBALS['wp_filter'] as $hook_name => $hook_instance) {
    if ($hook_instance instanceof \WP_Hook && !($hook_instance instanceof \WPFlame\Hook)) {
        $GLOBALS['wp_filter'][$hook_name] = Hook::from_wp_hook(
            $hook_instance, $hook_name, $collector, $min_ms
        );
    }
}
```

The `instanceof` check makes the function idempotent — safe to call multiple times. `foreach` iterates a copy of the array; hooks added during iteration are intentionally deferred to the next pass.

**Known limitation:** Hooks first registered between `plugins_loaded` priority 1 and the completion of Pass 1 may not be caught by either pass. In practice this window is negligible — the replacement loop completes in microseconds. Hooks registered during `after_setup_theme` or `init` priority > 0 callbacks are caught by Pass 2.

**`do_action()` note:** `WP_Hook::do_action()` calls `apply_filters()` internally, so no separate override is needed for action hooks. Our `apply_filters()` override instruments both filters and actions.

### Settings

One new option added to activation defaults:

- `wp_flame_min_callback_ms` — float, default 0.5

Read once in `wp_flame_init()`:
```php
$min_callback_ms = (float) get_option('wp_flame_min_callback_ms', 0.5);
```

Added to activation defaults in `wp_flame_activate()`. Cleaned up by `uninstall.php` — the existing `DELETE WHERE option_name LIKE 'wp\_flame\_%'` wildcard already covers this option.

## File Changes

**New files:**
- `src/Hook.php` — the WP_Hook wrapper class
- `tests/Unit/HookTest.php` — unit tests for callback name/source resolution

**Modified files:**
- `src/Collector.php` — add `end_span_filtered()`, add `span_count_at_start` to stack entries
- `wp-flame.php` — add `wp_flame_replace_hooks()` function, call it at `plugins_loaded` and `init`
- `tests/Unit/CollectorTest.php` — add tests for `end_span_filtered()`

## Testing Strategy

**Unit tests for `Collector::end_span_filtered()`:**
- Span above threshold → kept in trace
- Span below threshold, no children → discarded from trace
- Span below threshold, has child spans → kept to preserve tree
- Stack nesting preserved correctly after discard
- Discarded span's parent_id doesn't affect sibling spans

**Unit tests for callback name resolution (`HookTest`):**
- Named function → `'function_name'`
- Array instance method `[$obj, 'method']` → `'ClassName::method'`
- Array static method `['Class', 'method']` → `'Class::method'`
- String static method `'Class::method'` → `'Class::method'`
- Closure → `'{file}:{line}'`
- Invocable object with `__invoke` → `'ClassName::__invoke'`
- Invalid callable → falls back to callback ID string
- Second call returns cached result

**Manual integration testing against wp-env:**
- Activate plugin, browse frontend page, verify flame graph shows per-callback spans nested inside lifecycle phases
- Verify DB queries are nested inside callback spans (not just lifecycle phases)
- Verify sub-threshold callbacks don't appear in trace
- Verify callbacks with child spans (DB queries) below threshold still appear
- Verify `remove_filter()` still works during hook execution
- Verify no fatal errors on pages with many plugins

## Performance Budget

- Per-callback overhead (below threshold, cached): 2x `microtime()` + name/source cache lookup + 1 float subtraction + 1 comparison = ~0.5 microseconds
- Per-callback overhead (first call, any threshold): + reflection for name/source resolution = ~2-5 microseconds (amortized once per unique callback ID across the request)
- Per-callback overhead (above threshold, cached): + span creation = ~1 microsecond additional
- 500 callbacks on a typical page, ~300 unique callback IDs: ~0.15ms first-call reflection + ~0.25ms cached overhead = ~0.4ms total
- Well within the <5ms overhead budget

## Risks

| Risk | Mitigation |
|---|---|
| WordPress updates `WP_Hook::apply_filters()` internals | Low frequency (unchanged since 4.7/2016). Monitor WP releases. Override is a direct copy with minimal additions. |
| Plugin calls `remove_filter()` during hook execution | WordPress handles this via `$this->iterations` tracking. Our override preserves this logic. |
| Reflection throws on invalid callable | try/catch with fallback to callback ID. Site continues working. |
| Another plugin also extends WP_Hook | `instanceof` check in replacement loop. If instance is already a custom subclass, skip it (don't replace). |

## Relationship to Phase 1

This spec builds on the Phase 1 implementation. It adds one new class (`Hook`), modifies one existing class (`Collector`), and updates the bootstrap (`wp-flame.php`). All Phase 1 functionality continues to work unchanged. The `end_span_filtered()` method is additive — existing code uses `end_span()` which is unmodified.
