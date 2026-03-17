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
        $engine = new InsightEngine( [
            new Rules\SlowHttpRequests(),
            new Rules\DuplicateDbQueries(),
            new Rules\HighQueryCount(),
            new Rules\SlowCallbacks(),
            new Rules\HttpDuringEarlyPhases(),
            new Rules\NoPersistentCache(),
            new Rules\LowCacheHitRatio(),
        ] );

        $insights = $engine->analyze( $trace );

        return array_map( function ( Insight $i ) {
            return $i->to_array();
        }, $insights );
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

        // Collect confirmed sequential endpoints, grouped by IP
        $by_ip = [];

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

            $ip = $data['ip'];
            $by_ip[$ip][] = sprintf('%s (pages %d-%d)', $data['endpoint'], min($pages), max($pages));
        }

        // One insight per IP, listing all scraped endpoints
        $insights = [];

        foreach ($by_ip as $ip => $endpoint_lines) {
            $insights[] = [
                'severity' => 'warning',
                /* translators: %s: IP address */
                'title'    => sprintf(__('Possible API scraping detected from IP %s', 'wp-flame'), $ip),
                'detail'   => implode("\n", $endpoint_lines) . "\n" . __('This may be unauthorized data extraction.', 'wp-flame'),
            ];
        }

        return $insights;
    }
}
