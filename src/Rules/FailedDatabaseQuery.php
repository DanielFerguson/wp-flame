<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Config;
use WPFlame\Score;
use WPFlame\Span;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FailedDatabaseQuery implements InsightRule
{
    public function id(): string
    {
        return 'failed_database_query';
    }

    /** @return Insight[] */
    public function analyze( Trace $trace ): array
    {
        if ( $trace->capture_report->score_version < Score::VERSION ) {
            return [];
        }
        $insights = [];
        foreach ( $trace->spans as $span ) {
            if ( $span->type !== Span::TYPE_DB || ! Config::boolean( $span->meta['query_failed'] ?? false ) ) {
                continue;
            }
            $insights[] = new Insight(
                $this->id(),
                'warning',
                __( 'A database query failed', 'wp-flame' ),
                __( 'WordPress returned a failed database result for this observed query. Review the redacted fingerprint and server database log.', 'wp-flame' ),
                [ $span->id ],
                $span->source,
                [ 'next_action' => __( 'Reproduce safely with database error logging and hand the evidence to the owning developer or host.', 'wp-flame' ) ]
            );
        }

        return array_slice( $insights, 0, 20 );
    }
}
