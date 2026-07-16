<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Config;
use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Score;
use WPFlame\Span;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SlowDatabaseQuery implements InsightRule
{
    private const THRESHOLD_MS = 100.0;
    private const MAX_INSIGHTS = 20;

    public function id(): string
    {
        return 'slow_database_query';
    }

    /** @return Insight[] */
    public function analyze( Trace $trace ): array
    {
        if ( $trace->capture_report->score_version < Score::VERSION ) {
            return [];
        }
        $insights = [];
        foreach ( $trace->spans as $span ) {
            if ( count( $insights ) >= self::MAX_INSIGHTS ) {
                break;
            }
            if ( $span->type !== Span::TYPE_DB || $span->duration_ms <= self::THRESHOLD_MS ) {
                continue;
            }

            $query = Config::string_value( $span->meta['query'] ?? $span->name, $span->name );
            $query = strlen( $query ) > 160 ? substr( $query, 0, 160 ) . '…' : $query;
            $duration = (int) round( $span->duration_ms );
            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: %d: observed query duration in milliseconds */
                sprintf( __( 'A database query took %dms', 'wp-flame' ), $duration ),
                /* translators: %s: redacted query label */
                sprintf( __( 'Observed query: %s. Inspect its execution plan, indexes, result size, and calling component.', 'wp-flame' ), $query ),
                [ $span->id ],
                $span->source,
                [
                    'next_action' => __( 'Give the fingerprint and calling source to the owning developer before changing indexes on production.', 'wp-flame' ),
                ]
            );
        }

        return $insights;
    }
}
