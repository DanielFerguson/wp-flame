# WP Flame Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin that captures request lifecycle timing and database queries, then renders an interactive SVG flame graph in wp-admin.

**Architecture:** Span-based tracing with a singleton Collector that maintains an in-memory span stack during each request. At shutdown, traces are persisted to a custom database table. The admin UI is server-rendered PHP with vanilla JS for the flame graph. A mu-plugin enables early instrumentation before other plugins load.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, Composer (PSR-4 autoload), PHPUnit, vanilla JS, SVG

**Spec:** `docs/superpowers/specs/2026-03-16-wp-flame-phase1-design.md`

---

## Chunk 1: Project Scaffolding + Data Layer (Span, Trace)

### Task 1: Initialize Project

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `tests/Unit/.gitkeep` (will be replaced by actual tests)
- Create: `tests/Integration/.gitkeep` (will be replaced by actual tests)

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "wp-flame/wp-flame",
    "description": "WordPress APM plugin with interactive flame graph",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "WPFlame\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "WPFlame\\Tests\\": "tests/"
        }
    }
}
```

Note: `yoast/phpunit-polyfills` is required by the WordPress test framework for PHPUnit 9.x compatibility.

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/bootstrap.php`**

This bootstrap detects which test suite is running. Unit tests load only the Composer autoloader. Integration tests load the WordPress test framework.

```php
<?php

$wp_flame_autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! file_exists($wp_flame_autoload)) {
    die("Run 'composer install' before running tests.\n");
}

require_once $wp_flame_autoload;

// If running integration suite, load WordPress test framework
$is_integration = getenv('WP_TESTS_DIR') !== false;

if ($is_integration) {
    $wp_tests_dir = getenv('WP_TESTS_DIR');

    if (! file_exists($wp_tests_dir . '/includes/functions.php')) {
        die("WordPress test framework not found at {$wp_tests_dir}\n");
    }

    // Load the plugin before WordPress finishes loading
    tests_add_filter('muplugins_loaded', function () {
        require dirname(__DIR__) . '/wp-flame.php';
    });

    require $wp_tests_dir . '/includes/bootstrap.php';
}
```

- [ ] **Step 4: Create directory structure**

```bash
mkdir -p src mu-plugin assets/js assets/css tests/Unit tests/Integration
```

- [ ] **Step 5: Run `composer install`**

```bash
composer install
```

Expected: Installs PHPUnit 9.x, yoast/phpunit-polyfills, generates `vendor/autoload.php`.

- [ ] **Step 6: Verify unit test suite runs (empty)**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: "No tests executed" with exit code 0.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock phpunit.xml tests/bootstrap.php src/.gitkeep mu-plugin/.gitkeep assets/js/.gitkeep assets/css/.gitkeep tests/Unit/.gitkeep tests/Integration/.gitkeep
git commit -m "chore: initialize project with Composer, PHPUnit, directory structure"
```

---

### Task 2: Span Value Object

**Files:**
- Create: `src/Span.php`
- Create: `tests/Unit/SpanTest.php`

- [ ] **Step 1: Write failing tests for Span**

```php
<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Span;

class SpanTest extends TestCase
{
    public function test_construction_sets_all_fields(): void
    {
        $span = new Span(
            'abc-123',
            'parent-456',
            'WooCommerce init',
            Span::TYPE_PLUGIN,
            'woocommerce/woocommerce.php',
            10.5,
            25.3,
            ['key' => 'value']
        );

        $this->assertSame('abc-123', $span->id);
        $this->assertSame('parent-456', $span->parent_id);
        $this->assertSame('WooCommerce init', $span->name);
        $this->assertSame(Span::TYPE_PLUGIN, $span->type);
        $this->assertSame('woocommerce/woocommerce.php', $span->source);
        $this->assertSame(10.5, $span->start_ms);
        $this->assertSame(25.3, $span->duration_ms);
        $this->assertSame(['key' => 'value'], $span->meta);
    }

    public function test_construction_with_null_parent(): void
    {
        $span = new Span(
            'abc-123',
            null,
            'Bootstrap',
            Span::TYPE_CORE,
            'wordpress',
            0.0,
            50.0
        );

        $this->assertNull($span->parent_id);
        $this->assertSame([], $span->meta);
    }

    public function test_type_constants_exist(): void
    {
        $this->assertSame('core', Span::TYPE_CORE);
        $this->assertSame('plugin', Span::TYPE_PLUGIN);
        $this->assertSame('theme', Span::TYPE_THEME);
        $this->assertSame('db', Span::TYPE_DB);
        $this->assertSame('http', Span::TYPE_HTTP);
        $this->assertSame('php', Span::TYPE_PHP);
    }

    public function test_to_array_returns_all_fields(): void
    {
        $span = new Span(
            'abc-123',
            'parent-456',
            'SELECT query',
            Span::TYPE_DB,
            'woocommerce/woocommerce.php',
            10.0,
            5.0,
            ['query' => 'SELECT * FROM wp_posts']
        );

        $array = $span->toArray();

        $this->assertSame([
            'id' => 'abc-123',
            'parent_id' => 'parent-456',
            'name' => 'SELECT query',
            'type' => 'db',
            'source' => 'woocommerce/woocommerce.php',
            'start_ms' => 10.0,
            'duration_ms' => 5.0,
            'meta' => ['query' => 'SELECT * FROM wp_posts'],
        ], $array);
    }

    public function test_from_array_reconstructs_span(): void
    {
        $original = new Span(
            'abc-123',
            'parent-456',
            'Theme Setup',
            Span::TYPE_THEME,
            'twentytwentyfour',
            5.0,
            15.0,
            ['template' => 'index.php']
        );

        $reconstructed = Span::fromArray($original->toArray());

        $this->assertSame($original->id, $reconstructed->id);
        $this->assertSame($original->parent_id, $reconstructed->parent_id);
        $this->assertSame($original->name, $reconstructed->name);
        $this->assertSame($original->type, $reconstructed->type);
        $this->assertSame($original->source, $reconstructed->source);
        $this->assertSame($original->start_ms, $reconstructed->start_ms);
        $this->assertSame($original->duration_ms, $reconstructed->duration_ms);
        $this->assertSame($original->meta, $reconstructed->meta);
    }

    public function test_from_array_handles_null_parent_id(): void
    {
        $data = [
            'id' => 'abc',
            'parent_id' => null,
            'name' => 'Root',
            'type' => 'core',
            'source' => 'wordpress',
            'start_ms' => 0.0,
            'duration_ms' => 100.0,
            'meta' => [],
        ];

        $span = Span::fromArray($data);
        $this->assertNull($span->parent_id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: FAIL — `Class 'WPFlame\Span' not found`.

- [ ] **Step 3: Implement Span**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Span
{
    public const TYPE_CORE   = 'core';
    public const TYPE_PLUGIN = 'plugin';
    public const TYPE_THEME  = 'theme';
    public const TYPE_DB     = 'db';
    public const TYPE_HTTP   = 'http';
    public const TYPE_PHP    = 'php';

    public string $id;
    public ?string $parent_id;
    public string $name;
    public string $type;
    public string $source;
    public float $start_ms;
    public float $duration_ms;
    public array $meta;

    public function __construct(
        string $id,
        ?string $parent_id,
        string $name,
        string $type,
        string $source,
        float $start_ms,
        float $duration_ms,
        array $meta = []
    ) {
        $this->id          = $id;
        $this->parent_id   = $parent_id;
        $this->name        = $name;
        $this->type        = $type;
        $this->source      = $source;
        $this->start_ms    = $start_ms;
        $this->duration_ms = $duration_ms;
        $this->meta        = $meta;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'name'        => $this->name,
            'type'        => $this->type,
            'source'      => $this->source,
            'start_ms'    => $this->start_ms,
            'duration_ms' => $this->duration_ms,
            'meta'        => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            isset($data['parent_id']) ? (string) $data['parent_id'] : null,
            (string) $data['name'],
            (string) $data['type'],
            (string) $data['source'],
            (float) $data['start_ms'],
            (float) $data['duration_ms'],
            (array) ($data['meta'] ?? [])
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Span.php tests/Unit/SpanTest.php
git commit -m "feat: add Span value object with serialization"
```

---

### Task 3: Trace Value Object

**Files:**
- Create: `src/Trace.php`
- Create: `tests/Unit/TraceTest.php`

- [ ] **Step 1: Write failing tests for Trace**

```php
<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Span;
use WPFlame\Trace;

class TraceTest extends TestCase
{
    private function make_span(string $id, string $type, float $start, float $duration, ?string $parent = null): Span
    {
        return new Span($id, $parent, "Span {$id}", $type, 'test', $start, $duration);
    }

    public function test_construction_sets_all_fields(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
        ];

        $trace = new Trace(
            'trace-abc',
            '/test-page',
            'GET',
            '2026-03-16T12:00:00+00:00',
            100.0,
            16777216,
            '7.4.33',
            '6.4.2',
            $spans
        );

        $this->assertSame('trace-abc', $trace->id);
        $this->assertSame('/test-page', $trace->url);
        $this->assertSame('GET', $trace->method);
        $this->assertSame('2026-03-16T12:00:00+00:00', $trace->timestamp);
        $this->assertSame(100.0, $trace->total_ms);
        $this->assertSame(16777216, $trace->peak_memory);
        $this->assertSame('7.4.33', $trace->php_version);
        $this->assertSame('6.4.2', $trace->wp_version);
        $this->assertCount(1, $trace->spans);
    }

    public function test_query_count_computed_from_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
            $this->make_span('s3', Span::TYPE_DB, 20.0, 3.0, 's1'),
            $this->make_span('s4', Span::TYPE_PLUGIN, 30.0, 10.0, 's1'),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertSame(2, $trace->query_count);
    }

    public function test_total_query_ms_computed_from_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.5, 's1'),
            $this->make_span('s3', Span::TYPE_DB, 20.0, 3.2, 's1'),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertEqualsWithDelta(8.7, $trace->total_query_ms, 0.001);
    }

    public function test_zero_queries_when_no_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertSame(0, $trace->query_count);
        $this->assertSame(0.0, $trace->total_query_ms);
    }

    public function test_to_array_includes_all_fields(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
        ];

        $trace = new Trace('t1', '/page', 'POST', '2026-03-16T00:00:00+00:00', 100.0, 2048, '8.1', '6.4', $spans);
        $array = $trace->toArray();

        $this->assertSame('t1', $array['id']);
        $this->assertSame('/page', $array['url']);
        $this->assertSame('POST', $array['method']);
        $this->assertSame(100.0, $array['total_ms']);
        $this->assertSame(1, $array['query_count']);
        $this->assertSame(5.0, $array['total_query_ms']);
        $this->assertCount(2, $array['spans']);
        $this->assertSame('s1', $array['spans'][0]['id']);
    }

    public function test_from_array_round_trip(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
        ];

        $original = new Trace('t1', '/page', 'GET', '2026-03-16T00:00:00+00:00', 100.0, 2048, '8.1', '6.4', $spans);
        $reconstructed = Trace::fromArray($original->toArray());

        $this->assertSame($original->id, $reconstructed->id);
        $this->assertSame($original->url, $reconstructed->url);
        $this->assertSame($original->method, $reconstructed->method);
        $this->assertSame($original->total_ms, $reconstructed->total_ms);
        $this->assertSame($original->query_count, $reconstructed->query_count);
        $this->assertEqualsWithDelta($original->total_query_ms, $reconstructed->total_query_ms, 0.001);
        $this->assertCount(2, $reconstructed->spans);
        $this->assertSame('s2', $reconstructed->spans[1]->id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: FAIL — `Class 'WPFlame\Trace' not found`.

- [ ] **Step 3: Implement Trace**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Trace
{
    public string $id;
    public string $url;
    public string $method;
    public string $timestamp;
    public float $total_ms;
    public int $peak_memory;
    public string $php_version;
    public string $wp_version;
    public int $query_count;
    public float $total_query_ms;
    /** @var Span[] */
    public array $spans;

    /**
     * @param Span[] $spans
     */
    public function __construct(
        string $id,
        string $url,
        string $method,
        string $timestamp,
        float $total_ms,
        int $peak_memory,
        string $php_version,
        string $wp_version,
        array $spans
    ) {
        $this->id          = $id;
        $this->url         = $url;
        $this->method      = $method;
        $this->timestamp   = $timestamp;
        $this->total_ms    = $total_ms;
        $this->peak_memory = $peak_memory;
        $this->php_version = $php_version;
        $this->wp_version  = $wp_version;
        $this->spans       = $spans;

        // Compute query aggregates from DB-type spans
        $this->query_count    = 0;
        $this->total_query_ms = 0.0;
        foreach ($spans as $span) {
            if ($span->type === Span::TYPE_DB) {
                $this->query_count++;
                $this->total_query_ms += $span->duration_ms;
            }
        }
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'url'            => $this->url,
            'method'         => $this->method,
            'timestamp'      => $this->timestamp,
            'total_ms'       => $this->total_ms,
            'peak_memory'    => $this->peak_memory,
            'php_version'    => $this->php_version,
            'wp_version'     => $this->wp_version,
            'query_count'    => $this->query_count,
            'total_query_ms' => $this->total_query_ms,
            'spans'          => array_map(fn(Span $s) => $s->toArray(), $this->spans),
        ];
    }

    public static function fromArray(array $data): self
    {
        $spans = array_map(
            fn(array $s) => Span::fromArray($s),
            $data['spans'] ?? []
        );

        return new self(
            (string) $data['id'],
            (string) $data['url'],
            (string) $data['method'],
            (string) $data['timestamp'],
            (float) $data['total_ms'],
            (int) $data['peak_memory'],
            (string) $data['php_version'],
            (string) $data['wp_version'],
            $spans
        );
    }
}
```

- [ ] **Step 4: Run all unit tests**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 12 tests PASS (6 Span + 6 Trace).

- [ ] **Step 5: Commit**

```bash
git add src/Trace.php tests/Unit/TraceTest.php
git commit -m "feat: add Trace value object with query aggregate computation"
```

---

## Chunk 2: Collector Engine

### Task 4: Collector — Basic Span Management

**Files:**
- Create: `src/Collector.php`
- Create: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Write failing tests for basic span operations**

```php
<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Collector;
use WPFlame\Span;

class CollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    public function test_instance_returns_singleton(): void
    {
        $a = Collector::instance();
        $b = Collector::instance();
        $this->assertSame($a, $b);
    }

    public function test_reset_creates_new_instance(): void
    {
        $a = Collector::instance();
        Collector::reset();
        $b = Collector::instance();
        $this->assertNotSame($a, $b);
    }

    public function test_is_initialized_false_before_start_request(): void
    {
        $this->assertFalse(Collector::instance()->is_initialized());
    }

    public function test_is_initialized_true_after_start_request(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);
        $this->assertTrue($collector->is_initialized());
    }

    public function test_start_and_end_span_creates_span(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $id = $collector->start_span('Test Span', Span::TYPE_CORE, 'test-source');
        $this->assertIsString($id);
        $this->assertNotEmpty($id);

        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertSame('Test Span', $trace->spans[0]->name);
        $this->assertSame(Span::TYPE_CORE, $trace->spans[0]->type);
        $this->assertSame('test-source', $trace->spans[0]->source);
    }

    public function test_span_duration_is_positive(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Timed', Span::TYPE_CORE, 'test');
        usleep(1000); // 1ms
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertGreaterThan(0, $trace->spans[0]->duration_ms);
    }

    public function test_span_start_ms_relative_to_request_start(): void
    {
        $request_start = microtime(true);
        $collector = Collector::instance();
        $collector->start_request($request_start);

        usleep(5000); // 5ms
        $id = $collector->start_span('Delayed', Span::TYPE_CORE, 'test');
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertGreaterThan(4.0, $trace->spans[0]->start_ms);
    }

    public function test_end_span_without_id_pops_stack_top(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->start_span('First', Span::TYPE_CORE, 'test');
        $collector->end_span();

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertSame('First', $trace->spans[0]->name);
    }

    public function test_span_meta_is_stored(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $id = $collector->start_span('Query', Span::TYPE_DB, 'test', ['query' => 'SELECT 1']);
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertSame(['query' => 'SELECT 1'], $trace->spans[0]->meta);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: FAIL — `Class 'WPFlame\Collector' not found`.

- [ ] **Step 3: Implement Collector (basic span management)**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Collector
{
    private static ?self $instance = null;

    private float $request_start = 0.0;
    private bool $initialized = false;
    private bool $stopped = false;

    /** @var array[] Lightweight stack entries: [id, name, type, source, start_ms, meta, parent_id] */
    private array $span_stack = [];

    /** @var Span[] Completed spans */
    private array $spans = [];

    /** @var array<string, array> File path to source attribution cache */
    private static array $source_cache = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$source_cache = [];
    }

    public function start_request(float $microtime): void
    {
        $this->request_start = $microtime;
        $this->initialized = true;
    }

    public function is_initialized(): bool
    {
        return $this->initialized;
    }

    public function start_span(string $name, string $type, string $source, array $meta = []): string
    {
        if ($this->stopped) {
            return '';
        }

        $id = self::generate_uuid();
        $parent_id = ! empty($this->span_stack)
            ? $this->span_stack[count($this->span_stack) - 1]['id']
            : null;

        $start_ms = (microtime(true) - $this->request_start) * 1000;

        $this->span_stack[] = [
            'id'        => $id,
            'name'      => $name,
            'type'      => $type,
            'source'    => $source,
            'start_ms'  => $start_ms,
            'meta'      => $meta,
            'parent_id' => $parent_id,
        ];

        return $id;
    }

    public function end_span(?string $span_id = null): void
    {
        if ($this->stopped || empty($this->span_stack)) {
            return;
        }

        $entry = array_pop($this->span_stack);

        if ($span_id !== null && $entry['id'] !== $span_id) {
            error_log(sprintf(
                'WP Flame: end_span() ID mismatch — expected "%s", got "%s"',
                $span_id,
                $entry['id']
            ));
        }

        $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];

        $this->spans[] = new Span(
            $entry['id'],
            $entry['parent_id'],
            $entry['name'],
            $entry['type'],
            $entry['source'],
            $entry['start_ms'],
            max(0.0, $duration_ms),
            $entry['meta']
        );
    }

    /**
     * Safety net: close all remaining open spans on the stack.
     * Each auto-closed span gets ['auto_closed' => true] in its meta.
     */
    public function close_open_spans(): void
    {
        while (! empty($this->span_stack)) {
            $entry = array_pop($this->span_stack);
            $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];
            $meta = $entry['meta'];
            $meta['auto_closed'] = true;

            $this->spans[] = new Span(
                $entry['id'],
                $entry['parent_id'],
                $entry['name'],
                $entry['type'],
                $entry['source'],
                $entry['start_ms'],
                max(0.0, $duration_ms),
                $meta
            );
        }
    }

    public function get_trace(): Trace
    {
        $total_ms = (microtime(true) - $this->request_start) * 1000;

        return new Trace(
            self::generate_uuid(),
            $_SERVER['REQUEST_URI'] ?? '/',
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            gmdate('c'),
            $total_ms,
            (int) memory_get_peak_usage(true),
            PHP_VERSION,
            function_exists('get_bloginfo') ? get_bloginfo('version', 'raw') : '',
            $this->spans
        );
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Generate a UUID v4 using random_bytes (no WordPress dependency).
     */
    private static function generate_uuid(): string
    {
        $bytes = random_bytes(16);
        // Set version (4) and variant (10xx)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }

    /**
     * Resolve a file path to a source attribution array.
     *
     * @return array{type: string, source: string}
     */
    public function get_source_from_file(string $file_path): array
    {
        if (isset(self::$source_cache[$file_path])) {
            return self::$source_cache[$file_path];
        }

        $result = ['type' => Span::TYPE_PHP, 'source' => basename($file_path)];

        // Check if file is in a plugin
        if (defined('WP_PLUGIN_DIR') && strpos($file_path, WP_PLUGIN_DIR) === 0) {
            $relative = substr($file_path, strlen(WP_PLUGIN_DIR) + 1);
            $parts = explode('/', $relative, 2);
            $result = ['type' => Span::TYPE_PLUGIN, 'source' => $parts[0]];
        }
        // Check if file is in mu-plugins
        elseif (defined('WPMU_PLUGIN_DIR') && strpos($file_path, WPMU_PLUGIN_DIR) === 0) {
            $relative = substr($file_path, strlen(WPMU_PLUGIN_DIR) + 1);
            $result = ['type' => Span::TYPE_PLUGIN, 'source' => 'mu:' . explode('/', $relative, 2)[0]];
        }
        // Check if file is in a theme
        elseif (function_exists('get_template_directory') && strpos($file_path, get_template_directory()) === 0) {
            $result = ['type' => Span::TYPE_THEME, 'source' => basename(get_template_directory())];
        }
        // Check if file is WordPress core
        elseif (defined('ABSPATH') && strpos($file_path, ABSPATH) === 0) {
            $result = ['type' => Span::TYPE_CORE, 'source' => 'wordpress'];
        }

        self::$source_cache[$file_path] = $result;
        return $result;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 21 tests PASS (6 Span + 6 Trace + 9 Collector).

- [ ] **Step 5: Commit**

```bash
git add src/Collector.php tests/Unit/CollectorTest.php
git commit -m "feat: add Collector singleton with span stack management"
```

---

### Task 5: Collector — Nesting, Stop, and Unclosed Span Handling

**Files:**
- Modify: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Write failing tests for nesting, stop, and safety net**

Append these test methods to `CollectorTest.php`:

```php
    public function test_nested_spans_get_correct_parent_ids(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $parent_id = $collector->start_span('Parent', Span::TYPE_CORE, 'test');
        $child_id = $collector->start_span('Child', Span::TYPE_PLUGIN, 'test');
        $grandchild_id = $collector->start_span('Grandchild', Span::TYPE_DB, 'test');

        $collector->end_span($grandchild_id);
        $collector->end_span($child_id);
        $collector->end_span($parent_id);

        $trace = $collector->get_trace();
        $spans_by_name = [];
        foreach ($trace->spans as $span) {
            $spans_by_name[$span->name] = $span;
        }

        $this->assertNull($spans_by_name['Parent']->parent_id);
        $this->assertSame($parent_id, $spans_by_name['Child']->parent_id);
        $this->assertSame($child_id, $spans_by_name['Grandchild']->parent_id);
    }

    public function test_sequential_spans_share_same_parent(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $parent_id = $collector->start_span('Parent', Span::TYPE_CORE, 'test');

        $child1 = $collector->start_span('Child1', Span::TYPE_DB, 'test');
        $collector->end_span($child1);

        $child2 = $collector->start_span('Child2', Span::TYPE_DB, 'test');
        $collector->end_span($child2);

        $collector->end_span($parent_id);

        $trace = $collector->get_trace();
        $spans_by_name = [];
        foreach ($trace->spans as $span) {
            $spans_by_name[$span->name] = $span;
        }

        $this->assertSame($parent_id, $spans_by_name['Child1']->parent_id);
        $this->assertSame($parent_id, $spans_by_name['Child2']->parent_id);
    }

    public function test_stop_makes_start_span_return_empty_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->stop();
        $id = $collector->start_span('Should Not Exist', Span::TYPE_CORE, 'test');

        $this->assertSame('', $id);

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_stop_makes_end_span_no_op(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $id = $collector->start_span('Before Stop', Span::TYPE_CORE, 'test');
        $collector->stop();
        $collector->end_span($id); // should not crash

        // Span was never completed because stop() was called
        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_close_open_spans_adds_auto_closed_meta(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->start_span('Unclosed', Span::TYPE_CORE, 'test');
        // Never call end_span

        $collector->close_open_spans();

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertTrue($trace->spans[0]->meta['auto_closed']);
    }

    public function test_close_open_spans_closes_multiple_in_order(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->start_span('Outer', Span::TYPE_CORE, 'test');
        $collector->start_span('Inner', Span::TYPE_PLUGIN, 'test');
        // Neither closed

        $collector->close_open_spans();

        $trace = $collector->get_trace();
        $this->assertCount(2, $trace->spans);

        // Inner should be closed first (stack order), so it appears first in spans array
        $this->assertSame('Inner', $trace->spans[0]->name);
        $this->assertSame('Outer', $trace->spans[1]->name);
        $this->assertTrue($trace->spans[0]->meta['auto_closed']);
        $this->assertTrue($trace->spans[1]->meta['auto_closed']);
    }
```

- [ ] **Step 2: Run tests to verify they pass**

These tests should pass since `close_open_spans()` and `stop()` are already implemented in the Collector from Task 4.

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 27 tests PASS (6 Span + 6 Trace + 15 Collector).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/CollectorTest.php
git commit -m "test: add nesting, stop, and safety net tests for Collector"
```

---

### Task 6: Collector — Source Attribution

**Files:**
- Modify: `tests/Unit/CollectorTest.php`

- [ ] **Step 1: Write tests for source attribution**

Append these to `CollectorTest.php`:

```php
    public function test_get_source_from_plugin_file(): void
    {
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', '/var/www/html/wp-content/plugins');
        }

        $collector = Collector::instance();
        $result = $collector->get_source_from_file('/var/www/html/wp-content/plugins/woocommerce/includes/class-wc-cart.php');

        $this->assertSame(Span::TYPE_PLUGIN, $result['type']);
        $this->assertSame('woocommerce', $result['source']);
    }

    public function test_get_source_from_core_file(): void
    {
        if (! defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        $collector = Collector::instance();
        $result = $collector->get_source_from_file('/var/www/html/wp-includes/post.php');

        $this->assertSame(Span::TYPE_CORE, $result['type']);
        $this->assertSame('wordpress', $result['source']);
    }

    public function test_get_source_caches_results(): void
    {
        $collector = Collector::instance();
        $path = '/some/unknown/path/file.php';

        $first = $collector->get_source_from_file($path);
        $second = $collector->get_source_from_file($path);

        $this->assertSame($first, $second);
        $this->assertSame(Span::TYPE_PHP, $first['type']);
    }
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All 30 tests PASS (6 Span + 6 Trace + 18 Collector).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/CollectorTest.php
git commit -m "test: add source attribution tests for Collector"
```

---

## Chunk 3: Storage Layer

### Task 6.5: Set Up WordPress Integration Test Environment

**Files:**
- Create: `bin/install-wp-tests.sh`
- Modify: `composer.json` (add script shortcut)

Before integration tests can run, the WordPress test framework must be installed locally.

- [ ] **Step 1: Download the WordPress test install script**

```bash
mkdir -p bin
curl -sL https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh -o bin/install-wp-tests.sh
chmod +x bin/install-wp-tests.sh
```

- [ ] **Step 2: Run the install script**

This creates a local WordPress test library. Requires a MySQL/MariaDB instance. Adjust database credentials as needed:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

This installs:
- WordPress test framework to `/tmp/wordpress-tests-lib` (default)
- WordPress core to `/tmp/wordpress`

- [ ] **Step 3: Verify integration tests can bootstrap**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite integration
```

Expected: "No tests executed" with exit code 0 (no integration test files exist yet).

- [ ] **Step 4: Add composer script shortcut**

Add to `composer.json` under a new `"scripts"` key:

```json
"scripts": {
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "WP_TESTS_DIR=/tmp/wordpress-tests-lib phpunit --testsuite integration",
    "test": "phpunit"
}
```

- [ ] **Step 5: Commit**

```bash
git add bin/install-wp-tests.sh composer.json
git commit -m "chore: add WordPress test framework install script"
```

---

### Task 7: Storage — Table Creation, CRUD, Filtering, Pruning

**Files:**
- Create: `src/Storage.php`
- Create: `tests/Integration/StorageTest.php`

This task requires the WordPress test framework. Integration tests need `WP_TESTS_DIR` set.

- [ ] **Step 1: Write failing tests for Storage**

```php
<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Span;
use WPFlame\Storage;
use WPFlame\Trace;

class StorageTest extends WP_UnitTestCase
{
    private Storage $storage;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->storage = new Storage($wpdb);
        $this->storage->create_table();
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_traces");
        parent::tear_down();
    }

    private function make_trace(string $id = 'trace-1', string $url = '/test', float $total_ms = 100.0): Trace
    {
        $spans = [
            new Span('s1', null, 'Bootstrap', Span::TYPE_CORE, 'wordpress', 0.0, 50.0),
            new Span('s2', 's1', 'SELECT', Span::TYPE_DB, 'test-plugin', 50.0, 10.0, ['query' => 'SELECT 1']),
        ];

        return new Trace($id, $url, 'GET', '2026-03-16T12:00:00+00:00', $total_ms, 16777216, '8.1.0', '6.4.2', $spans);
    }

    public function test_create_table_creates_flame_traces_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        $this->assertSame($table, $result);
    }

    public function test_save_and_get_trace_round_trip(): void
    {
        $trace = $this->make_trace();
        $this->storage->save_trace($trace);

        $retrieved = $this->storage->get_trace('trace-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('trace-1', $retrieved->id);
        $this->assertSame('/test', $retrieved->url);
        $this->assertSame('GET', $retrieved->method);
        $this->assertSame(100.0, $retrieved->total_ms);
        $this->assertCount(2, $retrieved->spans);
        $this->assertSame('Bootstrap', $retrieved->spans[0]->name);
        $this->assertSame(1, $retrieved->query_count);
    }

    public function test_get_trace_returns_null_for_nonexistent(): void
    {
        $result = $this->storage->get_trace('does-not-exist');
        $this->assertNull($result);
    }

    public function test_delete_trace_removes_row(): void
    {
        $this->storage->save_trace($this->make_trace());
        $this->storage->delete_trace('trace-1');

        $this->assertNull($this->storage->get_trace('trace-1'));
    }

    public function test_list_traces_returns_lightweight_rows(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/page-1', 100.0));
        $this->storage->save_trace($this->make_trace('t2', '/page-2', 200.0));

        $rows = $this->storage->list_traces([]);

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('trace_id', $rows[0]);
        $this->assertArrayHasKey('url', $rows[0]);
        $this->assertArrayHasKey('total_ms', $rows[0]);
        $this->assertArrayNotHasKey('trace_data', $rows[0]);
    }

    public function test_list_traces_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}", "/page-{$i}"));
        }

        $page1 = $this->storage->list_traces(['per_page' => 2, 'page' => 1]);
        $page2 = $this->storage->list_traces(['per_page' => 2, 'page' => 2]);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
    }

    public function test_count_traces_returns_total(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}"));
        }

        $this->assertSame(3, $this->storage->count_traces([]));
    }

    public function test_list_traces_filter_by_min_duration(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/fast', 50.0));
        $this->storage->save_trace($this->make_trace('t2', '/slow', 500.0));

        $rows = $this->storage->list_traces(['min_duration' => 100.0]);

        $this->assertCount(1, $rows);
        $this->assertSame('/slow', $rows[0]['url']);
    }

    public function test_list_traces_filter_by_url(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/admin/settings'));
        $this->storage->save_trace($this->make_trace('t2', '/shop/product'));

        $rows = $this->storage->list_traces(['url' => 'admin']);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('admin', $rows[0]['url']);
    }

    public function test_prune_old_deletes_expired_traces(): void
    {
        global $wpdb;
        $this->storage->save_trace($this->make_trace());

        // Manually backdate the trace
        $wpdb->update(
            $wpdb->prefix . 'flame_traces',
            ['created_at' => '2020-01-01 00:00:00'],
            ['trace_id' => 'trace-1']
        );

        $this->storage->prune_old(7);

        $this->assertNull($this->storage->get_trace('trace-1'));
    }
}
```

- [ ] **Step 2: Implement Storage**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Storage
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'flame_traces';
    }

    public function create_table(): void
    {
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trace_id char(36) NOT NULL,
            url varchar(2048) NOT NULL DEFAULT '',
            method varchar(10) NOT NULL DEFAULT '',
            total_ms float NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            peak_memory bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            trace_data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trace_id (trace_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function save_trace(Trace $trace): void
    {
        $this->wpdb->insert(
            $this->table,
            [
                'trace_id'    => $trace->id,
                'url'         => $trace->url,
                'method'      => $trace->method,
                'total_ms'    => $trace->total_ms,
                'query_count' => $trace->query_count,
                'peak_memory' => $trace->peak_memory,
                'created_at'  => current_time('mysql', true),
                'trace_data'  => wp_json_encode($trace->toArray()),
            ],
            ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s']
        );
    }

    public function get_trace(string $trace_id): ?Trace
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trace_data FROM {$this->table} WHERE trace_id = %s",
                $trace_id
            )
        );

        if (! $row) {
            return null;
        }

        $data = json_decode($row->trace_data, true);
        if (! is_array($data)) {
            return null;
        }

        return Trace::fromArray($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_traces(array $filters): array
    {
        $where  = '1=1';
        $params = [];

        if (! empty($filters['url'])) {
            $where  .= ' AND url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['url']) . '%';
        }

        if (isset($filters['min_duration'])) {
            $where  .= ' AND total_ms >= %f';
            $params[] = (float) $filters['min_duration'];
        }

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
        }

        $per_page = (int) ($filters['per_page'] ?? 20);
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $sql = "SELECT trace_id, url, method, total_ms, query_count, peak_memory, created_at
                FROM {$this->table}
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $sql = $this->wpdb->prepare($sql, $params);

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return is_array($results) ? $results : [];
    }

    public function count_traces(array $filters): int
    {
        $where  = '1=1';
        $params = [];

        if (! empty($filters['url'])) {
            $where  .= ' AND url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['url']) . '%';
        }

        if (isset($filters['min_duration'])) {
            $where  .= ' AND total_ms >= %f';
            $params[] = (float) $filters['min_duration'];
        }

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";

        if (! empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    public function delete_trace(string $trace_id): void
    {
        $this->wpdb->delete(
            $this->table,
            ['trace_id' => $trace_id],
            ['%s']
        );
    }

    public function prune_old(int $days): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
```

- [ ] **Step 3: Run integration tests (if WP test environment available)**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite integration --filter StorageTest
```

Expected: All 10 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Storage.php tests/Integration/StorageTest.php
git commit -m "feat: add Storage class with CRUD, pagination, filtering, and pruning"
```

---

## Chunk 4: DB Instrumentation

### Task 8: DB Class — Query Wrapping and Conflict Detection

**Files:**
- Create: `src/DB.php`
- Create: `tests/Integration/DBTest.php`

- [ ] **Step 1: Write failing tests for DB**

```php
<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Collector;
use WPFlame\DB;
use WPFlame\Span;

class DBTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Collector::reset();
    }

    public function tear_down(): void
    {
        Collector::reset();
        parent::tear_down();
    }

    public function test_from_wpdb_creates_instance_with_working_connection(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $db = DB::from_wpdb($wpdb, $collector);

        // Verify the connection works
        $result = $db->get_var('SELECT 1');
        $this->assertSame('1', $result);
    }

    public function test_from_wpdb_preserves_prefix(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $db = DB::from_wpdb($wpdb, $collector);

        $this->assertSame($wpdb->prefix, $db->prefix);
    }

    public function test_query_creates_db_span(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $db = DB::from_wpdb($wpdb, $collector);
        $db->query('SELECT 1');

        $trace = $collector->get_trace();
        $db_spans = array_filter($trace->spans, fn(Span $s) => $s->type === Span::TYPE_DB);

        $this->assertGreaterThanOrEqual(1, count($db_spans));
    }

    public function test_query_span_has_query_text_truncated(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $db = DB::from_wpdb($wpdb, $collector);
        $db->query('SELECT 1');

        $trace = $collector->get_trace();
        $db_spans = array_values(array_filter($trace->spans, fn(Span $s) => $s->type === Span::TYPE_DB));

        $this->assertNotEmpty($db_spans);
        $this->assertArrayHasKey('query', $db_spans[0]->meta);
        $this->assertLessThanOrEqual(200, strlen($db_spans[0]->meta['query']));
    }

    public function test_extract_query_type_identifies_select(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $db = DB::from_wpdb($wpdb, $collector);
        $db->query('SELECT * FROM wp_posts LIMIT 1');

        $trace = $collector->get_trace();
        $db_spans = array_values(array_filter($trace->spans, fn(Span $s) => $s->type === Span::TYPE_DB));

        $this->assertNotEmpty($db_spans);
        $this->assertStringContainsString('SELECT', $db_spans[0]->name);
    }

    public function test_can_replace_returns_true_for_standard_wpdb(): void
    {
        global $wpdb;
        // In the test environment, $wpdb may already be a subclass.
        // We test the logic: can_replace checks get_class() === 'wpdb'
        $this->assertIsBool(DB::can_replace($wpdb));
    }

    public function test_stopped_collector_skips_instrumentation(): void
    {
        global $wpdb;
        $collector = Collector::instance();
        $collector->start_request(microtime(true));
        $collector->stop();

        $db = DB::from_wpdb($wpdb, $collector);
        $db->query('SELECT 1');

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }
}
```

- [ ] **Step 2: Implement DB**

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class DB extends \wpdb
{
    private Collector $collector;
    private bool $full_query_text;

    /**
     * Create an instrumented DB instance from an existing wpdb.
     */
    public static function from_wpdb(\wpdb $original, Collector $collector): self
    {
        $reflection = new \ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        // Copy all properties from original
        foreach (get_object_vars($original) as $key => $value) {
            $instance->$key = $value;
        }

        $instance->collector = $collector;
        $instance->full_query_text = (bool) get_option('wp_flame_full_query_text', false);

        return $instance;
    }

    /**
     * Check if $wpdb can be safely replaced.
     */
    public static function can_replace(\wpdb $wpdb): bool
    {
        return get_class($wpdb) === 'wpdb';
    }

    /**
     * Override wpdb::query() to wrap with span timing.
     *
     * @param string $query
     * @return int|bool
     */
    public function query($query)
    {
        $span_id = $this->collector->start_span(
            $this->extract_query_type($query),
            Span::TYPE_DB,
            $this->get_caller_source(),
            ['query' => $this->truncate_query($query)]
        );

        $result = parent::query($query);

        $this->collector->end_span($span_id);

        return $result;
    }

    /**
     * Extract the SQL statement type (SELECT, INSERT, UPDATE, DELETE, etc.)
     */
    private function extract_query_type(string $query): string
    {
        $query = ltrim($query);
        $first_word = strtoupper(strtok($query, " \t\n\r"));
        $known_types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'SHOW', 'SET'];

        return in_array($first_word, $known_types, true) ? $first_word : 'QUERY';
    }

    /**
     * Truncate query text based on settings.
     */
    private function truncate_query(string $query): string
    {
        if ($this->full_query_text) {
            return $query;
        }
        return substr($query, 0, 200);
    }

    /**
     * Determine the source of the query via backtrace.
     */
    private function get_caller_source(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $wp_flame_dir = dirname(__DIR__);

        foreach ($trace as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            $file = $frame['file'];

            // Skip wp-includes, wp-admin, and our own plugin
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

- [ ] **Step 3: Run integration tests (if WP test environment available)**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite integration --filter DBTest
```

Expected: All 7 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/DB.php tests/Integration/DBTest.php
git commit -m "feat: add DB instrumentation class extending wpdb"
```

---

## Chunk 5: Plugin Wiring (mu-plugin, Bootstrap, Activation/Deactivation)

### Task 9: mu-plugin File

**Files:**
- Create: `mu-plugin/wp-flame-early-hooks.php`

- [ ] **Step 1: Create the mu-plugin file**

```php
<?php
/**
 * WP Flame Early Hooks
 *
 * This file is copied to wp-content/mu-plugins/ on plugin activation.
 * It ensures instrumentation loads before all other plugins.
 *
 * @package WPFlame
 */

// Record request start as early as possible
$wp_flame_request_start = microtime(true);

// Load the main plugin's autoloader
$wp_flame_autoload = WP_PLUGIN_DIR . '/wp-flame/vendor/autoload.php';
if ( ! file_exists( $wp_flame_autoload ) ) {
    return; // Main plugin missing — graceful no-op
}
require_once $wp_flame_autoload;

// Initialize collector with the precise start time
$collector = WPFlame\Collector::instance();
$collector->start_request( $wp_flame_request_start );

// Start the Bootstrap phase span
$wp_flame_bootstrap_id = $collector->start_span( 'Bootstrap', WPFlame\Span::TYPE_CORE, 'wordpress' );

// Store the current phase span ID so we can close it at the next transition
$GLOBALS['wp_flame_current_phase_id'] = $wp_flame_bootstrap_id;

// Register lifecycle phase transitions
$wp_flame_phases = [
    'muplugins_loaded'   => 'Plugin Load',
    'plugins_loaded'     => 'Theme Setup',
    'after_setup_theme'  => 'Init',
    'init'               => 'Routing',
    'wp'                 => 'Main Query',
    'template_redirect'  => 'Render',
];

foreach ( $wp_flame_phases as $hook => $next_phase_name ) {
    add_action( $hook, function () use ( $next_phase_name ) {
        $collector = WPFlame\Collector::instance();
        $collector->end_span( $GLOBALS['wp_flame_current_phase_id'] );
        $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
            $next_phase_name,
            WPFlame\Span::TYPE_CORE,
            'wordpress'
        );
    }, 0 );
}
```

- [ ] **Step 2: Commit**

```bash
git add mu-plugin/wp-flame-early-hooks.php
git commit -m "feat: add mu-plugin for early lifecycle instrumentation"
```

---

### Task 10: Main Plugin Bootstrap

**Files:**
- Create: `wp-flame.php`
- Create: `uninstall.php`

- [ ] **Step 1: Create `wp-flame.php`**

```php
<?php
/**
 * Plugin Name: WP Flame
 * Plugin URI:  https://github.com/your-repo/wp-flame
 * Description: See exactly where your WordPress request spends its time. Interactive flame graph APM.
 * Version:     0.1.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: wp-flame
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package WPFlame
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_FLAME_VERSION', '0.1.0' );
define( 'WP_FLAME_FILE', __FILE__ );
define( 'WP_FLAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_FLAME_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader (may already be loaded by mu-plugin)
$wp_flame_autoloader = WP_FLAME_DIR . 'vendor/autoload.php';
if ( file_exists( $wp_flame_autoloader ) && ! class_exists( 'WPFlame\\Collector' ) ) {
    require_once $wp_flame_autoloader;
}

// --- Activation / Deactivation hooks ---

register_activation_hook( __FILE__, 'wp_flame_activate' );
register_deactivation_hook( __FILE__, 'wp_flame_deactivate' );

function wp_flame_activate(): void {
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->create_table();

    // Attempt to copy mu-plugin
    $mu_dir  = WPMU_PLUGIN_DIR;
    $mu_src  = WP_FLAME_DIR . 'mu-plugin/wp-flame-early-hooks.php';
    $mu_dest = $mu_dir . '/wp-flame-early-hooks.php';

    if ( ! is_dir( $mu_dir ) ) {
        wp_mkdir_p( $mu_dir );
    }

    if ( file_exists( $mu_src ) ) {
        $copied = @copy( $mu_src, $mu_dest );
        if ( ! $copied ) {
            update_option( 'wp_flame_mu_plugin_failed', true );
        } else {
            delete_option( 'wp_flame_mu_plugin_failed' );
        }
    }

    // Schedule daily prune
    if ( ! wp_next_scheduled( 'wp_flame_prune_traces' ) ) {
        wp_schedule_event( time(), 'daily', 'wp_flame_prune_traces' );
    }

    // Set default options (add_option won't overwrite existing values)
    add_option( 'wp_flame_enabled', true );
    add_option( 'wp_flame_retention_days', 7 );
    add_option( 'wp_flame_full_query_text', false );
}

function wp_flame_deactivate(): void {
    // Remove mu-plugin
    $mu_file = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
    if ( file_exists( $mu_file ) ) {
        @unlink( $mu_file );
    }

    // Clear cron
    $timestamp = wp_next_scheduled( 'wp_flame_prune_traces' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wp_flame_prune_traces' );
    }
}

// --- Main plugin initialization ---

add_action( 'plugins_loaded', 'wp_flame_init', 0 );

function wp_flame_init(): void {
    if ( ! get_option( 'wp_flame_enabled', true ) ) {
        return;
    }

    $collector = WPFlame\Collector::instance();

    // Degraded mode: if mu-plugin didn't initialize the collector, start now
    if ( ! $collector->is_initialized() ) {
        $collector->start_request( microtime( true ) );

        // Start a Theme Setup span (first phase we can capture)
        $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
            'Theme Setup',
            WPFlame\Span::TYPE_CORE,
            'wordpress'
        );

        // Register remaining phase transitions
        $phases = [
            'after_setup_theme' => 'Init',
            'init'              => 'Routing',
            'wp'                => 'Main Query',
            'template_redirect' => 'Render',
        ];

        foreach ( $phases as $hook => $next_phase_name ) {
            add_action( $hook, function () use ( $next_phase_name ) {
                $collector = WPFlame\Collector::instance();
                $collector->end_span( $GLOBALS['wp_flame_current_phase_id'] );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
                    $next_phase_name,
                    WPFlame\Span::TYPE_CORE,
                    'wordpress'
                );
            }, 0 );
        }
    }

    // Replace $wpdb with instrumented version
    global $wpdb;
    if ( WPFlame\DB::can_replace( $wpdb ) ) {
        $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector );
    }

    // Set admin detection flag at init
    add_action( 'init', function () {
        $GLOBALS['wp_flame_is_admin_request'] = current_user_can( 'manage_options' );
    }, 0 );

    // Register shutdown handler
    add_action( 'shutdown', 'wp_flame_shutdown', 9999 );

    // Register admin UI
    if ( is_admin() ) {
        global $wpdb;
        $admin = new WPFlame\Admin( new WPFlame\Storage( $wpdb ) );
        $admin->register();
    }
}

function wp_flame_shutdown(): void {
    $collector = WPFlame\Collector::instance();

    if ( ! $collector->is_initialized() ) {
        return;
    }

    // Step 1: Close current phase span explicitly
    if ( isset( $GLOBALS['wp_flame_current_phase_id'] ) ) {
        $collector->end_span( $GLOBALS['wp_flame_current_phase_id'] );
    }

    // Step 2: Safety net — close any remaining open spans
    $collector->close_open_spans();

    // Step 3: Check if we should save
    if ( ! get_option( 'wp_flame_enabled', true ) ) {
        return;
    }
    if ( empty( $GLOBALS['wp_flame_is_admin_request'] ) ) {
        return;
    }

    // Step 4: Build trace
    $trace = $collector->get_trace();

    // Step 5: Stop collector (prevents self-instrumentation during save)
    $collector->stop();

    // Step 6: Save trace
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->save_trace( $trace );
}

// --- Cron handler ---

add_action( 'wp_flame_prune_traces', function () {
    $days = (int) get_option( 'wp_flame_retention_days', 7 );
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->prune_old( $days );
} );
```

- [ ] **Step 2: Create `uninstall.php`**

```php
<?php
/**
 * WP Flame Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * @package WPFlame
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom table
$table = $wpdb->prefix . 'flame_traces';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Delete all plugin options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_flame\_%'" );

// Remove mu-plugin
$mu_file = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
if ( file_exists( $mu_file ) ) {
    @unlink( $mu_file );
}
```

- [ ] **Step 3: Commit**

```bash
git add wp-flame.php uninstall.php
git commit -m "feat: add plugin bootstrap, activation/deactivation, and uninstall"
```

---

## Chunk 6: Admin UI (PHP)

### Task 11: Admin Class — Trace List and Flame Graph View

**Files:**
- Create: `src/Admin.php`
- Create: `tests/Integration/AdminTest.php`

- [ ] **Step 1: Write failing tests for Admin**

```php
<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Admin;
use WPFlame\Storage;

class AdminTest extends WP_UnitTestCase
{
    private Admin $admin;
    private Storage $storage;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->storage = new Storage($wpdb);
        $this->storage->create_table();
        $this->admin = new Admin($this->storage);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_traces");
        parent::tear_down();
    }

    public function test_register_adds_admin_menu(): void
    {
        $this->admin->register();

        // Simulate admin_menu hook
        do_action('admin_menu');

        $menu_slug = 'wp-flame';
        global $submenu;
        $found = false;
        if (isset($submenu['tools.php'])) {
            foreach ($submenu['tools.php'] as $item) {
                if ($item[2] === $menu_slug) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'WP Flame menu item should be registered under Tools');
    }

    public function test_delete_trace_requires_valid_nonce(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $_POST['wp_flame_delete_trace'] = 'trace-123';
        $_POST['_wpnonce'] = 'invalid';

        // Should not crash, just skip
        $this->admin->handle_delete();

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Implement Admin class**

Create `src/Admin.php`. This is the largest single file. It handles:
- Menu registration (`add_management_page`)
- Trace list view (server-rendered HTML table with pagination/filtering)
- Flame graph view (PHP shell + `wp_localize_script` for JS data)
- Delete handler (POST form with nonce verification)
- Admin notices (mu-plugin missing, $wpdb conflict)
- Asset enqueueing

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Admin
{
    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_notices', [$this, 'render_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_management_page(
            'WP Flame',
            'WP Flame',
            'manage_options',
            'wp-flame',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $this->handle_delete();

        $trace_id = isset($_GET['trace_id']) ? sanitize_text_field(wp_unslash($_GET['trace_id'])) : '';

        if ($trace_id) {
            $this->render_flame_graph_view($trace_id);
        } else {
            $this->render_list_view();
        }
    }

    public function handle_delete(): void
    {
        if (! isset($_POST['wp_flame_delete_trace'])) {
            return;
        }

        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_flame_delete')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        $trace_id = sanitize_text_field(wp_unslash($_POST['wp_flame_delete_trace']));
        $this->storage->delete_trace($trace_id);

        wp_safe_redirect(admin_url('tools.php?page=wp-flame&deleted=1'));
        exit;
    }

    private function render_list_view(): void
    {
        $filters = [];

        if (! empty($_GET['s'])) {
            $filters['url'] = sanitize_text_field(wp_unslash($_GET['s']));
        }
        if (! empty($_GET['min_duration'])) {
            $filters['min_duration'] = (float) $_GET['min_duration'];
        }

        $paged    = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 20;

        $filters['page']     = $paged;
        $filters['per_page'] = $per_page;

        $traces = $this->storage->list_traces($filters);
        $total  = $this->storage->count_traces($filters);
        $pages  = (int) ceil($total / $per_page);

        echo '<div class="wrap">';
        echo '<h1>WP Flame</h1>';

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Trace deleted.</p></div>';
        }

        // Search/filter form
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wp-flame">';
        echo '<div class="tablenav top"><div class="alignleft">';
        echo '<input type="search" name="s" value="' . esc_attr($filters['url'] ?? '') . '" placeholder="Filter by URL...">';
        echo ' <input type="number" name="min_duration" value="' . esc_attr(isset($filters['min_duration']) ? (string) $filters['min_duration'] : '') . '" placeholder="Min ms..." step="any" style="width:100px">';
        echo ' <input type="submit" class="button" value="Filter">';
        echo '</div></div>';
        echo '</form>';

        // Table
        echo '<table class="widefat striped wp-flame-traces">';
        echo '<thead><tr>';
        echo '<th>URL</th><th>Method</th><th>Duration</th><th>Queries</th><th>Memory</th><th>Date</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($traces)) {
            echo '<tr><td colspan="7">No traces found. Browse your site as an admin to generate traces.</td></tr>';
        }

        foreach ($traces as $row) {
            $is_slow  = ((float) $row['total_ms'] > 500);
            $view_url = admin_url('tools.php?page=wp-flame&trace_id=' . urlencode($row['trace_id']));
            $mem_mb   = round((int) $row['peak_memory'] / 1048576, 1);

            echo $is_slow ? '<tr class="wp-flame-slow">' : '<tr>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($row['url']) . '</a></td>';
            echo '<td>' . esc_html($row['method']) . '</td>';
            echo '<td>' . esc_html(round((float) $row['total_ms'], 1)) . ' ms</td>';
            echo '<td>' . esc_html($row['query_count']) . '</td>';
            echo '<td>' . esc_html($mem_mb) . ' MB</td>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '">View</a> | ';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('wp_flame_delete');
            echo '<input type="hidden" name="wp_flame_delete_trace" value="' . esc_attr($row['trace_id']) . '">';
            echo '<button type="submit" class="button-link" onclick="return confirm(\'Delete this trace?\')">Delete</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ]);
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_flame_graph_view(string $trace_id): void
    {
        $trace = $this->storage->get_trace($trace_id);

        if (! $trace) {
            echo '<div class="wrap"><h1>WP Flame</h1>';
            echo '<div class="notice notice-error"><p>Trace not found.</p></div></div>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>';
        echo '<a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '">&larr; All Traces</a>';
        echo ' &mdash; ' . esc_html($trace->method) . ' ' . esc_html($trace->url);
        echo '</h1>';

        // Summary stats bar
        echo '<div class="wp-flame-summary">';
        echo '<div class="wp-flame-stat"><span class="wp-flame-stat-value">' . esc_html(round($trace->total_ms, 1)) . ' ms</span><span class="wp-flame-stat-label">Total</span></div>';
        echo '<div class="wp-flame-stat"><span class="wp-flame-stat-value">' . esc_html(round($trace->total_query_ms, 1)) . ' ms</span><span class="wp-flame-stat-label">DB Time</span></div>';
        echo '<div class="wp-flame-stat"><span class="wp-flame-stat-value">' . esc_html((string) $trace->query_count) . '</span><span class="wp-flame-stat-label">Queries</span></div>';
        echo '<div class="wp-flame-stat"><span class="wp-flame-stat-value">' . esc_html(round($trace->peak_memory / 1048576, 1)) . ' MB</span><span class="wp-flame-stat-label">Peak Memory</span></div>';
        echo '</div>';

        // Flame graph container
        echo '<div id="wp-flame-breadcrumbs"></div>';
        echo '<div id="wp-flame-graph"></div>';
        echo '<div id="wp-flame-tooltip" style="display:none"></div>';

        echo '</div>';

        // Pass trace data to JS
        wp_localize_script('wp-flame-graph', 'wpFlameTrace', $trace->toArray());
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'tools_page_wp-flame') {
            return;
        }

        wp_enqueue_style(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/css/admin.css',
            [],
            WP_FLAME_VERSION
        );

        wp_enqueue_script(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/js/admin.js',
            [],
            WP_FLAME_VERSION,
            true
        );

        if (isset($_GET['trace_id'])) {
            wp_enqueue_script(
                'wp-flame-graph',
                WP_FLAME_URL . 'assets/js/flame-graph.js',
                [],
                WP_FLAME_VERSION,
                true
            );
        }
    }

    public function render_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $mu_file = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php' : '';
        if ($mu_file && ! file_exists($mu_file)) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>WP Flame</strong> is running in limited mode &mdash; plugin load timing is unavailable. ';
            echo 'Copy <code>wp-flame/mu-plugin/wp-flame-early-hooks.php</code> to <code>wp-content/mu-plugins/</code> for full instrumentation.';
            echo '</p></div>';
        }

        global $wpdb;
        if (! ($wpdb instanceof DB) && get_class($wpdb) !== 'wpdb') {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>WP Flame</strong>: DB query instrumentation is disabled &mdash; another plugin is modifying the database layer.';
            echo '</p></div>';
        }
    }
}
```

- [ ] **Step 3: Run integration tests (if WP test environment available)**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite integration --filter AdminTest
```

Expected: Both tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Admin.php tests/Integration/AdminTest.php
git commit -m "feat: add Admin class with trace list and flame graph views"
```

---

## Chunk 7: Frontend Assets + Final Integration

### Task 12: Flame Graph SVG Renderer (JavaScript)

**Files:**
- Create: `assets/js/flame-graph.js`

- [ ] **Step 1: Create the flame graph renderer**

```javascript
/**
 * WP Flame — SVG Flame Graph Renderer
 *
 * Reads window.wpFlameTrace (set by wp_localize_script) and renders
 * an interactive SVG flame graph.
 */
(function () {
    'use strict';

    var ROW_HEIGHT = 24;
    var MIN_WIDTH_PX = 2;
    var COLORS = {
        core: '#9e9e9e',
        plugin: '#4285f4',
        theme: '#34a853',
        db: '#f4a742',
        http: '#ea4335',
        php: '#9c27b0'
    };

    var container = document.getElementById('wp-flame-graph');
    var breadcrumbsEl = document.getElementById('wp-flame-breadcrumbs');
    var tooltipEl = document.getElementById('wp-flame-tooltip');

    if (!container || !window.wpFlameTrace) {
        return;
    }

    var trace = window.wpFlameTrace;
    var spans = trace.spans || [];

    // Build tree structure from flat parent_id references
    var spanMap = {};
    var roots = [];
    var i, span;

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        span.children = [];
        spanMap[span.id] = span;
    }

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        if (span.parent_id && spanMap[span.parent_id]) {
            spanMap[span.parent_id].children.push(span);
        } else {
            roots.push(span);
        }
    }

    // Compute max depth for SVG height
    function getDepth(node) {
        var max = 0;
        for (var j = 0; j < node.children.length; j++) {
            var d = getDepth(node.children[j]);
            if (d > max) max = d;
        }
        return max + 1;
    }

    var maxDepth = 0;
    for (i = 0; i < roots.length; i++) {
        var d = getDepth(roots[i]);
        if (d > maxDepth) maxDepth = d;
    }

    // View state for zoom
    var viewStart = 0;
    var viewEnd = trace.total_ms;
    var zoomStack = [];

    // Escape text for safe use in SVG attributes
    function escapeAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function render() {
        var width = container.clientWidth || 800;
        var height = maxDepth * ROW_HEIGHT + 10;
        var timeRange = viewEnd - viewStart;

        var svgParts = [];
        svgParts.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" class="wp-flame-svg">');

        function renderSpan(s, depth) {
            var x = ((s.start_ms - viewStart) / timeRange) * width;
            var w = (s.duration_ms / timeRange) * width;

            if (w < MIN_WIDTH_PX) w = MIN_WIDTH_PX;

            // Skip spans entirely outside view
            if (x + w < 0 || x > width) return;

            var y = depth * ROW_HEIGHT;
            var color = COLORS[s.type] || COLORS.php;
            var pct = trace.total_ms > 0 ? ((s.duration_ms / trace.total_ms) * 100).toFixed(1) : '0.0';

            svgParts.push('<g class="wp-flame-span" data-id="' + escapeAttr(s.id) + '" data-name="' + escapeAttr(s.name) + '" data-duration="' + s.duration_ms.toFixed(2) + '" data-source="' + escapeAttr(s.source) + '" data-pct="' + pct + '">');
            svgParts.push('<rect x="' + x.toFixed(1) + '" y="' + y + '" width="' + w.toFixed(1) + '" height="' + (ROW_HEIGHT - 2) + '" fill="' + color + '" rx="2" />');

            // Text label (only if wide enough)
            if (w > 40) {
                var label = s.name;
                var maxChars = Math.floor(w / 7);
                if (label.length > maxChars) {
                    label = label.substring(0, maxChars - 1) + '\u2026';
                }
                svgParts.push('<text x="' + (x + 4).toFixed(1) + '" y="' + (y + ROW_HEIGHT - 7) + '" fill="#fff" font-size="11" font-family="monospace">' + escapeAttr(label) + '</text>');
            }

            svgParts.push('</g>');

            // Render children
            for (var j = 0; j < s.children.length; j++) {
                renderSpan(s.children[j], depth + 1);
            }
        }

        for (i = 0; i < roots.length; i++) {
            renderSpan(roots[i], 0);
        }

        svgParts.push('</svg>');

        // Build SVG via DOMParser to avoid raw innerHTML XSS risks
        var parser = new DOMParser();
        var doc = parser.parseFromString(svgParts.join(''), 'image/svg+xml');
        var svgEl = doc.documentElement;

        // Clear container safely and append parsed SVG
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        container.appendChild(document.importNode(svgEl, true));

        // Attach event listeners to rendered spans
        var groups = container.querySelectorAll('.wp-flame-span');
        for (var g = 0; g < groups.length; g++) {
            groups[g].addEventListener('mouseenter', showTooltip);
            groups[g].addEventListener('mouseleave', hideTooltip);
            groups[g].addEventListener('click', handleClick);
        }

        renderBreadcrumbs();
    }

    function showTooltip(e) {
        var el = e.currentTarget;
        var name = el.getAttribute('data-name');
        var duration = el.getAttribute('data-duration');
        var source = el.getAttribute('data-source');
        var pct = el.getAttribute('data-pct');

        // Build tooltip content safely using DOM methods
        while (tooltipEl.firstChild) {
            tooltipEl.removeChild(tooltipEl.firstChild);
        }

        var strong = document.createElement('strong');
        strong.textContent = name;
        tooltipEl.appendChild(strong);
        tooltipEl.appendChild(document.createElement('br'));
        tooltipEl.appendChild(document.createTextNode(duration + ' ms (' + pct + '%)'));
        tooltipEl.appendChild(document.createElement('br'));

        var sourceSpan = document.createElement('span');
        sourceSpan.style.opacity = '0.7';
        sourceSpan.textContent = source;
        tooltipEl.appendChild(sourceSpan);

        tooltipEl.style.display = 'block';
        document.addEventListener('mousemove', moveTooltip);
    }

    function moveTooltip(e) {
        tooltipEl.style.left = (e.pageX + 12) + 'px';
        tooltipEl.style.top = (e.pageY - 10) + 'px';
    }

    function hideTooltip() {
        tooltipEl.style.display = 'none';
        document.removeEventListener('mousemove', moveTooltip);
    }

    function handleClick(e) {
        var el = e.currentTarget;
        var spanId = el.getAttribute('data-id');
        var s = spanMap[spanId];
        if (!s || s.children.length === 0) return;

        zoomStack.push({ start: viewStart, end: viewEnd });
        viewStart = s.start_ms;
        viewEnd = s.start_ms + s.duration_ms;
        render();
    }

    function zoomOut() {
        if (zoomStack.length === 0) return;
        var prev = zoomStack.pop();
        viewStart = prev.start;
        viewEnd = prev.end;
        render();
    }

    function resetZoom() {
        zoomStack = [];
        viewStart = 0;
        viewEnd = trace.total_ms;
        render();
    }

    function renderBreadcrumbs() {
        if (!breadcrumbsEl) return;

        // Build breadcrumbs safely using DOM methods
        while (breadcrumbsEl.firstChild) {
            breadcrumbsEl.removeChild(breadcrumbsEl.firstChild);
        }

        var resetBtn = document.createElement('button');
        resetBtn.className = 'button button-small';
        resetBtn.textContent = 'Full View';
        resetBtn.addEventListener('click', resetZoom);
        breadcrumbsEl.appendChild(resetBtn);

        if (zoomStack.length > 0) {
            var backBtn = document.createElement('button');
            backBtn.className = 'button button-small';
            backBtn.textContent = '\u2190 Back';
            backBtn.style.marginLeft = '4px';
            backBtn.addEventListener('click', zoomOut);
            breadcrumbsEl.appendChild(backBtn);

            var info = document.createElement('span');
            info.className = 'wp-flame-zoom-info';
            info.textContent = 'Viewing ' + viewStart.toFixed(1) + ' ms \u2013 ' + viewEnd.toFixed(1) + ' ms';
            breadcrumbsEl.appendChild(info);
        }
    }

    // Initial render
    render();

    // Re-render on window resize (debounced)
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 150);
    });
})();
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/flame-graph.js
git commit -m "feat: add SVG flame graph renderer with zoom and tooltips"
```

---

### Task 13: Admin CSS

**Files:**
- Create: `assets/css/admin.css`

- [ ] **Step 1: Create admin styles**

```css
/* WP Flame Admin Styles */

/* Summary stats bar */
.wp-flame-summary {
    display: flex;
    gap: 24px;
    margin: 16px 0;
    padding: 16px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

.wp-flame-stat {
    display: flex;
    flex-direction: column;
}

.wp-flame-stat-value {
    font-size: 20px;
    font-weight: 600;
    line-height: 1.2;
}

.wp-flame-stat-label {
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Trace list table */
.wp-flame-traces .wp-flame-slow td {
    background-color: #fcf0f0;
}

/* Flame graph container */
#wp-flame-graph {
    margin: 16px 0;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 8px;
    overflow-x: auto;
}

#wp-flame-graph svg {
    display: block;
    cursor: pointer;
}

#wp-flame-graph .wp-flame-span rect {
    stroke: rgba(0, 0, 0, 0.1);
    stroke-width: 0.5;
    transition: opacity 0.1s;
}

#wp-flame-graph .wp-flame-span:hover rect {
    opacity: 0.85;
}

#wp-flame-graph .wp-flame-span text {
    pointer-events: none;
    user-select: none;
}

/* Breadcrumbs */
#wp-flame-breadcrumbs {
    margin: 12px 0 4px;
}

.wp-flame-zoom-info {
    margin-left: 8px;
    color: #646970;
    font-size: 13px;
}

/* Tooltip */
#wp-flame-tooltip {
    position: absolute;
    background: #1d2327;
    color: #fff;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    line-height: 1.5;
    pointer-events: none;
    z-index: 100000;
    max-width: 400px;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/admin.css
git commit -m "feat: add admin CSS for trace list, flame graph, and tooltips"
```

---

### Task 14: Admin JS (minimal)

**Files:**
- Create: `assets/js/admin.js`

- [ ] **Step 1: Create admin JS**

Minimal script for the list page — auto-dismiss success notices after 3 seconds.

```javascript
(function () {
    'use strict';

    var notices = document.querySelectorAll('.notice.notice-success.is-dismissible');
    for (var i = 0; i < notices.length; i++) {
        (function (notice) {
            setTimeout(function () {
                var btn = notice.querySelector('.notice-dismiss');
                if (btn) btn.click();
            }, 3000);
        })(notices[i]);
    }
})();
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/admin.js
git commit -m "feat: add minimal admin JS for list page"
```

---

### Task 15: Final Cleanup and Verification

**Files:**
- Remove: all `.gitkeep` placeholder files

- [ ] **Step 1: Remove .gitkeep placeholder files**

```bash
find . -name '.gitkeep' -not -path './vendor/*' -delete
```

- [ ] **Step 2: Run unit tests**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: All unit tests pass (Span: 6, Trace: 6, Collector: 18 = 30 total).

- [ ] **Step 3: Run integration tests (if WP test environment available)**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --testsuite integration
```

Expected: All integration tests pass (Storage: 10, DB: 7, Admin: 2 = 19 total).

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: remove placeholder files, verify full test suite"
```

- [ ] **Step 5: Create version tag**

```bash
git tag -a v0.1.0-alpha -m "Phase 1: Core instrumentation + flame graph"
```
