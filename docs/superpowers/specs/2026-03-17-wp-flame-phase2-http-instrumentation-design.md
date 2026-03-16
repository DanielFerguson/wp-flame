# WP Flame Phase 2.2 Design Spec: HTTP Instrumentation

## Overview

Instrument WordPress external HTTP requests so they appear as red spans in the flame graph. External HTTP calls (license checks, API calls, webhook sends) are often the #1 performance killer on WordPress sites, frequently blocking for 500ms+.

## Approach

Hook `pre_http_request` (before) and `http_response` (after) to wrap each external HTTP call with a span. The span captures URL, HTTP method, response code, and duration. Source attribution via backtrace identifies which plugin triggered the request.

## Component Designs

### Http Class (`src/Http.php`)

**Class:** `WPFlame\Http` — instance class, receives Collector.

**Constructor:** `__construct(Collector $collector)`

Registers two hooks on construction:
- `add_filter('pre_http_request', [$this, 'on_pre_request'], 1, 3)` — priority 1 to run early
- `add_filter('http_response', [$this, 'on_response'], 9999, 3)` — priority 9999 to run late (after the request completes)

**Instance properties:**
- `private Collector $collector`
- `private array $pending_spans = []` — maps request key → span ID for in-flight requests

**`on_pre_request($preempt, $parsed_args, $url): mixed`**

This is a filter — must return `$preempt` unchanged (returning a non-false value would short-circuit the actual HTTP request).

```php
public function on_pre_request($preempt, $parsed_args, $url)
{
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? 'unknown';

    $span_id = $this->collector->start_span(
        'HTTP ' . $host,
        Span::TYPE_HTTP,
        $this->get_caller_source(),
        [
            'url'    => $url,
            'method' => strtoupper($parsed_args['method'] ?? 'GET'),
        ]
    );

    // Key by URL + method to handle concurrent tracking
    $key = md5($url . ($parsed_args['method'] ?? 'GET'));
    $this->pending_spans[$key] = $span_id;

    return $preempt;
}
```

**`on_response($response, $parsed_args, $url): mixed`**

This is a filter — must return `$response` unchanged.

```php
public function on_response($response, $parsed_args, $url)
{
    $key = md5($url . ($parsed_args['method'] ?? 'GET'));

    if (!isset($this->pending_spans[$key])) {
        return $response;
    }

    $span_id = $this->pending_spans[$key];
    unset($this->pending_spans[$key]);

    // Add response metadata before closing the span
    $status_code = wp_remote_retrieve_response_code($response);
    $this->collector->add_span_meta($span_id, [
        'status' => (int) $status_code,
    ]);

    $this->collector->end_span($span_id);

    return $response;
}
```

**`get_caller_source(): string`**

Same backtrace pattern as `DB::get_caller_source()` — walks `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)` to find the first file outside `wp-includes/`, `wp-admin/`, and the wp-flame plugin directory. Delegates to `$this->collector->get_source_from_file()`.

**Edge cases:**
- If `pre_http_request` is short-circuited by another plugin (returns a non-false value), `http_response` still fires with the cached response. The span will have a very short duration reflecting the cache hit, which is correct.
- If `http_response` never fires (fatal error during request, timeout handled elsewhere), the span stays open on the stack and gets closed by the shutdown safety net with `auto_closed` meta.

### Collector Changes (`src/Collector.php`)

**New method: `add_span_meta(string $span_id, array $additional_meta): void`**

Searches the span stack (from top) for the entry matching `$span_id` and merges additional metadata into its `meta` array. No-op if the span is not found on the stack (already completed or Collector is stopped).

```php
public function add_span_meta(string $span_id, array $additional_meta): void
{
    if ($this->stopped) {
        return;
    }

    for ($i = count($this->span_stack) - 1; $i >= 0; $i--) {
        if ($this->span_stack[$i]['id'] === $span_id) {
            $this->span_stack[$i]['meta'] = array_merge(
                $this->span_stack[$i]['meta'],
                $additional_meta
            );
            break;
        }
    }
}
```

This method is useful beyond HTTP — template parts and future instrumentation can use it to add metadata after span creation.

### Bootstrap Changes (`wp-flame.php`)

In `wp_flame_init()`, after the DB replacement block:

```php
// HTTP request instrumentation
new WPFlame\Http($collector);
```

The Http constructor registers its hooks, so no additional setup is needed.

## File Changes

**New files:**
- `src/Http.php` — HTTP request instrumentation
- `tests/Unit/HttpTest.php` — unit tests for span creation logic

**Modified files:**
- `src/Collector.php` — add `add_span_meta()`
- `tests/Unit/CollectorTest.php` — test for `add_span_meta()`
- `wp-flame.php` — instantiate Http in `wp_flame_init()`

## Testing Strategy

**Unit tests for `Collector::add_span_meta()` (`CollectorTest`):**
- Meta is merged into the correct span on the stack
- No-op when Collector is stopped
- No-op when span_id is not found on stack

**Unit tests for `Http` (`HttpTest`):**
- `on_pre_request` creates a span with TYPE_HTTP and returns $preempt unchanged
- `on_response` ends the span and returns $response unchanged
- Span meta includes url, method, and status after response
- Unmatched response (no prior pre_request) is handled gracefully

**Manual integration testing:**
- Install a plugin that makes HTTP calls (or trigger WordPress update check)
- Verify red HTTP spans appear in flame graph
- Verify tooltip shows URL, method, and response code

## Flame Graph

HTTP spans already render as red (`#ea4335`) — the color mapping for `Span::TYPE_HTTP` was defined in Phase 1's `flame-graph.js`. No JS changes needed.

## Relationship to Phase 1 and 2.1

Additive — one new class, one new Collector method. No changes to existing behavior. HTTP spans nest correctly inside lifecycle phases and callback spans via the existing Collector stack.
