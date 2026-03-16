# WP Flame Phase 2.1: Per-Callback Instrumentation — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wrap individual WordPress hook callbacks with span timing so the flame graph shows per-plugin, per-callback granularity inside each lifecycle phase.

**Architecture:** An invocable `CallbackWrapper` object replaces each callback's `function` entry in WP_Hook's public `callbacks` array. A `CallbackResolver` utility resolves human-readable names via reflection (cached). The Collector gains `end_span_filtered()` which discards sub-threshold spans while preserving the parent/child tree. Two wrapping passes at `plugins_loaded` priority 1 and `init` priority 1 cover the majority of registered callbacks.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, PHPUnit, existing WP Flame Phase 1 codebase

**Spec:** `docs/superpowers/specs/2026-03-16-wp-flame-phase2-hook-wrapper-design.md`

---

## Chunk 1: Collector Changes

### Task 1: Add `span_count_at_start` to Collector stack entries

**Files:**
- Modify: `src/Collector.php`
- Modify: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Update `start_span()` stack entry**

In `src/Collector.php`, add `'span_count_at_start' => count($this->spans)` to the array pushed onto `$this->span_stack` in `start_span()`:

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

- [ ] **Step 2: Run existing tests to verify nothing breaks**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 30 tests PASS. The new field is ignored by existing `end_span()` and `close_open_spans()`.

- [ ] **Step 3: Commit**

```bash
git add src/Collector.php
git commit -m "feat: add span_count_at_start to Collector stack entries"
```

---

### Task 2: Add `end_span_filtered()` to Collector

**Files:**
- Modify: `src/Collector.php`
- Modify: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Write failing tests for `end_span_filtered()`**

Append these test methods to `tests/Unit/CollectorTest.php`:

```php
    public function test_end_span_filtered_keeps_span_above_threshold(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Slow callback', Span::TYPE_PLUGIN, 'test');
        usleep(2000); // 2ms
        $collector->end_span_filtered($id, 0.5); // threshold 0.5ms

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertSame('Slow callback', $trace->spans[0]->name);
    }

    public function test_end_span_filtered_discards_span_below_threshold_no_children(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Fast callback', Span::TYPE_PLUGIN, 'test');
        // No usleep — effectively 0ms
        $collector->end_span_filtered($id, 100.0); // threshold 100ms — will be below

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_end_span_filtered_keeps_span_below_threshold_with_children(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $parent_id = $collector->start_span('Parent callback', Span::TYPE_PLUGIN, 'test');

        // Create a child span that gets retained
        $child_id = $collector->start_span('DB Query', Span::TYPE_DB, 'test');
        usleep(2000); // 2ms
        $collector->end_span($child_id); // Regular end_span — always kept

        // Parent is below threshold but has a retained child
        $collector->end_span_filtered($parent_id, 100.0);

        $trace = $collector->get_trace();
        $this->assertCount(2, $trace->spans);

        $names = array_map(fn($s) => $s->name, $trace->spans);
        $this->assertContains('Parent callback', $names);
        $this->assertContains('DB Query', $names);
    }

    public function test_end_span_filtered_preserves_stack_nesting_after_discard(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $outer = $collector->start_span('Outer', Span::TYPE_CORE, 'test');

        // This callback span will be discarded
        $fast = $collector->start_span('Fast', Span::TYPE_PLUGIN, 'test');
        $collector->end_span_filtered($fast, 100.0);

        // This span should still be a child of Outer, not Fast
        $next = $collector->start_span('Next', Span::TYPE_PLUGIN, 'test');
        usleep(1000);
        $collector->end_span($next);

        $collector->end_span($outer);

        $trace = $collector->get_trace();
        $this->assertCount(2, $trace->spans); // Outer + Next (Fast was discarded)

        $spans_by_name = [];
        foreach ($trace->spans as $s) {
            $spans_by_name[$s->name] = $s;
        }

        $this->assertSame($outer, $spans_by_name['Next']->parent_id);
    }

    public function test_end_span_filtered_noop_when_stopped(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Before stop', Span::TYPE_PLUGIN, 'test');
        $collector->stop();
        $collector->end_span_filtered($id, 0.0);

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }
```

- [ ] **Step 2: Run tests to verify new tests fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: 5 new tests FAIL — `end_span_filtered` method does not exist.

- [ ] **Step 3: Implement `end_span_filtered()`**

Add this method to `src/Collector.php` after the existing `end_span()` method:

```php
    /**
     * End a span with a minimum duration threshold.
     * Discards spans below threshold unless they have retained child spans.
     */
    public function end_span_filtered(?string $span_id, float $min_ms): void
    {
        if ($this->stopped || empty($this->span_stack)) {
            return;
        }

        $entry = array_pop($this->span_stack);

        if ($span_id !== null && $entry['id'] !== $span_id) {
            error_log(sprintf(
                'WP Flame: end_span_filtered() ID mismatch — expected "%s", got "%s"',
                $span_id,
                $entry['id']
            ));
        }

        $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];
        $duration_ms = max(0.0, $duration_ms);

        $has_retained_children = count($this->spans) > ($entry['span_count_at_start'] ?? 0);

        if ($duration_ms >= $min_ms || $has_retained_children) {
            $this->spans[] = new Span(
                $entry['id'],
                $entry['parent_id'],
                $entry['name'],
                $entry['type'],
                $entry['source'],
                $entry['start_ms'],
                $duration_ms,
                $entry['meta']
            );
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 35 tests PASS (30 existing + 5 new).

- [ ] **Step 5: Commit**

```bash
git add src/Collector.php tests/Unit/CollectorTest.php
git commit -m "feat: add end_span_filtered() with threshold and child-span detection"
```

---

## Chunk 2: CallbackResolver

### Task 3: CallbackResolver — Name and Source Resolution

**Files:**
- Create: `src/CallbackResolver.php`
- Create: `tests/Unit/CallbackResolverTest.php`

- [ ] **Step 1: Write failing tests for CallbackResolver**

```php
<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CallbackResolver;
use WPFlame\Collector;
use WPFlame\Span;

// Test fixtures
function wp_flame_test_function() { return 'test'; }

class WPFlameTestClass
{
    public function instance_method() { return 'test'; }
    public static function static_method() { return 'test'; }
}

class WPFlameInvocableClass
{
    public function __invoke() { return 'test'; }
}

class CallbackResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CallbackResolver::reset();
        Collector::reset();
    }

    public function test_resolve_name_for_named_function(): void
    {
        $name = CallbackResolver::resolve_name(
            'WPFlame\\Tests\\Unit\\wp_flame_test_function',
            'WPFlame\\Tests\\Unit\\wp_flame_test_function'
        );
        $this->assertSame('WPFlame\\Tests\\Unit\\wp_flame_test_function', $name);
    }

    public function test_resolve_name_for_array_instance_method(): void
    {
        $obj = new WPFlameTestClass();
        $name = CallbackResolver::resolve_name('test-id', [$obj, 'instance_method']);
        $this->assertSame('WPFlameTestClass::instance_method', $name);
    }

    public function test_resolve_name_for_array_static_method(): void
    {
        $name = CallbackResolver::resolve_name('test-id', [WPFlameTestClass::class, 'static_method']);
        $this->assertSame('WPFlameTestClass::static_method', $name);
    }

    public function test_resolve_name_for_string_static_method(): void
    {
        $name = CallbackResolver::resolve_name('test-id', 'WPFlame\\Tests\\Unit\\WPFlameTestClass::static_method');
        $this->assertSame('WPFlameTestClass::static_method', $name);
    }

    public function test_resolve_name_for_closure(): void
    {
        $closure = function () { return 'test'; };
        $name = CallbackResolver::resolve_name('test-id', $closure);

        // Should contain filename and line number
        $this->assertStringContainsString('CallbackResolverTest.php', $name);
        $this->assertMatchesRegularExpression('/:\d+$/', $name);
    }

    public function test_resolve_name_for_invocable_object(): void
    {
        $obj = new WPFlameInvocableClass();
        $name = CallbackResolver::resolve_name('test-id', $obj);
        $this->assertSame('WPFlameInvocableClass::__invoke', $name);
    }

    public function test_resolve_name_for_invalid_callable_falls_back(): void
    {
        $name = CallbackResolver::resolve_name('fallback-id', ['NonExistentClass', 'method']);
        $this->assertSame('fallback-id', $name);
    }

    public function test_resolve_name_caches_result(): void
    {
        $obj = new WPFlameTestClass();
        $first = CallbackResolver::resolve_name('cache-test', [$obj, 'instance_method']);
        $second = CallbackResolver::resolve_name('cache-test', [$obj, 'instance_method']);
        $this->assertSame($first, $second);
    }

    public function test_resolve_source_returns_type_and_source(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $obj = new WPFlameTestClass();
        $source = CallbackResolver::resolve_source('test-id', [$obj, 'instance_method'], $collector);

        $this->assertArrayHasKey('type', $source);
        $this->assertArrayHasKey('source', $source);
        $this->assertIsString($source['type']);
        $this->assertIsString($source['source']);
    }

    public function test_resolve_source_for_invalid_callable_returns_unknown(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $source = CallbackResolver::resolve_source('test-id', ['NonExistentClass', 'method'], $collector);

        $this->assertSame(Span::TYPE_PHP, $source['type']);
        $this->assertSame('unknown', $source['source']);
    }

    public function test_reset_clears_caches(): void
    {
        $obj = new WPFlameTestClass();
        CallbackResolver::resolve_name('reset-test', [$obj, 'instance_method']);

        CallbackResolver::reset();

        // After reset, the same call should still work (just re-resolved)
        $name = CallbackResolver::resolve_name('reset-test', [$obj, 'instance_method']);
        $this->assertSame('WPFlameTestClass::instance_method', $name);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: FAIL — `Class 'WPFlame\CallbackResolver' not found`.

- [ ] **Step 3: Implement CallbackResolver**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class CallbackResolver
{
    /** @var array<string, string> */
    private static array $name_cache = [];

    /** @var array<string, array> */
    private static array $source_cache = [];

    /**
     * Resolve a human-readable name for a WordPress callback.
     */
    public static function resolve_name(string $callback_id, $callback): string
    {
        if (isset(self::$name_cache[$callback_id])) {
            return self::$name_cache[$callback_id];
        }

        try {
            $name = self::do_resolve_name($callback);
        } catch (\ReflectionException $e) {
            $name = $callback_id;
        }

        self::$name_cache[$callback_id] = $name;
        return $name;
    }

    /**
     * Resolve source attribution for a WordPress callback.
     *
     * @return array{type: string, source: string}
     */
    public static function resolve_source(string $callback_id, $callback, Collector $collector): array
    {
        if (isset(self::$source_cache[$callback_id])) {
            return self::$source_cache[$callback_id];
        }

        try {
            $filename = self::get_callback_filename($callback);
            $source = $collector->get_source_from_file($filename);
        } catch (\ReflectionException $e) {
            $source = ['type' => Span::TYPE_PHP, 'source' => 'unknown'];
        }

        self::$source_cache[$callback_id] = $source;
        return $source;
    }

    /**
     * Clear all caches. Called by Collector::reset() for test isolation.
     */
    public static function reset(): void
    {
        self::$name_cache = [];
        self::$source_cache = [];
    }

    private static function do_resolve_name($callback): string
    {
        // String static method: "ClassName::method"
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $parts = explode('::', $callback, 2);
            $ref = new \ReflectionMethod($parts[0], $parts[1]);
            return self::short_class_name($ref->getDeclaringClass()->getName()) . '::' . $ref->getName();
        }

        // Named function
        if (is_string($callback) && function_exists($callback)) {
            return $callback;
        }

        // Array method: [$object, 'method'] or ['ClassName', 'method']
        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return self::short_class_name($class) . '::' . $callback[1];
        }

        // Closure
        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            $file = $ref->getFileName();
            $line = $ref->getStartLine();
            return self::relative_path($file) . ':' . $line;
        }

        // Invocable object
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return self::short_class_name(get_class($callback)) . '::__invoke';
        }

        // Unknown — throw so the caller falls back to $callback_id
        throw new \ReflectionException('Unknown callback type');
    }

    private static function get_callback_filename($callback): string
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $parts = explode('::', $callback, 2);
            $ref = new \ReflectionMethod($parts[0], $parts[1]);
            return $ref->getFileName();
        }

        if (is_string($callback) && function_exists($callback)) {
            $ref = new \ReflectionFunction($callback);
            return $ref->getFileName();
        }

        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $ref = new \ReflectionMethod($callback[0], $callback[1]);
            return $ref->getFileName();
        }

        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            return $ref->getFileName();
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            $ref = new \ReflectionMethod($callback, '__invoke');
            return $ref->getFileName();
        }

        throw new \ReflectionException('Cannot determine filename for callback');
    }

    private static function short_class_name(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private static function relative_path(string $file): string
    {
        if (defined('WP_PLUGIN_DIR') && strpos($file, WP_PLUGIN_DIR) === 0) {
            return substr($file, strlen(WP_PLUGIN_DIR) + 1);
        }

        if (function_exists('get_template_directory') && strpos($file, get_template_directory()) === 0) {
            return substr($file, strlen(get_template_directory()) + 1);
        }

        if (defined('ABSPATH') && strpos($file, ABSPATH) === 0) {
            return substr($file, strlen(ABSPATH));
        }

        return basename($file);
    }
}
```

- [ ] **Step 4: Update `Collector::reset()` to call `CallbackResolver::reset()`**

In `src/Collector.php`, update the `reset()` method:

```php
    public static function reset(): void
    {
        self::$instance = null;
        self::$source_cache = [];
        CallbackResolver::reset();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 46 tests PASS (35 existing + 11 new CallbackResolver tests).

- [ ] **Step 6: Commit**

```bash
git add src/CallbackResolver.php tests/Unit/CallbackResolverTest.php src/Collector.php
git commit -m "feat: add CallbackResolver for callback name and source resolution"
```

---

## Chunk 3: CallbackWrapper + Bootstrap Integration

### Task 4: CallbackWrapper

**Files:**
- Create: `src/CallbackWrapper.php`
- Create: `tests/Unit/CallbackWrapperTest.php`

- [ ] **Step 1: Write failing tests for CallbackWrapper**

```php
<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CallbackWrapper;
use WPFlame\Collector;
use WPFlame\Span;

class CallbackWrapperTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    private function make_wrapper($original, float $min_ms = 0.0): CallbackWrapper
    {
        return new CallbackWrapper(
            $original,
            Collector::instance(),
            'test_hook',
            10,
            'test_callback',
            ['type' => Span::TYPE_PLUGIN, 'source' => 'test-plugin'],
            $min_ms
        );
    }

    public function test_invoke_passes_args_to_original(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $received = null;
        $wrapper = $this->make_wrapper(function ($a, $b) use (&$received) {
            $received = [$a, $b];
            return 'result';
        });

        $result = $wrapper('hello', 'world');

        $this->assertSame(['hello', 'world'], $received);
        $this->assertSame('result', $result);
    }

    public function test_invoke_creates_span_above_threshold(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            usleep(2000); // 2ms
            return 'ok';
        }, 0.5);

        $wrapper();

        $trace = $collector->get_trace();
        $plugin_spans = array_filter($trace->spans, fn(Span $s) => $s->type === Span::TYPE_PLUGIN);
        $this->assertGreaterThanOrEqual(1, count($plugin_spans));
    }

    public function test_invoke_discards_span_below_threshold(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            return 'fast';
        }, 100.0); // 100ms threshold — will be below

        $wrapper();

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_get_original_returns_wrapped_callback(): void
    {
        $original = function () { return 'test'; };
        $wrapper = $this->make_wrapper($original);

        $this->assertSame($original, $wrapper->get_original());
    }

    public function test_invoke_closes_span_on_exception(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            throw new \RuntimeException('test error');
        });

        try {
            $wrapper();
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Span stack should be empty (span was closed by finally block)
        // Verify by starting and ending another span successfully
        $id = $collector->start_span('After exception', Span::TYPE_CORE, 'test');
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $names = array_map(fn($s) => $s->name, $trace->spans);
        $this->assertContains('After exception', $names);
    }

    public function test_span_meta_includes_hook_and_priority(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = new CallbackWrapper(
            function () { usleep(2000); },
            $collector,
            'wp_head',
            20,
            'my_callback',
            ['type' => Span::TYPE_PLUGIN, 'source' => 'my-plugin'],
            0.0
        );

        $wrapper();

        $trace = $collector->get_trace();
        $this->assertSame('wp_head', $trace->spans[0]->meta['hook']);
        $this->assertSame(20, $trace->spans[0]->meta['priority']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: FAIL — `Class 'WPFlame\CallbackWrapper' not found`.

- [ ] **Step 3: Implement CallbackWrapper**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class CallbackWrapper
{
    /** @var callable */
    private $original;
    private Collector $collector;
    private string $hook_name;
    private int $priority;
    private string $span_name;
    private array $span_source;
    private float $min_duration_ms;

    /**
     * @param callable $original       The original callback to wrap
     * @param Collector $collector     The span collector
     * @param string $hook_name        WordPress hook name
     * @param int $priority            Hook priority
     * @param string $span_name        Pre-resolved callback name
     * @param array $span_source       Pre-resolved ['type' => ..., 'source' => ...]
     * @param float $min_duration_ms   Minimum duration to retain span
     */
    public function __construct(
        $original,
        Collector $collector,
        string $hook_name,
        int $priority,
        string $span_name,
        array $span_source,
        float $min_duration_ms
    ) {
        $this->original        = $original;
        $this->collector       = $collector;
        $this->hook_name       = $hook_name;
        $this->priority        = $priority;
        $this->span_name       = $span_name;
        $this->span_source     = $span_source;
        $this->min_duration_ms = $min_duration_ms;
    }

    /**
     * Invoke the wrapped callback with span timing.
     *
     * @return mixed
     */
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

    /**
     * Get the original unwrapped callback.
     *
     * @return callable
     */
    public function get_original()
    {
        return $this->original;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 52 tests PASS (46 existing + 6 new CallbackWrapper tests).

- [ ] **Step 5: Commit**

```bash
git add src/CallbackWrapper.php tests/Unit/CallbackWrapperTest.php
git commit -m "feat: add CallbackWrapper invocable for per-callback span timing"
```

---

### Task 5: Bootstrap Integration — `wp_flame_wrap_callbacks()`

**Files:**
- Modify: `wp-flame.php`

- [ ] **Step 1: Add `wp_flame_wrap_callbacks()` function**

Add this function to `wp-flame.php` after the existing `wp_flame_shutdown()` function:

```php
/**
 * Wrap registered WordPress hook callbacks with timing instrumentation.
 * Iterates all hooks in $wp_filter and replaces each callback's function
 * entry with a CallbackWrapper that adds span timing.
 */
function wp_flame_wrap_callbacks( WPFlame\Collector $collector, float $min_ms ): void {
    foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook_instance ) {
        if ( ! ( $hook_instance instanceof \WP_Hook ) ) {
            continue;
        }

        foreach ( $hook_instance->callbacks as $priority => $priority_callbacks ) {
            foreach ( $priority_callbacks as $id => $the_ ) {
                // Skip already-wrapped callbacks
                if ( $the_['function'] instanceof WPFlame\CallbackWrapper ) {
                    continue;
                }

                $original = $the_['function'];

                // Skip our own plugin's callbacks to avoid self-instrumentation
                $source = WPFlame\CallbackResolver::resolve_source( $id, $original, $collector );
                if ( defined( 'WP_FLAME_DIR' ) ) {
                    try {
                        $filename = '';
                        if ( is_string( $original ) && function_exists( $original ) ) {
                            $filename = ( new \ReflectionFunction( $original ) )->getFileName();
                        } elseif ( is_array( $original ) && isset( $original[0], $original[1] ) ) {
                            $filename = ( new \ReflectionMethod( $original[0], $original[1] ) )->getFileName();
                        } elseif ( $original instanceof \Closure ) {
                            $filename = ( new \ReflectionFunction( $original ) )->getFileName();
                        } elseif ( is_object( $original ) && method_exists( $original, '__invoke' ) ) {
                            $filename = ( new \ReflectionMethod( $original, '__invoke' ) )->getFileName();
                        }
                        if ( $filename && strpos( $filename, WP_FLAME_DIR ) === 0 ) {
                            continue;
                        }
                    } catch ( \ReflectionException $e ) {
                        // Can't determine file — wrap it anyway
                    }
                }

                $name = WPFlame\CallbackResolver::resolve_name( $id, $original );

                $hook_instance->callbacks[ $priority ][ $id ]['function'] = new WPFlame\CallbackWrapper(
                    $original, $collector, $hook_name, (int) $priority,
                    $name, $source, $min_ms
                );
            }
        }
    }
}
```

- [ ] **Step 2: Add wrapping calls to `wp_flame_init()`**

In `wp_flame_init()`, after the existing admin UI registration block, add:

```php
    // Per-callback instrumentation (Phase 2)
    $min_callback_ms = (float) get_option( 'wp_flame_min_callback_ms', 0.5 );

    // Pass 1: wrap callbacks registered before plugins_loaded
    add_action( 'plugins_loaded', function () use ( $collector, $min_callback_ms ) {
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );

    // Pass 2: wrap callbacks registered between plugins_loaded and init
    add_action( 'init', function () use ( $collector, $min_callback_ms ) {
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );
```

- [ ] **Step 3: Add `wp_flame_min_callback_ms` to activation defaults**

In `wp_flame_activate()`, add after the existing `add_option` calls:

```php
    add_option( 'wp_flame_min_callback_ms', 0.5 );
```

- [ ] **Step 4: Run unit tests to verify nothing breaks**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 52 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add wp-flame.php
git commit -m "feat: add callback wrapping to bootstrap for per-callback instrumentation"
```

---

### Task 6: Manual Integration Test

- [ ] **Step 1: Verify wp-env picks up changes**

The plugin is mounted directly by wp-env, so changes are live. Clear old traces:

```bash
npx wp-env run cli wp db query "DELETE FROM wp_flame_traces" 2>/dev/null
```

- [ ] **Step 2: Browse the frontend homepage**

Navigate to `http://localhost:8888/` in a browser while logged in as admin.

- [ ] **Step 3: Check WP Flame for the new trace**

Navigate to `http://localhost:8888/wp-admin/tools.php?page=wp-flame` and click the homepage trace.

**Expected:** The flame graph should now show multiple levels:
- Row 0: Lifecycle phases (Bootstrap, Plugin Load, etc.)
- Row 1: Individual callback spans inside each phase (plugin/theme callbacks)
- Row 2: DB queries nested inside callback spans

The flame graph should be visually richer with more colored blocks showing which callbacks are taking time within each lifecycle phase.

- [ ] **Step 4: Verify tooltips show callback names**

Hover over callback spans. Tooltips should show:
- Callback name (e.g., `WPFlameTestClass::method` or `twentytwentyfive/functions.php:42`)
- Duration in ms
- Source plugin/theme name
- Hook name and priority in context

- [ ] **Step 5: Commit a verification note**

```bash
git commit --allow-empty -m "test: manual verification of per-callback flame graph rendering"
```
