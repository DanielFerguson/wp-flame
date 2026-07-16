<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic, local-only comparison of two guided capture cohorts.
 */
class CohortComparison
{
    public const DIRECTIONAL_MIN_PER_COHORT = 10;
    public const VERIFIED_MIN_PER_COHORT = 20;
    public const P95_MIN_PER_COHORT = 20;

    /**
     * @param Trace[] $baseline
     * @param Trace[] $after
     * @param array<string, EnvironmentSnapshot> $environments
     * @return array<string, mixed>
     */
    public static function compare( array $baseline, array $after, array $environments = [] ): array
    {
        $baseline = self::traces_only( $baseline );
        $after = self::traces_only( $after );
        $reasons = [];
        $warnings = [];

        if ( $baseline === [] || $after === [] ) {
            $reasons[] = 'Both a baseline and after cohort need stored traces.';
        }
        $baseline_signature = self::cohort_signature( $baseline, 'baseline', $reasons );
        $after_signature = self::cohort_signature( $after, 'after', $reasons );
        foreach ( [ 'route_key', 'request_type', 'instrumentation_mode', 'capability_cohort', 'score_version' ] as $key ) {
            if (
                $baseline_signature !== []
                && $after_signature !== []
                && ( $baseline_signature[ $key ] ?? null ) !== ( $after_signature[ $key ] ?? null )
            ) {
                $reasons[] = 'The cohorts have different ' . str_replace( '_', ' ', $key ) . ' values.';
            }
        }

        $baseline_environment = Config::string_value( $baseline_signature['environment_snapshot_id'] ?? '', '' );
        $after_environment = Config::string_value( $after_signature['environment_snapshot_id'] ?? '', '' );
        if ( $baseline_environment === '' || $after_environment === '' ) {
            $reasons[] = 'Environment context is missing, so configuration compatibility cannot be proved.';
        } elseif ( ! hash_equals( $baseline_environment, $after_environment ) ) {
            self::compare_environments(
                $baseline_environment,
                $after_environment,
                $environments,
                $reasons,
                $warnings
            );
        }

        $reasons = array_values( array_unique( $reasons ) );
        $valid = $reasons === [];
        $baseline_metrics = self::metrics( $baseline );
        $after_metrics = self::metrics( $after );
        $minimum = min( count( $baseline ), count( $after ) );
        $status = 'insufficient';
        if ( $valid && $minimum >= self::VERIFIED_MIN_PER_COHORT ) {
            $status = 'verified';
        } elseif ( $valid && $minimum >= self::DIRECTIONAL_MIN_PER_COHORT ) {
            $status = 'directional';
        }

        $delta_ms = (float) $after_metrics['p50_ms'] - (float) $baseline_metrics['p50_ms'];
        $delta_percent = (float) $baseline_metrics['p50_ms'] > 0.0
            ? ( $delta_ms / (float) $baseline_metrics['p50_ms'] ) * 100
            : 0.0;

        return [
            'valid'              => $valid,
            'status'             => $valid ? $status : 'invalid',
            'reasons'            => $reasons,
            'warnings'           => array_values( array_unique( $warnings ) ),
            'baseline_count'     => count( $baseline ),
            'after_count'        => count( $after ),
            'minimum_required'   => self::DIRECTIONAL_MIN_PER_COHORT,
            'verified_required'  => self::VERIFIED_MIN_PER_COHORT,
            'p50_delta_ms'       => round( $delta_ms, 2 ),
            'p50_delta_percent'  => round( $delta_percent, 1 ),
            'baseline'           => $baseline_metrics,
            'after'              => $after_metrics,
            'signature'          => $valid ? $baseline_signature : [],
            'baseline_signature' => $baseline_signature,
            'after_signature'    => $after_signature,
            'uncertainty'        => self::uncertainty( $status, $valid, $minimum ),
        ];
    }

    /**
     * Allow an intentional plugin/theme change while rejecting runtime and
     * configuration drift that would make the measured cohorts incomparable.
     *
     * @param array<string, EnvironmentSnapshot> $environments
     * @param string[] $reasons
     * @param string[] $warnings
     */
    private static function compare_environments(
        string $baseline_id,
        string $after_id,
        array $environments,
        array &$reasons,
        array &$warnings
    ): void {
        $baseline = $environments[ $baseline_id ] ?? null;
        $after = $environments[ $after_id ] ?? null;
        if ( ! $baseline instanceof EnvironmentSnapshot || ! $after instanceof EnvironmentSnapshot ) {
            $reasons[] = 'Environment snapshots differ and their compatibility details are unavailable.';
            return;
        }

        $hard_keys = [
            'wordpress_version',
            'php_version',
            'multisite',
            'external_object_cache',
            'page_cache_constant',
            'permalink_structure_hash',
            'environment_snapshot_schema',
        ];
        foreach ( $hard_keys as $key ) {
            if ( ( $baseline->data[ $key ] ?? null ) !== ( $after->data[ $key ] ?? null ) ) {
                $reasons[] = str_replace( '_', ' ', ucfirst( $key ) ) . ' changed between cohorts.';
            }
        }
        if ( ( $baseline->data['plugins'] ?? [] ) !== ( $after->data['plugins'] ?? [] ) ) {
            $warnings[] = 'The active plugin set or a plugin version changed; treat that controlled change as the candidate cause, not proof by itself.';
        }
        if ( ( $baseline->data['theme'] ?? [] ) !== ( $after->data['theme'] ?? [] ) ) {
            $warnings[] = 'The active theme or theme version changed; treat that controlled change as the candidate cause, not proof by itself.';
        }
    }

    /** @param Trace[] $traces @param string[] $reasons @return array<string, mixed> */
    private static function cohort_signature( array $traces, string $expected_phase, array &$reasons ): array
    {
        if ( $traces === [] ) {
            return [];
        }
        $signatures = [];
        foreach ( $traces as $trace ) {
            $report = $trace->capture_report;
            if ( $report->capture_phase !== $expected_phase ) {
                $reasons[] = ucfirst( $expected_phase ) . ' cohort contains a trace labelled ' . $report->capture_phase . '.';
            }
            $signature = [
                'route_key'                => $report->route_key,
                'request_type'             => $report->request_type,
                'instrumentation_mode'     => $report->instrumentation_mode,
                'capability_cohort'        => $report->capability_cohort(),
                'score_version'            => $report->score_version,
                'environment_snapshot_id'  => $report->environment_snapshot_id ?? '',
                'capabilities'              => $report->capabilities,
            ];
            $encoded = wp_json_encode( $signature );
            $signatures[ is_string( $encoded ) ? $encoded : '' ] = $signature;
        }
        if ( count( $signatures ) !== 1 ) {
            $reasons[] = ucfirst( $expected_phase ) . ' traces do not form one compatible route and capability cohort.';
        }

        $first = reset( $signatures );
        return is_array( $first ) ? $first : [];
    }

    /** @param Trace[] $traces @return array<string, mixed> */
    private static function metrics( array $traces ): array
    {
        $durations = [];
        $query_counts = [];
        $db_times = [];
        $http_times = [];
        $scores = [];
        $complete = 0;
        $sources = [];
        $callbacks = [];
        $factors = [];

        foreach ( $traces as $trace ) {
            $durations[] = $trace->total_ms;
            $query_counts[] = $trace->query_count;
            $db_times[] = $trace->total_query_ms;
            $http_ms = 0.0;
            foreach ( $trace->spans as $span ) {
                if ( $span->type === Span::TYPE_HTTP ) {
                    $http_ms += $span->duration_ms;
                }
                $source = $span->source !== '' ? $span->source : 'unknown';
                $sources[ $source ] = ( $sources[ $source ] ?? 0.0 ) + $span->duration_ms;
                if ( isset( $span->meta['hook'] ) ) {
                    $callback = Config::string_value( $span->meta['hook'], '' ) . '@'
                        . Config::string_value( $span->meta['priority'] ?? '', '' ) . ':' . $span->name;
                    $callbacks[ $callback ] = ( $callbacks[ $callback ] ?? 0.0 ) + $span->duration_ms;
                }
            }
            $http_times[] = $http_ms;
            $score = Score::calculate( $trace );
            $scores[] = Config::bounded_int( $score['score'] ?? 0, 0, 0, 100 );
            foreach ( $score['factors'] as $factor ) {
                if ( ! is_array( $factor ) || ( $factor['status'] ?? 'observed' ) !== 'observed' ) {
                    continue;
                }
                $label = Config::string_value( $factor['label'] ?? '', '' );
                if ( $label !== '' ) {
                    $factors[ $label ][] = (float) ( $factor['score'] ?? 0.0 );
                }
            }
            if ( self::is_complete( $trace ) ) {
                $complete++;
            }
        }

        arsort( $sources );
        arsort( $callbacks );
        $factor_averages = [];
        foreach ( $factors as $label => $values ) {
            $factor_averages[ $label ] = round( self::average( $values ), 1 );
        }

        return [
            'p50_ms'             => self::percentile( $durations, 0.50 ),
            'p95_ms'             => count( $durations ) >= self::P95_MIN_PER_COHORT ? self::percentile( $durations, 0.95 ) : null,
            'distribution_ms'    => array_map( static fn ( $value ): float => round( (float) $value, 2 ), array_slice( self::sorted( $durations ), 0, 100 ) ),
            'average_queries'    => round( self::average( $query_counts ), 2 ),
            'average_db_ms'      => round( self::average( $db_times ), 2 ),
            'average_http_ms'    => round( self::average( $http_times ), 2 ),
            'average_score'      => round( self::average( $scores ), 1 ),
            'complete_count'     => $complete,
            'top_sources'        => self::top_map( $sources ),
            'top_callbacks'      => self::top_map( $callbacks ),
            'score_factors'      => $factor_averages,
        ];
    }

    private static function is_complete( Trace $trace ): bool
    {
        $report = $trace->capture_report;
        return $report->incomplete_reasons === []
            && ! $report->trace_truncated
            && $report->dropped_span_count === 0
            && $report->auto_closed_span_count === 0
            && $report->trimmed_span_count === 0;
    }

    /** @param array<string, float> $values @return array<string, float> */
    private static function top_map( array $values ): array
    {
        $result = [];
        foreach ( array_slice( $values, 0, 10, true ) as $key => $value ) {
            $result[ substr( (string) $key, 0, 300 ) ] = round( $value, 2 );
        }
        return $result;
    }

    /** @param array<int, mixed> $values */
    private static function average( array $values ): float
    {
        return $values === [] ? 0.0 : array_sum( array_map( 'floatval', $values ) ) / count( $values );
    }

    /** @param array<int, mixed> $values @return float[] */
    private static function sorted( array $values ): array
    {
        $values = array_values( array_map( 'floatval', $values ) );
        sort( $values, SORT_NUMERIC );
        return $values;
    }

    /** @param array<int, mixed> $values */
    private static function percentile( array $values, float $percentile ): float
    {
        $values = self::sorted( $values );
        if ( $values === [] ) {
            return 0.0;
        }
        $rank = ( count( $values ) - 1 ) * min( 1.0, max( 0.0, $percentile ) );
        $lower = (int) floor( $rank );
        $upper = (int) ceil( $rank );
        $value = $values[ $lower ];
        if ( $upper !== $lower ) {
            $value += ( $values[ $upper ] - $values[ $lower ] ) * ( $rank - $lower );
        }
        return round( $value, 2 );
    }

    private static function uncertainty( string $status, bool $valid, int $minimum ): string
    {
        if ( ! $valid ) {
            return 'No performance conclusion is valid until the cohort differences are resolved.';
        }
        if ( $status === 'verified' ) {
            return 'Verified means at least 20 compatible observations per cohort; it does not prove causality outside this workflow and environment.';
        }
        if ( $status === 'directional' ) {
            return 'Directional only: collect at least ' . ( self::VERIFIED_MIN_PER_COHORT - $minimum ) . ' more compatible observation(s) per cohort before using the verified label.';
        }
        return 'Too few observations: collect at least ' . ( self::DIRECTIONAL_MIN_PER_COHORT - $minimum ) . ' more compatible observation(s) per cohort before drawing a directional conclusion.';
    }

    /** @param array<int, mixed> $items @return Trace[] */
    private static function traces_only( array $items ): array
    {
        return array_values( array_filter( $items, static fn ( $item ): bool => $item instanceof Trace ) );
    }
}
