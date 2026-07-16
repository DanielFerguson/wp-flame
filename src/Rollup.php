<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure, versioned projection from one trace into bounded aggregate rows.
 */
class Rollup
{
    public const VERSION = 1;
    public const MAX_DIMENSIONS_PER_TRACE = 100;
    public const HISTOGRAM_BOUNDS_MS = [ 50, 100, 200, 500, 1000, 1500, 3000, 5000, 10000, 30000, 60000 ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows_for_trace( Trace $trace, ?int $score, string $bucket_start ): array
    {
        $report = $trace->capture_report;
        $capability_json = $report->capability_data();
        $base = [
            'bucket_start'        => substr( $bucket_start, 0, 10 ),
            'rollup_version'      => self::VERSION,
            'score_version'       => $report->score_version,
            'request_type'        => substr( $report->request_type, 0, 40 ),
            'route_key'           => substr( $report->route_key, 0, 512 ),
            'instrumentation_mode' => substr( $report->instrumentation_mode, 0, 20 ),
            'capability_cohort'    => $report->capability_cohort(),
            'capability_data'      => $capability_json,
            'sample_count'        => 1,
            'total_duration_ms'   => $trace->total_ms,
            'total_query_count'   => $trace->query_count,
            'total_score'         => $score !== null ? max( 0, min( 100, $score ) ) : 0,
            'scored_count'        => $score !== null ? 1 : 0,
            'complete_count'      => $report->incomplete_reasons === []
                && ! $report->trace_truncated
                && $report->dropped_span_count === 0
                && $report->auto_closed_span_count === 0
                && $report->trimmed_span_count === 0
                    ? 1
                    : 0,
        ];

        $rows = [ self::dimension_row( $base, '', '', '', count( $trace->spans ), $trace->total_ms, $trace->total_ms ) ];
        $groups = [];
        $self_ms = [];
        $first_index_by_id = [];
        foreach ( $trace->spans as $index => $span ) {
            $self_ms[ $index ] = $span->duration_ms;
            if ( $span->id !== '' && ! isset( $first_index_by_id[ $span->id ] ) ) {
                $first_index_by_id[ $span->id ] = $index;
            }
        }
        foreach ( $trace->spans as $span ) {
            if ( $span->parent_id !== null && isset( $first_index_by_id[ $span->parent_id ] ) ) {
                $parent_index = $first_index_by_id[ $span->parent_id ];
                $self_ms[ $parent_index ] -= $span->duration_ms;
            }
        }

        foreach ( $trace->spans as $index => $span ) {
            $callback = '';
            if ( isset( $span->meta['hook'] ) && Config::string_value( $span->meta['hook'], '' ) !== '' ) {
                $callback = substr(
                    Config::string_value( $span->meta['hook'], '' ) . '@'
                        . Config::string_value( $span->meta['priority'] ?? '', '' ) . ':' . $span->name,
                    0,
                    512
                );
            }
            $key = $span->source . "\0" . $span->type . "\0" . $callback;
            if ( ! isset( $groups[ $key ] ) ) {
                if ( count( $groups ) >= self::MAX_DIMENSIONS_PER_TRACE ) {
                    continue;
                }
                $groups[ $key ] = [
                    'source'       => substr( $span->source, 0, 200 ),
                    'span_type'    => substr( $span->type, 0, 40 ),
                    'callback_key' => $callback,
                    'span_count'   => 0,
                    'duration_ms'  => 0.0,
                    'self_ms'      => 0.0,
                ];
            }
            $groups[ $key ]['span_count']++;
            $groups[ $key ]['duration_ms'] += $span->duration_ms;
            $groups[ $key ]['self_ms'] += max( 0.0, $self_ms[ $index ] ?? $span->duration_ms );
        }

        foreach ( $groups as $group ) {
            $rows[] = self::dimension_row(
                $base,
                $group['source'],
                $group['span_type'],
                $group['callback_key'],
                $group['span_count'],
                $group['duration_ms'],
                $group['self_ms']
            );
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function dimension_row(
        array $base,
        string $source,
        string $span_type,
        string $callback_key,
        int $span_count,
        float $span_duration_ms,
        float $self_duration_ms
    ): array {
        $dimension = implode( '|', [
            $base['bucket_start'],
            (string) $base['rollup_version'],
            (string) $base['score_version'],
            $base['request_type'],
            $base['route_key'],
            $base['instrumentation_mode'],
            $base['capability_cohort'],
            $source,
            $span_type,
            $callback_key,
        ] );

        $histogram = array_fill( 0, count( self::HISTOGRAM_BOUNDS_MS ) + 1, 0 );
        if ( $source === '' && $span_type === '' && $callback_key === '' ) {
            $bucket = count( self::HISTOGRAM_BOUNDS_MS );
            foreach ( self::HISTOGRAM_BOUNDS_MS as $index => $bound ) {
                if ( $base['total_duration_ms'] <= $bound ) {
                    $bucket = $index;
                    break;
                }
            }
            $histogram[ $bucket ] = 1;
        }

        $row = array_merge( $base, [
            'dimension_hash'   => hash( 'sha256', $dimension ),
            'source'           => $source,
            'span_type'        => $span_type,
            'callback_key'     => $callback_key,
            'span_count'       => max( 0, $span_count ),
            'span_duration_ms' => max( 0.0, $span_duration_ms ),
            'self_duration_ms' => max( 0.0, $self_duration_ms ),
        ] );
        foreach ( $histogram as $index => $count ) {
            $row[ 'duration_b' . $index ] = $count;
        }

        return $row;
    }
}
