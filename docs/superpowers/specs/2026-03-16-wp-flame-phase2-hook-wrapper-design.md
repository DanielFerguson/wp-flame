# WP Flame Phase 2.1 Design Spec: Per-Callback Instrumentation

## Overview

Add per-callback instrumentation to the flame graph by wrapping individual callback functions registered on WordPress hooks. This transforms the flame graph from showing only lifecycle phases and DB queries to showing individual plugin/theme callback execution within each hook — e.g., "Yoast's `wpseo_head` callback took 22ms within the `wp_head` action."

## Critical Design Constraint

**`WP_Hook` is `final` in WordPress 6.7+.** It cannot be extended. Its `iterations`, `current_priority`, and `nesting_level` properties are `private` — inaccessible to subclasses even if `final` were removed.

**Approach: wrap callbacks in-place.** Instead of replacing `WP_Hook` instances, we iterate the `callbacks` public property and replace each callback's `function` entry with an invocable `CallbackWrapper` object. The `WP_Hook` instance stays completely untouched — it handles all iteration, nesting, and priority logic. We only touch the leaf-level callback functions.

**Why `remove_filter()` and `has_filter()` still work:** Both functions look up callbacks by the array key generated from `_wp_filter_build_unique_id()`. This key is set once at `add_filter()` time and stored as the array key in `$hook->callbacks[$priority][$key]`. Our wrapping changes only the `function` value inside the entry — the key is untouched. When `remove_filter('init', [$obj, 'method'])` is called, WordPress re-derives the key from `[$obj, 'method']` and looks it up — it matches because the key was set from the same original callback. The wrapped `CallbackWrapper` object is never used for key generation.

## Constraints

- Same as Phase 1: PHP 7.4+, WordPress 6.0+, full TDD
- Must not break existing Phase 1 functionality
- Must not crash the site if a callback is invalid or reflection fails
- Overhead target: <0.5ms total for all callback wrapping on a typical page (~500 callbacks)

## Component Designs

### CallbackWrapper (`src/CallbackWrapper.php`)

**Class:** `WPFlame\CallbackWrapper` — an invocable object that wraps a single WordPress hook callback with span timing.

**Constructor:**
```php
public function __construct(
    $original,              // The original callback (any callable)
    Collector $collector,
    string $hook_name,
    int $priority,
    string $span_name,      // Pre-resolved callback name
    array $span_source,     // Pre-resolved ['type' => ..., 'source' => ...]
    float $min_duration_ms
)
```

Name and source resolution happen at wrapping time (not at invocation time) — so the reflection cost is paid once during the replacement pass, not on every callback invocation.

**`__invoke(...$args)`:**
```php
public function __invoke(...$args)
{
    $span_id = $this->collector->start_span(
        $this->span_name,
        $this->span_source['type'],
        $this->span_source['source'],
        ['hook' => $this->hook_name, 'priority' => $this->priority]
    );

    try {
        $result = call_user_func_array($this->original, $args);
    } finally {
        $this->collector->end_span_filtered($span_id, $this->min_duration_ms);
    }

    return $result;
}
```

Uses variadic `...$args` (PHP 7.4+ compatible) instead of `func_get_args()`. The `try/finally` ensures the span is always closed even if the original callback throws — the exception propagates normally but the Collector's span stack stays consistent.

WP_Hook handles `accepted_args` slicing before calling our wrapper — we receive only the args intended for this callback and pass them through to the original.

**`get_original()`** — public method returning the original callback. Useful for debugging and for detecting already-wrapped callbacks.

### CallbackResolver (`src/CallbackResolver.php`)

**Class:** `WPFlame\CallbackResolver` — static utility class for resolving callback names and sources. Separated from CallbackWrapper to keep the wrapper lightweight and make the resolver independently testable.

**`static resolve_name(string $callback_id, $callback): string`**

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

**`static resolve_source(string $callback_id, $callback, Collector $collector): array`**

Returns `['type' => Span::TYPE_*, 'source' => '...']`. Uses reflection to get the filename, then delegates to `$collector->get_source_from_file($filename)` which handles file-path-level caching internally.

Cached in `private static array $source_cache` keyed by `$callback_id`. On reflection failure, returns `['type' => Span::TYPE_PHP, 'source' => 'unknown']`.

**Two-level caching:** `CallbackResolver::$source_cache` is keyed by WordPress callback ID (avoids re-running reflection). `Collector::$source_cache` is keyed by file path (avoids re-running path matching). These serve different key spaces and both are needed.

**Callback ID format:** WordPress generates callback IDs via `_wp_filter_build_unique_id()`. For named functions: the function name string. For methods: `spl_object_hash($object) . method_name`. For closures: `spl_object_hash($closure)`. The ID is unique per callback registration. Both caches are per-request (PHP process lifetime).

**`static reset(): void`** — clears `CallbackResolver::$name_cache` and `CallbackResolver::$source_cache` only. Does NOT clear `Collector::$source_cache` — that is independently managed by `Collector::reset()`. Called by `Collector::reset()` for test isolation.

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
- If `$duration_ms < $min_ms` AND `count($this->spans) > $entry['span_count_at_start']` → child spans were *retained* during this callback (i.e., they were above threshold or had their own children), so keep the parent span to preserve tree structure
- If `$duration_ms < $min_ms` AND no retained children → discard (don't add to `$this->spans`). Note: if a child span was started and discarded by its own `end_span_filtered()` call, it does not count — "child" means a span that is actually in `$this->spans`.

The stack is always popped regardless of whether the span is kept, so nesting remains correct for subsequent spans.

**3. Update `reset()` to also call `CallbackResolver::reset()`** — ensures test isolation for the resolver's static caches.

### Callback Wrapping (`wp-flame.php`)

**Two wrapping passes:**

**Pass 1: At `plugins_loaded` priority 1** (registered inside `wp_flame_init`, fires after all priority-0 callbacks including `wp_flame_init` itself):
```php
add_action('plugins_loaded', function() use ($collector, $min_callback_ms) {
    wp_flame_wrap_callbacks($collector, $min_callback_ms);
}, 1);
```

**Pass 2: At `init` priority 1** (fires after WP Flame's own phase-transition callback at priority 0):
```php
add_action('init', function() use ($collector, $min_callback_ms) {
    wp_flame_wrap_callbacks($collector, $min_callback_ms);
}, 1);
```

**Why priority 1, not 0:** `wp_flame_init` runs at `plugins_loaded` priority 0 and registers phase-transition closures on `init`, `wp`, etc. at priority 0. If the wrapping pass also ran at priority 0, it would wrap WP Flame's own closures — causing self-instrumentation artifacts (WP Flame spans appearing inside callback spans). Running at priority 1 ensures all WP Flame closures are already registered and firing before wrapping begins.

**Self-instrumentation exclusion:** Additionally, `wp_flame_wrap_callbacks()` skips callbacks whose source file is within `WP_FLAME_DIR`:

```php
$source = CallbackResolver::resolve_source($id, $original, $collector);
if (strpos($source['source'], 'wp-flame') === 0 || $source['source'] === 'wordpress-apm-plugin') {
    continue; // Don't wrap our own callbacks
}
```

This provides a belt-and-suspenders defense against instrumenting our own plugin's callbacks.

**`wp_flame_wrap_callbacks(Collector $collector, float $min_ms): void`**

Iterates `$GLOBALS['wp_filter']` and wraps each callback that isn't already wrapped:

```php
function wp_flame_wrap_callbacks(Collector $collector, float $min_ms): void {
    foreach ($GLOBALS['wp_filter'] as $hook_name => $hook_instance) {
        if (! ($hook_instance instanceof \WP_Hook)) {
            continue;
        }

        foreach ($hook_instance->callbacks as $priority => $priority_callbacks) {
            foreach ($priority_callbacks as $id => $the_) {
                // Skip already-wrapped callbacks
                if ($the_['function'] instanceof \WPFlame\CallbackWrapper) {
                    continue;
                }

                $original = $the_['function'];

                $name = CallbackResolver::resolve_name($id, $original);
                $source = CallbackResolver::resolve_source($id, $original, $collector);

                $hook_instance->callbacks[$priority][$id]['function'] = new CallbackWrapper(
                    $original, $collector, $hook_name, (int) $priority,
                    $name, $source, $min_ms
                );
            }
        }
    }
}
```

The `instanceof CallbackWrapper` check makes the function idempotent — safe to call multiple times. `foreach` on the outer array iterates a copy; modifications to inner arrays go directly to the WP_Hook instance via the `$hook_instance` reference.

**Known limitation:** Callbacks registered after both passes complete (e.g., during `init` priority > 0) won't be wrapped. These are typically admin-specific or late-registration callbacks. The majority of performance-critical callbacks are registered by `init` time.

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
- `src/CallbackWrapper.php` — invocable timing wrapper for individual callbacks
- `src/CallbackResolver.php` — static utility for callback name/source resolution
- `tests/Unit/CallbackResolverTest.php` — unit tests for name/source resolution
- `tests/Unit/CallbackWrapperTest.php` — unit tests for the wrapper invocation

**Modified files:**
- `src/Collector.php` — add `end_span_filtered()`, add `span_count_at_start` to stack entries, update `reset()` to clear resolver caches
- `wp-flame.php` — add `wp_flame_wrap_callbacks()` function, call it at `plugins_loaded` and `init`, add `wp_flame_min_callback_ms` to activation defaults
- `tests/Unit/CollectorTest.php` — add tests for `end_span_filtered()`

## Testing Strategy

**Unit tests for `Collector::end_span_filtered()` (`CollectorTest`):**
- Span above threshold → kept in trace
- Span below threshold, no children → discarded from trace
- Span below threshold, has child spans → kept to preserve tree
- Stack nesting preserved correctly after discard
- Stopped collector returns early (no-op)

**Unit tests for `CallbackResolver` (`CallbackResolverTest`):**
- Named function → `'function_name'`
- Array instance method `[$obj, 'method']` → `'ClassName::method'`
- Array static method `['Class', 'method']` → `'Class::method'`
- String static method `'Class::method'` → `'Class::method'`
- Closure → `'{file}:{line}'`
- Invocable object with `__invoke` → `'ClassName::__invoke'`
- Invalid callable → falls back to callback ID string
- Second call returns cached result
- Source resolution delegates to Collector::get_source_from_file()

**Unit tests for `CallbackWrapper` (`CallbackWrapperTest`):**
- Invocation passes args through to original callback
- Return value is passed through from original callback
- Span is created when callback exceeds threshold
- Span is discarded when callback is below threshold
- `get_original()` returns the wrapped callback

**Manual integration testing against wp-env:**
- Browse frontend page, verify flame graph shows per-callback spans nested inside lifecycle phases
- Verify DB queries are nested inside callback spans (not just lifecycle phases)
- Verify sub-threshold callbacks don't appear in trace
- Verify callbacks with child spans (DB queries) below threshold still appear
- Verify `remove_filter()` still works after wrapping
- Verify no fatal errors on pages with many plugins

## Performance Budget

- Per-callback overhead at invocation time (cached): `start_span()` + `call_user_func_array()` + `end_span_filtered()` = ~1-2 microseconds
- Wrapping pass overhead (one-time per pass): reflection for ~300 unique callbacks = ~0.5ms per pass, 2 passes = ~1ms total
- 500 callback invocations during a request: ~0.5-1.0ms total invocation overhead
- Well within the <5ms overhead budget

## Risks

| Risk | Mitigation |
|---|---|
| Wrapped callback changes behaviour subtly | CallbackWrapper passes all args through and returns the result. `accepted_args` handling is done by WP_Hook before our wrapper is called. |
| `remove_filter()` can't find wrapped callbacks | Array key is preserved — only the `function` value changes. `remove_filter` matches by key. |
| `has_filter()` returns wrong result | Same key-based lookup. Works correctly. |
| Reflection throws on invalid callable | try/catch in CallbackResolver with fallback to callback ID. |
| Plugin registers callback after both wrapping passes | Unwrapped callback runs normally (no timing). Not a correctness issue. |
| By-reference args in `do_all_hook` | `do_all_hook` callbacks are not performance-critical. Variadic `...$args` may not preserve references. Accept this limitation. |
| Wrapping during active hook iteration | Pass 1 runs during `plugins_loaded` while WP_Hook is mid-iteration for that hook. Wrapping modifies `function` values inside priority arrays of *other* hooks, not the currently-executing one. For the currently-executing hook (`plugins_loaded`), only higher-priority callbacks not yet fired are affected. PHP's single-threaded model ensures no concurrent modification. Safe in practice. |

## Relationship to Phase 1

This spec builds on the Phase 1 implementation. It adds two new classes (`CallbackWrapper`, `CallbackResolver`), modifies one existing class (`Collector`), and updates the bootstrap (`wp-flame.php`). All Phase 1 functionality continues to work unchanged. The `end_span_filtered()` method is additive — existing code uses `end_span()` which is unmodified.

## Advantages Over Original WP_Hook Extension Approach

1. **Works with `final` WP_Hook** — no class extension needed
2. **Zero WordPress internal code copied** — no maintenance burden when WordPress updates `WP_Hook`
3. **Simpler** — wrapping is a straightforward array manipulation, not a class replacement
4. **`remove_filter()` compatibility** — key-based lookup is unaffected
5. **Name/source resolved at wrap time** — no reflection cost during callback invocation
