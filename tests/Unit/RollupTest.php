<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CaptureReport;
use WPFlame\Rollup;
use WPFlame\Span;
use WPFlame\Trace;

class RollupTest extends TestCase
{
    public function test_trace_projects_to_versioned_cohort_and_grouped_dimension_rows(): void
    {
        $trace = $this->trace([
            new Span('1', null, 'init callback', Span::TYPE_PLUGIN, 'shop-plugin', 0.0, 25.0, [
                'hook'     => 'init',
                'priority' => 10,
            ]),
            new Span('2', null, 'query', Span::TYPE_DB, 'shop-plugin', 25.0, 10.0),
            new Span('3', null, 'other query', Span::TYPE_DB, 'shop-plugin', 35.0, 5.0),
        ]);

        $rows = Rollup::rows_for_trace($trace, 87, '2026-07-16');

        $this->assertCount(3, $rows);
        $cohort = $rows[0];
        $this->assertSame(Rollup::VERSION, $cohort['rollup_version']);
        $this->assertSame('frontend', $cohort['request_type']);
        $this->assertSame('GET /shop/product', $cohort['route_key']);
        $this->assertSame(1, $cohort['sample_count']);
        $this->assertSame(3, $cohort['span_count']);
        $this->assertSame(100.0, $cohort['total_duration_ms']);
        $this->assertSame(2, $cohort['total_query_count']);
        $this->assertSame(87, $cohort['total_score']);
        $this->assertSame(1, $cohort['complete_count']);
        $this->assertSame(1, $cohort['duration_b1']);
        $this->assertSame(0, $cohort['duration_b0']);
        $this->assertSame('', $cohort['source']);
        $this->assertSame(64, strlen($cohort['capability_cohort']));

        $plugin = $rows[1];
        $this->assertSame('shop-plugin', $plugin['source']);
        $this->assertSame(Span::TYPE_PLUGIN, $plugin['span_type']);
        $this->assertSame('init@10:init callback', $plugin['callback_key']);
        $this->assertSame(25.0, $plugin['span_duration_ms']);
        $this->assertSame(0, array_sum(array_filter($plugin, static function ($key): bool {
            return strpos((string) $key, 'duration_b') === 0;
        }, ARRAY_FILTER_USE_KEY)));

        $database = $rows[2];
        $this->assertSame(2, $database['span_count']);
        $this->assertSame(15.0, $database['span_duration_ms']);
    }

    public function test_capability_cohorts_and_completeness_prevent_incompatible_comparisons(): void
    {
        $complete = Rollup::rows_for_trace($this->trace([]), null, '2026-07-16')[0];
        $limited = Rollup::rows_for_trace($this->trace([], [
            'capabilities'       => [
                'database' => [ 'status' => 'unavailable', 'reason' => 'drop_in' ],
            ],
            'incomplete_reasons' => [ 'database_unavailable' ],
        ]), null, '2026-07-16')[0];

        $this->assertNotSame($complete['capability_cohort'], $limited['capability_cohort']);
        $this->assertSame(1, $complete['complete_count']);
        $this->assertSame(0, $limited['complete_count']);
        $this->assertSame(0, $limited['scored_count']);
    }

    public function test_dimension_projection_is_bounded(): void
    {
        $spans = [];
        for ($index = 0; $index < Rollup::MAX_DIMENSIONS_PER_TRACE + 20; $index++) {
            $spans[] = new Span((string) $index, null, 'callback-' . $index, Span::TYPE_PLUGIN, 'plugin-' . $index, 0.0, 1.0, [
                'hook' => 'hook-' . $index,
            ]);
        }

        $rows = Rollup::rows_for_trace($this->trace($spans), 50, '2026-07-16');

        $this->assertCount(Rollup::MAX_DIMENSIONS_PER_TRACE + 1, $rows);
    }

    public function test_source_time_keeps_inclusive_callback_evidence_and_exclusive_breakdown_time(): void
    {
        $rows = Rollup::rows_for_trace($this->trace([
            new Span('parent', null, 'outer callback', Span::TYPE_PLUGIN, 'outer-plugin', 0.0, 80.0, [
                'hook' => 'init',
            ]),
            new Span('child', 'parent', 'inner query', Span::TYPE_DB, 'inner-plugin', 10.0, 30.0),
        ]), 80, '2026-07-16');

        $this->assertSame(80.0, $rows[1]['span_duration_ms']);
        $this->assertSame(50.0, $rows[1]['self_duration_ms']);
        $this->assertSame(30.0, $rows[2]['span_duration_ms']);
        $this->assertSame(30.0, $rows[2]['self_duration_ms']);
    }

    /**
     * @param Span[] $spans
     * @param array<string, mixed> $overrides
     */
    private function trace(array $spans, array $overrides = []): Trace
    {
        $capabilities = [];
        foreach (CaptureReport::CAPABILITY_KEYS as $key) {
            $capabilities[$key] = [ 'status' => 'captured', 'reason' => '' ];
        }

        return new Trace(
            'trace-1',
            '/shop/product',
            'GET',
            '2026-07-16T00:00:00+00:00',
            100.0,
            1024,
            '8.3',
            '6.8',
            $spans,
            [],
            array_merge([
                'request_type'        => 'frontend',
                'route_key'           => 'GET /shop/product',
                'instrumentation_mode' => 'standard',
                'score_version'       => 2,
                'capabilities'        => $capabilities,
            ], $overrides)
        );
    }
}
