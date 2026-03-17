<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Span;
use WPFlame\Trace;

class SlowHttpRequests implements InsightRule
{
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

            $url    = $span->meta['url'] ?? '';
            $method = $span->meta['method'] ?? 'GET';
            $status = $span->meta['status'] ?? '';
            $host   = '';

            if ($url !== '') {
                $parsed = parse_url($url);
                $host   = $parsed['host'] ?? $url;
            }

            $duration = round($span->duration_ms);

            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: 1: hostname, 2: duration in milliseconds */
                sprintf(__('External HTTP call to %1$s took %2$dms', 'wp-flame'), $host, $duration),
                /* translators: 1: full URL, 2: HTTP method, 3: HTTP status code */
                sprintf(__('URL: %1$s, Method: %2$s, Status: %3$s. Consider caching the response or deferring to a background task.', 'wp-flame'), $url, $method, $status),
                [$span->id]
            );
        }

        return $insights;
    }
}
