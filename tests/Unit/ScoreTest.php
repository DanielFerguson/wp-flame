<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Score;
use WPFlame\Span;
use WPFlame\Trace;

class ScoreTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function make_trace(
        float $total_ms,
        array $spans = [],
        array $meta = []
    ): Trace {
        return new Trace(
            'trace-score-test',
            '/test',
            'GET',
            '2026-03-17T00:00:00+00:00',
            $total_ms,
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
        float $duration_ms,
        array $meta = []
    ): Span {
        return new Span($id, null, 'test-span', $type, 'test-source', 0.0, $duration_ms, $meta);
    }

    // ---------------------------------------------------------------------------
    // Fast trace → high score, grade A
    // ---------------------------------------------------------------------------

    public function test_fast_trace_scores_high_and_gets_grade_A(): void
    {
        // 50ms total, 10 DB queries (all fast), no HTTP, no slow callbacks
        $db_spans = [];
        for ($i = 0; $i < 10; $i++) {
            $db_spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 1.0);
        }

        $trace  = $this->make_trace(50.0, $db_spans);
        $result = Score::calculate($trace);

        $this->assertGreaterThanOrEqual(90, $result['score'], 'Fast trace should score ≥ 90');
        $this->assertSame('A', $result['grade']);
        $this->assertSame('#22c55e', $result['color']);
    }

    // ---------------------------------------------------------------------------
    // Slow trace → low score, grade F
    // ---------------------------------------------------------------------------

    public function test_slow_trace_scores_low_and_gets_grade_F(): void
    {
        // 2000ms total, 150 DB queries, 500ms HTTP, 5 slow callbacks
        $spans = [];
        for ($i = 0; $i < 150; $i++) {
            $spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 5.0);
        }
        $spans[] = $this->make_span('h1', Span::TYPE_HTTP, 500.0);
        for ($i = 0; $i < 5; $i++) {
            $spans[] = $this->make_span("cb{$i}", Span::TYPE_PLUGIN, 75.0, ['hook' => 'init']);
        }

        $trace  = $this->make_trace(2000.0, $spans);
        $result = Score::calculate($trace);

        $this->assertLessThan(60, $result['score'], 'Slow trace should score < 60');
        $this->assertSame('F', $result['grade']);
        $this->assertSame('#ef4444', $result['color']);
    }

    // ---------------------------------------------------------------------------
    // Medium trace → mid score
    // ---------------------------------------------------------------------------

    public function test_medium_trace_scores_in_middle_range(): void
    {
        // 300ms total, 40 DB queries, no HTTP, 1 slow callback
        $spans = [];
        for ($i = 0; $i < 40; $i++) {
            $spans[] = $this->make_span("d{$i}", Span::TYPE_DB, 3.0);
        }
        $spans[] = $this->make_span('cb1', Span::TYPE_PLUGIN, 75.0, ['hook' => 'wp_head']);

        $trace  = $this->make_trace(300.0, $spans);
        $result = Score::calculate($trace);

        $this->assertGreaterThan(60, $result['score'], 'Medium trace should score > 60');
        $this->assertLessThan(90, $result['score'], 'Medium trace should score < 90');
    }

    // ---------------------------------------------------------------------------
    // Edge: total_ms < 100ms → DB ratio auto 100
    // ---------------------------------------------------------------------------

    public function test_db_ratio_is_auto_100_when_total_ms_below_100(): void
    {
        // Only 50ms total — DB ratio factor must be auto-set to 100
        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 40.0), // 80% of time in DB
        ];

        $trace  = $this->make_trace(50.0, $spans);
        $result = Score::calculate($trace);

        // Find the db_ratio factor
        $db_ratio_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'db_ratio') {
                $db_ratio_factor = $factor;
                break;
            }
        }

        $this->assertNotNull($db_ratio_factor);
        $this->assertSame(100, $db_ratio_factor['score'], 'DB ratio should be auto 100 when total_ms < 100');
    }

    // ---------------------------------------------------------------------------
    // Edge: no HTTP spans → HTTP factor 100
    // ---------------------------------------------------------------------------

    public function test_no_http_spans_gives_http_factor_100(): void
    {
        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 5.0),
        ];

        $trace  = $this->make_trace(200.0, $spans);
        $result = Score::calculate($trace);

        $http_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'http_time') {
                $http_factor = $factor;
                break;
            }
        }

        $this->assertNotNull($http_factor);
        $this->assertSame(100, $http_factor['score'], 'HTTP factor should be 100 with no HTTP spans');
    }

    // ---------------------------------------------------------------------------
    // Edge: no callback spans → slow callbacks 100
    // ---------------------------------------------------------------------------

    public function test_no_slow_callbacks_gives_slow_callbacks_factor_100(): void
    {
        $spans = [
            $this->make_span('d1', Span::TYPE_DB, 5.0),
        ];

        $trace  = $this->make_trace(200.0, $spans);
        $result = Score::calculate($trace);

        $cb_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'slow_callbacks') {
                $cb_factor = $factor;
                break;
            }
        }

        $this->assertNotNull($cb_factor);
        $this->assertSame(100, $cb_factor['score'], 'Slow callbacks factor should be 100 when no slow callbacks');
    }

    // ---------------------------------------------------------------------------
    // Factor structure
    // ---------------------------------------------------------------------------

    public function test_calculate_returns_five_factors(): void
    {
        $trace  = $this->make_trace(200.0, []);
        $result = Score::calculate($trace);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('color', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertCount(5, $result['factors']);
    }

    public function test_each_factor_has_required_keys(): void
    {
        $trace  = $this->make_trace(200.0, []);
        $result = Score::calculate($trace);

        foreach ($result['factors'] as $factor) {
            $this->assertArrayHasKey('key', $factor);
            $this->assertArrayHasKey('label', $factor);
            $this->assertArrayHasKey('score', $factor);
            $this->assertArrayHasKey('weight', $factor);
            $this->assertArrayHasKey('value', $factor);
        }
    }

    // ---------------------------------------------------------------------------
    // Grade boundaries
    // ---------------------------------------------------------------------------

    public function test_grade_90_is_A(): void
    {
        $grade = Score::grade(90);
        $this->assertSame('A', $grade['grade']);
        $this->assertSame('#22c55e', $grade['color']);
    }

    public function test_grade_89_is_B(): void
    {
        $grade = Score::grade(89);
        $this->assertSame('B', $grade['grade']);
        $this->assertSame('#84cc16', $grade['color']);
    }

    public function test_grade_80_is_B(): void
    {
        $grade = Score::grade(80);
        $this->assertSame('B', $grade['grade']);
        $this->assertSame('#84cc16', $grade['color']);
    }

    public function test_grade_70_is_C(): void
    {
        $grade = Score::grade(70);
        $this->assertSame('C', $grade['grade']);
        $this->assertSame('#eab308', $grade['color']);
    }

    public function test_grade_60_is_D(): void
    {
        $grade = Score::grade(60);
        $this->assertSame('D', $grade['grade']);
        $this->assertSame('#f97316', $grade['color']);
    }

    public function test_grade_59_is_F(): void
    {
        $grade = Score::grade(59);
        $this->assertSame('F', $grade['grade']);
        $this->assertSame('#ef4444', $grade['color']);
    }

    public function test_grade_100_is_A(): void
    {
        $grade = Score::grade(100);
        $this->assertSame('A', $grade['grade']);
    }

    public function test_grade_0_is_F(): void
    {
        $grade = Score::grade(0);
        $this->assertSame('F', $grade['grade']);
    }

    // ---------------------------------------------------------------------------
    // Interpolation boundary: exactly at threshold
    // ---------------------------------------------------------------------------

    public function test_response_time_at_best_threshold_scores_100(): void
    {
        // Exactly 100ms → response time factor should be 100
        $trace  = $this->make_trace(100.0, []);
        $result = Score::calculate($trace);

        $rt_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'response_time') {
                $rt_factor = $factor;
                break;
            }
        }

        $this->assertSame(100, $rt_factor['score']);
    }

    public function test_response_time_at_worst_threshold_scores_0(): void
    {
        // Exactly 3000ms → response time factor should be 0
        $trace  = $this->make_trace(3000.0, []);
        $result = Score::calculate($trace);

        $rt_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'response_time') {
                $rt_factor = $factor;
                break;
            }
        }

        $this->assertSame(0, $rt_factor['score']);
    }

    public function test_response_time_beyond_worst_threshold_scores_0(): void
    {
        // 5000ms — should clamp to 0
        $trace  = $this->make_trace(5000.0, []);
        $result = Score::calculate($trace);

        $rt_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'response_time') {
                $rt_factor = $factor;
                break;
            }
        }

        $this->assertSame(0, $rt_factor['score']);
    }

    // ---------------------------------------------------------------------------
    // calculate_from_basic
    // ---------------------------------------------------------------------------

    public function test_calculate_from_basic_returns_integer_between_0_and_100(): void
    {
        $score = Score::calculate_from_basic(300.0, 30);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_calculate_from_basic_fast_request_scores_high(): void
    {
        $score = Score::calculate_from_basic(50.0, 5);
        $this->assertGreaterThanOrEqual(90, $score);
    }

    // ---------------------------------------------------------------------------
    // Slow callback detection: only counts spans with hook meta AND duration > 50ms
    // ---------------------------------------------------------------------------

    public function test_callback_with_hook_meta_at_exactly_50ms_is_not_slow(): void
    {
        $spans = [
            $this->make_span('cb1', Span::TYPE_PLUGIN, 50.0, ['hook' => 'init']),
        ];

        $trace  = $this->make_trace(200.0, $spans);
        $result = Score::calculate($trace);

        $cb_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'slow_callbacks') {
                $cb_factor = $factor;
                break;
            }
        }

        $this->assertSame(100, $cb_factor['score'], 'Exactly 50ms should not count as slow');
        $this->assertSame('0', $cb_factor['value']);
    }

    public function test_callback_without_hook_meta_is_not_counted_as_slow(): void
    {
        // A plugin span without a hook key in meta — should not count
        $spans = [
            $this->make_span('p1', Span::TYPE_PLUGIN, 100.0, []),
        ];

        $trace  = $this->make_trace(200.0, $spans);
        $result = Score::calculate($trace);

        $cb_factor = null;
        foreach ($result['factors'] as $factor) {
            if ($factor['key'] === 'slow_callbacks') {
                $cb_factor = $factor;
                break;
            }
        }

        $this->assertSame(100, $cb_factor['score']);
    }

    // ---------------------------------------------------------------------------
    // GraphQL resolver span detected via hook meta
    // ---------------------------------------------------------------------------

    public function test_score_detects_slow_graphql_resolvers_via_hook_meta(): void
    {
        // Build a resolver span with meta['hook'] — same pattern Score.php:43 checks
        $resolver_span = $this->make_span('r1', Span::TYPE_PLUGIN, 200.0,
            ['hook' => 'graphql:RootQuery.posts', 'type_name' => 'RootQuery', 'field_key' => 'posts']);

        $trace = $this->make_trace(300.0, [$resolver_span]);

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
}
