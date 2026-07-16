<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class IncompleteCapture implements InsightRule
{
    public function id(): string
    {
        return 'incomplete_capture';
    }

    /** @return Insight[] */
    public function analyze( Trace $trace ): array
    {
        $reasons = $trace->capture_report->incomplete_reasons;
        if ( $trace->capture_report->dropped_span_count > 0 ) {
            $reasons[] = 'dropped_spans';
        }
        if ( $trace->capture_report->auto_closed_span_count > 0 ) {
            $reasons[] = 'auto_closed_spans';
        }
        if ( $trace->capture_report->trimmed_span_count > 0 || $trace->capture_report->trace_truncated ) {
            $reasons[] = 'trace_truncated';
        }
        $reasons = array_values( array_unique( array_slice( $reasons, 0, 20 ) ) );
        if ( $reasons === [] ) {
            return [];
        }

        return [
            new Insight(
                $this->id(),
                'info',
                __( 'This capture is incomplete', 'wp-flame' ),
                /* translators: %s: comma-separated bounded capture limitation codes */
                sprintf( __( 'Limitations: %s. Absence of evidence in unavailable areas is not evidence that those areas are fast.', 'wp-flame' ), implode( ', ', $reasons ) ),
                [],
                null,
                [ 'next_action' => __( 'Resolve the visible capability limitation or repeat with the appropriate time-boxed mode.', 'wp-flame' ) ]
            ),
        ];
    }
}
