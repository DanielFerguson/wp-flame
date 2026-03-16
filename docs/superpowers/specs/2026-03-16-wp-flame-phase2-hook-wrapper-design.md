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

### Collector Changes (`src/Collector.php`)

Two additions. No changes to existing methods.

**1. `span_count_at_start` in stack entries:**

`start_span()` now stores `'span_count_at_start' => count($this->spans)` in the lightweight stack entry. This tracks how many completed spans existed when this span was opened. Used by `end_span_filtered()` to detect whether child spans were created during this span's lifetime.

**2. New method: `end_span_filtered(?string $span_id, float $min_ms): void`**

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

The `instanceof` check makes the function idempotent — safe to call multiple times.

### Settings

One new option added to activation defaults:

- `wp_flame_min_callback_ms` — float, default 0.5

Read once in `wp_flame_init()`:
```php
$min_callback_ms = (float) get_option('wp_flame_min_callback_ms', 0.5);
```

## File Changes

**New files:**
- `src/Hook.php` — the WP_Hook wrapper class
- `tests/Unit/HookCallbackResolverTest.php` — unit tests for callback name/source resolution

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

**Unit tests for callback name resolution (`HookCallbackResolverTest`):**
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

- Per-callback overhead (below threshold): 2x `microtime()` + 1 float subtraction + 1 comparison = ~0.1 microseconds
- Per-callback overhead (above threshold): + reflection (first time only, cached after) + span creation = ~5 microseconds first call, ~1 microsecond cached
- 500 callbacks on a typical page, 90% below threshold: ~0.05ms + ~0.05ms = ~0.1ms total
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
