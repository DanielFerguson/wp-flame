<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Score
{
    // Grade thresholds
    private const GRADE_A = 90;
    private const GRADE_B = 80;
    private const GRADE_C = 70;
    private const GRADE_D = 60;

    // Grade colors
    private const COLOR_A = '#22c55e';
    private const COLOR_B = '#84cc16';
    private const COLOR_C = '#eab308';
    private const COLOR_D = '#f97316';
    private const COLOR_F = '#ef4444';

    /**
     * Compute the full performance score for a trace.
     *
     * @return array{score: int, grade: string, color: string, factors: array<int, array<string, mixed>>}
     */
    public static function calculate(Trace $trace): array
    {
        // Factor 1: Response Time (35%) — best ≤100ms, worst ≥3000ms
        $rt_score = self::interpolate($trace->total_ms, 100.0, 3000.0);

        // Factor 2: External HTTP time (20%) — sum duration_ms for http-type spans
        $http_total_ms = 0.0;
        $slow_callback_count = 0;
        foreach ($trace->spans as $span) {
            if ($span->type === Span::TYPE_HTTP) {
                $http_total_ms += $span->duration_ms;
            }
            if (isset($span->meta['hook']) && $span->duration_ms > 50) {
                $slow_callback_count++;
            }
        }
        $http_score = self::interpolate($http_total_ms, 0.0, 2000.0);

        // Factor 3: DB Query Count (15%) — best ≤15, worst ≥200
        $qc_score = self::interpolate((float) $trace->query_count, 15.0, 200.0);

        // Factor 4: DB Time Ratio (15%) — auto 100 if total_ms < 100
        if ($trace->total_ms < 100) {
            $db_ratio_score = 100;
            $db_ratio_value = 0.0;
        } else {
            $db_ratio_value = $trace->total_query_ms / $trace->total_ms;
            $db_ratio_score = self::interpolate($db_ratio_value, 0.10, 0.60);
        }

        // Factor 5: Slow Callbacks (15%) — best 0, worst ≥10
        $cb_score = self::interpolate((float) $slow_callback_count, 0.0, 10.0);

        // Allow site profiles to adjust factor weights/scores.
        $computed_factors = apply_filters( 'wp_flame_score_factors', [
            'response_time'  => [ 'weight' => 0.35, 'score' => $rt_score ],
            'http_time'      => [ 'weight' => 0.20, 'score' => $http_score ],
            'query_count'    => [ 'weight' => 0.15, 'score' => $qc_score ],
            'db_ratio'       => [ 'weight' => 0.15, 'score' => $db_ratio_score ],
            'slow_callbacks' => [ 'weight' => 0.15, 'score' => $cb_score ],
        ], $trace );

        $overall = 0.0;
        foreach ( $computed_factors as $f ) {
            $overall += $f['score'] * $f['weight'];
        }
        $overall = max( 0, min( 100, (int) round( $overall ) ) );

        $grade_info = self::grade($overall);

        return [
            'score'   => $overall,
            'grade'   => $grade_info['grade'],
            'color'   => $grade_info['color'],
            'factors' => [
                [
                    'key'    => 'response_time',
                    'label'  => esc_html__('Response Time', 'wp-flame'),
                    'score'  => $rt_score,
                    'weight' => 35,
                    'value'  => round($trace->total_ms) . 'ms',
                ],
                [
                    'key'    => 'http_time',
                    'label'  => esc_html__('External HTTP', 'wp-flame'),
                    'score'  => $http_score,
                    'weight' => 20,
                    'value'  => round($http_total_ms) . 'ms',
                ],
                [
                    'key'    => 'query_count',
                    'label'  => esc_html__('DB Queries', 'wp-flame'),
                    'score'  => $qc_score,
                    'weight' => 15,
                    'value'  => (string) $trace->query_count,
                ],
                [
                    'key'    => 'db_ratio',
                    'label'  => esc_html__('DB Time Ratio', 'wp-flame'),
                    'score'  => $db_ratio_score,
                    'weight' => 15,
                    'value'  => $trace->total_ms >= 100
                        ? round($db_ratio_value * 100) . '%'
                        : '—',
                ],
                [
                    'key'    => 'slow_callbacks',
                    'label'  => esc_html__('Slow Callbacks', 'wp-flame'),
                    'score'  => $cb_score,
                    'weight' => 15,
                    'value'  => (string) $slow_callback_count,
                ],
            ],
        ];
    }

    /**
     * Return grade letter and color for a 0-100 score.
     *
     * @return array{grade: string, color: string}
     */
    public static function grade(int $score): array
    {
        if ($score >= self::GRADE_A) {
            return ['grade' => 'A', 'color' => self::COLOR_A];
        }
        if ($score >= self::GRADE_B) {
            return ['grade' => 'B', 'color' => self::COLOR_B];
        }
        if ($score >= self::GRADE_C) {
            return ['grade' => 'C', 'color' => self::COLOR_C];
        }
        if ($score >= self::GRADE_D) {
            return ['grade' => 'D', 'color' => self::COLOR_D];
        }
        return ['grade' => 'F', 'color' => self::COLOR_F];
    }

    /**
     * Simplified score from just response time and query count.
     * Used as a fallback for traces without full span data.
     */
    public static function calculate_from_basic(float $total_ms, int $query_count): int
    {
        $rt_score = self::interpolate($total_ms, 100.0, 3000.0);
        $qc_score = self::interpolate((float) $query_count, 15.0, 200.0);

        return (int) round(
            $rt_score * 0.35 +
            100 * 0.20 +  // http_time — no data, assume excellent
            $qc_score * 0.15 +
            100 * 0.15 +  // db_ratio  — no data, assume excellent
            100 * 0.15    // slow_callbacks — no data, assume excellent
        );
    }

    /**
     * Linear interpolation: maps a value between best (→100) and worst (→0), clamped.
     */
    private static function interpolate(float $value, float $best, float $worst): int
    {
        if ($value <= $best) {
            return 100;
        }
        if ($value >= $worst) {
            return 0;
        }
        return (int) round(100 * (1 - ($value - $best) / ($worst - $best)));
    }
}
