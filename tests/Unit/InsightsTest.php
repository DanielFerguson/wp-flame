<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Insights;
use WPFlame\Span;
use WPFlame\Trace;

class InsightsTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function make_trace(array $spans, array $meta = []): Trace
    {
        return new Trace(
            'trace-test',
            '/test',
            'GET',
            '2026-03-17T00:00:00+00:00',
            500.0,
            1048576,
            '8.1',
            '6.4',
            $spans,
            $meta
        );
    }

    private function make_span(
        string $id,
        string $type,
        string $name,
        float $duration_ms,
        ?string $parent_id = null,
        array $meta = []
    ): Span {
        return new Span($id, $parent_id, $name, $type, 'test-source', 0.0, $duration_ms, $meta);
    }

    // ---------------------------------------------------------------------------
    // Rule 1: slow_http_requests
    // ---------------------------------------------------------------------------

    public function test_slow_http_request_above_threshold_produces_warning(): void
    {
        $span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 250.0, null, [
            'url'    => 'https://api.example.com/data',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('api.example.com', $insights[0]['title']);
        $this->assertStringContainsString('250ms', $insights[0]['title']);
        $this->assertStringContainsString('caching', $insights[0]['detail']);
    }

    public function test_http_request_at_threshold_does_not_trigger(): void
    {
        $span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 100.0, null, [
            'url'    => 'https://api.example.com/data',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    public function test_http_request_below_threshold_does_not_trigger(): void
    {
        $span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 50.0, null, [
            'url'    => 'https://api.example.com/data',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    // ---------------------------------------------------------------------------
    // Rule 2: duplicate_db_queries
    // ---------------------------------------------------------------------------

    public function test_duplicate_queries_2_to_3_produces_info(): void
    {
        $query = 'SELECT * FROM wp_options WHERE option_name = "siteurl"';

        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
            $this->make_span('d2', Span::TYPE_DB, 'DB', 6.0, null, ['query' => $query]),
        ];

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('info', $insights[0]['severity']);
        $this->assertStringContainsString('2 duplicate', $insights[0]['title']);
        $this->assertStringContainsString('2 times', $insights[0]['detail']);
        $this->assertStringContainsString('wp_cache', $insights[0]['detail']);
    }

    public function test_duplicate_queries_4_or_more_produces_warning(): void
    {
        $query = 'SELECT ID FROM wp_posts WHERE post_status = "publish"';

        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 'DB', 3.0, null, ['query' => $query]),
            $this->make_span('d2', Span::TYPE_DB, 'DB', 3.0, null, ['query' => $query]),
            $this->make_span('d3', Span::TYPE_DB, 'DB', 3.0, null, ['query' => $query]),
            $this->make_span('d4', Span::TYPE_DB, 'DB', 3.0, null, ['query' => $query]),
        ];

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('4 duplicate', $insights[0]['title']);
    }

    public function test_single_occurrence_of_query_does_not_trigger(): void
    {
        $span = $this->make_span('d1', Span::TYPE_DB, 'DB', 5.0, null, [
            'query' => 'SELECT * FROM wp_options',
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    public function test_duplicate_query_title_includes_query_type(): void
    {
        $query = 'SELECT option_value FROM wp_options WHERE option_name = "blogname"';

        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
            $this->make_span('d2', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
            $this->make_span('d3', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
        ];

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('SELECT', $insights[0]['title']);
        $this->assertSame('info', $insights[0]['severity']); // 3 = info
    }

    // ---------------------------------------------------------------------------
    // Rule 3: high_query_count
    // ---------------------------------------------------------------------------

    public function test_query_count_above_50_produces_info(): void
    {
        $spans = [];
        for ($i = 0; $i < 55; $i++) {
            $spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 'DB', 1.0);
        }

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        // Filter to just the high_query_count insight (others may not fire for
        // these spans since they have no query meta)
        $hqc = array_filter($insights, fn($i) => str_contains($i['title'], 'database queries'));
        $hqc = array_values($hqc);

        $this->assertCount(1, $hqc);
        $this->assertSame('info', $hqc[0]['severity']);
        $this->assertStringContainsString('55', $hqc[0]['title']);
    }

    public function test_query_count_above_100_produces_warning(): void
    {
        $spans = [];
        for ($i = 0; $i < 105; $i++) {
            $spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 'DB', 1.0);
        }

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $hqc = array_filter($insights, fn($i) => str_contains($i['title'], 'database queries'));
        $hqc = array_values($hqc);

        $this->assertCount(1, $hqc);
        $this->assertSame('warning', $hqc[0]['severity']);
        $this->assertStringContainsString('105', $hqc[0]['title']);
    }

    public function test_query_count_at_or_below_50_does_not_trigger(): void
    {
        $spans = [];
        for ($i = 0; $i < 50; $i++) {
            $spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 'DB', 1.0);
        }

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $hqc = array_filter($insights, fn($i) => str_contains($i['title'], 'database queries'));
        $this->assertEmpty($hqc);
    }

    // ---------------------------------------------------------------------------
    // Rule 4: slow_callbacks
    // ---------------------------------------------------------------------------

    public function test_slow_callback_above_50ms_produces_warning(): void
    {
        $span = $this->make_span('cb1', Span::TYPE_PLUGIN, 'my_plugin_callback', 75.0, null, [
            'hook' => 'init',
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('my_plugin_callback', $insights[0]['title']);
        $this->assertStringContainsString('75ms', $insights[0]['title']);
        $this->assertStringContainsString('init', $insights[0]['title']);
        $this->assertStringContainsString('bottleneck', $insights[0]['detail']);
    }

    public function test_callback_at_threshold_does_not_trigger(): void
    {
        $span = $this->make_span('cb1', Span::TYPE_PLUGIN, 'my_callback', 50.0, null, [
            'hook' => 'wp_head',
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    public function test_callback_below_threshold_does_not_trigger(): void
    {
        $span = $this->make_span('cb1', Span::TYPE_PLUGIN, 'fast_callback', 10.0, null, [
            'hook' => 'wp_footer',
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    public function test_span_without_hook_meta_does_not_trigger_slow_callback_rule(): void
    {
        $span = $this->make_span('p1', Span::TYPE_PLUGIN, 'some_function', 100.0);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    // ---------------------------------------------------------------------------
    // Rule 5: http_during_early_phases
    // ---------------------------------------------------------------------------

    public function test_http_during_init_phase_produces_warning(): void
    {
        $init_span = $this->make_span('lc1', Span::TYPE_CORE, 'Init', 200.0);
        $http_span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 30.0, 'lc1', [
            'url'    => 'https://external.example.com/api',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$init_span, $http_span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('Init', $insights[0]['title']);
        $this->assertStringContainsString('external.example.com', $insights[0]['detail']);
    }

    public function test_http_during_plugin_load_phase_produces_warning(): void
    {
        $phase_span = $this->make_span('lc1', Span::TYPE_CORE, 'Plugin Load', 300.0);
        $http_span  = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 50.0, 'lc1', [
            'url'    => 'https://cdn.example.org/asset',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$phase_span, $http_span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('Plugin Load', $insights[0]['title']);
    }

    public function test_http_during_theme_setup_phase_produces_warning(): void
    {
        $phase_span = $this->make_span('lc1', Span::TYPE_CORE, 'Theme Setup', 150.0);
        $http_span  = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 40.0, 'lc1', [
            'url'    => 'https://fonts.example.com/css',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$phase_span, $http_span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('warning', $insights[0]['severity']);
        $this->assertStringContainsString('Theme Setup', $insights[0]['title']);
    }

    public function test_http_during_early_phase_via_grandparent_produces_warning(): void
    {
        // HTTP span is a grandchild of Init
        $init_span = $this->make_span('lc1', Span::TYPE_CORE, 'Init', 200.0);
        $mid_span  = $this->make_span('m1', Span::TYPE_PLUGIN, 'Some Plugin', 150.0, 'lc1');
        $http_span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 40.0, 'm1', [
            'url'    => 'https://api.example.com/check',
            'method' => 'POST',
            'status' => 201,
        ]);

        $trace    = $this->make_trace([$init_span, $mid_span, $http_span]);
        $insights = Insights::analyze($trace);

        $warnings = array_filter($insights, fn($i) => str_contains($i['title'], 'Init'));
        $this->assertCount(1, $warnings);
    }

    public function test_http_not_during_early_phase_does_not_trigger(): void
    {
        $late_span = $this->make_span('lc1', Span::TYPE_CORE, 'Render', 200.0);
        $http_span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 50.0, 'lc1', [
            'url'    => 'https://api.example.com/data',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$late_span, $http_span]);
        $insights = Insights::analyze($trace);

        // The HTTP span is below 100ms so slow_http rule won't fire either;
        // the early-phase rule should also not fire.
        $early = array_filter($insights, fn($i) => str_contains($i['title'], 'blocks page load'));
        $this->assertEmpty($early);
    }

    // ---------------------------------------------------------------------------
    // No issues
    // ---------------------------------------------------------------------------

    public function test_trace_with_no_issues_returns_empty_array(): void
    {
        $spans = [
            $this->make_span('c1', Span::TYPE_CORE, 'Request', 200.0),
            $this->make_span('d1', Span::TYPE_DB, 'Query', 5.0, 'c1', ['query' => 'SELECT 1']),
            $this->make_span('d2', Span::TYPE_DB, 'Query', 4.0, 'c1', ['query' => 'SELECT 2']),
            $this->make_span('p1', Span::TYPE_PLUGIN, 'fast_cb', 10.0, 'c1', ['hook' => 'init']),
            $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 80.0, 'c1', [
                'url'    => 'https://safe.example.com/api',
                'method' => 'GET',
                'status' => 200,
            ]),
        ];

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $this->assertEmpty($insights);
    }

    // ---------------------------------------------------------------------------
    // Rule 6: no_persistent_cache
    // ---------------------------------------------------------------------------

    public function test_no_persistent_cache_fires_when_wp_object_cache_with_many_misses(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend' => 'WP_Object_Cache',
            'cache_hits'    => 10,
            'cache_misses'  => 25,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'No persistent object cache'));
        $matches = array_values($matches);

        $this->assertCount(1, $matches);
        $this->assertSame('info', $matches[0]['severity']);
        $this->assertStringContainsString('25', $matches[0]['detail']);
    }

    public function test_no_persistent_cache_does_not_fire_with_redis_backend(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend' => 'Redis_Object_Cache',
            'cache_hits'    => 10,
            'cache_misses'  => 25,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'No persistent object cache'));
        $this->assertEmpty($matches);
    }

    public function test_no_persistent_cache_does_not_fire_when_misses_at_or_below_20(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend' => 'WP_Object_Cache',
            'cache_hits'    => 50,
            'cache_misses'  => 20,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'No persistent object cache'));
        $this->assertEmpty($matches);
    }

    // ---------------------------------------------------------------------------
    // Rule 7: low_cache_hit_ratio
    // ---------------------------------------------------------------------------

    public function test_low_cache_hit_ratio_fires_when_ratio_below_80_percent(): void
    {
        // 60% hit ratio: 6 hits, 4 misses = 10 total (not > 10, use 11 total)
        $trace = $this->make_trace([], [
            'cache_backend' => 'Redis_Object_Cache',
            'cache_hits'    => 60,
            'cache_misses'  => 40,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'Low cache hit ratio'));
        $matches = array_values($matches);

        $this->assertCount(1, $matches);
        $this->assertSame('warning', $matches[0]['severity']);
        $this->assertStringContainsString('60%', $matches[0]['title']);
        $this->assertStringContainsString('40', $matches[0]['detail']);
    }

    public function test_low_cache_hit_ratio_does_not_fire_when_ratio_above_80_percent(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend' => 'Redis_Object_Cache',
            'cache_hits'    => 95,
            'cache_misses'  => 5,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'Low cache hit ratio'));
        $this->assertEmpty($matches);
    }

    public function test_low_cache_hit_ratio_does_not_fire_on_tiny_request(): void
    {
        // 2 hits, 1 miss = 3 total (not > 10)
        $trace = $this->make_trace([], [
            'cache_backend' => 'Redis_Object_Cache',
            'cache_hits'    => 2,
            'cache_misses'  => 1,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'Low cache hit ratio'));
        $this->assertEmpty($matches);
    }

    // ---------------------------------------------------------------------------
    // analyze() returns flat merged array from all rules
    // ---------------------------------------------------------------------------

    public function test_analyze_merges_multiple_rule_results(): void
    {
        $query = 'SELECT * FROM wp_options';

        $spans = [
            // Rule 1: slow HTTP (> 100ms)
            $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 200.0, null, [
                'url'    => 'https://slow.example.com/api',
                'method' => 'GET',
                'status' => 200,
            ]),
            // Rule 2: duplicate DB queries
            $this->make_span('d1', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
            $this->make_span('d2', Span::TYPE_DB, 'DB', 5.0, null, ['query' => $query]),
            // Rule 4: slow callback
            $this->make_span('cb1', Span::TYPE_PLUGIN, 'heavy_callback', 60.0, null, [
                'hook' => 'wp_loaded',
            ]),
        ];

        $trace    = $this->make_trace($spans);
        $insights = Insights::analyze($trace);

        $this->assertGreaterThanOrEqual(3, count($insights));

        $severities = array_column($insights, 'severity');
        $this->assertContains('warning', $severities);
        $this->assertContains('info', $severities);
    }
}
