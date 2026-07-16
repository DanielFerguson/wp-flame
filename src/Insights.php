<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Insights
{
    private const MAX_INSIGHTS = 20;
    private const MAX_TITLE_BYTES = 300;
    private const MAX_DETAIL_BYTES = 2000;
    private const MAX_DASHBOARD_ROWS = 200;
    private const MAX_IP_BYTES = 45;
    private const MAX_ENDPOINT_BYTES = 2048;
    private const MAX_USER_LABEL_BYTES = 200;
    private const MAX_PAGINATION_GROUPS = 100;
    private const MAX_PAGINATION_PAGES_PER_GROUP = 50;
    private const MAX_PAGINATION_ENDPOINTS_PER_IP = 5;
    private const MAX_PAGINATION_QUERY_PARAMS = 50;
    private const MAX_SPAN_IDS = 100;
    private const MAX_SPAN_ID_BYTES = 128;
    private const MAX_SOURCE_BYTES = 200;
    private const MAX_VERSION_BYTES = 64;
    private const MAX_CONTRACT_BYTES = 1000;

    /**
     * Run all rules against the trace and return a flat array of insight items.
     *
     * Each insight: ['severity' => 'warning'|'info', 'title' => string, 'detail' => string]
     *
     * @return array<int, array<string, mixed>>
     */
    public static function analyze(Trace $trace, ?EnvironmentSnapshot $environment = null): array
    {
        $engine = new InsightEngine( [
            new Rules\IncompleteCapture(),
            new Rules\RouteBudgetViolation(),
            new Rules\FailedHttpRequest(),
            new Rules\SlowHttpRequests(),
            new Rules\FailedDatabaseQuery(),
            new Rules\SlowDatabaseQuery(),
            new Rules\DuplicateDbQueries(),
            new Rules\HighQueryCount(),
            new Rules\SlowCallbacks(),
            new Rules\HttpDuringEarlyPhases(),
            new Rules\NoPersistentCache(),
            new Rules\LowCacheHitRatio(),
        ] );

        $insights = $engine->analyze( $trace );
        if ( $environment !== null ) {
            foreach ( $insights as $insight ) {
                if ( $insight->source !== null && $insight->source_version === null ) {
                    $insight->source_version = self::source_version( $insight->source, $environment );
                }
            }
        }

        return self::normalize( array_map( function ( Insight $i ) {
            return $i->to_array();
        }, $insights ) );
    }

    public static function source_version( string $source, EnvironmentSnapshot $environment ): ?string
    {
        $data = $environment->data;
        // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- lowercase internal source identifier.
        if ( $source === 'wordpress' ) {
            $version = self::display_string( $data['wordpress_version'] ?? '', '' );
            return $version !== '' ? self::limit_string( $version, self::MAX_VERSION_BYTES ) : null;
        }
        if ( strpos( $source, 'child-theme:' ) === 0 || strpos( $source, 'parent-theme:' ) === 0 ) {
            $theme = isset( $data['theme'] ) && is_array( $data['theme'] ) ? $data['theme'] : [];
            $is_child = strpos( $source, 'child-theme:' ) === 0;
            $key = $is_child ? 'stylesheet_version' : 'template_version';
            $version = self::display_string( $theme[ $key ] ?? '', '' );
            return $version !== '' ? self::limit_string( $version, self::MAX_VERSION_BYTES ) : null;
        }
        if ( strpos( $source, ':' ) !== false ) {
            return null;
        }

        $plugins = isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : [];
        foreach ( $plugins as $plugin_file => $version ) {
            $plugin_file = self::display_string( $plugin_file, '' );
            if ( $plugin_file === $source || strpos( $plugin_file, $source . '/' ) === 0 ) {
                $version = self::display_string( $version, '' );
                return $version !== '' ? self::limit_string( $version, self::MAX_VERSION_BYTES ) : null;
            }
        }

        return null;
    }

    /**
     * Analyze aggregate dashboard data for abuse patterns.
     *
     * @param array<int, array<string, mixed>> $top_users  Results from Storage::get_top_users()
     * @param array<int, array<string, mixed>> $top_ips    Results from Storage::get_top_ips()
     * @param array<int, array<string, mixed>> $traces     Row arrays from Storage::list_traces()
     * @return array<int, array<string, mixed>>
     */
    public static function analyze_dashboard(array $top_users, array $top_ips, array $traces): array
    {
        $top_users = array_slice( $top_users, 0, self::MAX_DASHBOARD_ROWS );
        $top_ips   = array_slice( $top_ips, 0, self::MAX_DASHBOARD_ROWS );
        $traces    = array_slice( $traces, 0, self::MAX_DASHBOARD_ROWS );
        $insights = [];

        $insights = array_merge($insights, self::high_request_rate($top_ips));
        $insights = array_merge($insights, self::high_resource_consumer($top_users));
        $insights = array_merge($insights, self::sequential_api_pagination($traces));

        return self::normalize( $insights );
    }

    /**
     * Normalize insight arrays after filters so malformed extension output cannot
     * break admin rendering.
     *
     * @param mixed $insights
     * @return array<int, array<string, mixed>>
     */
    public static function normalize( $insights ): array
    {
        if ( ! is_array( $insights ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $insights as $insight ) {
            if ( $insight instanceof Insight ) {
                $insight = $insight->to_array();
            }

            if ( ! is_array( $insight ) ) {
                continue;
            }

            if ( count( $normalized ) >= self::MAX_INSIGHTS ) {
                break;
            }

            $title  = self::limit_string( self::display_string( $insight['title'] ?? '', '' ), self::MAX_TITLE_BYTES );
            $detail = self::limit_string( self::display_string( $insight['detail'] ?? '', '' ), self::MAX_DETAIL_BYTES );
            if ( $title === '' && $detail === '' ) {
                continue;
            }

            $severity = self::display_string( $insight['severity'] ?? 'info', 'info' );
            $span_ids = [];
            $raw_span_ids = isset( $insight['span_ids'] ) && is_array( $insight['span_ids'] ) ? $insight['span_ids'] : [];
            foreach ( array_slice( $raw_span_ids, 0, self::MAX_SPAN_IDS ) as $span_id ) {
                $span_id = self::limit_string( self::display_string( $span_id, '' ), self::MAX_SPAN_ID_BYTES );
                if ( $span_id !== '' ) {
                    $span_ids[] = $span_id;
                }
            }
            $confidence = self::display_string( $insight['confidence'] ?? 'low', 'low' );
            $normalized[] = [
                'severity'            => $severity === 'warning' ? 'warning' : 'info',
                'title'               => $title,
                'detail'              => $detail,
                'span_ids'            => $span_ids,
                'source'              => self::limit_string( self::display_string( $insight['source'] ?? '', '' ), self::MAX_SOURCE_BYTES ),
                'source_version'      => self::limit_string( self::display_string( $insight['source_version'] ?? '', '' ), self::MAX_VERSION_BYTES ),
                'measured_impact_ms'  => max( 0.0, self::number( $insight['measured_impact_ms'] ?? 0, 0.0 ) ),
                'evidence_count'      => max( 0, self::integer( $insight['evidence_count'] ?? 0, 0 ) ),
                'confidence'          => in_array( $confidence, [ 'low', 'medium', 'high' ], true ) ? $confidence : 'low',
                'required_capability' => self::limit_string( self::display_string( $insight['required_capability'] ?? '', '' ), 80 ),
                'action_type'         => self::limit_string( self::display_string( $insight['action_type'] ?? 'investigate', 'investigate' ), 80 ),
                'remediation'         => self::normalize_remediation( $insight['remediation'] ?? null ),
                'verification'        => self::limit_string( self::display_string( $insight['verification'] ?? '', '' ), self::MAX_CONTRACT_BYTES ),
            ];
        }

        return $normalized;
    }

    /** @param mixed $remediation @return array<string, string>|null */
    private static function normalize_remediation( $remediation ): ?array
    {
        if ( ! is_array( $remediation ) ) {
            return null;
        }

        $normalized = [];
        foreach ( array_slice( $remediation, 0, 10, true ) as $key => $value ) {
            $key = self::limit_string( self::display_string( $key, '' ), 80 );
            $value = self::limit_string( self::display_string( $value, '' ), self::MAX_CONTRACT_BYTES );
            if ( $key !== '' && $value !== '' ) {
                $normalized[ $key ] = $value;
            }
        }

        return $normalized !== [] ? $normalized : null;
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
            if ( ! is_array( $row ) ) {
                continue;
            }

            $count = self::integer( $row['request_count'] ?? 0, 0 );
            if ($count <= 100) {
                continue;
            }

            $ip     = self::limit_string( self::display_string( $row['ip_address'] ?? '', '' ), self::MAX_IP_BYTES );
            $avg_ms = (int) round(self::number( $row['avg_ms'] ?? 0, 0.0 ));

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
            if ( ! is_array( $row ) ) {
                continue;
            }

            $total_ms = self::number( $row['total_ms'] ?? 0, 0.0 );
            if ($total_ms <= 60000) {
                continue;
            }

            $user_id = self::integer( $row['user_id'] ?? 0, 0 );
            $count   = self::integer( $row['request_count'] ?? 0, 0 );
            $total_s = round($total_ms / 1000, 1);

            if ($user_id > 0 && function_exists('get_userdata')) {
                $user      = get_userdata($user_id);
                $user_name = $user ? self::display_string( $user->display_name ?? '', '#' . $user_id ) : '#' . $user_id;
            } else {
                $user_name = __('Anonymous', 'wp-flame');
            }
            $user_name = self::limit_string( $user_name, self::MAX_USER_LABEL_BYTES );

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
            if ( ! is_array( $row ) ) {
                continue;
            }

            $ip  = self::limit_string( self::display_string( $row['ip_address'] ?? '', '' ), self::MAX_IP_BYTES );
            $url = self::limit_string( self::display_string( $row['url'] ?? '', '' ), self::MAX_ENDPOINT_BYTES );

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
            $query_string = self::limit_string( $query_string, 4096 );
            $query_parts  = explode( '&', $query_string );
            if ( count( $query_parts ) > self::MAX_PAGINATION_QUERY_PARAMS ) {
                $query_parts = array_slice( $query_parts, 0, self::MAX_PAGINATION_QUERY_PARAMS );
            }
            $query_string = implode( '&', $query_parts );
            parse_str($query_string, $params);

            if (! isset($params['page']) || is_array($params['page']) || ! is_numeric($params['page'])) {
                continue;
            }

            $page     = max(1, (int) $params['page']);
            $endpoint = self::limit_string( substr($url, 0, $query_pos), self::MAX_ENDPOINT_BYTES );
            $key      = $ip . '|||' . $endpoint;

            if (! isset($groups[$key])) {
                if (count($groups) >= self::MAX_PAGINATION_GROUPS) {
                    continue;
                }

                $groups[$key] = ['ip' => $ip, 'endpoint' => $endpoint, 'pages' => []];
            }

            if (count($groups[$key]['pages']) >= self::MAX_PAGINATION_PAGES_PER_GROUP) {
                continue;
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
            if (isset($by_ip[$ip]) && count($by_ip[$ip]) >= self::MAX_PAGINATION_ENDPOINTS_PER_IP) {
                continue;
            }

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

    /**
     * @param mixed $value
     */
    private static function number( $value, float $fallback ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? $number : $fallback;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? $number : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     */
    private static function integer( $value, int $fallback ): int
    {
        return (int) round( self::number( $value, (float) $fallback ) );
    }

    private static function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }

    /**
     * @param mixed $value
     */
    private static function display_string( $value, string $fallback ): string
    {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
            try {
                return (string) $value;
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }
}
