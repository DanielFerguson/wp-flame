<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage
{
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
            trace_data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trace_id (trace_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function save_trace(Trace $trace): void
    {
        $this->wpdb->insert(
            $this->table,
            [
                'trace_id'    => $trace->id,
                'url'         => $trace->url,
                'method'      => $trace->method,
                'total_ms'    => $trace->total_ms,
                'query_count' => $trace->query_count,
                'peak_memory' => $trace->peak_memory,
                'created_at'  => current_time('mysql', true),
                'trace_data'  => wp_json_encode($trace->toArray()),
            ],
            ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s']
        );
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

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
        }

        $per_page = (int) ($filters['per_page'] ?? 20);
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $sql = "SELECT trace_id, url, method, total_ms, query_count, peak_memory, created_at
                FROM {$this->table}
                WHERE {$where}
                ORDER BY created_at DESC
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

        if (! empty($filters['after'])) {
            $where  .= ' AND created_at >= %s';
            $params[] = $filters['after'];
        }

        if (! empty($filters['before'])) {
            $where  .= ' AND created_at <= %s';
            $params[] = $filters['before'];
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
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
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
}
