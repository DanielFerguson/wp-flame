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

    public function test_add_span_meta_merges_into_correct_span(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $outer_id = $collector->start_span('Outer', Span::TYPE_CORE, 'test', ['existing' => 'value']);
        $inner_id = $collector->start_span('Inner', Span::TYPE_HTTP, 'test', ['url' => 'https://example.com']);

        $collector->add_span_meta($inner_id, ['status' => 200]);

        $collector->end_span($inner_id);
        $collector->end_span($outer_id);

        $trace = $collector->get_trace();
        $spans_by_name = [];
        foreach ($trace->spans as $span) {
            $spans_by_name[$span->name] = $span;
        }

        // Inner span should have merged meta
        $this->assertSame('https://example.com', $spans_by_name['Inner']->meta['url']);
        $this->assertSame(200, $spans_by_name['Inner']->meta['status']);

        // Outer span should be unchanged
        $this->assertSame('value', $spans_by_name['Outer']->meta['existing']);
        $this->assertArrayNotHasKey('status', $spans_by_name['Outer']->meta);
    }

    public function test_add_span_meta_noop_when_stopped(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Span', Span::TYPE_HTTP, 'test', ['url' => 'https://example.com']);

        $collector->stop();
        $collector->add_span_meta($id, ['status' => 200]);

        // Collector is stopped, so end_span is also a no-op — get_trace still works
        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_get_trace_passes_meta(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $trace = $collector->get_trace(['key' => 'value']);

        $this->assertSame('value', $trace->meta['key']);
    }

    public function test_add_span_meta_noop_when_span_id_not_found(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Span', Span::TYPE_HTTP, 'test', ['url' => 'https://example.com']);

        // Call with a non-existent span ID — should not throw or corrupt state
        $collector->add_span_meta('non-existent-span-id', ['status' => 404]);

        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        // The real span should not have the meta that was meant for the non-existent span
        $this->assertArrayNotHasKey('status', $trace->spans[0]->meta);
        $this->assertSame('https://example.com', $trace->spans[0]->meta['url']);
    }

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

    public function test_add_completed_span_has_null_parent_when_stack_empty(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->add_completed_span('SELECT', Span::TYPE_DB, 'wordpress', 1000.010, 0.001);

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertNull($trace->spans[0]->parent_id);
    }

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
}
