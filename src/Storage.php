<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage
{
    const SCHEMA_VERSION = 3;
    public const DASHBOARD_TRACE_DATA_LIMIT = 25;
    public const DASHBOARD_MAX_TRACE_BYTES = 262144;
    public const PRUNE_BATCH_LIMIT = 5000;
    private const MAX_TRACE_ID_BYTES = 36;
    private const MAX_STORED_MS = 86400000.0;
    private const MAX_STORED_BYTES = 1099511627776;
    private const MAX_STORED_COUNT = 1000000;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_TIMESTAMP_BYTES = 64;
    private const MAX_VERSION_BYTES = 64;
    private const MAX_SPAN_NAME_BYTES = 300;
    private const MAX_SPAN_TYPE_BYTES = 40;
    private const MAX_SPAN_SOURCE_BYTES = 200;
    private const MAX_META_ENTRIES = 50;
    private const MAX_META_ARRAY_ENTRIES = 20;
    private const MAX_META_ARRAY_DEPTH = 2;
    private const MAX_META_KEY_BYTES = 80;
    private const MAX_META_STRING_BYTES = 500;

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
        $current = Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );
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
        $trace_data = $this->trace_array_for_storage( $trace );

        $db_time_ms   = $this->sum_span_duration_ms( $trace->spans, \WPFlame\Span::TYPE_DB );
        $http_time_ms = $this->sum_span_duration_ms( $trace->spans, \WPFlame\Span::TYPE_HTTP );

        if ( $trace_data['total_ms'] < max( $db_time_ms, $http_time_ms ) ) {
            $trace_data['total_ms'] = max( $db_time_ms, $http_time_ms );
        }

        $json = $this->encode_trace_data( $trace_data );
        if ( $json === null ) {
            error_log( 'WP Flame: Failed to encode trace ' . $trace->id );
            return;
        }

        $max_trace_bytes = Config::bounded_int(
            Config::instance()->get( 'wp_flame_max_trace_bytes', Config::DEFAULT_MAX_TRACE_BYTES ),
            Config::DEFAULT_MAX_TRACE_BYTES,
            Config::MIN_MAX_TRACE_BYTES,
            Config::MAX_MAX_TRACE_BYTES
        );
        if ( strlen( $json ) > $max_trace_bytes ) {
            $json = $this->trim_trace_json_to_size( $trace_data, $max_trace_bytes );
            if ( $json === null ) {
                error_log( 'WP Flame: Failed to trim trace ' . $trace->id . ' to storage limit' );
                return;
            }
        }

        $url          = $this->limit_string( Config::string_value( $trace_data['url'] ?? '', '' ), self::MAX_URL_BYTES );
        $url_path     = $this->limit_string( explode( '?', $url, 2 )[0], self::MAX_URL_BYTES );
        $total_ms     = $this->bounded_float( $trace_data['total_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS );
        $query_count  = $this->bounded_int( $trace_data['query_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT );
        $peak_memory  = $this->bounded_int( $trace_data['peak_memory'] ?? 0, 0, 0, self::MAX_STORED_BYTES );

        $data    = [
            'trace_id'     => Config::string_value( $trace_data['id'] ?? '', '' ),
            'url'          => $url,
            'url_path'     => $url_path,
            'method'       => $this->limit_string( Config::string_value( $trace_data['method'] ?? '', '' ), 10 ),
            'total_ms'     => $total_ms,
            'query_count'  => $query_count,
            'peak_memory'  => $peak_memory,
            'db_time_ms'   => $db_time_ms,
            'http_time_ms' => $http_time_ms,
            'created_at'   => current_time( 'mysql', true ),
            'user_id'      => $this->bounded_int( $user_id, 0, 0, PHP_INT_MAX ),
            'ip_address'   => $this->limit_string( $ip_address, 45 ),
            'trace_data'   => $json,
        ];
        $formats = [ '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%f', '%f', '%s', '%d', '%s', '%s' ];

        if ( $score !== null ) {
            $data['score'] = $this->bounded_int( $score, 0, 0, 100 );
            $formats[]     = '%d';
        }

        $result = $this->wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            error_log( 'WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error );
        }
    }

    private function trace_array_for_storage( Trace $trace ): array
    {
        $data = $trace->toArray();
        $data['id']          = $this->limit_string( Config::string_value( $data['id'] ?? '', '' ), self::MAX_TRACE_ID_BYTES );
        $data['url']         = $this->limit_string( Config::string_value( $data['url'] ?? '', '' ), self::MAX_URL_BYTES );
        $data['method']      = $this->limit_string( Config::string_value( $data['method'] ?? '', '' ), self::MAX_METHOD_BYTES );
        $data['timestamp']   = $this->limit_string( Config::string_value( $data['timestamp'] ?? '', '' ), self::MAX_TIMESTAMP_BYTES );
        $data['total_ms']    = $this->bounded_float( $data['total_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS );
        $data['peak_memory'] = $this->bounded_int( $data['peak_memory'] ?? 0, 0, 0, self::MAX_STORED_BYTES );
        $data['query_count'] = $this->bounded_int( $data['query_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT );
        $data['total_query_ms'] = $this->bounded_float( $data['total_query_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS );
        $data['php_version'] = $this->limit_string( Config::string_value( $data['php_version'] ?? '', '' ), self::MAX_VERSION_BYTES );
        $data['wp_version']  = $this->limit_string( Config::string_value( $data['wp_version'] ?? '', '' ), self::MAX_VERSION_BYTES );
        $data['spans']       = isset( $data['spans'] ) && is_array( $data['spans'] )
            ? $this->normalize_span_rows( $data['spans'] )
            : [];
        $data['meta']        = isset( $data['meta'] ) && is_array( $data['meta'] )
            ? $this->normalize_meta( $data['meta'] )
            : [];

        return $data;
    }

    private function encode_trace_data( array $data ): ?string
    {
        $json = wp_json_encode( $data );
        if ( $json === false ) {
            $json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
        }

        return $json !== false ? $json : null;
    }

    private function trim_trace_json_to_size( array $data, int $max_bytes ): ?string
    {
        $original_span_count = isset( $data['spans'] ) && is_array( $data['spans'] ) ? count( $data['spans'] ) : 0;
        $data['meta'] = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : [];
        $data['meta']['wp_flame_trace_truncated'] = true;
        $data['meta']['wp_flame_original_span_count'] = $original_span_count;

        while ( isset( $data['spans'] ) && is_array( $data['spans'] ) && count( $data['spans'] ) > 0 ) {
            $data['meta']['wp_flame_stored_span_count'] = count( $data['spans'] );
            $json = $this->encode_trace_data( $data );
            if ( $json !== null && strlen( $json ) <= $max_bytes ) {
                return $json;
            }

            $remove = max( 1, (int) ceil( count( $data['spans'] ) * 0.2 ) );
            $data['spans'] = $this->remove_leaf_spans_for_trim( $data['spans'], $remove );
        }

        $data['spans'] = [];
        $data['meta']['wp_flame_stored_span_count'] = 0;

        $json = $this->encode_trace_data( $data );
        return $json !== null && strlen( $json ) <= $max_bytes ? $json : null;
    }

    /**
     * Remove leaf spans first so storage trimming does not leave children whose
     * parent span was discarded.
     *
     * @param array<int, array<string, mixed>> $spans
     * @return array<int, array<string, mixed>>
     */
    private function remove_leaf_spans_for_trim( array $spans, int $remove_count ): array
    {
        $remove_count = max( 1, $remove_count );

        for ( $removed = 0; $removed < $remove_count && ! empty( $spans ); $removed++ ) {
            $parent_ids = [];
            foreach ( $spans as $span ) {
                if ( isset( $span['parent_id'] ) && $span['parent_id'] !== null && $span['parent_id'] !== '' ) {
                    $parent_ids[ (string) $span['parent_id'] ] = true;
                }
            }

            $removed_leaf = false;
            for ( $i = count( $spans ) - 1; $i >= 0; $i-- ) {
                $id = isset( $spans[ $i ]['id'] ) ? (string) $spans[ $i ]['id'] : '';
                if ( $id === '' || ! isset( $parent_ids[ $id ] ) ) {
                    array_splice( $spans, $i, 1 );
                    $removed_leaf = true;
                    break;
                }
            }

            if ( ! $removed_leaf ) {
                array_pop( $spans );
            }
        }

        return array_values( $spans );
    }

    public function get_trace( string $trace_id ): ?Trace
    {
        $trace_id = $this->limit_string( $trace_id, self::MAX_TRACE_ID_BYTES );
        if ( $trace_id === '' ) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trace_data, user_id, ip_address, score, created_at FROM {$this->table} WHERE trace_id = %s",
                $trace_id
            )
        );

        if ( ! is_object( $row ) ) {
            return null;
        }

        $trace_data = Config::string_value( $row->trace_data ?? '', '' );
        if ( $trace_data === '' ) {
            return null;
        }

        if ( strlen( $trace_data ) > Config::MAX_MAX_TRACE_BYTES ) {
            return null;
        }

        $data = json_decode( $trace_data, true, 32 );
        if ( ! is_array( $data ) ) {
            if ( json_last_error() !== JSON_ERROR_DEPTH ) {
                error_log( 'WP Flame: Failed to decode trace ' . $trace_id . ': ' . json_last_error_msg() );
            }

            return null;
        }

        $trace = Trace::fromArray( $data );

        // Attach row-level columns that are NOT stored in trace_data JSON.
        $trace->meta['_row_user_id']    = $this->bounded_int( $row->user_id ?? 0, 0, 0, PHP_INT_MAX );
        $trace->meta['_row_ip_address'] = $this->limit_string( Config::string_value( $row->ip_address ?? '', '' ), 45 );
        $trace->meta['_row_score']      = $this->score_or_null( $row->score ?? null );
        $trace->meta['_row_created_at'] = $this->limit_string( Config::string_value( $row->created_at ?? '', '' ), 64 );

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

        $url = Config::string_value( $filters['url'] ?? '', '' );
        if ($url !== '') {
            $where[]  = 'url LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($this->limit_string($url, 2048)) . '%';
        }

        $min_duration = $this->number_or_null($filters['min_duration'] ?? null);
        $max_duration = $this->number_or_null($filters['max_duration'] ?? null);

        if ($min_duration !== null && $max_duration !== null && $max_duration < $min_duration) {
            $requested_min = $min_duration;
            $min_duration = $max_duration;
            $max_duration = $requested_min;
        }

        if ($min_duration !== null) {
            $where[]  = 'total_ms >= %f';
            $params[] = max(0.0, $min_duration);
        }

        if ($max_duration !== null) {
            $where[]  = 'total_ms < %f';
            $params[] = max(0.0, $max_duration);
        }

        $after = Config::string_value( $filters['after'] ?? '', '' );
        if ($after !== '') {
            $where[]  = 'created_at >= %s';
            $params[] = $this->limit_string($after, 32);
        }

        $before = Config::string_value( $filters['before'] ?? '', '' );
        if ($before !== '') {
            $where[]  = 'created_at <= %s';
            $params[] = $this->limit_string($before, 32);
        }

        $method = Config::string_value( $filters['method'] ?? '', '' );
        if ($method !== '') {
            $where[]  = 'method = %s';
            $params[] = $this->limit_string(strtoupper($method), 10);
        }

        if (isset($filters['user_id']) && is_numeric($filters['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = max(0, (int) $filters['user_id']);
        }

        $ip_address = Config::string_value( $filters['ip_address'] ?? '', '' );
        if ($ip_address !== '') {
            $where[]  = 'ip_address = %s';
            $params[] = $this->limit_string($ip_address, 45);
        }

        if (isset($filters['min_score']) && is_numeric($filters['min_score'])) {
            $where[]  = 'score >= %d';
            $params[] = $this->bounded_int($filters['min_score'], 0, 0, 100);
        }

        if (isset($filters['max_score']) && is_numeric($filters['max_score'])) {
            $where[]  = 'score <= %d';
            $params[] = $this->bounded_int($filters['max_score'], 100, 0, 100);
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

        $per_page = $this->bounded_int( $filters['per_page'] ?? 20, 20, 1, 200 );
        $page     = $this->bounded_int( $filters['page'] ?? 1, 1, 1, 10000 );
        $offset   = ($page - 1) * $per_page;

        $allowed_orderby = ['created_at', 'total_ms', 'query_count', 'peak_memory', 'score'];
        $requested_orderby = Config::string_value($filters['orderby'] ?? '', '');
        $orderby = in_array($requested_orderby, $allowed_orderby, true) ? $requested_orderby : 'created_at';
        $order   = strtoupper(Config::string_value($filters['order'] ?? '', '')) === 'ASC' ? 'ASC' : 'DESC';

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

        return $this->bounded_int( $this->wpdb->get_var($sql), 0, 0, PHP_INT_MAX );
    }

    public function delete_trace(string $trace_id): void
    {
        $trace_id = $this->limit_string( $trace_id, self::MAX_TRACE_ID_BYTES );
        if ( $trace_id === '' ) {
            return;
        }

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
        $user_id = $this->bounded_int( $user_id, 0, 0, PHP_INT_MAX );
        if ( $user_id <= 0 ) {
            return 0;
        }

        $result = $this->wpdb->delete( $this->table, [ 'user_id' => $user_id ], [ '%d' ] );
        return $result !== false ? $this->bounded_int( $result, 0, 0, PHP_INT_MAX ) : 0;
    }

    public function prune_old(int $days): void
    {
        $days = $this->bounded_days( $days );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT %d",
                $days,
                self::PRUNE_BATCH_LIMIT
            )
        );
    }

    public function purge_all(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        return $this->bounded_int( $this->wpdb->query( "DELETE FROM `{$this->table}`" ), 0, 0, PHP_INT_MAX );
    }

    /**
     * @return array{avg_ms: float, count: int, avg_queries: float, prev_avg_ms: float|null}
     */
    public function get_aggregate_stats(int $days = 7): array
    {
        $days = $this->bounded_days( $days );

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

        $current = is_object( $current ) ? $current : null;
        $prev    = is_object( $prev ) ? $prev : null;
        $prev_avg_ms = $prev ? $this->number_or_null( $prev->avg_ms ?? null ) : null;

        return [
            'avg_ms'      => max( 0.0, $this->number( $current->avg_ms ?? 0, 0.0 ) ),
            'count'       => $this->bounded_int( $current->count ?? 0, 0, 0, PHP_INT_MAX ),
            'avg_queries' => max( 0.0, $this->number( $current->avg_queries ?? 0, 0.0 ) ),
            'prev_avg_ms' => $prev_avg_ms !== null ? max( 0.0, $prev_avg_ms ) : null,
        ];
    }

    public function get_avg_score(int $days = 7): ?float
    {
        $days = $this->bounded_days( $days );

        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT AVG(score) FROM `{$this->table}` WHERE score IS NOT NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $days
        ));
        $score = $this->number_or_null( $result );
        return $score !== null ? round( min( 100.0, max( 0.0, $score ) ), 1 ) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_slowest_pages(int $limit = 5, int $days = 7): array
    {
        $limit = $this->bounded_int( $limit, 5, 1, 50 );
        $days  = $this->bounded_days( $days );

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
     * @param mixed $limit
     * @param mixed $max_bytes
     * @return array<int, string>
     */
    public function get_recent_trace_data($limit = self::DASHBOARD_TRACE_DATA_LIMIT, $max_bytes = self::DASHBOARD_MAX_TRACE_BYTES): array
    {
        $limit     = $this->bounded_int( $limit, self::DASHBOARD_TRACE_DATA_LIMIT, 1, 50 );
        $max_bytes = $this->bounded_int( $max_bytes, self::DASHBOARD_MAX_TRACE_BYTES, 4096, Config::MAX_MAX_TRACE_BYTES );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $results = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT trace_data FROM `{$this->table}` WHERE LENGTH(trace_data) <= %d ORDER BY created_at DESC LIMIT %d",
            $max_bytes, $limit
        ));

        return is_array($results) ? $results : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_response_time_distribution( int $days = 7 ): array
    {
        $days = $this->bounded_days( $days );

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
            [ 'label' => '0-50ms',     'count' => $this->bounded_int( $row->b0 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 0,    'max' => 50 ],
            [ 'label' => '50-100ms',   'count' => $this->bounded_int( $row->b1 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 50,   'max' => 100 ],
            [ 'label' => '100-200ms',  'count' => $this->bounded_int( $row->b2 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 100,  'max' => 200 ],
            [ 'label' => '200-500ms',  'count' => $this->bounded_int( $row->b3 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 200,  'max' => 500 ],
            [ 'label' => '500-1000ms', 'count' => $this->bounded_int( $row->b4 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 500,  'max' => 1000 ],
            [ 'label' => '1000-1500ms','count' => $this->bounded_int( $row->b5 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 1000, 'max' => 1500 ],
            [ 'label' => '1500ms+',    'count' => $this->bounded_int( $row->b6 ?? 0, 0, 0, PHP_INT_MAX ), 'min' => 1500, 'max' => 999999 ],
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
        $row = is_object( $row ) ? $row : null;
        return [
            'count' => $this->bounded_int( $row->count ?? 0, 0, 0, PHP_INT_MAX ),
            'bytes' => $this->bounded_int( $row->bytes ?? 0, 0, 0, PHP_INT_MAX ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_top_users(int $limit = 5, int $days = 7): array
    {
        $limit = $this->bounded_int( $limit, 5, 1, 50 );
        $days  = $this->bounded_days( $days );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT user_id, COUNT(*) as request_count, AVG(total_ms) as avg_ms, SUM(total_ms) as total_ms
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND user_id > 0
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
        $limit = $this->bounded_int( $limit, 5, 1, 50 );
        $days  = $this->bounded_days( $days );

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
        $days = $this->bounded_days( $days );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT user_id, COUNT(*) as request_count
             FROM `{$this->table}`
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND user_id > 0
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
        $days = $this->bounded_days( $days );

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
        $days = $this->bounded_days( $days );

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    COALESCE(AVG(db_time_ms), 0) AS avg_db_ms,
                    COALESCE(AVG(http_time_ms), 0) AS avg_http_ms,
                    COALESCE(AVG(GREATEST(total_ms - COALESCE(db_time_ms, 0) - COALESCE(http_time_ms, 0), 0)), 0) AS avg_php_ms
                FROM {$this->table}
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return [
            'avg_db_ms'   => max( 0.0, $this->number( $row['avg_db_ms'] ?? 0, 0.0 ) ),
            'avg_http_ms' => max( 0.0, $this->number( $row['avg_http_ms'] ?? 0, 0.0 ) ),
            'avg_php_ms'  => max( 0.0, $this->number( $row['avg_php_ms'] ?? 0, 0.0 ) ),
        ];
    }

    /**
     * Get aggregate stats for a specific route (URL stripped of query params).
     *
     * @return array{avg_ms: float, min_ms: float, max_ms: float, avg_queries: float, count: int}|null
     */
    public function get_route_stats(string $url, int $days = 7): ?array
    {
        $route = $this->limit_string( explode('?', $url, 2)[0], 2048 );
        $days  = $this->bounded_days( $days );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT AVG(total_ms) as avg_ms, MIN(total_ms) as min_ms, MAX(total_ms) as max_ms,
                    AVG(query_count) as avg_queries, COUNT(*) as count
             FROM `{$this->table}`
             WHERE url_path = %s
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $route, $days
        ));

        if ( ! is_object( $row ) ) {
            return null;
        }

        $count = $this->bounded_int( $row->count ?? 0, 0, 0, PHP_INT_MAX );
        if ($count < 2) {
            return null; // Need at least 2 traces to compare against
        }

        $min_ms = max( 0.0, $this->number( $row->min_ms ?? 0, 0.0 ) );
        $max_ms = max( $min_ms, $this->number( $row->max_ms ?? 0, 0.0 ) );

        return [
            'avg_ms'      => round( max( 0.0, $this->number( $row->avg_ms ?? 0, 0.0 ) ), 1 ),
            'min_ms'      => round( $min_ms, 1 ),
            'max_ms'      => round( $max_ms, 1 ),
            'avg_queries' => round( max( 0.0, $this->number( $row->avg_queries ?? 0, 0.0 ) ), 1 ),
            'count'       => $count,
        ];
    }

    private function bounded_int( $value, int $default, int $min, int $max ): int
    {
        return Config::bounded_int( $value, $default, $min, $max );
    }

    /**
     * @param mixed $value
     */
    private function bounded_float( $value, float $default, float $min, float $max ): float
    {
        $number = $this->number( $value, $default );
        return min( $max, max( $min, $number ) );
    }

    /**
     * @param Span[] $spans
     */
    private function sum_span_duration_ms( array $spans, string $type ): float
    {
        $total = 0.0;
        foreach ( $spans as $span ) {
            if ( ! ( $span instanceof Span ) || $span->type !== $type ) {
                continue;
            }

            $total += $this->bounded_float( $span->duration_ms, 0.0, 0.0, self::MAX_STORED_MS );
            if ( $total >= self::MAX_STORED_MS ) {
                return self::MAX_STORED_MS;
            }
        }

        return $total;
    }

    private function bounded_days( int $days ): int
    {
        return Config::bounded_int(
            $days,
            Config::DEFAULT_RETENTION_DAYS,
            1,
            Config::MAX_RETENTION_DAYS
        );
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }

    /**
     * @param array<int, mixed> $spans
     * @return array<int, array<string, mixed>>
     */
    private function normalize_span_rows( array $spans ): array
    {
        $normalized = [];
        foreach ( $spans as $span ) {
            if ( ! is_array( $span ) ) {
                continue;
            }

            $parent_id = Config::string_value( $span['parent_id'] ?? '', '' );
            $normalized[] = [
                'id'          => $this->limit_string( Config::string_value( $span['id'] ?? '', '' ), self::MAX_TRACE_ID_BYTES ),
                'parent_id'   => $parent_id !== ''
                    ? $this->limit_string( $parent_id, self::MAX_TRACE_ID_BYTES )
                    : null,
                'name'        => $this->limit_string( Config::string_value( $span['name'] ?? 'unknown', 'unknown' ), self::MAX_SPAN_NAME_BYTES ),
                'type'        => $this->limit_string( Config::string_value( $span['type'] ?? Span::TYPE_PHP, Span::TYPE_PHP ), self::MAX_SPAN_TYPE_BYTES ),
                'source'      => $this->limit_string( Config::string_value( $span['source'] ?? 'unknown', 'unknown' ), self::MAX_SPAN_SOURCE_BYTES ),
                'start_ms'    => $this->bounded_float( $span['start_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                'duration_ms' => $this->bounded_float( $span['duration_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                'self_ms'     => $this->bounded_float( $span['self_ms'] ?? $span['duration_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                'meta'        => isset( $span['meta'] ) && is_array( $span['meta'] )
                    ? $this->normalize_meta( $span['meta'] )
                    : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $meta
     * @return array<string, mixed>
     */
    private function normalize_meta( array $meta, int $depth = 0 ): array
    {
        $normalized = [];
        $count      = 0;

        foreach ( $meta as $key => $value ) {
            if ( $count >= ( $depth === 0 ? self::MAX_META_ENTRIES : self::MAX_META_ARRAY_ENTRIES ) ) {
                break;
            }

            $key = $this->limit_string( Config::string_value( $key, '' ), self::MAX_META_KEY_BYTES );
            if ( $key === '' ) {
                $key = 'meta_' . ( $count + 1 );
            }

            $value = $this->normalize_meta_value( $value, $depth );
            if ( $value === null ) {
                continue;
            }

            $normalized[ $key ] = $value;
            $count++;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed|null
     */
    private function normalize_meta_value( $value, int $depth )
    {
        if ( is_bool( $value ) || is_int( $value ) ) {
            return $value;
        }

        if ( is_float( $value ) ) {
            return is_finite( $value ) ? $value : 0.0;
        }

        if ( is_string( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
            return $this->limit_string( Config::string_value( $value, '' ), self::MAX_META_STRING_BYTES );
        }

        if ( is_array( $value ) && $depth < self::MAX_META_ARRAY_DEPTH ) {
            return $this->normalize_meta( $value, $depth + 1 );
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function number( $value, float $fallback ): float
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
    private function number_or_null( $value ): ?float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? $number : null;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? $number : null;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function score_or_null( $value ): ?int
    {
        if ( $value === null || $value === '' ) {
            return null;
        }

        $score = $this->number_or_null( $value );
        if ( $score === null ) {
            return null;
        }

        return min( 100, max( 0, (int) $score ) );
    }
}
