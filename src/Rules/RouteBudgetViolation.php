<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Config;
use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Score;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RouteBudgetViolation implements InsightRule
{
    public function id(): string
    {
        return 'route_budget_violation';
    }

    /** @return Insight[] */
    public function analyze( Trace $trace ): array
    {
        if ( $trace->capture_report->score_version < Score::VERSION ) {
            return [];
        }
        $duration_budget = Config::bounded_int( Config::instance()->get( 'wp_flame_budget_max_ms', 500 ), 500, 0, PHP_INT_MAX );
        $query_budget = Config::bounded_int( Config::instance()->get( 'wp_flame_budget_max_queries', 100 ), 100, 0, PHP_INT_MAX );
        $database_captured = ( $trace->capture_report->capabilities['database']['status'] ?? '' ) === 'captured';
        $duration_exceeded = $duration_budget > 0 && $trace->total_ms > $duration_budget;
        $queries_exceeded = $database_captured && $query_budget > 0 && $trace->query_count > $query_budget;
        if ( ! $duration_exceeded && ! $queries_exceeded ) {
            return [];
        }

        $evidence = [];
        if ( $duration_exceeded ) {
            $evidence[] = round( $trace->total_ms ) . 'ms > ' . $duration_budget . 'ms';
        }
        if ( $queries_exceeded ) {
            $evidence[] = $trace->query_count . ' queries > ' . $query_budget;
        }

        return [
            new Insight(
                $this->id(),
                'warning',
                __( 'This route exceeded its configured performance budget', 'wp-flame' ),
                implode( '; ', $evidence ) . '. ' . __( 'Use the measured contributors below to choose the first investigation.', 'wp-flame' ),
                [],
                null,
                [ 'next_action' => __( 'Address the largest supported contributor, then repeat this exact route.', 'wp-flame' ) ],
                null,
                $trace->total_ms,
                1
            ),
        ];
    }
}
