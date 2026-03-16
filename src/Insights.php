<?php

declare(strict_types=1);

namespace WPFlame;

class Insights
{
    /**
     * Run all rules against the trace and return a flat array of insight items.
     *
     * Each insight: ['severity' => 'warning'|'info', 'title' => string, 'detail' => string]
     *
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    public static function analyze(Trace $trace): array
    {
        $insights = [];

        $insights = array_merge($insights, self::slow_http_requests($trace));
        $insights = array_merge($insights, self::duplicate_db_queries($trace));
        $insights = array_merge($insights, self::high_query_count($trace));
        $insights = array_merge($insights, self::slow_callbacks($trace));
        $insights = array_merge($insights, self::http_during_early_phases($trace));

        return $insights;
    }

    /**
     * Rule 1: Any TYPE_HTTP span > 100ms triggers a warning.
     */
    private static function slow_http_requests(Trace $trace): array
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

            $insights[] = [
                'severity' => 'warning',
                'title'    => "External HTTP call to {$host} took {$duration}ms",
                'detail'   => "URL: {$url}, Method: {$method}, Status: {$status}. Consider caching the response or deferring to a background task.",
            ];
        }

        return $insights;
    }

    /**
     * Rule 2: TYPE_DB spans with duplicate query text (meta['query']).
     * info if 2–3 duplicates, warning if 4+.
     */
    private static function duplicate_db_queries(Trace $trace): array
    {
        $groups = [];

        foreach ($trace->spans as $span) {
            if ($span->type !== Span::TYPE_DB) {
                continue;
            }

            $query = $span->meta['query'] ?? '';
            if ($query === '') {
                continue;
            }

            if (! isset($groups[$query])) {
                $groups[$query] = ['count' => 0, 'total_ms' => 0.0];
            }

            $groups[$query]['count']++;
            $groups[$query]['total_ms'] += $span->duration_ms;
        }

        $insights = [];

        foreach ($groups as $query => $data) {
            $count = $data['count'];
            if ($count < 2) {
                continue;
            }

            $severity     = $count >= 4 ? 'warning' : 'info';
            $total_ms     = round($data['total_ms']);
            $query_type   = self::extract_query_type($query);
            $truncated    = strlen($query) > 80 ? substr($query, 0, 80) . '...' : $query;

            $insights[] = [
                'severity' => $severity,
                'title'    => "{$count} duplicate {$query_type} queries detected",
                'detail'   => "The query '{$truncated}' ran {$count} times totalling {$total_ms}ms. Consider caching with wp_cache or a transient.",
            ];
        }

        return $insights;
    }

    /**
     * Rule 3: High total DB span count. info if > 50, warning if > 100.
     */
    private static function high_query_count(Trace $trace): array
    {
        $count = 0;

        foreach ($trace->spans as $span) {
            if ($span->type === Span::TYPE_DB) {
                $count++;
            }
        }

        if ($count <= 50) {
            return [];
        }

        $severity = $count > 100 ? 'warning' : 'info';

        return [
            [
                'severity' => $severity,
                'title'    => "{$count} database queries on this page",
                'detail'   => 'Consider enabling object caching or reducing queries.',
            ],
        ];
    }

    /**
     * Rule 4: Callback spans (has 'hook' in meta) with duration > 50ms → warning.
     */
    private static function slow_callbacks(Trace $trace): array
    {
        $insights = [];

        foreach ($trace->spans as $span) {
            if (! isset($span->meta['hook'])) {
                continue;
            }
            if ($span->duration_ms <= 50) {
                continue;
            }

            $duration = round($span->duration_ms);
            $hook     = $span->meta['hook'];
            $source   = $span->source;

            $insights[] = [
                'severity' => 'warning',
                'title'    => "{$span->name} took {$duration}ms on the '{$hook}' hook",
                'detail'   => "Source: {$source}. This callback is a performance bottleneck.",
            ];
        }

        return $insights;
    }

    /**
     * Rule 5: TYPE_HTTP spans whose parent chain includes a lifecycle span named
     * "Init", "Plugin Load", or "Theme Setup" → warning.
     */
    private static function http_during_early_phases(Trace $trace): array
    {
        $early_phases = ['Init', 'Plugin Load', 'Theme Setup'];

        // Build a map from span id → span for quick lookup
        $span_map = [];
        foreach ($trace->spans as $span) {
            $span_map[$span->id] = $span;
        }

        // Collect IDs of all early-phase lifecycle spans
        $early_span_ids = [];
        foreach ($trace->spans as $span) {
            if (in_array($span->name, $early_phases, true)) {
                $early_span_ids[$span->id] = $span->name;
            }
        }

        if (empty($early_span_ids)) {
            return [];
        }

        $insights = [];

        foreach ($trace->spans as $span) {
            if ($span->type !== Span::TYPE_HTTP) {
                continue;
            }

            $phase = self::find_early_phase_ancestor($span, $span_map, $early_span_ids);
            if ($phase === null) {
                continue;
            }

            $url  = $span->meta['url'] ?? '';
            $host = '';
            if ($url !== '') {
                $parsed = parse_url($url);
                $host   = $parsed['host'] ?? $url;
            }

            $insights[] = [
                'severity' => 'warning',
                'title'    => "HTTP request during {$phase} blocks page load",
                'detail'   => "{$host} called during {$phase} — consider deferring to a later hook or using a transient.",
            ];
        }

        return $insights;
    }

    /**
     * Walk the parent chain of $span; return the phase name if an early-phase
     * ancestor is found, or null otherwise.
     *
     * @param array<string, Span>   $span_map
     * @param array<string, string> $early_span_ids  id → phase name
     */
    private static function find_early_phase_ancestor(
        Span $span,
        array $span_map,
        array $early_span_ids
    ): ?string {
        $current_id = $span->parent_id;

        while ($current_id !== null) {
            if (isset($early_span_ids[$current_id])) {
                return $early_span_ids[$current_id];
            }

            if (! isset($span_map[$current_id])) {
                break;
            }

            $current_id = $span_map[$current_id]->parent_id;
        }

        return null;
    }

    /**
     * Extract the SQL verb (SELECT, INSERT, UPDATE, DELETE, …) from a query string.
     */
    private static function extract_query_type(string $query): string
    {
        $trimmed = ltrim($query);
        $parts   = preg_split('/\s+/', $trimmed, 2);
        return strtoupper($parts[0] ?? 'SQL');
    }
}
