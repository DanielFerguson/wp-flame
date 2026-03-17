<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage
{
    const SCHEMA_VERSION = 1;

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
            method varchar(10) NOT NULL DEFAULT '',
            total_ms float NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            peak_memory bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            user_id int NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL DEFAULT '',
            score tinyint unsigned DEFAULT NULL,
            trace_data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trace_id (trace_id),
            KEY created_at (created_at),
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

        $this->create_table();

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

        $data    = [
            'trace_id'    => $trace->id,
            'url'         => $trace->url,
            'method'      => $trace->method,
            'total_ms'    => $trace->total_ms,
            'query_count' => $trace->query_count,
            'peak_memory' => $trace->peak_memory,
            'created_at'  => current_time( 'mysql', true ),
            'user_id'     => $user_id,
            'ip_address'  => $ip_address,
            'trace_data'  => $json,
        ];
        $formats = [ '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%s', '%s' ];

        if ( $score !== null ) {
            $data['score'] = $score;
            $formats[]     = '%d';
        }

        $result = $this->wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            error_log( 'WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error );
        }
    }

    public function get_trace(string $trace_id): ?Trace
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trace_data FROM {$this->table} WHERE trace_id = %s",
                $trace_id
            )
        );

        if (! $row) {
            return null;
        }

        $data = json_decode($row->trace_data, true);
        if (! is_array($data)) {
            return null;
        }

        return Trace::fromArray($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_traces(array $filters): array
    {
        $where  = '1=1';
        $params = [];

        if (! empty($filters['url'])) {
            $where  .= ' AND url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['url']) . '%';
        }

        if (isset($filters['min_duration'])) {
            $where  .= ' AND total_ms >= %f';
            $params[] = (float) $filters['min_duration'];
        }

        if (isset($filters['max_duration'])) {
            $where  .= ' AND total_ms < %f';
            $params[] = (float) $filters['max_duration'];
        }

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
        }

        if (! empty($filters['method'])) {
            $where  .= ' AND method = %s';
            $params[] = $filters['method'];
        }

        if (isset($filters['user_id'])) {
            $where  .= ' AND user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (! empty($filters['ip_address'])) {
            $where  .= ' AND ip_address = %s';
            $params[] = $filters['ip_address'];
        }

        if (isset($filters['min_score'])) {
            $where  .= ' AND score >= %d';
            $params[] = (int) $filters['min_score'];
        }

        if (isset($filters['max_score'])) {
            $where  .= ' AND score <= %d';
            $params[] = (int) $filters['max_score'];
        }

        $per_page = (int) ($filters['per_page'] ?? 20);
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $allowed_orderby = ['created_at', 'total_ms', 'query_count', 'peak_memory', 'score'];
        $orderby = in_array($filters['orderby'] ?? '', $allowed_orderby, true) ? $filters['orderby'] : 'created_at';
        $order   = strtoupper($filters['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT trace_id, url, method, total_ms, query_count, peak_memory, created_at, score, user_id, ip_address
                FROM {$this->table}
                WHERE {$where}
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
        $where  = '1=1';
        $params = [];

        if (! empty($filters['url'])) {
            $where  .= ' AND url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['url']) . '%';
        }

        if (isset($filters['min_duration'])) {
            $where  .= ' AND total_ms >= %f';
            $params[] = (float) $filters['min_duration'];
        }

        if (isset($filters['max_duration'])) {
            $where  .= ' AND total_ms < %f';
            $params[] = (float) $filters['max_duration'];
        }

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
        }

        if (! empty($filters['method'])) {
            $where  .= ' AND method = %s';
            $params[] = $filters['method'];
        }

        if (isset($filters['user_id'])) {
            $where  .= ' AND user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (! empty($filters['ip_address'])) {
            $where  .= ' AND ip_address = %s';
            $params[] = $filters['ip_address'];
        }

        if (isset($filters['min_score'])) {
            $where  .= ' AND score >= %d';
            $params[] = (int) $filters['min_score'];
        }

        if (isset($filters['max_score'])) {
            $where  .= ' AND score <= %d';
            $params[] = (int) $filters['max_score'];
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";

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
            "SELECT SUBSTRING_INDEX(url, '?', 1) as page_url, AVG(total_ms) as avg_ms, COUNT(*) as hits
             FROM `{$this->table}` WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY page_url ORDER BY avg_ms DESC LIMIT %d",
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
    public function get_response_time_distribution(int $days = 7): array
    {
        $buckets = [
            ['label' => '0-50ms', 'min' => 0, 'max' => 50],
            ['label' => '50-100ms', 'min' => 50, 'max' => 100],
            ['label' => '100-200ms', 'min' => 100, 'max' => 200],
            ['label' => '200-500ms', 'min' => 200, 'max' => 500],
            ['label' => '500-1000ms', 'min' => 500, 'max' => 1000],
            ['label' => '1000-1500ms', 'min' => 1000, 'max' => 1500],
            ['label' => '1500ms+', 'min' => 1500, 'max' => 999999],
        ];

        $result = [];
        foreach ($buckets as $bucket) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->table}` WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND total_ms >= %f AND total_ms < %f",
                $days, $bucket['min'], $bucket['max']
            ));
            $result[] = ['label' => $bucket['label'], 'count' => $count, 'min' => $bucket['min'], 'max' => $bucket['max']];
        }

        return $result;
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
             WHERE SUBSTRING_INDEX(url, '?', 1) = %s
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
