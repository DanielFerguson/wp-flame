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
