<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Insights;
use WPFlame\Rules\DuplicateDbQueries;
use WPFlame\Rules\SlowCallbacks;
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

    public function test_slow_http_request_tolerates_malformed_url(): void
    {
        $span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 250.0, null, [
            'url'    => 'http://',
            'method' => 'GET',
            'status' => 0,
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('unknown', $insights[0]['title']);
    }

    public function test_slow_http_request_redacts_legacy_full_url_query_values(): void
    {
        $span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 250.0, null, [
            'url'    => 'https://api.example.com/customer?email=person@example.com&page=2&token=secret',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('api.example.com/customer', $insights[0]['detail']);
        $this->assertStringContainsString('page=2', $insights[0]['detail']);
        $this->assertStringContainsString('email=[redacted]', $insights[0]['detail']);
        $this->assertStringContainsString('token=[redacted]', $insights[0]['detail']);
        $this->assertStringNotContainsString('person@example.com', $insights[0]['detail']);
        $this->assertStringNotContainsString('secret', $insights[0]['detail']);
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

    public function test_duplicate_query_rule_bounds_retained_span_ids(): void
    {
        $spans = [];
        for ($i = 0; $i < 60; $i++) {
            $spans[] = $this->make_span('db-' . $i, Span::TYPE_DB, 'DB', 1.0, null, [
                'query'      => 'SELECT option_value FROM wp_options',
                'query_hash' => 'same-query',
            ]);
        }

        $rule = new DuplicateDbQueries();
        $insights = $rule->analyze($this->make_trace($spans));

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('60 duplicate SELECT', $insights[0]->title);
        $this->assertCount(50, $insights[0]->affected_span_ids);
    }

    public function test_duplicate_query_rule_bounds_distinct_groups(): void
    {
        $spans = [];
        for ($group = 0; $group < 105; $group++) {
            $spans[] = $this->make_span('db-' . $group . '-a', Span::TYPE_DB, 'DB', 1.0, null, [
                'query'      => 'SELECT option_value FROM wp_options WHERE option_id = ' . $group,
                'query_hash' => 'query-' . $group,
            ]);
            $spans[] = $this->make_span('db-' . $group . '-b', Span::TYPE_DB, 'DB', 1.0, null, [
                'query'      => 'SELECT option_value FROM wp_options WHERE option_id = ' . $group,
                'query_hash' => 'query-' . $group,
            ]);
        }

        $rule = new DuplicateDbQueries();
        $insights = $rule->analyze($this->make_trace($spans));

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('100 distinct queries', $insights[0]->title);
        $this->assertCount(100, $insights[0]->affected_span_ids);
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

    public function test_slow_callback_rule_bounds_output_count_and_labels(): void
    {
        $spans = [];
        for ($i = 0; $i < 25; $i++) {
            $spans[] = $this->make_span(
                str_repeat('s', 180) . $i,
                Span::TYPE_PLUGIN,
                str_repeat('CallbackName', 30) . $i,
                75.0,
                null,
                ['hook' => str_repeat('very_long_hook_name_', 20) . $i]
            );
        }

        $rule = new SlowCallbacks();
        $insights = $rule->analyze($this->make_trace($spans));

        $this->assertCount(20, $insights);
        $this->assertLessThan(300, strlen($insights[0]->title));
        $this->assertStringNotContainsString(str_repeat('CallbackName', 18), $insights[0]->title);
        $this->assertLessThanOrEqual(128, strlen($insights[0]->affected_span_ids[0]));
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

    public function test_http_during_early_phase_tolerates_malformed_url(): void
    {
        $phase_span = $this->make_span('lc1', Span::TYPE_CORE, 'Theme Setup', 150.0);
        $http_span  = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 40.0, 'lc1', [
            'url'    => 'http://',
            'method' => 'GET',
            'status' => 0,
        ]);

        $trace    = $this->make_trace([$phase_span, $http_span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString('http://', $insights[0]['detail']);
    }

    public function test_http_during_early_phase_bounds_legacy_url_and_host_metadata(): void
    {
        $phase_span = $this->make_span('lc1', Span::TYPE_CORE, 'Theme Setup', 150.0);
        $long_host = str_repeat('h', 300);
        $http_span = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 40.0, 'lc1', [
            'url' => 'https://' . $long_host . '.example.com/' . str_repeat('path', 3000),
        ]);

        $trace = $this->make_trace([$phase_span, $http_span]);
        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertLessThanOrEqual(360, strlen($insights[0]['detail']));
        $this->assertStringContainsString(str_repeat('h', 255), $insights[0]['detail']);
        $this->assertStringNotContainsString(str_repeat('h', 256), $insights[0]['detail']);
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
        $early = array_filter($insights, fn($i) => str_contains($i['title'], 'delays observed execution'));
        $this->assertEmpty($early);
    }

    public function test_http_during_early_phase_ignores_cyclic_parent_chains(): void
    {
        $phase_span = $this->make_span('lc1', Span::TYPE_CORE, 'Init', 200.0);
        $first      = $this->make_span('p1', Span::TYPE_PLUGIN, 'Plugin A', 50.0, 'p2');
        $second     = $this->make_span('p2', Span::TYPE_PLUGIN, 'Plugin B', 50.0, 'p1');
        $http_span  = $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 40.0, 'p1', [
            'host'   => 'api.example.com',
            'method' => 'GET',
            'status' => 200,
        ]);

        $trace    = $this->make_trace([$phase_span, $first, $second, $http_span]);
        $insights = Insights::analyze($trace);

        $early = array_filter($insights, fn($i) => str_contains($i['title'], 'delays observed execution'));
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

    public function test_no_persistent_cache_does_not_fire_when_external_cache_is_configured(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend'                    => 'WP_Object_Cache',
            'cache_hits'                       => 1,
            'cache_misses'                     => 100,
            'external_object_cache_configured' => true,
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

    public function test_low_cache_hit_ratio_handles_pathological_counters(): void
    {
        $trace = $this->make_trace([], [
            'cache_backend' => 'Redis_Object_Cache',
            'cache_hits'    => PHP_INT_MAX,
            'cache_misses'  => PHP_INT_MAX,
        ]);

        $insights = Insights::analyze($trace);

        $matches = array_values(array_filter($insights, fn($i) => str_contains($i['title'], 'Low cache hit ratio')));
        $this->assertCount(1, $matches);
        $this->assertStringContainsString('50%', $matches[0]['title']);
    }

    public function test_trace_insight_rules_tolerate_malformed_meta_without_warnings(): void
    {
        $trace = $this->make_trace(
            [
                $this->make_span('h1', Span::TYPE_HTTP, 'HTTP', 250.0, null, [
                    'url'    => ['bad'],
                    'host'   => ['bad'],
                    'method' => ['GET'],
                    'status' => ['200'],
                ]),
                $this->make_span('d1', Span::TYPE_DB, 'DB', 5.0, null, [
                    'query'      => ['SELECT 1'],
                    'query_hash' => ['bad'],
                ]),
                $this->make_span('cb1', Span::TYPE_PLUGIN, 'callback', 75.0, null, [
                    'hook' => ['init'],
                ]),
            ],
            [
                'cache_backend' => ['WP_Object_Cache'],
                'cache_hits'    => ['bad'],
                'cache_misses'  => ['bad'],
            ]
        );
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $insights = Insights::analyze($trace);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertCount(1, $insights);
        $this->assertStringContainsString('unknown', $insights[0]['title']);
    }

    // ---------------------------------------------------------------------------
    // analyze_dashboard() — abuse detection rules
    // ---------------------------------------------------------------------------

    public function test_high_request_rate_above_100_produces_warning(): void
    {
        $top_ips = [
            ['ip_address' => '203.0.113.45', 'request_count' => 412, 'avg_ms' => 1720.0, 'total_ms' => 708640.0],
        ];

        $insights = Insights::analyze_dashboard([], $top_ips, []);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], '203.0.113.45'));
        $matches = array_values($matches);

        $this->assertCount(1, $matches);
        $this->assertSame('warning', $matches[0]['severity']);
        $this->assertStringContainsString('412', $matches[0]['title']);
        $this->assertStringContainsString('scraping', $matches[0]['detail']);
    }

    public function test_high_request_rate_at_threshold_does_not_trigger(): void
    {
        $top_ips = [
            ['ip_address' => '10.0.0.1', 'request_count' => 100, 'avg_ms' => 200.0, 'total_ms' => 20000.0],
        ];

        $insights = Insights::analyze_dashboard([], $top_ips, []);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], '10.0.0.1'));
        $this->assertEmpty($matches);
    }

    public function test_dashboard_high_request_rate_bounds_ip_labels_before_rendering(): void
    {
        $top_ips = [
            ['ip_address' => str_repeat('1', 200), 'request_count' => 412, 'avg_ms' => 1720.0, 'total_ms' => 708640.0],
        ];

        $insights = Insights::analyze_dashboard([], $top_ips, []);

        $this->assertCount(1, $insights);
        $this->assertStringContainsString(str_repeat('1', 45), $insights[0]['title']);
        $this->assertStringNotContainsString(str_repeat('1', 46), $insights[0]['title']);
    }

    public function test_high_resource_consumer_above_60s_produces_warning(): void
    {
        $top_users = [
            ['user_id' => 0, 'request_count' => 347, 'avg_ms' => 1850.0, 'total_ms' => 641950.0],
        ];

        $insights = Insights::analyze_dashboard($top_users, [], []);

        $matches = array_filter($insights, fn($i) => str_contains($i['detail'], 'significant server load'));
        $matches = array_values($matches);

        $this->assertCount(1, $matches);
        $this->assertSame('warning', $matches[0]['severity']);
        $this->assertStringContainsString('347', $matches[0]['title']);
    }

    public function test_high_resource_consumer_at_threshold_does_not_trigger(): void
    {
        $top_users = [
            ['user_id' => 0, 'request_count' => 50, 'avg_ms' => 1200.0, 'total_ms' => 60000.0],
        ];

        $insights = Insights::analyze_dashboard($top_users, [], []);

        $matches = array_filter($insights, fn($i) => str_contains($i['detail'], 'significant server load'));
        $this->assertEmpty($matches);
    }

    public function test_no_abuse_with_normal_data_returns_empty(): void
    {
        $top_users = [
            ['user_id' => 0, 'request_count' => 20, 'avg_ms' => 150.0, 'total_ms' => 3000.0],
        ];
        $top_ips = [
            ['ip_address' => '192.168.1.1', 'request_count' => 20, 'avg_ms' => 150.0, 'total_ms' => 3000.0],
        ];
        $traces = [
            ['url' => '/wp-json/wp/v2/posts?page=1', 'ip_address' => '192.168.1.1'],
            ['url' => '/wp-json/wp/v2/posts?page=2', 'ip_address' => '192.168.1.1'],
        ];

        $insights = Insights::analyze_dashboard($top_users, $top_ips, $traces);

        // No rule should fire: request count ≤ 100, total_ms ≤ 60000, pages < 3
        $this->assertEmpty($insights);
    }

    public function test_sequential_api_pagination_produces_warning(): void
    {
        $traces = [
            ['url' => '/wp-json/wc/v3/customers?page=1', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=2', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=3', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=4', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=5', 'ip_address' => '203.0.113.45'],
        ];

        $insights = Insights::analyze_dashboard([], [], $traces);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'scraping'));
        $matches = array_values($matches);

        $this->assertCount(1, $matches);
        $this->assertSame('warning', $matches[0]['severity']);
        $this->assertStringContainsString('203.0.113.45', $matches[0]['title']);
        $this->assertStringContainsString('pages 1-5', $matches[0]['detail']);
    }

    public function test_sequential_api_pagination_below_threshold_does_not_trigger(): void
    {
        // Only 2 sequential pages — not enough
        $traces = [
            ['url' => '/wp-json/wp/v2/posts?page=1', 'ip_address' => '10.0.0.5'],
            ['url' => '/wp-json/wp/v2/posts?page=2', 'ip_address' => '10.0.0.5'],
        ];

        $insights = Insights::analyze_dashboard([], [], $traces);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'scraping'));
        $this->assertEmpty($matches);
    }

    public function test_dashboard_insights_tolerate_malformed_rows_without_warnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $insights = Insights::analyze_dashboard(
                [
                    'not-a-row',
                    ['user_id' => ['bad'], 'request_count' => ['bad'], 'total_ms' => ['bad']],
                ],
                [
                    'not-a-row',
                    ['ip_address' => ['bad'], 'request_count' => ['bad'], 'avg_ms' => ['bad']],
                ],
                [
                    'not-a-row',
                    ['url' => ['bad'], 'ip_address' => ['bad']],
                    ['url' => '/wp-json/wp/v2/posts?page[]=1', 'ip_address' => '203.0.113.45'],
                ]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame([], $insights);
    }

    public function test_sequential_api_pagination_ignores_non_numeric_page_values(): void
    {
        $traces = [
            ['url' => '/wp-json/wc/v3/customers?page=first', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=2', 'ip_address' => '203.0.113.45'],
            ['url' => '/wp-json/wc/v3/customers?page=3', 'ip_address' => '203.0.113.45'],
        ];

        $insights = Insights::analyze_dashboard([], [], $traces);

        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'scraping'));
        $this->assertEmpty($matches);
    }

    public function test_dashboard_pagination_analysis_caps_query_params_before_parsing(): void
    {
        $noise = [];
        for ($i = 1; $i <= 55; $i++) {
            $noise[] = 'noise' . $i . '=1';
        }

        $url = '/wp-json/wc/v3/customers?' . implode('&', $noise) . '&page=';
        $traces = [
            ['url' => $url . '1', 'ip_address' => '203.0.113.45'],
            ['url' => $url . '2', 'ip_address' => '203.0.113.45'],
            ['url' => $url . '3', 'ip_address' => '203.0.113.45'],
        ];

        $insights = Insights::analyze_dashboard([], [], $traces);
        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'scraping'));

        $this->assertEmpty($matches);
    }

    public function test_dashboard_pagination_analysis_bounds_endpoint_detail_lines(): void
    {
        $traces = [];
        for ($endpoint = 1; $endpoint <= 8; $endpoint++) {
            for ($page = 1; $page <= 3; $page++) {
                $traces[] = [
                    'url'        => '/wp-json/wc/v3/resource-' . $endpoint . '?page=' . $page,
                    'ip_address' => '203.0.113.45',
                ];
            }
        }

        $insights = Insights::analyze_dashboard([], [], $traces);
        $matches = array_values(array_filter($insights, fn($i) => str_contains($i['title'], 'scraping')));

        $this->assertCount(1, $matches);
        $this->assertSame(5, substr_count($matches[0]['detail'], 'pages 1-3'));
        $this->assertStringContainsString('resource-5', $matches[0]['detail']);
        $this->assertStringNotContainsString('resource-6', $matches[0]['detail']);
    }

    public function test_dashboard_pagination_analysis_bounds_trace_rows(): void
    {
        $traces = [];
        for ($i = 1; $i <= 200; $i++) {
            $traces[] = [
                'url'        => '/wp-json/wc/v3/noise-' . $i . '?page=1',
                'ip_address' => '203.0.113.45',
            ];
        }

        $traces[] = ['url' => '/wp-json/wc/v3/customers?page=1', 'ip_address' => '203.0.113.45'];
        $traces[] = ['url' => '/wp-json/wc/v3/customers?page=2', 'ip_address' => '203.0.113.45'];
        $traces[] = ['url' => '/wp-json/wc/v3/customers?page=3', 'ip_address' => '203.0.113.45'];

        $insights = Insights::analyze_dashboard([], [], $traces);
        $matches = array_filter($insights, fn($i) => str_contains($i['title'], 'scraping'));

        $this->assertEmpty($matches);
    }

    public function test_dashboard_pagination_analysis_bounds_ip_and_endpoint_labels(): void
    {
        $endpoint = '/wp-json/wc/v3/' . str_repeat('customers', 220);
        $traces = [
            ['url' => $endpoint . '?page=1', 'ip_address' => str_repeat('2', 200)],
            ['url' => $endpoint . '?page=2', 'ip_address' => str_repeat('2', 200)],
            ['url' => $endpoint . '?page=3', 'ip_address' => str_repeat('2', 200)],
        ];

        $insights = Insights::analyze_dashboard([], [], $traces);
        $matches = array_values(array_filter($insights, fn($i) => str_contains($i['title'], 'scraping')));

        $this->assertCount(1, $matches);
        $this->assertStringContainsString(str_repeat('2', 45), $matches[0]['title']);
        $this->assertStringNotContainsString(str_repeat('2', 46), $matches[0]['title']);
        $this->assertLessThanOrEqual(2000, strlen($matches[0]['detail']));
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

    public function test_normalize_skips_malformed_insights_and_coerces_safe_entries(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $normalized = Insights::normalize([
                'not-an-insight',
                ['severity' => 'critical', 'title' => ['bad'], 'detail' => new \stdClass()],
                ['severity' => 'warning', 'title' => 'Valid warning', 'detail' => 'Useful detail'],
                new \WPFlame\Insight('custom', 'info', 'Object insight', 'Object detail'),
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertCount(2, $normalized);
        $this->assertSame('warning', $normalized[0]['severity']);
        $this->assertSame('Valid warning', $normalized[0]['title']);
        $this->assertSame('Useful detail', $normalized[0]['detail']);
        $this->assertSame('low', $normalized[0]['confidence']);
        $this->assertSame('investigate', $normalized[0]['action_type']);
        $this->assertSame('info', $normalized[1]['severity']);
        $this->assertSame('Object insight', $normalized[1]['title']);
        $this->assertSame('medium', $normalized[1]['confidence']);
    }

    public function test_normalize_bounds_insight_count_and_text_size(): void
    {
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = [
                'severity' => 'warning',
                'title'    => str_repeat('T', 400),
                'detail'   => str_repeat('D', 2500),
            ];
        }

        $normalized = Insights::normalize($items);

        $this->assertCount(20, $normalized);
        $this->assertSame(300, strlen($normalized[0]['title']));
        $this->assertSame(2000, strlen($normalized[0]['detail']));
    }

    public function test_normalize_returns_empty_array_for_non_array_filter_output(): void
    {
        $this->assertSame([], Insights::normalize('invalid'));
    }
}
