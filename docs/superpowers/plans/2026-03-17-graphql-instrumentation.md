# GraphQL Instrumentation Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full GraphQL instrumentation using WPGraphQL native hooks, with lightweight fallback for non-WPGraphQL sites, and zero Stellate conflicts.

**Architecture:** Three-tier detection: WPGraphQL sites get resolver-level spans via native hooks, other GraphQL sites get DB+lifecycle instrumentation via `log_query_custom_data`, normal requests are unchanged. Two-phase URL heuristic at `plugins_loaded` → `GRAPHQL_REQUEST` confirmation at `init`. New `GraphQL` class follows same pattern as `Http` class.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WPGraphQL hooks, PHPUnit 9

**Spec:** `docs/superpowers/specs/2026-03-17-graphql-instrumentation-design.md`

**Working directory:** All changes target the worktree at `.worktrees/stellate-fix/` (branch `fix/stellate-compatibility`), which already has the initial `$is_graphql` guard from the quick-fix. This plan replaces that guard with the full tiered instrumentation.

---

## Chunk 1: Collector.add_completed_span()

### Task 1: Add `add_completed_span()` to Collector

**Files:**
- Modify: `src/Collector.php:113` (after `end_span()` method, before `end_span_filtered()`)
- Test: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Write failing test — basic span creation with pre-computed timing**

Add to `tests/Unit/CollectorTest.php`:

```php
public function test_add_completed_span_creates_span_with_precomputed_timing(): void
{
    $collector = Collector::instance();
    $collector->start_request(1000.0); // request started at t=1000

    // Query started at t=1000.050 (50ms after request), lasted 0.002s (2ms)
    $id = $collector->add_completed_span(
        'SELECT',
        Span::TYPE_DB,
        'some-plugin',
        1000.050,
        0.002,
        ['query' => 'SELECT 1']
    );

    $this->assertIsString($id);
    $this->assertNotEmpty($id);

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);

    $span = $trace->spans[0];
    $this->assertSame('SELECT', $span->name);
    $this->assertSame(Span::TYPE_DB, $span->type);
    $this->assertSame('some-plugin', $span->source);
    $this->assertEqualsWithDelta(50.0, $span->start_ms, 0.01);
    $this->assertEqualsWithDelta(2.0, $span->duration_ms, 0.01);
    $this->assertSame(['query' => 'SELECT 1'], $span->meta);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_add_completed_span_creates_span_with_precomputed_timing`
Expected: FAIL — method `add_completed_span` does not exist

- [ ] **Step 3: Write failing test — parent_id from span stack**

```php
public function test_add_completed_span_uses_current_parent_from_stack(): void
{
    $collector = Collector::instance();
    $collector->start_request(1000.0);

    $parent_id = $collector->start_span('Resolver', Span::TYPE_PLUGIN, 'wpgraphql');

    // DB query happens while resolver span is open
    $collector->add_completed_span('SELECT', Span::TYPE_DB, 'wordpress', 1000.010, 0.001);

    $collector->end_span($parent_id);

    $trace = $collector->get_trace();
    $this->assertCount(2, $trace->spans);

    $spans_by_name = [];
    foreach ($trace->spans as $span) {
        $spans_by_name[$span->name] = $span;
    }

    $this->assertSame($parent_id, $spans_by_name['SELECT']->parent_id);
    $this->assertNull($spans_by_name['Resolver']->parent_id);
}
```

- [ ] **Step 4: Write failing test — no parent when stack empty**

```php
public function test_add_completed_span_has_null_parent_when_stack_empty(): void
{
    $collector = Collector::instance();
    $collector->start_request(1000.0);

    $collector->add_completed_span('SELECT', Span::TYPE_DB, 'wordpress', 1000.010, 0.001);

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);
    $this->assertNull($trace->spans[0]->parent_id);
}
```

- [ ] **Step 5: Write failing test — noop when stopped**

```php
public function test_add_completed_span_noop_when_stopped(): void
{
    $collector = Collector::instance();
    $collector->start_request(1000.0);
    $collector->stop();

    $id = $collector->add_completed_span('SELECT', Span::TYPE_DB, 'test', 1000.0, 0.001);

    $this->assertSame('', $id);
    $trace = $collector->get_trace();
    $this->assertCount(0, $trace->spans);
}
```

- [ ] **Step 6: Implement `add_completed_span()`**

Add this method to `src/Collector.php` after `end_span()` (around line 113):

```php
/**
 * Create a span with pre-computed timing (for log_query_custom_data).
 * Accepts absolute microtime start and duration in seconds.
 * Parent is determined from the current span stack.
 */
public function add_completed_span(
    string $name,
    string $type,
    string $source,
    float $abs_start,
    float $duration_sec,
    array $meta = []
): string {
    if ($this->stopped) {
        return '';
    }

    $id = self::generate_uuid();
    $parent_id = ! empty($this->span_stack)
        ? $this->span_stack[count($this->span_stack) - 1]['id']
        : null;

    $start_ms = ($abs_start - $this->request_start) * 1000;
    $duration_ms = $duration_sec * 1000;

    $this->spans[] = new Span(
        $id,
        $parent_id,
        $name,
        $type,
        $source,
        max(0.0, $start_ms),
        max(0.0, $duration_ms),
        $meta
    );

    return $id;
}
```

- [ ] **Step 7: Run all tests**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All tests pass (122 existing + 4 new = 126)

- [ ] **Step 8: Commit**

```
git add src/Collector.php tests/Unit/CollectorTest.php
git commit -m "feat: add Collector::add_completed_span() for pre-computed timing"
```

---

## Chunk 2: GraphQL class — DB query capture

### Task 2: Create `src/GraphQL.php` with DB hooks

**Files:**
- Create: `src/GraphQL.php`
- Create: `tests/Unit/GraphQLTest.php`

- [ ] **Step 1: Write failing test — constructor registers nothing visible, deactivate is safe**

Create `tests/Unit/GraphQLTest.php`. This test file needs WP function stubs similar to `HttpTest.php`. The GraphQL class uses `add_filter`, `remove_filter`, and `get_option` — all need stubs.

```php
<?php

namespace WPFlame {
    // Track filter registrations for test assertions
    $GLOBALS['wp_flame_test_filters'] = [];

    if (!function_exists('WPFlame\add_filter')) {
        function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            $GLOBALS['wp_flame_test_filters'][$hook_name][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
            return true;
        }
    }

    if (!function_exists('WPFlame\remove_filter')) {
        function remove_filter($hook_name, $callback, $priority = 10): bool
        {
            if (isset($GLOBALS['wp_flame_test_filters'][$hook_name])) {
                foreach ($GLOBALS['wp_flame_test_filters'][$hook_name] as $i => $entry) {
                    if ($entry['callback'] === $callback && $entry['priority'] === $priority) {
                        unset($GLOBALS['wp_flame_test_filters'][$hook_name][$i]);
                        return true;
                    }
                }
            }
            return false;
        }
    }

    if (!function_exists('WPFlame\add_action')) {
        function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            return add_filter($hook_name, $callback, $priority, $accepted_args);
        }
    }

    if (!function_exists('WPFlame\get_option')) {
        function get_option(string $option, $default = false)
        {
            return $GLOBALS['wp_flame_test_options'][$option] ?? $default;
        }
    }
}

namespace WPFlame\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use WPFlame\Collector;
    use WPFlame\GraphQL;
    use WPFlame\Span;

    class GraphQLTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
        }

        protected function tearDown(): void
        {
            Collector::reset();
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
        }

        private function make_collector(): Collector
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);
            return $collector;
        }

        public function test_constructor_registers_log_query_custom_data_hook(): void
        {
            $collector = $this->make_collector();
            new GraphQL($collector);

            $this->assertArrayHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['log_query_custom_data']);
        }

        public function test_deactivate_removes_log_query_custom_data_hook(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL($collector);

            $this->assertNotEmpty($GLOBALS['wp_flame_test_filters']['log_query_custom_data']);

            $gql->deactivate();

            // After deactivation, the filter should be removed
            $remaining = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
            $this->assertEmpty($remaining);
        }

        public function test_deactivate_twice_is_safe(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL($collector);

            $gql->deactivate();
            $gql->deactivate(); // Should not throw

            $this->assertTrue(true); // No exception
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GraphQLTest`
Expected: FAIL — class `WPFlame\GraphQL` not found

- [ ] **Step 3: Implement GraphQL class skeleton with DB hooks**

Create `src/GraphQL.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GraphQL
{
    private Collector $collector;
    private bool $full_query_text;

    /** @var array<string, string[]> Stack of span IDs per field key for alias handling */
    private array $resolver_span_stacks = [];

    private ?string $operation_span_id = null;

    /** @var callable|null Stored for remove_filter() in deactivate() */
    private $db_hook_callback = null;

    public function __construct(Collector $collector)
    {
        $this->collector = $collector;
        $this->full_query_text = (bool) get_option('wp_flame_full_query_text', false);
        $this->register_db_hooks();
    }

    /**
     * Activate WPGraphQL-specific resolver and operation hooks (Tier 1 only).
     */
    public function activate_wpgraphql_hooks(): void
    {
        $this->register_operation_hooks();
        $this->register_resolver_hooks();
    }

    /**
     * Remove all hooks registered by this instance.
     */
    public function deactivate(): void
    {
        if ($this->db_hook_callback !== null) {
            remove_filter('log_query_custom_data', $this->db_hook_callback, 10);
            $this->db_hook_callback = null;
        }
    }

    private function register_db_hooks(): void
    {
        $this->db_hook_callback = function ($query_data, $query, $query_time, $query_callstack, $query_start) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            $source = $this->get_caller_source($backtrace);
            $query_text = $this->full_query_text ? $query : substr($query, 0, 200);

            $this->collector->add_completed_span(
                $this->extract_query_type($query),
                Span::TYPE_DB,
                $source,
                (float) $query_start,
                (float) $query_time,
                ['query' => $query_text]
            );

            return $query_data;
        };
        add_filter('log_query_custom_data', $this->db_hook_callback, 10, 5);
    }

    private function register_operation_hooks(): void
    {
        // Implemented in Task 3
    }

    private function register_resolver_hooks(): void
    {
        // Implemented in Task 4
    }

    /**
     * Extract SQL verb (SELECT, INSERT, etc.) for span name.
     */
    private function extract_query_type(string $query): string
    {
        $query = ltrim($query);
        $first_word = strtoupper(strtok($query, " \t\n\r"));
        $known_types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'SHOW', 'SET'];

        return in_array($first_word, $known_types, true) ? $first_word : 'QUERY';
    }

    /**
     * Determine query source via backtrace (same pattern as DB::get_caller_source()).
     */
    private function get_caller_source(array $backtrace): string
    {
        $wp_flame_dir = dirname(__DIR__);

        foreach ($backtrace as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            $file = $frame['file'];

            if (defined('ABSPATH')) {
                if (strpos($file, ABSPATH . 'wp-includes/') === 0) {
                    continue;
                }
                if (strpos($file, ABSPATH . 'wp-admin/') === 0) {
                    continue;
                }
            }
            if (strpos($file, $wp_flame_dir) === 0) {
                continue;
            }

            $source = $this->collector->get_source_from_file($file);
            return $source['source'];
        }

        return 'wordpress';
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter GraphQLTest`
Expected: All 3 tests pass

- [ ] **Step 5: Write failing test — DB hook creates spans with correct timing**

Add to `GraphQLTest.php`:

```php
public function test_db_hook_creates_span_with_correct_timing(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);

    // Simulate log_query_custom_data being called
    $filters = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
    $this->assertNotEmpty($filters);

    $callback = $filters[0]['callback'];

    // Call the callback as WordPress would
    $callback(
        [],                          // $query_data
        'SELECT * FROM wp_posts',    // $query
        0.005,                       // $query_time (5ms)
        'some_function',             // $query_callstack
        1000.100                     // $query_start (100ms after request)
    );

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);

    $span = $trace->spans[0];
    $this->assertSame('SELECT', $span->name);
    $this->assertSame(Span::TYPE_DB, $span->type);
    $this->assertEqualsWithDelta(100.0, $span->start_ms, 0.01);
    $this->assertEqualsWithDelta(5.0, $span->duration_ms, 0.01);
    $this->assertStringContainsString('SELECT * FROM wp_posts', $span->meta['query']);
}
```

- [ ] **Step 6: Run test**

Run: `vendor/bin/phpunit --filter test_db_hook_creates_span_with_correct_timing`
Expected: PASS (implementation already done)

- [ ] **Step 7: Write failing test — query truncation respects setting**

```php
public function test_db_hook_truncates_query_when_full_text_disabled(): void
{
    $GLOBALS['wp_flame_test_options']['wp_flame_full_query_text'] = false;

    $collector = $this->make_collector();
    $gql = new GraphQL($collector);

    $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

    $long_query = 'SELECT ' . str_repeat('x', 300) . ' FROM wp_posts';
    $callback([], $long_query, 0.001, '', 1000.0);

    $trace = $collector->get_trace();
    $this->assertSame(200, strlen($trace->spans[0]->meta['query']));
}

public function test_db_hook_keeps_full_query_when_setting_enabled(): void
{
    $GLOBALS['wp_flame_test_options']['wp_flame_full_query_text'] = true;

    $collector = $this->make_collector();
    $gql = new GraphQL($collector);

    $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

    $long_query = 'SELECT ' . str_repeat('x', 300) . ' FROM wp_posts';
    $callback([], $long_query, 0.001, '', 1000.0);

    $trace = $collector->get_trace();
    $this->assertSame(strlen($long_query), strlen($trace->spans[0]->meta['query']));
}
```

- [ ] **Step 8: Run test**

Run: `vendor/bin/phpunit --filter "test_db_hook_truncates|test_db_hook_keeps"`
Expected: PASS

- [ ] **Step 9: Write test — no DB spans after deactivation**

```php
public function test_no_db_spans_after_deactivation(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);

    $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

    // First call works
    $callback([], 'SELECT 1', 0.001, '', 1000.0);

    $gql->deactivate();

    // In real WordPress, remove_filter prevents future calls.
    // Our test stub removes it from the array.
    $remaining = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
    $this->assertEmpty($remaining);

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans); // Only the first call
}
```

- [ ] **Step 10: Run all tests**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All pass (126 existing + 6 new = 132)

- [ ] **Step 11: Commit**

```
git add src/GraphQL.php tests/Unit/GraphQLTest.php
git commit -m "feat: add GraphQL class with DB query capture via log_query_custom_data"
```

---

## Chunk 3: GraphQL class — WPGraphQL operation and resolver hooks

### Task 3: Add operation-level span hooks

**Files:**
- Modify: `src/GraphQL.php` (fill in `register_operation_hooks()`)
- Modify: `tests/Unit/GraphQLTest.php`

- [ ] **Step 1: Write failing test — activate_wpgraphql_hooks registers operation hooks**

Add to `GraphQLTest.php`:

```php
public function test_activate_wpgraphql_hooks_registers_operation_hooks(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $this->assertArrayHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
}

public function test_constructor_does_not_register_operation_hooks(): void
{
    $collector = $this->make_collector();
    new GraphQL($collector);

    $this->assertArrayNotHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayNotHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_activate_wpgraphql_hooks_registers|test_constructor_does_not_register_operation"`
Expected: First test FAILS (hooks not registered), second PASSES

- [ ] **Step 3: Write failing test — operation span lifecycle**

```php
public function test_operation_span_created_and_closed(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    // Simulate graphql_process_request
    $wp_graphql = new class {
        public function get_query(): string { return '{ posts { nodes { title } } }'; }
        public function get_operation_name(): ?string { return 'GetPosts'; }
    };

    $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
    $process_callback($wp_graphql);

    // Simulate graphql_return_response
    $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
    $response = ['data' => ['posts' => []]];
    $result = $response_callback($response);

    // Response must be returned unchanged
    $this->assertSame($response, $result);

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);
    $this->assertSame('GraphQL: GetPosts', $trace->spans[0]->name);
    $this->assertSame(Span::TYPE_CORE, $trace->spans[0]->type);
    $this->assertSame('wpgraphql', $trace->spans[0]->source);
    $this->assertSame('GetPosts', $trace->spans[0]->meta['graphql_operation']);
}
```

- [ ] **Step 4: Write failing test — response returned even on error**

```php
public function test_graphql_return_response_returns_response_without_operation_span(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    // Call graphql_return_response without prior graphql_process_request
    $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
    $response = ['errors' => [['message' => 'Validation failed']]];
    $result = $response_callback($response);

    $this->assertSame($response, $result);
    $trace = $collector->get_trace();
    $this->assertCount(0, $trace->spans);
}

public function test_anonymous_operation_name(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $wp_graphql = new class {
        public function get_query(): string { return '{ posts { nodes { title } } }'; }
        public function get_operation_name(): ?string { return null; }
    };

    $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
    $process_callback($wp_graphql);

    $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
    $response_callback([]);

    $trace = $collector->get_trace();
    $this->assertSame('GraphQL: anonymous', $trace->spans[0]->name);
}
```

- [ ] **Step 5: Implement `register_operation_hooks()`**

In `src/GraphQL.php`, replace the empty `register_operation_hooks()`:

```php
private function register_operation_hooks(): void
{
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
}
```

Note: `add_action` is an alias for `add_filter` in WordPress. The `add_action` stub was already added to `GraphQLTest.php` in Task 2 Step 1 alongside the other stubs.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit --filter GraphQLTest`
Expected: All pass

- [ ] **Step 7: Commit**

```
git add src/GraphQL.php tests/Unit/GraphQLTest.php
git commit -m "feat: add WPGraphQL operation-level span hooks"
```

### Task 4: Add root field resolver hooks

**Files:**
- Modify: `src/GraphQL.php` (fill in `register_resolver_hooks()`)
- Modify: `tests/Unit/GraphQLTest.php`

- [ ] **Step 1: Write failing test — resolver hooks registered**

```php
public function test_activate_wpgraphql_hooks_registers_resolver_hooks(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $this->assertArrayHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
}
```

- [ ] **Step 2: Write failing test — root field creates span**

```php
public function test_root_field_creates_resolver_span(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
    $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

    // Simulate resolving RootQuery.posts
    $default = null;
    $result_value = ['data' => []];

    $pre_result = $pre_resolve($default, null, [], null, null, 'RootQuery', 'posts', null, null);
    $this->assertNull($pre_result); // Must return $default unchanged

    $resolve_result = $resolve($result_value, null, [], null, null, 'RootQuery', 'posts', null, null);
    $this->assertSame($result_value, $resolve_result); // Must return $result unchanged

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);

    $span = $trace->spans[0];
    $this->assertSame('RootQuery.posts', $span->name);
    $this->assertSame(Span::TYPE_PLUGIN, $span->type);
    $this->assertSame('wpgraphql', $span->source);
    $this->assertSame('graphql:RootQuery.posts', $span->meta['hook']);
    $this->assertSame('RootQuery', $span->meta['type_name']);
    $this->assertSame('posts', $span->meta['field_key']);
}
```

- [ ] **Step 3: Write failing test — non-root fields are skipped**

```php
public function test_non_root_field_does_not_create_span(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
    $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

    // Simulate resolving Post.title (not a root field)
    $pre_resolve(null, null, [], null, null, 'Post', 'title', null, null);
    $resolve('Hello World', null, [], null, null, 'Post', 'title', null, null);

    $trace = $collector->get_trace();
    $this->assertCount(0, $trace->spans); // No resolver spans for non-root
}
```

- [ ] **Step 4: Write failing test — aliased fields use stack correctly**

```php
public function test_aliased_root_fields_resolve_correctly_via_stack(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
    $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

    // Two aliased posts queries: first: posts(...), second: posts(...)
    $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);
    $resolve(['first batch'], null, [], null, null, 'RootQuery', 'posts', null, null);

    $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);
    $resolve(['second batch'], null, [], null, null, 'RootQuery', 'posts', null, null);

    $trace = $collector->get_trace();
    $this->assertCount(2, $trace->spans);
    $this->assertSame('RootQuery.posts', $trace->spans[0]->name);
    $this->assertSame('RootQuery.posts', $trace->spans[1]->name);

    // Both should have unique IDs
    $this->assertNotSame($trace->spans[0]->id, $trace->spans[1]->id);
}
```

- [ ] **Step 5: Write failing test — RootMutation and RootSubscription are detected**

```php
public function test_root_mutation_creates_span(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
    $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

    $pre_resolve(null, null, [], null, null, 'RootMutation', 'createPost', null, null);
    $resolve(['id' => 1], null, [], null, null, 'RootMutation', 'createPost', null, null);

    $trace = $collector->get_trace();
    $this->assertCount(1, $trace->spans);
    $this->assertSame('RootMutation.createPost', $trace->spans[0]->name);
}
```

- [ ] **Step 6: Implement `register_resolver_hooks()`**

In `src/GraphQL.php`, replace the empty `register_resolver_hooks()`:

```php
private const ROOT_TYPES = ['rootquery', 'rootmutation', 'rootsubscription'];

private function register_resolver_hooks(): void
{
    add_filter('graphql_pre_resolve_field', function ($default, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
        try {
            if (! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
                return $default;
            }

            $span_id = $this->collector->start_span(
                "{$type_name}.{$field_key}",
                Span::TYPE_PLUGIN,
                'wpgraphql',
                [
                    'type_name' => $type_name,
                    'field_key' => $field_key,
                    'hook'      => "graphql:{$type_name}.{$field_key}",
                ]
            );

            $key = strtolower($type_name) . '.' . $field_key;
            $this->resolver_span_stacks[$key][] = $span_id;
        } catch (\Throwable $e) {
            // Don't break field resolution
        }
        return $default;
    }, 10, 9);

    add_filter('graphql_resolve_field', function ($result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
        try {
            if (! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
                return $result;
            }

            $key = strtolower($type_name) . '.' . $field_key;
            if (! empty($this->resolver_span_stacks[$key])) {
                $span_id = array_pop($this->resolver_span_stacks[$key]);
                $this->collector->end_span($span_id);
            }
        } catch (\Throwable $e) {
            // Don't break field resolution
        }
        return $result;
    }, 10, 9);
}
```

- [ ] **Step 7: Run all tests**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All pass

- [ ] **Step 8: Commit**

```
git add src/GraphQL.php tests/Unit/GraphQLTest.php
git commit -m "feat: add WPGraphQL root field resolver span hooks"
```

---

## Chunk 4: Two-phase detection in wp-flame.php

### Task 5: Rewrite `wp_flame_init()` with tiered GraphQL detection

**Files:**
- Modify: `wp-flame.php` (in `.worktrees/stellate-fix/`)

This task replaces the simple `$is_graphql` skip-guards with the full two-phase detection from the spec.

- [ ] **Step 1: Read current state of wp-flame.php in worktree**

Read `wp-flame.php` to confirm the current `$is_graphql` guard code that needs replacing.

- [ ] **Step 2: Replace the GraphQL detection + DB guard section**

In `wp_flame_init()`, replace the block from `// Skip heavy instrumentation` through the `$wpdb` replacement with the Phase 1 detection:

Replace:
```php
    // Skip heavy instrumentation for GraphQL requests (conflicts with Stellate/WPGraphQL)
    $is_graphql = defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST;

    // Replace $wpdb with instrumented version
    global $wpdb;
    if ( ! $is_graphql && WPFlame\DB::can_replace( $wpdb ) ) {
        $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector );
    }
```

With:
```php
    // Two-phase GraphQL detection (GRAPHQL_REQUEST not available at plugins_loaded)
    $graphql_endpoint = apply_filters( 'graphql_endpoint', 'graphql' );
    $request_path = isset( $_SERVER['REQUEST_URI'] )
        ? parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
        : '';
    $is_likely_graphql = $request_path !== ''
        && ( $request_path === '/' . $graphql_endpoint
            || substr( $request_path, -strlen( '/' . $graphql_endpoint ) ) === '/' . $graphql_endpoint );

    global $wpdb;
    $graphql_inst = null;

    if ( $is_likely_graphql ) {
        // Enable query logging for GraphQL DB capture
        if ( ! defined( 'SAVEQUERIES' ) ) {
            define( 'SAVEQUERIES', true );
        }
        $graphql_inst = new WPFlame\GraphQL( $collector );
        $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
    } else {
        if ( WPFlame\DB::can_replace( $wpdb ) ) {
            $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector );
        }
        $GLOBALS['wp_flame_skip_callback_wrapping'] = false;
    }
```

- [ ] **Step 3: Replace the callback wrapping section**

Replace the `if ( ! $is_graphql )` guarded callback wrapping block:

```php
    // Per-callback instrumentation (Phase 2) — skip for GraphQL (conflicts with Stellate)
    if ( ! $is_graphql ) {
        $min_callback_ms = (float) get_option( 'wp_flame_min_callback_ms', 0.5 );

        // Pass 1: wrap callbacks registered before plugins_loaded
        add_action( 'plugins_loaded', function () use ( $collector, $min_callback_ms ) {
            wp_flame_wrap_callbacks( $collector, $min_callback_ms );
        }, 1 );

        // Pass 2: wrap callbacks registered between plugins_loaded and init
        add_action( 'init', function () use ( $collector, $min_callback_ms ) {
            wp_flame_wrap_callbacks( $collector, $min_callback_ms );
        }, 1 );
    }
```

With:
```php
    // Per-callback instrumentation — guarded by flag (cleared on GraphQL false-positive)
    $min_callback_ms = (float) get_option( 'wp_flame_min_callback_ms', 0.5 );

    add_action( 'plugins_loaded', function () use ( $collector, $min_callback_ms ) {
        if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
            return;
        }
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );

    add_action( 'init', function () use ( $collector, $min_callback_ms ) {
        if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
            return;
        }
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );

    // Phase 2: confirm GraphQL detection at init (GRAPHQL_REQUEST now available)
    add_action( 'init', function () use ( $collector, &$graphql_inst, $is_likely_graphql ) {
        if ( ! $is_likely_graphql ) {
            return;
        }

        $confirmed = defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST;
        $has_wpgraphql = function_exists( 'graphql' );

        if ( $confirmed && $has_wpgraphql ) {
            $graphql_inst->activate_wpgraphql_hooks();
        } elseif ( $confirmed ) {
            // Tier 2: DB hooks already active, nothing more to do
        } else {
            // False positive: deactivate GraphQL hooks, start normal instrumentation
            $graphql_inst->deactivate();
            $graphql_inst = null;

            global $wpdb;
            if ( WPFlame\DB::can_replace( $wpdb ) ) {
                $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector );
            }

            $GLOBALS['wp_flame_skip_callback_wrapping'] = false;
        }
    }, 0 );
```

- [ ] **Step 4: Run all existing tests to verify no regressions**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All pass (existing 122 + new tests from Tasks 1-4)

- [ ] **Step 5: Commit**

```
git add wp-flame.php
git commit -m "feat: two-phase GraphQL detection with tiered instrumentation dispatch"
```

---

## Chunk 5: Missing test coverage

### Task 5a: URL heuristic, Tier 2 mode, and Score integration tests

**Files:**
- Modify: `tests/Unit/GraphQLTest.php`

- [ ] **Step 1: Write URL heuristic tests**

Add a helper method and tests to `GraphQLTest.php`. The URL heuristic logic is inline in `wp-flame.php`, but we can test the same logic pattern:

```php
/**
 * Replicate the URL heuristic from wp-flame.php for unit testing.
 */
private function url_matches_graphql(string $uri, string $endpoint = 'graphql'): bool
{
    $request_path = parse_url($uri, PHP_URL_PATH);
    if ($request_path === false || $request_path === null) {
        return false;
    }
    return $request_path === '/' . $endpoint
        || substr($request_path, -strlen('/' . $endpoint)) === '/' . $endpoint;
}

public function test_url_heuristic_matches_graphql_endpoint(): void
{
    $this->assertTrue($this->url_matches_graphql('/graphql'));
    $this->assertTrue($this->url_matches_graphql('/graphql?query=test'));
    $this->assertTrue($this->url_matches_graphql('/wp/graphql'));
    $this->assertTrue($this->url_matches_graphql('/index.php/graphql'));
}

public function test_url_heuristic_rejects_non_graphql_urls(): void
{
    $this->assertFalse($this->url_matches_graphql('/my-page/graphql-tools'));
    $this->assertFalse($this->url_matches_graphql('/docs/graphql-api'));
    $this->assertFalse($this->url_matches_graphql('/'));
    $this->assertFalse($this->url_matches_graphql('/wp-admin/'));
    $this->assertFalse($this->url_matches_graphql('/search?q=graphql'));
}

public function test_url_heuristic_custom_endpoint(): void
{
    $this->assertTrue($this->url_matches_graphql('/api', 'api'));
    $this->assertFalse($this->url_matches_graphql('/graphql', 'api'));
}
```

- [ ] **Step 2: Write Tier 2 mode test (no resolver hooks, DB hooks active)**

```php
public function test_tier2_mode_has_db_hooks_but_no_resolver_hooks(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);

    // DB hooks are active
    $this->assertArrayHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);

    // But NO resolver or operation hooks
    $this->assertArrayNotHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayNotHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayNotHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
    $this->assertArrayNotHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
}
```

- [ ] **Step 3: Write Score integration test with resolver spans as slow callback proxies**

Add to `tests/Unit/ScoreTest.php`:

```php
public function test_score_detects_slow_graphql_resolvers_via_hook_meta(): void
{
    // Build a trace with a slow resolver span that has meta['hook']
    $spans = [
        new Span('s1', null, 'RootQuery.posts', Span::TYPE_PLUGIN, 'wpgraphql',
            0.0, 200.0, ['hook' => 'graphql:RootQuery.posts', 'type_name' => 'RootQuery', 'field_key' => 'posts']),
    ];

    $trace = new Trace(
        'trace-1', '/graphql', 'POST', gmdate('c'),
        300.0, 1048576, '8.1', '6.4', $spans, []
    );

    $result = Score::calculate($trace);

    // The slow_callbacks factor should detect this span (duration 200ms > 50ms threshold)
    $slow_cb_factor = null;
    foreach ($result['factors'] as $factor) {
        if ($factor['key'] === 'slow_callbacks') {
            $slow_cb_factor = $factor;
            break;
        }
    }

    $this->assertNotNull($slow_cb_factor);
    $this->assertSame('1', $slow_cb_factor['value']); // 1 slow resolver
    $this->assertLessThan(100, $slow_cb_factor['score']); // Score penalized
}
```

- [ ] **Step 4: Write DB span parent-child test with resolver as parent**

```php
public function test_db_span_parent_is_resolver_span_when_on_stack(): void
{
    $collector = $this->make_collector();
    $gql = new GraphQL($collector);
    $gql->activate_wpgraphql_hooks();

    // Start a resolver span (simulating graphql_pre_resolve_field)
    $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
    $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);

    // Simulate a DB query during resolver execution
    $db_callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
    $db_callback([], 'SELECT * FROM wp_posts', 0.003, '', 1000.050);

    // End the resolver span
    $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];
    $resolve(['data'], null, [], null, null, 'RootQuery', 'posts', null, null);

    $trace = $collector->get_trace();
    $this->assertCount(2, $trace->spans);

    // Find spans by type
    $db_span = null;
    $resolver_span = null;
    foreach ($trace->spans as $span) {
        if ($span->type === Span::TYPE_DB) { $db_span = $span; }
        if ($span->type === Span::TYPE_PLUGIN) { $resolver_span = $span; }
    }

    $this->assertNotNull($db_span);
    $this->assertNotNull($resolver_span);
    $this->assertSame($resolver_span->id, $db_span->parent_id);
}
```

- [ ] **Step 5: Run all tests**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All pass

- [ ] **Step 6: Commit**

```
git add tests/Unit/GraphQLTest.php tests/Unit/ScoreTest.php
git commit -m "test: add URL heuristic, Tier 2 mode, Score, and parent-child coverage"
```

---

## Chunk 6: Admin UI changes

### Task 7: Add GraphQL type filter and limited instrumentation badge

**Files:**
- Modify: `src/Admin.php:93-98` (type filter SQL logic)
- Modify: `src/Admin.php:200-202` (type filter dropdown)
- Modify: `src/Admin.php:~661` (flame graph view — add badge)

- [ ] **Step 1: Add GraphQL to type filter SQL logic**

In `src/Admin.php`, after the `rest` type case (line 98), add:

```php
} elseif ($type === 'graphql') {
    $filters['url'] = '/graphql';
```

- [ ] **Step 2: Add GraphQL to type filter dropdown**

After the REST API option (line 202), add:

```php
echo '<option value="graphql"' . selected($current_type, 'graphql', false) . '>' . esc_html__('GraphQL', 'wp-flame') . '</option>';
```

- [ ] **Step 3: Add limited instrumentation badge to flame graph view**

In `src/Admin.php`, in `render_flame_graph_view()`, after the score breakdown section (line ~812) and before the flame graph container div (line ~814), add:

```php
// Limited instrumentation notice for Tier 2 GraphQL traces
$is_graphql_url = strpos($trace->url, '/graphql') !== false;
$has_resolver_spans = false;
if ($is_graphql_url) {
    foreach ($trace->spans as $span) {
        if (isset($span->meta['type_name'])) {
            $has_resolver_spans = true;
            break;
        }
    }
    if (!$has_resolver_spans) {
        echo '<div class="notice notice-info inline" style="margin: 10px 0"><p>';
        echo esc_html__('Limited instrumentation — resolver detail requires WPGraphQL.', 'wp-flame');
        echo '</p></div>';
    }
}
```

- [ ] **Step 4: Run all tests**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All pass (no unit tests for Admin rendering — these are integration tests)

- [ ] **Step 5: Commit**

```
git add src/Admin.php
git commit -m "feat: add GraphQL type filter and limited instrumentation badge"
```

---

## Chunk 7: CHANGELOG + final verification

### Task 8: Update CHANGELOG and run final tests

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update CHANGELOG.md**

Add the GraphQL instrumentation entry to the `### Added` section under `[Unreleased]`:

Find the existing line:
```
- GraphQL/Stellate compatibility: skip DB and callback instrumentation for GraphQL requests
```

Replace with:
```
- Full GraphQL instrumentation: WPGraphQL native hooks for resolver timing, DB queries via log_query_custom_data, operation-level spans
- Three-tier detection: full WPGraphQL instrumentation (Tier 1), lightweight fallback (Tier 2), unchanged normal requests (Tier 3)
- Stellate compatibility: zero conflict with GraphQL CDN plugins (no $wpdb replacement or callback wrapping for GraphQL)
- GraphQL type filter in trace list
- Limited instrumentation badge for non-WPGraphQL GraphQL traces
```

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: All tests pass

- [ ] **Step 3: Commit**

```
git add CHANGELOG.md
git commit -m "docs: update CHANGELOG with GraphQL instrumentation features"
```

- [ ] **Step 4: Rebuild ZIP**

```
cd /path/to/worktree && zip -r /path/to/wp-flame-1.0.1.zip \
  wp-flame.php uninstall.php readme.txt CHANGELOG.md \
  assets/ mu-plugin/ src/ vendor/ \
  -x "vendor/bin/*" "vendor/phpunit/*" "vendor/yoast/*" "vendor/phar-io/*" \
  "vendor/sebastian/*" "vendor/myclabs/*" "vendor/nikic/*" "vendor/phpspec/*" \
  "vendor/doctrine/*" "vendor/theseer/*"
```

- [ ] **Step 5: Commit ZIP if applicable**

Only if the ZIP is tracked in the repository.
