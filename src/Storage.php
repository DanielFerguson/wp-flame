<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage
{
    const SCHEMA_VERSION = 3;

    private \wpdb $wpdb;
    private string $table;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'flame_traces';
    }

    public function create_table(): void
    {
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trace_id char(36) NOT NULL,
            url varchar(2048) NOT NULL DEFAULT '',
            url_path varchar(2048) NOT NULL DEFAULT '',
            method varchar(10) NOT NULL DEFAULT '',
            total_ms float NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            peak_memory bigint unsigned NOT NULL DEFAULT 0,
            db_time_ms float NOT NULL DEFAULT 0,
            http_time_ms float NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            user_id int NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL DEFAULT '',
            score tinyint unsigned DEFAULT NULL,
            trace_data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trace_id (trace_id),
            KEY url_path (url_path(191)),
            KEY created_at (created_at),
            KEY created_total (created_at, total_ms),
            KEY created_queries (created_at, query_count),
            KEY user_id (user_id),
            KEY ip_address (ip_address)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Run schema migrations if needed.
     */
    public function maybe_upgrade(): void
    {
        $current = (int) get_option( 'wp_flame_schema_version', 0 );
        if ( $current >= self::SCHEMA_VERSION ) {
            return;
        }

        // Always run dbDelta so new columns are added to existing tables.
        $this->create_table();

        if ( $current < 2 ) {
            $this->wpdb->query( "UPDATE {$this->table} SET url_path = SUBSTRING_INDEX(url, '?', 1) WHERE url_path = ''" );
        }

        // Version 3: db_time_ms and http_time_ms columns added by dbDelta above.
        // Old rows default to 0 — no backfill needed.

        update_option( 'wp_flame_schema_version', self::SCHEMA_VERSION );
    }

    public function save_trace(Trace $trace, ?int $score = null, int $user_id = 0, string $ip_address = ''): void
    {
        $json = wp_json_encode( $trace->toArray() );
        if ( $json === false ) {
            $json = wp_json_encode( $trace->toArray(), JSON_INVALID_UTF8_SUBSTITUTE );
            if ( $json === false ) {
                error_log( 'WP Flame: Failed to encode trace ' . $trace->id );
                return;
            }
        }

        $url_path = explode( '?', $trace->url, 2 )[0];

        $db_time_ms   = 0.0;
        $http_time_ms = 0.0;
        foreach ( $trace->spans as $span ) {
            if ( $span->type === \WPFlame\Span::TYPE_DB ) {
                $db_time_ms += $span->duration_ms;
            } elseif ( $span->type === \WPFlame\Span::TYPE_HTTP ) {
                $http_time_ms += $span->duration_ms;
            }
        }

        $data    = [
            'trace_id'     => $trace->id,
            'url'          => $trace->url,
            'url_path'     => $url_path,
            'method'       => $trace->method,
            'total_ms'     => $trace->total_ms,
            'query_count'  => $trace->query_count,
            'peak_memory'  => $trace->peak_memory,
            'db_time_ms'   => $db_time_ms,
            'http_time_ms' => $http_time_ms,
            'created_at'   => current_time( 'mysql', true ),
            'user_id'      => $user_id,
            'ip_address'   => $ip_address,
            'trace_data'   => $json,
        ];
        $formats = [ '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%f', '%f', '%s', '%d', '%s', '%s' ];

        if ( $score !== null ) {
            $data['score'] = $score;
            $formats[]     = '%d';
        }

        $result = $this->wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            error_log( 'WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error );
        }
    }

    public function get_trace( string $trace_id ): ?Trace
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trace_data, user_id, ip_address, score, created_at FROM {$this->table} WHERE trace_id = %s",
                $trace_id
            )
        );

        if ( ! $row || empty( $row->trace_data ) ) {
            return null;
        }

        $data = json_decode( $row->trace_data, true );
        if ( ! is_array( $data ) ) {
            error_log( 'WP Flame: Failed to decode trace ' . $trace_id . ': ' . json_last_error_msg() );
            return null;
        }

        $trace = Trace::fromArray( $data );

        // Attach row-level columns that are NOT stored in trace_data JSON.
        $trace->meta['_row_user_id']    = (int) $row->user_id;
        $trace->meta['_row_ip_address'] = (string) $row->ip_address;
        $trace->meta['_row_score']      = $row->score !== null ? (int) $row->score : null;
        $trace->meta['_row_created_at'] = (string) $row->created_at;

        return $trace;
    }

    /**
     * Build WHERE clause and parameters from filters.
     *
     * @param array $filters
     * @return array{0: string, 1: array} [where_clause_without_WHERE_keyword, params]
     */
    private function build_where_clause( array $filters ): array
    {
        $where  = [];
        $params = [];

        if (! empty($filters['url'])) {
            $where[]  = 'url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['url']) . '%';
        }

        if (isset($filters['min_duration'])) {
            $where[]  = 'total_ms >= %f';
            $params[] = (float) $filters['min_duration'];
        }

        if (isset($filters['max_duration'])) {
            $where[]  = 'total_ms < %f';
            $params[] = (float) $filters['max_duration'];
        }

        if (! empty($filters['after'])) {
            $where[]  = 'created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where[]  = 'created_at <= %s';
            $params[] = $filters['before'];
        }

        if (! empty($filters['method'])) {
            $where[]  = 'method = %s';
            $params[] = $filters['method'];
        }

        if (isset($filters['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (! empty($filters['ip_address'])) {
            $where[]  = 'ip_address = %s';
            $params[] = $filters['ip_address'];
        }

        if (isset($filters['min_score'])) {
            $where[]  = 'score >= %d';
            $params[] = (int) $filters['min_score'];
        }

        if (isset($filters['max_score'])) {
            $where[]  = 'score <= %d';
            $params[] = (int) $filters['max_score'];
        }

        $where_sql = '1=1' . ( $where ? ' AND ' . implode( ' AND ', $where ) : '' );
        return [ $where_sql, $params ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_traces(array $filters): array
    {
        list( $where_sql, $params ) = $this->build_where_clause( $filters );

        $per_page = (int) ($filters['per_page'] ?? 20);
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $allowed_orderby = ['created_at', 'total_ms', 'query_count', 'peak_memory', 'score'];
        $orderby = in_array($filters['orderby'] ?? '', $allowed_orderby, true) ? $filters['orderby'] : 'created_at';
        $order   = strtoupper($filters['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT trace_id, url, method, total_ms, query_count, peak_memory, created_at, score, user_id, ip_address
                FROM {$this->table}
                WHERE {$where_sql}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $sql = $this->wpdb->prepare($sql, $params);

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return is_array($results) ? $results : [];
    }

    public function count_traces(array $filters): int
    {
        list( $where_sql, $params ) = $this->build_where_clause( $filters );

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

        if (! empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    public function delete_trace(string $trace_id): void
    {
        $this->wpdb->delete(
            $this->table,
            ['trace_id' => $trace_id],
            ['%s']
        );
    }

    /**
     * Delete all traces for a specific user.
     *
     * @param int $user_id
     * @return int Number of rows deleted.
     */
    public function delete_traces_by_user( int $user_id ): int
    {
        $result = $this->wpdb->delete( $this->table, [ 'user_id' => $user_id ], [ '%d' ] );
        return $result !== false ? $result : 0;
    }

    public function prune_old(int $days): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    public function purge_all(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        return (int) $this->wpdb->query( "DELETE FROM `{$this->table}`" );
    }

    /**
     * @return array{avg_ms: float, count: int, avg_queries: float, prev_avg_ms: float|null}
     */
    public function get_aggregate_stats(int $days = 7): array
    {
        $current = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT AVG(total_ms) as avg_ms, COUNT(*) as count, AVG(query_count) as avg_queries
             FROM `{$this->table}` WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        ));

        $prev = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT AVG(total_ms) as avg_ms
             FROM `{$this->table}` WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days * 2, $days
        ));

        return [
            'avg_ms'      => (float) ($current->avg_ms ?? 0),
            'count'       => (int) ($current->count ?? 0),
            'avg_queries' => (float) ($current->avg_queries ?? 0),
            'prev_avg_ms' => $prev->avg_ms !== null ? (float) $prev->avg_ms : null,
        ];
    }

    public function get_avg_score(int $days = 7): ?float
    {
        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT AVG(score) FROM `{$this->table}` WHERE score IS NOT NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        ));
        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_slowest_pages(int $limit = 5, int $days = 7): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT url_path as page_url, AVG(total_ms) as avg_ms, COUNT(*) as hits
             FROM `{$this->table}` WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY url_path ORDER BY avg_ms DESC LIMIT %d",
            $days, $limit
        ), ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * @return array<int, string>
     */
    public function get_recent_trace_data(int $limit = 50): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $results = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT trace_data FROM `{$this->table}` ORDER BY created_at DESC LIMIT %d",
            $limit
        ));

        return is_array($results) ? $results : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_response_time_distribution( int $days = 7 ): array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    SUM(total_ms < 50) AS b0,
                    SUM(total_ms >= 50 AND total_ms < 100) AS b1,
                    SUM(total_ms >= 100 AND total_ms < 200) AS b2,
                    SUM(total_ms >= 200 AND total_ms < 500) AS b3,
                    SUM(total_ms >= 500 AND total_ms < 1000) AS b4,
                    SUM(total_ms >= 1000 AND total_ms < 1500) AS b5,
                    SUM(total_ms >= 1500) AS b6
                FROM {$this->table}
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            )
        );

        if ( ! $row ) {
            $row = (object) [ 'b0' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0, 'b5' => 0, 'b6' => 0 ];
        }

        return [
            [ 'label' => '0-50ms',     'count' => (int) $row->b0, 'min' => 0,    'max' => 50 ],
            [ 'label' => '50-100ms',   'count' => (int) $row->b1, 'min' => 50,   'max' => 100 ],
            [ 'label' => '100-200ms',  'count' => (int) $row->b2, 'min' => 100,  'max' => 200 ],
            [ 'label' => '200-500ms',  'count' => (int) $row->b3, 'min' => 200,  'max' => 500 ],
            [ 'label' => '500-1000ms', 'count' => (int) $row->b4, 'min' => 500,  'max' => 1000 ],
            [ 'label' => '1000-1500ms','count' => (int) $row->b5, 'min' => 1000, 'max' => 1500 ],
            [ 'label' => '1500ms+',    'count' => (int) $row->b6, 'min' => 1500, 'max' => 999999 ],
        ];
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public function get_stats(): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $row = $this->wpdb->get_row(
            "SELECT COUNT(*) as count, COALESCE(SUM(LENGTH(trace_data)), 0) as bytes FROM `{$this->table}`"
        );
        return [
            'count' => (int) ($row->count ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_top_users(int $limit = 5, int $days = 7): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT user_id, COUNT(*) as request_count, AVG(total_ms) as avg_ms, SUM(total_ms) as total_ms
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY user_id
             ORDER BY total_ms DESC
             LIMIT %d",
            $days, $limit
        ), ARRAY_A);
        return is_array($results) ? $results : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_top_ips(int $limit = 5, int $days = 7): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ip_address, COUNT(*) as request_count, AVG(total_ms) as avg_ms, SUM(total_ms) as total_ms
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND ip_address != ''
             GROUP BY ip_address
             ORDER BY request_count DESC
             LIMIT %d",
            $days, $limit
        ), ARRAY_A);
        return is_array($results) ? $results : [];
    }

    /**
     * Get distinct user IDs from recent traces for filter dropdowns.
     *
     * @return array<int, array{user_id: int, request_count: int}>
     */
    public function get_distinct_users(int $days = 7): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT user_id, COUNT(*) as request_count
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY user_id
             ORDER BY request_count DESC
             LIMIT 50",
            $days
        ), ARRAY_A);
        return is_array($results) ? $results : [];
    }

    /**
     * Get distinct IP addresses from recent traces for filter dropdowns.
     *
     * @return array<int, array{ip_address: string, request_count: int}>
     */
    public function get_distinct_ips(int $days = 7): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ip_address, COUNT(*) as request_count
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND ip_address != ''
             GROUP BY ip_address
             ORDER BY request_count DESC
             LIMIT 50",
            $days
        ), ARRAY_A);
        return is_array($results) ? $results : [];
    }

    /**
     * @param int $days
     * @return array{avg_db_ms: float, avg_http_ms: float, avg_php_ms: float}|null
     */
    public function get_time_breakdown( int $days = 7 ): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    COALESCE(AVG(db_time_ms), 0) AS avg_db_ms,
                    COALESCE(AVG(http_time_ms), 0) AS avg_http_ms,
                    COALESCE(AVG(total_ms - COALESCE(db_time_ms, 0) - COALESCE(http_time_ms, 0)), 0) AS avg_php_ms
                FROM {$this->table}
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get aggregate stats for a specific route (URL stripped of query params).
     *
     * @return array{avg_ms: float, min_ms: float, max_ms: float, avg_queries: float, count: int}|null
     */
    public function get_route_stats(string $url, int $days = 7): ?array
    {
        $route = explode('?', $url, 2)[0];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT AVG(total_ms) as avg_ms, MIN(total_ms) as min_ms, MAX(total_ms) as max_ms,
                    AVG(query_count) as avg_queries, COUNT(*) as count
             FROM `{$this->table}`
             WHERE url_path = %s
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $route, $days
        ));

        if (!$row || (int) $row->count < 2) {
            return null; // Need at least 2 traces to compare against
        }

        return [
            'avg_ms'      => round((float) $row->avg_ms, 1),
            'min_ms'      => round((float) $row->min_ms, 1),
            'max_ms'      => round((float) $row->max_ms, 1),
            'avg_queries' => round((float) $row->avg_queries, 1),
            'count'       => (int) $row->count,
        ];
    }
}
