<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        $insights = array_merge($insights, self::no_persistent_cache($trace));
        $insights = array_merge($insights, self::low_cache_hit_ratio($trace));

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
                /* translators: 1: hostname, 2: duration in milliseconds */
                'title'    => sprintf(__('External HTTP call to %1$s took %2$dms', 'wp-flame'), $host, $duration),
                /* translators: 1: full URL, 2: HTTP method, 3: HTTP status code */
                'detail'   => sprintf(__('URL: %1$s, Method: %2$s, Status: %3$s. Consider caching the response or deferring to a background task.', 'wp-flame'), $url, $method, $status),
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
                /* translators: 1: number of duplicate queries, 2: SQL query type (e.g. SELECT) */
                'title'    => sprintf(__('%1$d duplicate %2$s queries detected', 'wp-flame'), $count, $query_type),
                /* translators: 1: truncated SQL query, 2: number of times run, 3: total duration in milliseconds */
                'detail'   => sprintf(__("The query '%1\$s' ran %2\$d times totalling %3\$dms. Consider caching with wp_cache or a transient.", 'wp-flame'), $truncated, $count, $total_ms),
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
                /* translators: %d: number of database queries */
                'title'    => sprintf(__('%d database queries on this page', 'wp-flame'), $count),
                'detail'   => __('Consider enabling object caching or reducing queries.', 'wp-flame'),
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
                /* translators: 1: callback/span name, 2: duration in milliseconds, 3: hook name */
                'title'    => sprintf(__("%1\$s took %2\$dms on the '%3\$s' hook", 'wp-flame'), $span->name, $duration, $hook),
                /* translators: %s: source plugin or theme name */
                'detail'   => sprintf(__('Source: %s. This callback is a performance bottleneck.', 'wp-flame'), $source),
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
                /* translators: %s: WordPress lifecycle phase name (e.g. Init, Plugin Load) */
                'title'    => sprintf(__('HTTP request during %s blocks page load', 'wp-flame'), $phase),
                /* translators: 1: hostname, 2: WordPress lifecycle phase name */
                'detail'   => sprintf(__('%1$s called during %2$s — consider deferring to a later hook or using a transient.', 'wp-flame'), $host, $phase),
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
     * Rule 6: No persistent object cache detected.
     * Fires when the backend is the default WP_Object_Cache and there are >20 misses.
     */
    private static function no_persistent_cache(Trace $trace): array
    {
        $backend = $trace->meta['cache_backend'] ?? '';

        if ($backend !== 'WP_Object_Cache') {
            return [];
        }

        $misses = (int) ($trace->meta['cache_misses'] ?? 0);

        if ($misses <= 20) {
            return [];
        }

        return [
            [
                'severity' => 'info',
                /* translators: %d: number of cache misses */
                'title'    => __('No persistent object cache detected', 'wp-flame'),
                /* translators: %d: number of cache misses */
                'detail'   => sprintf(
                    __('This request had %d cache misses. A persistent cache (Redis or Memcached) would cache these across requests, reducing database load.', 'wp-flame'),
                    $misses
                ),
            ],
        ];
    }

    /**
     * Rule 7: Low cache hit ratio.
     * Fires when hit ratio < 80% and total operations > 10.
     */
    private static function low_cache_hit_ratio(Trace $trace): array
    {
        if (! isset($trace->meta['cache_hits'])) {
            return [];
        }

        $hits   = (int) $trace->meta['cache_hits'];
        $misses = (int) ($trace->meta['cache_misses'] ?? 0);
        $total  = $hits + $misses;

        if ($total <= 10) {
            return [];
        }

        $ratio = (int) round(($hits / $total) * 100);

        if ($ratio >= 80) {
            return [];
        }

        return [
            [
                'severity' => 'warning',
                /* translators: %d: cache hit ratio as a percentage */
                'title'    => sprintf(__('Low cache hit ratio (%d%%)', 'wp-flame'), $ratio),
                /* translators: 1: number of cache misses, 2: total cache operations */
                'detail'   => sprintf(
                    __('%1$d cache misses out of %2$d operations. Investigate which cache groups are missing frequently.', 'wp-flame'),
                    $misses,
                    $total
                ),
            ],
        ];
    }

    /**
     * Analyze aggregate dashboard data for abuse patterns.
     *
     * @param array<int, array<string, mixed>> $top_users  Results from Storage::get_top_users()
     * @param array<int, array<string, mixed>> $top_ips    Results from Storage::get_top_ips()
     * @param array<int, array<string, mixed>> $traces     Row arrays from Storage::list_traces()
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    public static function analyze_dashboard(array $top_users, array $top_ips, array $traces): array
    {
        $insights = [];

        $insights = array_merge($insights, self::high_request_rate($top_ips));
        $insights = array_merge($insights, self::high_resource_consumer($top_users));
        $insights = array_merge($insights, self::sequential_api_pagination($traces));

        return $insights;
    }

    /**
     * Dashboard Rule 1: High request rate from single IP (> 100 requests).
     *
     * @param array<int, array<string, mixed>> $top_ips
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    private static function high_request_rate(array $top_ips): array
    {
        $insights = [];

        foreach ($top_ips as $row) {
            $count = (int) $row['request_count'];
            if ($count <= 100) {
                continue;
            }

            $ip     = (string) $row['ip_address'];
            $avg_ms = (int) round((float) $row['avg_ms']);

            $insights[] = [
                'severity' => 'warning',
                /* translators: 1: IP address, 2: request count, 3: average duration ms */
                'title'    => sprintf(__('IP %1$s made %2$d requests (avg %3$dms)', 'wp-flame'), $ip, $count, $avg_ms),
                'detail'   => __('High request volume may indicate scraping or abuse. Consider blocking this IP.', 'wp-flame'),
            ];
        }

        return $insights;
    }

    /**
     * Dashboard Rule 2: High resource consumer — user with > 60,000ms total server time.
     *
     * @param array<int, array<string, mixed>> $top_users
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    private static function high_resource_consumer(array $top_users): array
    {
        $insights = [];

        foreach ($top_users as $row) {
            $total_ms = (float) $row['total_ms'];
            if ($total_ms <= 60000) {
                continue;
            }

            $user_id = (int) $row['user_id'];
            $count   = (int) $row['request_count'];
            $total_s = round($total_ms / 1000, 1);

            if ($user_id > 0 && function_exists('get_userdata')) {
                $user      = get_userdata($user_id);
                $user_name = $user ? $user->display_name : '#' . $user_id;
            } else {
                $user_name = __('Anonymous', 'wp-flame');
            }

            $insights[] = [
                'severity' => 'warning',
                /* translators: 1: user display name, 2: total server time in seconds, 3: request count */
                'title'    => sprintf(__("User '%1\$s' consumed %2\$ss of server time across %3\$d requests", 'wp-flame'), $user_name, $total_s, $count),
                'detail'   => __('This user is generating significant server load.', 'wp-flame'),
            ];
        }

        return $insights;
    }

    /**
     * Dashboard Rule 3: Sequential API pagination — same IP incrementing page= parameter.
     *
     * @param array<int, array<string, mixed>> $traces  Row arrays from Storage::list_traces()
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    private static function sequential_api_pagination(array $traces): array
    {
        // Group REST API traces by IP + base endpoint
        $groups = [];

        foreach ($traces as $row) {
            $ip  = (string) ($row['ip_address'] ?? '');
            $url = (string) ($row['url'] ?? '');

            if ($ip === '') {
                continue;
            }

            // Only REST API endpoints
            if (strpos($url, '/wp-json/') === false) {
                continue;
            }

            // Extract page parameter
            $query_pos = strpos($url, '?');
            if ($query_pos === false) {
                continue;
            }

            $query_string = substr($url, $query_pos + 1);
            parse_str($query_string, $params);

            if (! isset($params['page'])) {
                continue;
            }

            $page     = (int) $params['page'];
            $endpoint = substr($url, 0, $query_pos);
            $key      = $ip . '|||' . $endpoint;

            if (! isset($groups[$key])) {
                $groups[$key] = ['ip' => $ip, 'endpoint' => $endpoint, 'pages' => []];
            }

            $groups[$key]['pages'][] = $page;
        }

        $insights = [];

        foreach ($groups as $data) {
            $pages = array_unique($data['pages']);
            sort($pages);

            if (count($pages) < 3) {
                continue;
            }

            // Check for sequential run: pages differ by 1 for at least 3 consecutive values
            $sequential_count = 1;
            $max_sequential   = 1;

            for ($i = 1; $i < count($pages); $i++) {
                if ($pages[$i] === $pages[$i - 1] + 1) {
                    $sequential_count++;
                    $max_sequential = max($max_sequential, $sequential_count);
                } else {
                    $sequential_count = 1;
                }
            }

            if ($max_sequential < 3) {
                continue;
            }

            $ip       = $data['ip'];
            $endpoint = $data['endpoint'];
            $min_page = min($pages);
            $max_page = max($pages);

            $insights[] = [
                'severity' => 'warning',
                /* translators: %s: IP address */
                'title'    => sprintf(__('Possible API scraping detected from IP %s', 'wp-flame'), $ip),
                /* translators: 1: endpoint URL, 2: minimum page number, 3: maximum page number */
                'detail'   => sprintf(__('Sequential pagination through %1$s (pages %2$d-%3$d). This may be unauthorized data extraction.', 'wp-flame'), $endpoint, $min_page, $max_page),
            ];
        }

        return $insights;
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
