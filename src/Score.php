<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Score
{
    public const VERSION = 2;
    private const MAX_FACTOR_KEY_BYTES = 80;
    private const MAX_FACTOR_LABEL_BYTES = 120;
    private const MAX_FACTOR_VALUE_BYTES = 160;

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
     * Historical traces use their persisted snapshot. Trace score version 1
     * remains readable through the original algorithm; current captures use v2.
     *
     * @return array<string, mixed>
     */
    public static function calculate(Trace $trace): array
    {
        if ( $trace->score_snapshot !== null ) {
            $snapshot = self::normalize_snapshot( $trace->score_snapshot );
            if ( $snapshot !== null ) {
                return $snapshot;
            }
        }

        $result = $trace->capture_report->score_version >= self::VERSION
            ? self::calculate_v2( $trace )
            : self::calculate_v1( $trace );

        // Score v1 did not persist factor snapshots. Preserve its stored
        // overall score so list and detail views cannot drift after filters or
        // thresholds change; the detail UI labels its factors as legacy.
        if ( $trace->capture_report->score_version < self::VERSION && array_key_exists( '_row_score', $trace->meta ) ) {
            $stored = Config::bounded_int( $trace->meta['_row_score'], $result['score'], 0, 100 );
            $grade = self::grade( $stored );
            $result['score'] = $stored;
            $result['grade'] = $grade['grade'];
            $result['color'] = $grade['color'];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function calculate_v1( Trace $trace ): array
    {
        // Factor 1: observed request duration (35%) — best ≤100ms, worst ≥3000ms
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
            $db_ratio_value = self::bounded_ratio( $trace->total_query_ms, $trace->total_ms );
            $db_ratio_score = self::interpolate($db_ratio_value, 0.10, 0.60);
        }

        // Factor 5: Slow Callbacks (15%) — best 0, worst ≥10
        $cb_score = self::interpolate((float) $slow_callback_count, 0.0, 10.0);

        $default_factors = [
            'response_time'  => [
                'weight' => 0.35,
                'score'  => $rt_score,
                'label'  => esc_html__('Observed Duration', 'wp-flame'),
                'value'  => round($trace->total_ms) . 'ms',
            ],
            'http_time'      => [
                'weight' => 0.20,
                'score'  => $http_score,
                'label'  => esc_html__('External HTTP', 'wp-flame'),
                'value'  => round($http_total_ms) . 'ms',
            ],
            'query_count'    => [
                'weight' => 0.15,
                'score'  => $qc_score,
                'label'  => esc_html__('DB Queries', 'wp-flame'),
                'value'  => (string) $trace->query_count,
            ],
            'db_ratio'       => [
                'weight' => 0.15,
                'score'  => $db_ratio_score,
                'label'  => esc_html__('DB Time Ratio', 'wp-flame'),
                'value'  => $trace->total_ms >= 100
                    ? round($db_ratio_value * 100) . '%'
                    : '—',
            ],
            'slow_callbacks' => [
                'weight' => 0.15,
                'score'  => $cb_score,
                'label'  => esc_html__('Slow Callbacks', 'wp-flame'),
                'value'  => (string) $slow_callback_count,
            ],
        ];

        // Allow site profiles to adjust factor weights/scores.
        $computed_factors = apply_filters( 'wp_flame_score_factors', $default_factors, $trace );
        if ( ! is_array( $computed_factors ) ) {
            $computed_factors = $default_factors;
        }

        $overall = 0.0;
        $display_factors = [];
        foreach ( $computed_factors as $key => $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }

            $default = $default_factors[ $key ] ?? [
                'label' => ucwords( str_replace( '_', ' ', (string) $key ) ),
                'value' => '',
            ];

            $default_score  = self::number( $default['score'] ?? 0, 0.0 );
            $default_weight = self::number( $default['weight'] ?? 0, 0.0 );
            $score          = max( 0, min( 100, (int) round( self::number( $f['score'] ?? $default_score, $default_score ) ) ) );
            $weight         = self::clamp_weight( self::number( $f['weight'] ?? $default_weight, $default_weight ) );
            $overall += $score * $weight;

            $display_factors[] = [
                'key'    => self::limit_string( self::display_string( $key, '' ), self::MAX_FACTOR_KEY_BYTES ),
                'label'  => self::limit_string(
                    self::display_string( $f['label'] ?? $default['label'], self::display_string( $default['label'], '' ) ),
                    self::MAX_FACTOR_LABEL_BYTES
                ),
                'score'  => $score,
                'weight' => (int) round( $weight * 100 ),
                'value'  => self::limit_string(
                    self::display_string( $f['value'] ?? $default['value'], self::display_string( $default['value'], '' ) ),
                    self::MAX_FACTOR_VALUE_BYTES
                ),
            ];
        }
        $overall = max( 0, min( 100, (int) round( $overall ) ) );

        $grade_info = self::grade($overall);

        return [
            'version' => 1,
            'score'   => $overall,
            'grade'   => $grade_info['grade'],
            'color'   => $grade_info['color'],
            'factors' => $display_factors,
        ];
    }

    /**
     * Score only observed factors and renormalize their configured weights.
     * Unavailable and intentionally unrequested telemetry remain visible as
     * unknown rather than receiving an assumed excellent score.
     *
     * @return array<string, mixed>
     */
    private static function calculate_v2( Trace $trace ): array
    {
        $http_total_ms = 0.0;
        $slow_callback_count = 0;
        foreach ( $trace->spans as $span ) {
            if ( $span->type === Span::TYPE_HTTP ) {
                $http_total_ms += $span->duration_ms;
            }
            if ( isset( $span->meta['hook'] ) && $span->duration_ms > 50 ) {
                $slow_callback_count++;
            }
        }

        $db_ratio = self::bounded_ratio( $trace->total_query_ms, $trace->total_ms );
        $factors = [
            'response_time' => self::v2_factor(
                0.35,
                self::interpolate( $trace->total_ms, 100.0, 3000.0 ),
                esc_html__( 'Observed Duration', 'wp-flame' ),
                round( $trace->total_ms ) . 'ms',
                '',
                'observed',
                $trace->capture_report->capture_start_stage !== 'mu_plugin' ? 'Capture began after the earliest lifecycle boundary.' : ''
            ),
            'http_time' => self::factor_for_capability(
                $trace,
                'http',
                0.20,
                self::interpolate( $http_total_ms, 0.0, 2000.0 ),
                esc_html__( 'External HTTP', 'wp-flame' ),
                round( $http_total_ms ) . 'ms'
            ),
            'query_count' => self::factor_for_capability(
                $trace,
                'database',
                0.15,
                self::interpolate( (float) $trace->query_count, 15.0, 200.0 ),
                esc_html__( 'DB Queries', 'wp-flame' ),
                (string) $trace->query_count
            ),
            'db_ratio' => self::factor_for_capability(
                $trace,
                'database',
                0.15,
                $trace->total_ms < 100 ? 100 : self::interpolate( $db_ratio, 0.10, 0.60 ),
                esc_html__( 'DB Time Ratio', 'wp-flame' ),
                $trace->total_ms >= 100 ? round( $db_ratio * 100 ) . '%' : '—'
            ),
            'slow_callbacks' => self::factor_for_capability(
                $trace,
                'callbacks',
                0.15,
                self::interpolate( (float) $slow_callback_count, 0.0, 10.0 ),
                esc_html__( 'Slow Callbacks', 'wp-flame' ),
                (string) $slow_callback_count
            ),
        ];

        $filtered = apply_filters( 'wp_flame_score_factors', $factors, $trace );
        if ( is_array( $filtered ) ) {
            $factors = $filtered;
        }

        $observed_weight = 0.0;
        $normalized = [];
        foreach ( $factors as $key => $factor ) {
            if ( ! is_array( $factor ) ) {
                continue;
            }
            $status = self::factor_status( $factor['status'] ?? 'unavailable' );
            $weight = self::clamp_weight( self::number( $factor['weight'] ?? 0, 0.0 ) );
            $score = max( 0, min( 100, (int) round( self::number( $factor['score'] ?? 0, 0.0 ) ) ) );
            if ( $status === 'observed' ) {
                $observed_weight += $weight;
            }
            $normalized[] = [
                'key'                 => self::limit_string( self::display_string( $key, '' ), self::MAX_FACTOR_KEY_BYTES ),
                'label'               => self::limit_string( self::display_string( $factor['label'] ?? '', '' ), self::MAX_FACTOR_LABEL_BYTES ),
                'value'               => self::limit_string( self::display_string( $factor['value'] ?? '', '' ), self::MAX_FACTOR_VALUE_BYTES ),
                'score'               => $score,
                'configured_weight'   => (int) round( $weight * 100 ),
                'weight'              => 0,
                'status'              => $status,
                'required_capability' => self::limit_string( self::display_string( $factor['required_capability'] ?? '', '' ), 80 ),
                'reason'              => self::limit_string( self::display_string( $factor['reason'] ?? '', '' ), 200 ),
            ];
        }

        $overall = 0.0;
        foreach ( $normalized as $index => $factor ) {
            if ( $factor['status'] !== 'observed' || $observed_weight <= 0.0 ) {
                continue;
            }
            $applied = ( $factor['configured_weight'] / 100 ) / $observed_weight;
            $normalized[ $index ]['weight'] = (int) round( $applied * 100 );
            $overall += $factor['score'] * $applied;
        }
        $overall = max( 0, min( 100, (int) round( $overall ) ) );
        $grade = self::grade( $overall );

        return [
            'version'           => self::VERSION,
            'score'             => $overall,
            'grade'             => $grade['grade'],
            'color'             => $grade['color'],
            'capability_cohort' => $trace->capture_report->capability_cohort(),
            'observed_weight'   => (int) round( $observed_weight * 100 ),
            'factors'           => $normalized,
        ];
    }

    /**
     * Normalize a persisted score snapshot before historical display.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|null
     */
    public static function normalize_snapshot( array $snapshot ): ?array
    {
        $version = Config::bounded_int( $snapshot['version'] ?? 0, 0, 1, 100000 );
        if ( $version <= 0 || ! isset( $snapshot['factors'] ) || ! is_array( $snapshot['factors'] ) ) {
            return null;
        }
        $score = Config::bounded_int( $snapshot['score'] ?? 0, 0, 0, 100 );
        $grade = self::grade( $score );
        $factors = [];
        foreach ( array_slice( $snapshot['factors'], 0, 50 ) as $factor ) {
            if ( ! is_array( $factor ) ) {
                continue;
            }
            $factor_score = Config::bounded_int( $factor['score'] ?? 0, 0, 0, 100 );
            $factors[] = [
                'key'                 => self::limit_string( self::display_string( $factor['key'] ?? '', '' ), self::MAX_FACTOR_KEY_BYTES ),
                'label'               => self::limit_string( self::display_string( $factor['label'] ?? '', '' ), self::MAX_FACTOR_LABEL_BYTES ),
                'value'               => self::limit_string( self::display_string( $factor['value'] ?? '', '' ), self::MAX_FACTOR_VALUE_BYTES ),
                'score'               => $factor_score,
                'configured_weight'   => Config::bounded_int( $factor['configured_weight'] ?? $factor['weight'] ?? 0, 0, 0, 100 ),
                'weight'              => Config::bounded_int( $factor['weight'] ?? 0, 0, 0, 100 ),
                'status'              => $version >= self::VERSION ? self::factor_status( $factor['status'] ?? 'unavailable' ) : 'observed',
                'required_capability' => self::limit_string( self::display_string( $factor['required_capability'] ?? '', '' ), 80 ),
                'reason'              => self::limit_string( self::display_string( $factor['reason'] ?? '', '' ), 200 ),
            ];
        }

        return [
            'version'           => $version,
            'score'             => $score,
            'grade'             => $grade['grade'],
            'color'             => $grade['color'],
            'capability_cohort' => self::limit_string( self::display_string( $snapshot['capability_cohort'] ?? '', '' ), 64 ),
            'observed_weight'   => Config::bounded_int( $snapshot['observed_weight'] ?? 100, 100, 0, 100 ),
            'factors'           => $factors,
        ];
    }

    /** @return array<string, mixed> */
    private static function factor_for_capability(
        Trace $trace,
        string $capability,
        float $weight,
        int $score,
        string $label,
        string $value
    ): array {
        $state = $trace->capture_report->capabilities[ $capability ] ?? [
            'status' => 'unavailable',
            'reason' => 'missing',
        ];
        $capture_status = Config::string_value( $state['status'] ?? 'unavailable', 'unavailable' );
        $status = $capture_status === 'captured' ? 'observed' : ( $capture_status === 'not_requested' ? 'not_requested' : 'unavailable' );

        return self::v2_factor(
            $weight,
            $score,
            $label,
            $status === 'observed' ? $value : 'Unknown',
            $capability,
            $status,
            Config::string_value( $state['reason'] ?? '', '' )
        );
    }

    /** @return array<string, mixed> */
    private static function v2_factor(
        float $weight,
        int $score,
        string $label,
        string $value,
        string $required_capability,
        string $status,
        string $reason
    ): array {
        return [
            'weight'              => $weight,
            'score'               => $score,
            'label'               => $label,
            'value'               => $value,
            'required_capability' => $required_capability,
            'status'              => $status,
            'reason'              => $reason,
        ];
    }

    /** @param mixed $status */
    private static function factor_status( $status ): string
    {
        $status = self::display_string( $status, 'unavailable' );

        return in_array( $status, [ 'observed', 'not_requested', 'unavailable' ], true ) ? $status : 'unavailable';
    }

    /**
     * Return grade letter and color for a 0-100 score.
     *
     * @return array{grade: string, color: string}
     */
    public static function grade(int $score): array
    {
        $score = max( 0, min( 100, $score ) );

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
     * Simplified score from just observed duration and query count.
     * Used as a fallback for traces without full span data.
     */
    public static function calculate_from_basic(float $total_ms, int $query_count): int
    {
        unset( $query_count );
        $rt_score = self::interpolate($total_ms, 100.0, 3000.0);

        return $rt_score;
    }

    /**
     * Linear interpolation: maps a value between best (→100) and worst (→0), clamped.
     */
    private static function interpolate(float $value, float $best, float $worst): int
    {
        if ( ! is_finite( $value ) ) {
            return 0;
        }

        if ($value <= $best) {
            return 100;
        }
        if ($value >= $worst) {
            return 0;
        }
        return (int) round(100 * (1 - ($value - $best) / ($worst - $best)));
    }

    /**
     * Coerce only scalar numeric values. Filter callbacks should not be able to
     * trigger PHP warnings by returning arrays or arbitrary objects.
     *
     * @param mixed $value
     */
    private static function number( $value, float $fallback ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? $number : $fallback;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? $number : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     */
    private static function display_string( $value, string $fallback ): string
    {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? (string) $value : $fallback;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
            try {
                return (string) $value;
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }

    private static function clamp_weight( float $weight ): float
    {
        if ( ! is_finite( $weight ) ) {
            return 0.0;
        }

        return max( 0.0, min( 1.0, $weight ) );
    }

    private static function bounded_ratio( float $part, float $total ): float
    {
        if ( ! is_finite( $part ) || ! is_finite( $total ) || $total <= 0.0 ) {
            return 0.0;
        }

        return max( 0.0, min( 1.0, $part / $total ) );
    }

    private static function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
