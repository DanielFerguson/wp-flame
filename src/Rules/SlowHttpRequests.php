<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Config;
use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Redactor;
use WPFlame\Span;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SlowHttpRequests implements InsightRule
{
    private const MAX_URL_BYTES = 8192;
    private const MAX_HOST_BYTES = 255;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_STATUS_BYTES = 20;

    public function id(): string
    {
        return 'slow_http_requests';
    }

    /**
     * Rule 1: Any TYPE_HTTP span > 100ms triggers a warning.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $insights = [];

        foreach ($trace->spans as $span) {
            if ($span->type !== Span::TYPE_HTTP) {
                continue;
            }
            if ($span->duration_ms <= 100) {
                continue;
            }

            $url    = $this->limit_string( Config::string_value( $span->meta['url'] ?? '', '' ), self::MAX_URL_BYTES );
            $method = $this->limit_string( Config::string_value( $span->meta['method'] ?? 'GET', 'GET' ), self::MAX_METHOD_BYTES );
            $status = $this->limit_string( Config::string_value( $span->meta['status'] ?? '', '' ), self::MAX_STATUS_BYTES );
            $host   = $this->limit_string( Config::string_value( $span->meta['host'] ?? '', '' ), self::MAX_HOST_BYTES );

            if ($host === '' && $url !== '') {
                $parsed = parse_url($url);
                $host   = is_array($parsed) ? $this->limit_string( Config::string_value( $parsed['host'] ?? '', '' ), self::MAX_HOST_BYTES ) : '';
            }
            if ($host === '') {
                $host = 'unknown';
            }

            $duration = round($span->duration_ms);
            $target   = $this->safe_target($url, $host);

            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: 1: hostname, 2: duration in milliseconds */
                sprintf(__('External HTTP call to %1$s took %2$dms', 'wp-flame'), $host, $duration),
                /* translators: 1: request target, 2: HTTP method, 3: HTTP status code */
                sprintf(__('Target: %1$s, Method: %2$s, Status: %3$s. Consider caching the response or deferring to a background task.', 'wp-flame'), $target, $method, $status),
                [$span->id]
            );
        }

        return $insights;
    }

    private function safe_target( string $url, string $host ): string
    {
        if ($url === '') {
            return $host;
        }

        $path = Redactor::redact_request_uri($url);
        if ($host === 'unknown') {
            return $path;
        }

        return $host . $path;
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
