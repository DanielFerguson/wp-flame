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

class FailedHttpRequest implements InsightRule
{
    public function id(): string
    {
        return 'failed_http_request';
    }

    /** @return Insight[] */
    public function analyze( Trace $trace ): array
    {
        if ( $trace->capture_report->score_version < Score::VERSION ) {
            return [];
        }
        $insights = [];
        foreach ( $trace->spans as $span ) {
            if ( $span->type !== Span::TYPE_HTTP ) {
                continue;
            }
            $raw_status = $span->meta['status'] ?? null;
            $status = is_int( $raw_status ) || is_float( $raw_status ) || ( is_string( $raw_status ) && is_numeric( trim( $raw_status ) ) )
                ? Config::bounded_int( $raw_status, 0, 0, 599 )
                : null;
            $error_code = Config::string_value( $span->meta['http_error_code'] ?? '', '' );
            if ( $error_code === '' && ( $status === null || $status < 500 ) ) {
                continue;
            }
            $host = Config::string_value( $span->meta['host'] ?? 'unknown', 'unknown' );
            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: %s: external host */
                sprintf( __( 'External HTTP request to %s failed', 'wp-flame' ), $host ),
                /* translators: 1: status code, 2: bounded error code */
                sprintf( __( 'Observed status: %1$d; error code: %2$s. Check the dependency, timeout policy, and failure handling.', 'wp-flame' ), $status ?? 0, $error_code !== '' ? $error_code : 'server_error' ),
                [ $span->id ],
                $span->source,
                [ 'next_action' => __( 'Confirm the dependency health before increasing timeouts; add a safe fallback where appropriate.', 'wp-flame' ) ]
            );
        }

        return array_slice( $insights, 0, 20 );
    }
}
