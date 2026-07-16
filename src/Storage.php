<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage
{
    const SCHEMA_VERSION = 6;
    public const DASHBOARD_TRACE_DATA_LIMIT = 25;
    public const DASHBOARD_MAX_TRACE_BYTES = 262144;
    public const PRUNE_BATCH_LIMIT = 500;
    public const PRUNE_MAX_BATCHES_PER_RUN = 10;
    public const PRUNE_TIME_BUDGET_MS = 250;
    public const MIGRATION_BACKFILL_BATCH_LIMIT = 500;
    public const MIGRATION_LOCK_TTL_SECONDS = 300;
    public const PRIVACY_DELETE_BATCH_LIMIT = 500;
    public const ROLLUP_BACKFILL_BATCH_LIMIT = 25;
    public const ROLLUP_TIME_BUDGET_MS = 250;
    public const ROLLUP_LOCK_TTL_SECONDS = 60;
    private const MAX_TRACE_ID_BYTES = 36;
    private const MAX_STORED_MS = 86400000.0;
    private const MAX_STORED_BYTES = 1099511627776;
    private const MAX_STORED_COUNT = 1000000;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_TIMESTAMP_BYTES = 64;
    private const MAX_VERSION_BYTES = 64;
    private const MAX_REQUEST_TYPE_BYTES = 40;
    private const MAX_ROUTE_KEY_BYTES = 512;
    private const MAX_CAPTURE_ORIGIN_BYTES = 20;
    private const MAX_INSTRUMENTATION_MODE_BYTES = 20;
    private const MAX_SESSION_ID_BYTES = 128;
    private const MAX_SPAN_NAME_BYTES = 300;
    private const MAX_SPAN_TYPE_BYTES = 40;
    private const MAX_SPAN_SOURCE_BYTES = 200;
    private const MAX_META_ENTRIES = 50;
    private const MAX_META_ARRAY_ENTRIES = 20;
    private const MAX_META_ARRAY_DEPTH = 2;
    private const MAX_META_KEY_BYTES = 80;
    private const MAX_META_STRING_BYTES = 500;
    private const MAX_HTTP_URL_BYTES = 2048;
    private const MAX_GRAPHQL_QUERY_BYTES = 65536;

    private \wpdb $wpdb;
    private string $table;
    private string $sessions_table;
    private string $environments_table;
    private string $rollups_table;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'flame_traces';
        $this->sessions_table = $wpdb->prefix . 'flame_sessions';
        $this->environments_table = $wpdb->prefix . 'flame_environments';
        $this->rollups_table = $wpdb->prefix . 'flame_rollups';
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
            request_type varchar(40) NOT NULL DEFAULT 'unknown',
            route_key varchar(512) NOT NULL DEFAULT '',
            http_status smallint unsigned DEFAULT NULL,
            instrumentation_mode varchar(20) NOT NULL DEFAULT 'unknown',
            capture_origin varchar(20) NOT NULL DEFAULT 'legacy',
            capture_session_id varchar(128) NOT NULL DEFAULT '',
            environment_snapshot_id varchar(128) NOT NULL DEFAULT '',
            score_version smallint unsigned NOT NULL DEFAULT 1,
            is_complete tinyint(1) unsigned NOT NULL DEFAULT 0,
            rollup_version smallint unsigned NOT NULL DEFAULT 0,
            trace_bytes bigint unsigned NOT NULL DEFAULT 0,
            trace_data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trace_id (trace_id),
            KEY url_path (url_path(191)),
            KEY created_at (created_at),
            KEY created_total (created_at, total_ms),
            KEY created_queries (created_at, query_count),
            KEY user_id (user_id),
            KEY ip_address (ip_address),
            KEY route_cohort (request_type, route_key(120), instrumentation_mode, score_version),
            KEY status_created (http_status, created_at),
            KEY completeness_created (is_complete, created_at),
            KEY capture_session (capture_session_id(36)),
            KEY environment_snapshot (environment_snapshot_id(36)),
            KEY trace_bytes (trace_bytes),
            KEY rollup_pending (rollup_version, id)
        ) {$charset};";

        $sessions_sql = "CREATE TABLE {$this->sessions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id char(36) NOT NULL,
            route_key varchar(512) NOT NULL DEFAULT '',
            request_type varchar(40) NOT NULL DEFAULT 'unknown',
            capture_policy varchar(128) NOT NULL DEFAULT 'manual_session',
            instrumentation_mode varchar(20) NOT NULL DEFAULT 'standard',
            requested_count smallint unsigned NOT NULL DEFAULT 1,
            captured_count smallint unsigned NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            phase varchar(20) NOT NULL DEFAULT 'observation',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY route_phase (request_type, route_key(120), phase, status),
            KEY status_expiry (status, expires_at)
        ) {$charset};";

        $environments_sql = "CREATE TABLE {$this->environments_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_id char(64) NOT NULL,
            schema_version smallint unsigned NOT NULL DEFAULT 1,
            snapshot_data longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY snapshot_id (snapshot_id)
        ) {$charset};";

        $rollups_sql = "CREATE TABLE {$this->rollups_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dimension_hash char(64) NOT NULL,
            bucket_start date NOT NULL,
            rollup_version smallint unsigned NOT NULL,
            score_version smallint unsigned NOT NULL,
            request_type varchar(40) NOT NULL DEFAULT 'unknown',
            route_key varchar(512) NOT NULL DEFAULT '',
            instrumentation_mode varchar(20) NOT NULL DEFAULT 'unknown',
            capability_cohort char(64) NOT NULL,
            capability_data text NOT NULL,
            source varchar(200) NOT NULL DEFAULT '',
            span_type varchar(40) NOT NULL DEFAULT '',
            callback_key varchar(512) NOT NULL DEFAULT '',
            sample_count bigint unsigned NOT NULL DEFAULT 0,
            span_count bigint unsigned NOT NULL DEFAULT 0,
            total_duration_ms double NOT NULL DEFAULT 0,
            span_duration_ms double NOT NULL DEFAULT 0,
            self_duration_ms double NOT NULL DEFAULT 0,
            total_query_count bigint unsigned NOT NULL DEFAULT 0,
            total_score bigint unsigned NOT NULL DEFAULT 0,
            scored_count bigint unsigned NOT NULL DEFAULT 0,
            complete_count bigint unsigned NOT NULL DEFAULT 0,
            duration_b0 bigint unsigned NOT NULL DEFAULT 0,
            duration_b1 bigint unsigned NOT NULL DEFAULT 0,
            duration_b2 bigint unsigned NOT NULL DEFAULT 0,
            duration_b3 bigint unsigned NOT NULL DEFAULT 0,
            duration_b4 bigint unsigned NOT NULL DEFAULT 0,
            duration_b5 bigint unsigned NOT NULL DEFAULT 0,
            duration_b6 bigint unsigned NOT NULL DEFAULT 0,
            duration_b7 bigint unsigned NOT NULL DEFAULT 0,
            duration_b8 bigint unsigned NOT NULL DEFAULT 0,
            duration_b9 bigint unsigned NOT NULL DEFAULT 0,
            duration_b10 bigint unsigned NOT NULL DEFAULT 0,
            duration_b11 bigint unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY dimension_hash (dimension_hash),
            KEY cohort_window (rollup_version, score_version, request_type, bucket_start),
            KEY route_window (route_key(120), instrumentation_mode, bucket_start),
            KEY source_window (source, span_type, bucket_start)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sessions_sql);
        dbDelta($environments_sql);
        dbDelta($rollups_sql);
    }

    /**
     * Run schema migrations if needed.
     */
    /**
     * Run one bounded migration step.
     *
     * @return array{status: string, current: int, target: int, processed: int, message: string}
     */
    public function maybe_upgrade(): array
    {
        $current = Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );
        if ( $current >= self::SCHEMA_VERSION ) {
            return $this->migration_result( 'complete', $current, 0, '' );
        }

        $token = $this->acquire_migration_lock();
        if ( $token === '' ) {
            return $this->migration_result( 'locked', $current, 0, 'Another migration worker owns the lock.' );
        }

        $result = $this->migration_result( 'running', $current, 0, '' );
        $this->record_migration_health( $result );
        try {
            // dbDelta is bounded to schema inspection and additive DDL. Existing
            // trace JSON is never decoded or rewritten during an update request.
            $this->create_table();

            if ( $current < 2 ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; the batch limit is a constant.
                $processed = $this->wpdb->query(
                    "UPDATE {$this->table} SET url_path = SUBSTRING_INDEX(url, '?', 1) WHERE url_path = '' LIMIT " . self::MIGRATION_BACKFILL_BATCH_LIMIT
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
                if ( $processed === false ) {
                    throw new \RuntimeException( 'Legacy route-key backfill failed.' );
                }

                $processed = $this->bounded_int( $processed, 0, 0, self::MIGRATION_BACKFILL_BATCH_LIMIT );
                if ( $processed >= self::MIGRATION_BACKFILL_BATCH_LIMIT ) {
                    $result = $this->migration_result( 'pending', $current, $processed, 'Legacy route-key backfill has more rows.' );
                    $this->record_migration_health( $result );
                    $this->schedule_migration_continuation();
                    return $result;
                }
            }

            // Versions 3 through 5 use additive columns with conservative legacy
            // defaults. Trace::fromArray() remains the compatibility boundary.
            // Reaching this block implies a pre-v6 schema because the current
            // version returned above. Keep quota accounting exact without
            // repeatedly scanning every LONGTEXT value after migration.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; the batch limit is a constant.
            $processed = $this->wpdb->query(
                "UPDATE {$this->table} SET trace_bytes = LENGTH(trace_data) WHERE trace_bytes = 0 LIMIT " . self::MIGRATION_BACKFILL_BATCH_LIMIT
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
            if ( $processed === false ) {
                throw new \RuntimeException( 'Trace-size backfill failed.' );
            }

            $processed = $this->bounded_int( $processed, 0, 0, self::MIGRATION_BACKFILL_BATCH_LIMIT );
            if ( $processed >= self::MIGRATION_BACKFILL_BATCH_LIMIT ) {
                $result = $this->migration_result( 'pending', $current, $processed, 'Trace-size backfill has more rows.' );
                $this->record_migration_health( $result );
                $this->schedule_migration_continuation();
                return $result;
            }

            update_option( 'wp_flame_schema_version', self::SCHEMA_VERSION );
            $result = $this->migration_result( 'complete', self::SCHEMA_VERSION, 0, '' );
            $this->record_migration_health( $result );
            return $result;
        } catch ( \Throwable $error ) {
            $message = $this->limit_string( $error->getMessage(), 300 );
            $result = $this->migration_result( 'failed', $current, 0, $message );
            $this->record_migration_health( $result );
            $this->schedule_migration_continuation();
            return $result;
        } finally {
            $this->release_migration_lock( $token );
        }
    }

    /**
     * @return array{status: string, current: int, target: int, processed: int, message: string, updated_at: string}
     */
    public function migration_health(): array
    {
        $stored = get_option( 'wp_flame_migration_health', [] );
        $stored = is_array( $stored ) ? $stored : [];
        $current = Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );

        return [
            'status'     => $this->limit_string( Config::string_value( $stored['status'] ?? ( $current >= self::SCHEMA_VERSION ? 'complete' : 'pending' ), 'pending' ), 20 ),
            'current'    => $current,
            'target'     => self::SCHEMA_VERSION,
            'processed'  => Config::bounded_int( $stored['processed'] ?? 0, 0, 0, self::MIGRATION_BACKFILL_BATCH_LIMIT ),
            'message'    => $this->limit_string( Config::string_value( $stored['message'] ?? '', '' ), 300 ),
            'updated_at' => $this->limit_string( Config::string_value( $stored['updated_at'] ?? '', '' ), self::MAX_TIMESTAMP_BYTES ),
        ];
    }

    public function save_trace(Trace $trace, ?int $score = null, int $user_id = 0, string $ip_address = ''): StorageResult
    {
        $trace_data = $this->trace_array_for_storage( $trace );

        $db_time_ms   = $this->sum_span_duration_ms( $trace->spans, \WPFlame\Span::TYPE_DB );
        $http_time_ms = $this->sum_span_duration_ms( $trace->spans, \WPFlame\Span::TYPE_HTTP );

        if ( $trace_data['total_ms'] < max( $db_time_ms, $http_time_ms ) ) {
            $trace_data['total_ms'] = max( $db_time_ms, $http_time_ms );
            $trace_data['observed_duration_ms'] = $trace_data['total_ms'];
        }

        $json = $this->encode_trace_data( $trace_data );
        if ( $json === null ) {
            error_log( 'WP Flame: Failed to encode trace ' . $trace->id );
            return $this->persistence_failure( StorageResult::ENCODING_FAILED );
        }

        $max_trace_bytes = Config::bounded_int(
            Config::instance()->get( 'wp_flame_max_trace_bytes', Config::DEFAULT_MAX_TRACE_BYTES ),
            Config::DEFAULT_MAX_TRACE_BYTES,
            Config::MIN_MAX_TRACE_BYTES,
            Config::MAX_MAX_TRACE_BYTES
        );
        $storage_trimmed = false;
        if ( strlen( $json ) > $max_trace_bytes ) {
            $json = $this->trim_trace_json_to_size( $trace_data, $max_trace_bytes );
            if ( $json === null ) {
                error_log( 'WP Flame: Failed to trim trace ' . $trace->id . ' to storage limit' );
                return $this->persistence_failure( StorageResult::SIZE_LIMIT_FAILED );
            }
            $storage_trimmed = true;
        }

        if ( ! $this->ensure_quota_for_trace( strlen( $json ) ) ) {
            return StorageResult::failed( StorageResult::QUOTA_REACHED );
        }

        $url          = $this->limit_string( Config::string_value( $trace_data['url'] ?? '', '' ), self::MAX_URL_BYTES );
        $url_path     = $this->limit_string( explode( '?', $url, 2 )[0], self::MAX_URL_BYTES );
        $total_ms     = $this->bounded_float( $trace_data['total_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS );
        $query_count  = $this->bounded_int( $trace_data['query_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT );
        $peak_memory  = $this->bounded_int( $trace_data['peak_memory'] ?? 0, 0, 0, self::MAX_STORED_BYTES );
        $request_type = $this->limit_string( Config::string_value( $trace_data['request_type'] ?? 'unknown', 'unknown' ), self::MAX_REQUEST_TYPE_BYTES );
        $route_key = $this->limit_string( Config::string_value( $trace_data['route_key'] ?? '', '' ), self::MAX_ROUTE_KEY_BYTES );
        $http_status = $this->nullable_bounded_int( $trace_data['http_status'] ?? null, 100, 599 );
        $mode = $this->limit_string( Config::string_value( $trace_data['instrumentation_mode'] ?? 'unknown', 'unknown' ), self::MAX_INSTRUMENTATION_MODE_BYTES );
        $capture_origin = $this->limit_string( Config::string_value( $trace_data['capture_origin'] ?? 'legacy', 'legacy' ), self::MAX_CAPTURE_ORIGIN_BYTES );
        $capture_session_id = $this->limit_string( Config::string_value( $trace_data['capture_session_id'] ?? '', '' ), self::MAX_SESSION_ID_BYTES );
        $environment_snapshot_id = $this->limit_string( Config::string_value( $trace_data['environment_snapshot_id'] ?? '', '' ), self::MAX_SESSION_ID_BYTES );
        $score_version = $this->bounded_int( $trace_data['score_version'] ?? 1, 1, 1, 65535 );
        $incomplete_reasons = isset( $trace_data['incomplete_reasons'] ) && is_array( $trace_data['incomplete_reasons'] )
            ? $trace_data['incomplete_reasons']
            : [];
        $is_complete = ! $storage_trimmed
            && empty( $incomplete_reasons )
            && empty( $trace_data['trace_truncated'] )
            && $this->bounded_int( $trace_data['dropped_span_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ) === 0
            && $this->bounded_int( $trace_data['auto_closed_span_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ) === 0;

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
            'request_type' => $request_type !== '' ? $request_type : 'unknown',
            'route_key'    => $route_key,
            'http_status'  => $http_status,
            'instrumentation_mode' => $mode !== '' ? $mode : 'unknown',
            'capture_origin' => $capture_origin !== '' ? $capture_origin : 'legacy',
            'capture_session_id' => $capture_session_id,
            'environment_snapshot_id' => $environment_snapshot_id,
            'score_version' => $score_version,
            'is_complete'  => $is_complete ? 1 : 0,
            'rollup_version' => 0,
            'trace_data'   => $json,
        ];
        $formats = [
            '%s', // trace_id.
            '%s', // url.
            '%s', // url_path.
            '%s', // method.
            '%f', // total_ms.
            '%d', // query_count.
            '%d', // peak_memory.
            '%f', // db_time_ms.
            '%f', // http_time_ms.
            '%s', // created_at.
            '%d', // user_id.
            '%s', // ip_address.
            '%s', // request_type.
            '%s', // route_key.
            '%d', // http_status.
            '%s', // instrumentation_mode.
            '%s', // capture_origin.
            '%s', // capture_session_id.
            '%s', // environment_snapshot_id.
            '%d', // score_version.
            '%d', // is_complete.
            '%d', // rollup_version.
            '%s', // trace_data.
        ];

        // Additive migrations run asynchronously. Continue storing with the
        // previous schema until v6 is complete; the column default and the
        // legacy quota query keep this path backward-compatible.
        if ( Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX ) >= 6 ) {
            $trace_data_json = $data['trace_data'];
            unset( $data['trace_data'] );
            array_pop( $formats );
            $data['trace_bytes'] = strlen( $json );
            $formats[] = '%d';
            $data['trace_data'] = $trace_data_json;
            $formats[] = '%s';
        }

        if ( $score !== null ) {
            $data['score'] = $this->bounded_int( $score, 0, 0, 100 );
            $formats[]     = '%d';
        }

        $result = $this->wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            error_log( 'WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error );
            return $this->persistence_failure( StorageResult::INSERT_FAILED );
        }

        if ( $capture_session_id !== '' ) {
            $this->record_session_trace( $capture_session_id );
        }
        $this->schedule_rollup_continuation();

        if ( function_exists( 'delete_option' ) ) {
            delete_option( 'wp_flame_persistence_failures' );
        }

        return StorageResult::stored( strlen( $json ), $storage_trimmed );
    }

    public function save_capture_session( CaptureSession $session ): bool
    {
        if ( $session->id === '' ) {
            return false;
        }

        $data = $session->to_array();
        $data['expires_at'] = $session->expires_at !== '' ? $session->expires_at : null;
        $data['created_at'] = current_time( 'mysql', true );
        $data['updated_at'] = $data['created_at'];

        return $this->wpdb->insert(
            $this->sessions_table,
            $data,
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        ) !== false;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names are prefix plus static suffixes; values use placeholders.
    public function get_capture_session( string $session_id ): ?CaptureSession
    {
        $session_id = $this->limit_string( $session_id, 36 );
        if ( $session_id === '' ) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT session_id, route_key, request_type, capture_policy, instrumentation_mode,
                        requested_count, captured_count, expires_at, phase, status
                 FROM {$this->sessions_table} WHERE session_id = %s",
                $session_id
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['id'] = $row['session_id'] ?? '';

        return new CaptureSession( $row );
    }

    /**
     * @return CaptureSession[]
     */
    public function list_capture_sessions( int $limit = 20 ): array
    {
        $limit = $this->bounded_int( $limit, 20, 1, 100 );
        $rows = $this->wpdb->get_results(
            "SELECT session_id, route_key, request_type, capture_policy, instrumentation_mode,
                    requested_count, captured_count, expires_at, phase, status
             FROM {$this->sessions_table}
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}",
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $sessions = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $row['id'] = $row['session_id'] ?? '';
            $session = new CaptureSession( $row );
            if ( $session->is_expired() && $session->status === 'active' ) {
                $session->status = 'expired';
            }
            $sessions[] = $session;
        }

        return $sessions;
    }

    public function cancel_capture_session( string $session_id ): bool
    {
        $session_id = $this->limit_string( $session_id, 36 );
        if ( $session_id === '' ) {
            return false;
        }

        return $this->wpdb->update(
            $this->sessions_table,
            [
                'status'     => 'cancelled',
                'updated_at' => current_time( 'mysql', true ),
            ],
            [
                'session_id' => $session_id,
                'status'     => 'active',
            ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        ) !== false;
    }

    /** @return Trace[] */
    public function get_capture_session_traces( string $session_id, int $limit = 100 ): array
    {
        $session_id = $this->limit_string( $session_id, 36 );
        if ( $session_id === '' ) {
            return [];
        }
        $limit = $this->bounded_int( $limit, 100, 1, 200 );
        $rows = $this->list_traces( [
            'capture_session_id' => $session_id,
            'per_page'           => $limit,
            'page'               => 1,
            'order'              => 'ASC',
        ] );
        $traces = [];
        foreach ( $rows as $row ) {
            $trace_id = Config::string_value( $row['trace_id'] ?? '', '' );
            $trace = $trace_id !== '' ? $this->get_trace( $trace_id ) : null;
            if ( $trace instanceof Trace ) {
                $traces[] = $trace;
            }
        }
        return $traces;
    }

    /**
     * Atomically pin a new guided session to its first observed cohort and
     * reject unrelated browser/background requests thereafter.
     */
    public function claim_capture_session_route(
        string $session_id,
        string $route_key,
        string $request_type
    ): ?CaptureSession {
        $session_id = $this->limit_string( $session_id, 36 );
        $route_key = $this->limit_string( $route_key, self::MAX_ROUTE_KEY_BYTES );
        $request_type = $this->limit_string( $request_type, self::MAX_REQUEST_TYPE_BYTES );
        if ( $session_id === '' || $route_key === '' || $request_type === '' ) {
            return null;
        }

        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->sessions_table}
                 SET route_key = IF(route_key = '', %s, route_key),
                     request_type = IF(request_type = 'unknown', %s, request_type),
                     updated_at = %s
                 WHERE session_id = %s
                   AND status = 'active'
                   AND captured_count < requested_count
                   AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                   AND (route_key = '' OR route_key = %s)
                   AND (request_type = 'unknown' OR request_type = %s)",
                $route_key,
                $request_type,
                current_time( 'mysql', true ),
                $session_id,
                $route_key,
                $request_type
            )
        );
        if ( $updated === false ) {
            return null;
        }

        $session = $this->get_capture_session( $session_id );
        return $session instanceof CaptureSession
            && $session->accepts_trace()
            && hash_equals( $session->route_key, $route_key )
            && hash_equals( $session->request_type, $request_type )
                ? $session
                : null;
    }

    public function save_environment_snapshot( EnvironmentSnapshot $snapshot ): string
    {
        $json = wp_json_encode( $snapshot->to_array() );
        if ( ! is_string( $json ) || strlen( $json ) > 262144 ) {
            return '';
        }

        $sql = $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->environments_table}
                (snapshot_id, schema_version, snapshot_data, created_at)
             VALUES (%s, %d, %s, %s)",
            $snapshot->fingerprint,
            EnvironmentSnapshot::SCHEMA_VERSION,
            $json,
            current_time( 'mysql', true )
        );

        return $this->wpdb->query( $sql ) !== false ? $snapshot->fingerprint : '';
    }

    public function get_environment_snapshot( string $snapshot_id ): ?EnvironmentSnapshot
    {
        $snapshot_id = $this->limit_string( $snapshot_id, 64 );
        if ( $snapshot_id === '' ) {
            return null;
        }

        $json = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT snapshot_data FROM {$this->environments_table} WHERE snapshot_id = %s",
                $snapshot_id
            )
        );
        $json = Config::string_value( $json, '' );
        if ( $json === '' || strlen( $json ) > 262144 ) {
            return null;
        }

        $data = json_decode( $json, true, 12 );
        if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
            return null;
        }

        $snapshot = new EnvironmentSnapshot( $data['data'] );

        return hash_equals( $snapshot_id, $snapshot->fingerprint ) ? $snapshot : null;
    }

    private function record_session_trace( string $session_id ): void
    {
        $session_id = $this->limit_string( $session_id, 36 );
        if ( $session_id === '' ) {
            return;
        }

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->sessions_table}
                 SET captured_count = LEAST(requested_count, captured_count + 1),
                     status = IF(captured_count >= requested_count, 'complete', status),
                     updated_at = %s
                 WHERE session_id = %s
                   AND status = 'active'
                   AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
                current_time( 'mysql', true ),
                $session_id
            )
        );
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

    private function trace_array_for_storage( Trace $trace ): array
    {
        $data = $trace->toArray();
        $data['id']          = $this->limit_string( Config::string_value( $data['id'] ?? '', '' ), self::MAX_TRACE_ID_BYTES );
        $data['url']         = $this->limit_string( Config::string_value( $data['url'] ?? '', '' ), self::MAX_URL_BYTES );
        $data['method']      = $this->limit_string( Config::string_value( $data['method'] ?? '', '' ), self::MAX_METHOD_BYTES );
        $data['timestamp']   = $this->limit_string( Config::string_value( $data['timestamp'] ?? '', '' ), self::MAX_TIMESTAMP_BYTES );
        $data['total_ms']    = $this->bounded_float( $data['total_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS );
        $data['observed_duration_ms'] = $data['total_ms'];
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
        $data['trace_truncated'] = true;
        $data['incomplete_reasons'] = isset( $data['incomplete_reasons'] ) && is_array( $data['incomplete_reasons'] )
            ? array_values( $data['incomplete_reasons'] )
            : [];
        if ( ! in_array( 'trace_size_trimming', $data['incomplete_reasons'], true ) ) {
            $data['incomplete_reasons'][] = 'trace_size_trimming';
        }

        while ( isset( $data['spans'] ) && is_array( $data['spans'] ) && count( $data['spans'] ) > 0 ) {
            $data['meta']['wp_flame_stored_span_count'] = count( $data['spans'] );
            $data['trimmed_span_count'] = max( 0, $original_span_count - count( $data['spans'] ) );
            $json = $this->encode_trace_data( $data );
            if ( $json !== null && strlen( $json ) <= $max_bytes ) {
                return $json;
            }

            $remove = max( 1, (int) ceil( count( $data['spans'] ) * 0.2 ) );
            $data['spans'] = $this->remove_leaf_spans_for_trim( $data['spans'], $remove );
        }

        $data['spans'] = [];
        $data['meta']['wp_flame_stored_span_count'] = 0;
        $data['trimmed_span_count'] = $original_span_count;

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

        $request_type = Config::string_value( $filters['request_type'] ?? '', '' );
        if ( $request_type !== '' ) {
            $where[] = 'request_type = %s';
            $params[] = $this->limit_string( $request_type, self::MAX_REQUEST_TYPE_BYTES );
        }

        $route_key = Config::string_value( $filters['route_key'] ?? '', '' );
        if ( $route_key !== '' ) {
            $where[] = 'route_key = %s';
            $params[] = $this->limit_string( $route_key, self::MAX_ROUTE_KEY_BYTES );
        }

        $mode = Config::string_value( $filters['instrumentation_mode'] ?? '', '' );
        if ( $mode !== '' ) {
            $where[] = 'instrumentation_mode = %s';
            $params[] = $this->limit_string( $mode, self::MAX_INSTRUMENTATION_MODE_BYTES );
        }

        $capture_session_id = Config::string_value( $filters['capture_session_id'] ?? '', '' );
        if ( $capture_session_id !== '' ) {
            $where[] = 'capture_session_id = %s';
            $params[] = $this->limit_string( $capture_session_id, 36 );
        }

        if ( array_key_exists( 'http_status', $filters ) ) {
            $http_status = $this->nullable_bounded_int( $filters['http_status'], 100, 599 );
            if ( $http_status !== null ) {
                $where[] = 'http_status = %d';
                $params[] = $http_status;
            }
        }

        if ( array_key_exists( 'is_complete', $filters ) ) {
            $where[] = 'is_complete = %d';
            $params[] = Config::boolean( $filters['is_complete'] ) ? 1 : 0;
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

        $sql = "SELECT trace_id, url, method, total_ms, query_count, peak_memory, created_at, score, user_id, ip_address,
                       request_type, route_key, http_status, instrumentation_mode, capture_origin,
                       capture_session_id, environment_snapshot_id, score_version, is_complete
                FROM {$this->table}
                WHERE {$where_sql}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $sql = $this->wpdb->prepare($sql, $params);

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and sort identifiers are allowlisted; all values are prepared above.
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

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table is internal; the WHERE fragment is fixed and all values are prepared above.
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

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; user ID uses a placeholder and the limit is constant.
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE user_id = %d LIMIT " . self::PRIVACY_DELETE_BATCH_LIMIT,
                $user_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        return $result !== false ? $this->bounded_int( $result, 0, 0, self::PRIVACY_DELETE_BATCH_LIMIT ) : 0;
    }

    public function prune_old(int $days): int
    {
        $days = $this->bounded_days( $days );

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT %d",
                $days,
                self::PRUNE_BATCH_LIMIT
            )
        );

        return $this->bounded_int( $result, 0, 0, self::PRUNE_BATCH_LIMIT );
    }

    /**
     * Run bounded retention batches and report whether another continuation is required.
     *
     * @return array{deleted: int, batches: int, backlog: bool, oldest_expired: string, quota_reached: bool}
     */
    public function run_retention_cleanup( int $days, int $time_budget_ms = self::PRUNE_TIME_BUDGET_MS ): array
    {
        $days = $this->bounded_days( $days );
        $time_budget_ms = $this->bounded_int( $time_budget_ms, self::PRUNE_TIME_BUDGET_MS, 25, 5000 );
        $started_at = microtime( true );
        $deleted = 0;
        $batches = 0;

        do {
            $batch_deleted = $this->prune_old( $days );
            $deleted += $batch_deleted;
            $batches++;

            if ( $batch_deleted < self::PRUNE_BATCH_LIMIT ) {
                break;
            }
        } while (
            $batches < self::PRUNE_MAX_BATCHES_PER_RUN
            && ( microtime( true ) - $started_at ) * 1000 < $time_budget_ms
        );

        $this->prune_old_rollups( $days );

        $oldest_expired = $this->oldest_expired_trace( $days );
        $backlog = $oldest_expired !== '';
        $quota = $this->quota_status();
        $this->record_quota_state( $quota );

        $result = [
            'deleted'        => $deleted,
            'batches'        => $batches,
            'backlog'        => $backlog,
            'oldest_expired' => $oldest_expired,
            'quota_reached'  => $quota['reached'],
        ];
        if ( function_exists( 'update_option' ) ) {
            update_option( 'wp_flame_last_cleanup_result', $result );
            update_option( 'wp_flame_last_cleanup_at', current_time( 'mysql', true ) );
        }

        return $result;
    }

    public function oldest_expired_trace( int $days ): string
    {
        $days = $this->bounded_days( $days );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted WordPress prefix plus a static suffix; the retention value uses a placeholder.
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MIN(created_at) FROM {$this->table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return $this->limit_string( Config::string_value( $value, '' ), self::MAX_TIMESTAMP_BYTES );
    }

    /**
     * @return array{reached: bool, reason: string, count: int, bytes: int, row_limit: int, byte_limit: int, row_percent: float, byte_percent: float}
     */
    public function quota_status( int $incoming_bytes = 0 ): array
    {
        $incoming_bytes = $this->bounded_int( $incoming_bytes, 0, 0, Config::MAX_MAX_TRACE_BYTES );
        $stats = $this->get_stats();
        $row_limit = Config::bounded_int(
            Config::instance()->get( 'wp_flame_storage_quota_rows', Config::DEFAULT_STORAGE_QUOTA_ROWS ),
            Config::DEFAULT_STORAGE_QUOTA_ROWS,
            Config::MIN_STORAGE_QUOTA_ROWS,
            Config::MAX_STORAGE_QUOTA_ROWS
        );
        $quota_mb = Config::bounded_int(
            Config::instance()->get( 'wp_flame_storage_quota_mb', Config::DEFAULT_STORAGE_QUOTA_MB ),
            Config::DEFAULT_STORAGE_QUOTA_MB,
            Config::MIN_STORAGE_QUOTA_MB,
            Config::MAX_STORAGE_QUOTA_MB
        );
        $byte_limit = $quota_mb * 1048576;
        $row_reached = $incoming_bytes > 0
            ? $stats['count'] + 1 > $row_limit
            : $stats['count'] >= $row_limit;
        $byte_reached = $incoming_bytes > 0
            ? $stats['bytes'] + $incoming_bytes > $byte_limit
            : $stats['bytes'] >= $byte_limit;
        $reason = $row_reached ? 'row_ceiling' : ( $byte_reached ? 'byte_ceiling' : '' );

        return [
            'reached'      => $row_reached || $byte_reached,
            'reason'       => $reason,
            'count'        => $stats['count'],
            'bytes'        => $stats['bytes'],
            'row_limit'    => $row_limit,
            'byte_limit'   => $byte_limit,
            'row_percent'  => min( 100.0, round( $stats['count'] / max( 1, $row_limit ) * 100, 1 ) ),
            'byte_percent' => min( 100.0, round( $stats['bytes'] / max( 1, $byte_limit ) * 100, 1 ) ),
        ];
    }

    /**
     * @return array{count: int, bytes: int, quota: array, oldest_expired: string, last_cleanup: array, last_cleanup_at: string, capture_paused: bool}
     */
    public function get_storage_health( int $retention_days ): array
    {
        $quota = $this->quota_status();
        $last_cleanup = function_exists( 'get_option' ) ? get_option( 'wp_flame_last_cleanup_result', [] ) : [];
        $last_cleanup = is_array( $last_cleanup ) ? array_slice( $last_cleanup, 0, 8, true ) : [];
        $last_cleanup_at = function_exists( 'get_option' ) ? get_option( 'wp_flame_last_cleanup_at', '' ) : '';

        return [
            'count'          => $quota['count'],
            'bytes'          => $quota['bytes'],
            'quota'          => $quota,
            'oldest_expired' => $this->oldest_expired_trace( $retention_days ),
            'last_cleanup'   => $last_cleanup,
            'last_cleanup_at' => $this->limit_string( Config::string_value( $last_cleanup_at, '' ), self::MAX_TIMESTAMP_BYTES ),
            'capture_paused' => $quota['reached'],
        ];
    }

    /** @return array{status: string, message: string} */
    public function table_health(): array
    {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix and the query has no values.
        $columns = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table}" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        if ( is_array( $columns ) && in_array( 'trace_id', $columns, true ) && in_array( 'trace_data', $columns, true ) ) {
            return [
                'status'  => 'ready',
                'message' => '',
            ];
        }

        $message = $this->limit_string( Config::string_value( $this->wpdb->last_error, 'Trace table is missing.' ), 300 );
        return [
            'status'  => 'unavailable',
            'message' => $message !== '' ? $message : 'Trace table is missing.',
        ];
    }

    /** @return array{count: int, last_status: string, last_at: string} */
    public function persistence_health(): array
    {
        $stored = get_option( 'wp_flame_persistence_failures', [] );
        $stored = is_array( $stored ) ? $stored : [];

        return [
            'count'       => Config::bounded_int( $stored['count'] ?? 0, 0, 0, 1000000 ),
            'last_status' => $this->limit_string( Config::string_value( $stored['last_status'] ?? '', '' ), 40 ),
            'last_at'     => $this->limit_string( Config::string_value( $stored['last_at'] ?? '', '' ), self::MAX_TIMESTAMP_BYTES ),
        ];
    }

    /**
     * Project a bounded trace batch into versioned aggregate rows.
     *
     * @return array{processed: int, failed: int, backlog: bool, version: int}
     */
    public function run_rollup_backfill( int $time_budget_ms = self::ROLLUP_TIME_BUDGET_MS ): array
    {
        $time_budget_ms = $this->bounded_int( $time_budget_ms, self::ROLLUP_TIME_BUDGET_MS, 25, 5000 );
        $lock_token = $this->acquire_rollup_lock();
        if ( $lock_token === '' ) {
            return [
                'processed' => 0,
                'failed'    => 0,
                'backlog'   => $this->rollup_pending_count() > 0,
                'version'   => Rollup::VERSION,
            ];
        }
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; version and limit use placeholders.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, trace_data, score, created_at FROM {$this->table} WHERE rollup_version < %d ORDER BY id ASC LIMIT %d",
                Rollup::VERSION,
                self::ROLLUP_BACKFILL_BATCH_LIMIT
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $rows = is_array( $rows ) ? $rows : [];
        $processed = 0;
        $failed = 0;
        $started_at = microtime( true );
        foreach ( $rows as $row ) {
            if ( $processed + $failed > 0 && ( microtime( true ) - $started_at ) * 1000 >= $time_budget_ms ) {
                break;
            }

            $id = $this->bounded_int( $row['id'] ?? 0, 0, 0, PHP_INT_MAX );
            $data = json_decode( Config::string_value( $row['trace_data'] ?? '', '' ), true );
            $malformed = false;
            $transaction_started = false;
            try {
                if ( $id <= 0 || ! is_array( $data ) ) {
                    $malformed = true;
                    throw new \RuntimeException( 'Malformed trace JSON.' );
                }
                $trace = Trace::fromArray( $data );
                $score = $this->nullable_bounded_int( $row['score'] ?? null, 0, 100 );
                $bucket = substr( Config::string_value( $row['created_at'] ?? '', '' ), 0, 10 );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bucket ) ) {
                    $bucket = gmdate( 'Y-m-d' );
                }
                $this->wpdb->query( 'START TRANSACTION' );
                $transaction_started = true;
                if ( ! $this->persist_rollup_rows( Rollup::rows_for_trace( $trace, $score, $bucket ) ) ) {
                    throw new \RuntimeException( 'Aggregate upsert failed.' );
                }
                if ( $this->wpdb->update( $this->table, [ 'rollup_version' => Rollup::VERSION ], [ 'id' => $id ], [ '%d' ], [ '%d' ] ) === false ) {
                    throw new \RuntimeException( 'Trace rollup checkpoint failed.' );
                }
                $this->wpdb->query( 'COMMIT' );
                $transaction_started = false;
                $processed++;
            } catch ( \Throwable $error ) {
                if ( $transaction_started ) {
                    $this->wpdb->query( 'ROLLBACK' );
                }
                $failed++;
                update_option( 'wp_flame_rollup_last_failure', [
                    'trace_row_id' => $id,
                    'message'      => $this->limit_string( $error->getMessage(), 300 ),
                    'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
                ] );
                // Mark only malformed input as examined so one corrupt row
                // cannot block all later traces. Database failures remain
                // pending and retry without double-counting due to rollback.
                if ( $malformed && $id > 0 ) {
                    $this->wpdb->update( $this->table, [ 'rollup_version' => Rollup::VERSION ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
                }
            }
        }

        $backlog = $this->rollup_pending_count() > 0;
        $result = [
            'processed' => $processed,
            'failed'    => $failed,
            'backlog'   => $backlog,
            'version'   => Rollup::VERSION,
        ];
        update_option( 'wp_flame_rollup_last_result', $result );
        update_option( 'wp_flame_rollup_last_at', gmdate( 'Y-m-d H:i:s' ) );
        if ( $backlog ) {
            $this->schedule_rollup_continuation();
        }
        $this->release_rollup_lock( $lock_token );

        return $result;
    }

    /** @return array{version: int, pending: int, last_result: array, last_at: string, last_failure: array} */
    public function rollup_health(): array
    {
        $last_result = get_option( 'wp_flame_rollup_last_result', [] );
        $last_failure = get_option( 'wp_flame_rollup_last_failure', [] );

        return [
            'version'      => Rollup::VERSION,
            'pending'      => $this->rollup_pending_count(),
            'last_result'  => is_array( $last_result ) ? array_slice( $last_result, 0, 8, true ) : [],
            'last_at'      => $this->limit_string( Config::string_value( get_option( 'wp_flame_rollup_last_at', '' ), '' ), self::MAX_TIMESTAMP_BYTES ),
            'last_failure' => is_array( $last_failure ) ? array_slice( $last_failure, 0, 8, true ) : [],
        ];
    }

    public function purge_all(): int
    {
        // Rollups are derived local diagnostic data and must not survive an
        // administrator's explicit purge-all action.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are the trusted prefix plus static suffixes.
        $this->wpdb->query( "DELETE FROM `{$this->rollups_table}`" );
        $deleted = $this->wpdb->query( "DELETE FROM `{$this->table}`" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $this->bounded_int( $deleted, 0, 0, PHP_INT_MAX );
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
     * Summarize measured span time from current, versioned rollups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_rollup_type_totals( int $days = 7 ): array
    {
        $days = $this->bounded_days( $days );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; version and period use placeholders.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT span_type, SUM(self_duration_ms) AS total_ms, SUM(span_count) AS evidence_count
                 FROM {$this->rollups_table}
                 WHERE rollup_version = %d AND bucket_start >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
                   AND source <> '' AND span_type <> ''
                 GROUP BY span_type ORDER BY total_ms DESC",
                Rollup::VERSION,
                $days
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return the slowest observed callback dimensions from current rollups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_rollup_callbacks( int $limit = 5, int $days = 7 ): array
    {
        $limit = $this->bounded_int( $limit, 5, 1, 50 );
        $days = $this->bounded_days( $days );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; all dynamic values use placeholders.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT callback_key, source, SUM(span_duration_ms) AS total_ms,
                        SUM(span_count) AS evidence_count, SUM(sample_count) AS sample_count
                 FROM {$this->rollups_table}
                 WHERE rollup_version = %d AND bucket_start >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
                   AND callback_key <> ''
                 GROUP BY callback_key, source ORDER BY total_ms DESC LIMIT %d",
                Rollup::VERSION,
                $days,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Disclose the population behind dashboard aggregate previews.
     *
     * @return array{rollup_version: int, sample_count: int, capability_cohorts: int, score_versions: int, modes: int, pending: int}
     */
    public function get_rollup_population( int $days = 7 ): array
    {
        $days = $this->bounded_days( $days );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; version and period use placeholders.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(sample_count), 0) AS sample_count,
                        COUNT(DISTINCT capability_cohort) AS capability_cohorts,
                        COUNT(DISTINCT score_version) AS score_versions,
                        COUNT(DISTINCT instrumentation_mode) AS modes
                 FROM {$this->rollups_table}
                 WHERE rollup_version = %d AND bucket_start >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
                   AND source = '' AND span_type = '' AND callback_key = ''",
                Rollup::VERSION,
                $days
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $row = is_array( $row ) ? $row : [];

        return [
            'rollup_version'   => Rollup::VERSION,
            'sample_count'     => $this->bounded_int( $row['sample_count'] ?? 0, 0, 0, PHP_INT_MAX ),
            'capability_cohorts' => $this->bounded_int( $row['capability_cohorts'] ?? 0, 0, 0, PHP_INT_MAX ),
            'score_versions'   => $this->bounded_int( $row['score_versions'] ?? 0, 0, 0, PHP_INT_MAX ),
            'modes'            => $this->bounded_int( $row['modes'] ?? 0, 0, 0, PHP_INT_MAX ),
            'pending'          => $this->rollup_pending_count(),
        ];
    }

    /**
     * Return compatible route cohorts with bounded histogram percentiles.
     * Percentiles are upper bounds from the committed rollup buckets rather
     * than invented precision from an average.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_route_cohort_trends( int $days = 7, int $limit = 20 ): array
    {
        $days = $this->bounded_days( $days );
        $limit = $this->bounded_int( $limit, 20, 1, 100 );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; version, period, and limit use placeholders.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT request_type, route_key, instrumentation_mode, capability_cohort, capability_data,
                        score_version, MIN(bucket_start) AS period_start, MAX(bucket_start) AS period_end,
                        SUM(sample_count) AS sample_count, SUM(total_duration_ms) AS total_duration_ms,
                        SUM(total_query_count) AS total_query_count, SUM(total_score) AS total_score,
                        SUM(scored_count) AS scored_count, SUM(complete_count) AS complete_count,
                        SUM(duration_b0) AS duration_b0, SUM(duration_b1) AS duration_b1,
                        SUM(duration_b2) AS duration_b2, SUM(duration_b3) AS duration_b3,
                        SUM(duration_b4) AS duration_b4, SUM(duration_b5) AS duration_b5,
                        SUM(duration_b6) AS duration_b6, SUM(duration_b7) AS duration_b7,
                        SUM(duration_b8) AS duration_b8, SUM(duration_b9) AS duration_b9,
                        SUM(duration_b10) AS duration_b10, SUM(duration_b11) AS duration_b11
                 FROM {$this->rollups_table}
                 WHERE rollup_version = %d AND bucket_start >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
                   AND source = '' AND span_type = '' AND callback_key = '' AND route_key <> ''
                 GROUP BY request_type, route_key, instrumentation_mode, capability_cohort, capability_data, score_version
                 ORDER BY sample_count DESC LIMIT %d",
                Rollup::VERSION,
                $days,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $rows = is_array( $rows ) ? $rows : [];
        $trends = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $sample_count = $this->bounded_int( $row['sample_count'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( $sample_count <= 0 ) {
                continue;
            }
            $histogram = [];
            $histogram_bucket_count = count( Rollup::HISTOGRAM_BOUNDS_MS ) + 1;
            for ( $index = 0; $index < $histogram_bucket_count; $index++ ) {
                $histogram[] = $this->bounded_int( $row[ 'duration_b' . $index ] ?? 0, 0, 0, PHP_INT_MAX );
            }
            $scored_count = $this->bounded_int( $row['scored_count'] ?? 0, 0, 0, PHP_INT_MAX );
            $complete_count = $this->bounded_int( $row['complete_count'] ?? 0, 0, 0, PHP_INT_MAX );
            $trends[] = [
                'request_type'        => $this->limit_string( Config::string_value( $row['request_type'] ?? 'unknown', 'unknown' ), self::MAX_REQUEST_TYPE_BYTES ),
                'route_key'           => $this->limit_string( Config::string_value( $row['route_key'] ?? '', '' ), self::MAX_ROUTE_KEY_BYTES ),
                'instrumentation_mode' => $this->limit_string( Config::string_value( $row['instrumentation_mode'] ?? 'unknown', 'unknown' ), self::MAX_INSTRUMENTATION_MODE_BYTES ),
                'capability_cohort'   => $this->limit_string( Config::string_value( $row['capability_cohort'] ?? '', '' ), 64 ),
                'capability_data'     => $this->limit_string( Config::string_value( $row['capability_data'] ?? '{}', '{}' ), 4096 ),
                'rollup_version'      => Rollup::VERSION,
                'score_version'       => $this->bounded_int( $row['score_version'] ?? 1, 1, 1, 65535 ),
                'period_start'        => $this->limit_string( Config::string_value( $row['period_start'] ?? '', '' ), 10 ),
                'period_end'          => $this->limit_string( Config::string_value( $row['period_end'] ?? '', '' ), 10 ),
                'sample_count'        => $sample_count,
                'complete_count'      => min( $sample_count, $complete_count ),
                'p50_ms'              => $this->histogram_percentile( $histogram, 0.50 ),
                'p95_ms'              => $this->histogram_percentile( $histogram, 0.95 ),
                'avg_ms'              => $this->bounded_float( $row['total_duration_ms'] ?? 0, 0.0, 0.0, PHP_FLOAT_MAX ) / $sample_count,
                'avg_queries'         => $this->bounded_float( $row['total_query_count'] ?? 0, 0.0, 0.0, PHP_FLOAT_MAX ) / $sample_count,
                'avg_score'           => $scored_count > 0
                    ? $this->bounded_float( $row['total_score'] ?? 0, 0.0, 0.0, PHP_FLOAT_MAX ) / $scored_count
                    : null,
            ];
        }

        return $trends;
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
        $schema_version = Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );
        $bytes_sql = $schema_version >= 6
            ? 'COALESCE(SUM(trace_bytes), 0)'
            : 'COALESCE(SUM(LENGTH(trace_data)), 0)';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $row = $this->wpdb->get_row(
            "SELECT COUNT(*) as count, {$bytes_sql} as bytes FROM `{$this->table}`"
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

    private function ensure_quota_for_trace( int $incoming_bytes ): bool
    {
        $quota = $this->quota_status( $incoming_bytes );
        if ( $quota['reached'] ) {
            $retention_days = Config::bounded_int(
                Config::instance()->get( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
                Config::DEFAULT_RETENTION_DAYS,
                1,
                Config::MAX_RETENTION_DAYS
            );
            $this->prune_old( $retention_days );
            $quota = $this->quota_status( $incoming_bytes );

            if ( $quota['reached'] && $this->oldest_expired_trace( $retention_days ) !== '' ) {
                $this->schedule_cleanup_continuation();
            }
        }

        $this->record_quota_state( $quota );

        return ! $quota['reached'];
    }

    private function persistence_failure( string $status ): StorageResult
    {
        if ( function_exists( 'update_option' ) ) {
            $current = get_option( 'wp_flame_persistence_failures', [] );
            $current = is_array( $current ) ? $current : [];
            update_option( 'wp_flame_persistence_failures', [
                'count'       => Config::bounded_int( $current['count'] ?? 0, 0, 0, 999999 ) + 1,
                'last_status' => $status,
                'last_at'     => gmdate( 'Y-m-d H:i:s' ),
            ] );
        }

        return StorageResult::failed( $status );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function persist_rollup_rows( array $rows ): bool
    {
        foreach ( $rows as $row ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; every row value uses a placeholder.
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->rollups_table}
                    (dimension_hash, bucket_start, rollup_version, score_version, request_type, route_key,
                     instrumentation_mode, capability_cohort, capability_data, source, span_type, callback_key,
                     sample_count, span_count, total_duration_ms, span_duration_ms, self_duration_ms, total_query_count,
                     total_score, scored_count, complete_count,
                     duration_b0, duration_b1, duration_b2, duration_b3, duration_b4, duration_b5,
                     duration_b6, duration_b7, duration_b8, duration_b9, duration_b10, duration_b11, updated_at)
                 VALUES (%s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %f, %f, %f, %d, %d, %d, %d,
                         %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    sample_count = sample_count + VALUES(sample_count),
                    span_count = span_count + VALUES(span_count),
                    total_duration_ms = total_duration_ms + VALUES(total_duration_ms),
                    span_duration_ms = span_duration_ms + VALUES(span_duration_ms),
                    self_duration_ms = self_duration_ms + VALUES(self_duration_ms),
                    total_query_count = total_query_count + VALUES(total_query_count),
                    total_score = total_score + VALUES(total_score),
                    scored_count = scored_count + VALUES(scored_count),
                    complete_count = complete_count + VALUES(complete_count),
                    duration_b0 = duration_b0 + VALUES(duration_b0),
                    duration_b1 = duration_b1 + VALUES(duration_b1),
                    duration_b2 = duration_b2 + VALUES(duration_b2),
                    duration_b3 = duration_b3 + VALUES(duration_b3),
                    duration_b4 = duration_b4 + VALUES(duration_b4),
                    duration_b5 = duration_b5 + VALUES(duration_b5),
                    duration_b6 = duration_b6 + VALUES(duration_b6),
                    duration_b7 = duration_b7 + VALUES(duration_b7),
                    duration_b8 = duration_b8 + VALUES(duration_b8),
                    duration_b9 = duration_b9 + VALUES(duration_b9),
                    duration_b10 = duration_b10 + VALUES(duration_b10),
                    duration_b11 = duration_b11 + VALUES(duration_b11),
                    capability_data = VALUES(capability_data),
                    updated_at = VALUES(updated_at)",
                Config::string_value( $row['dimension_hash'] ?? '', '' ),
                Config::string_value( $row['bucket_start'] ?? '', '' ),
                $this->bounded_int( $row['rollup_version'] ?? 0, 0, 0, 65535 ),
                $this->bounded_int( $row['score_version'] ?? 1, 1, 1, 65535 ),
                Config::string_value( $row['request_type'] ?? 'unknown', 'unknown' ),
                Config::string_value( $row['route_key'] ?? '', '' ),
                Config::string_value( $row['instrumentation_mode'] ?? 'unknown', 'unknown' ),
                Config::string_value( $row['capability_cohort'] ?? '', '' ),
                Config::string_value( $row['capability_data'] ?? '{}', '{}' ),
                Config::string_value( $row['source'] ?? '', '' ),
                Config::string_value( $row['span_type'] ?? '', '' ),
                Config::string_value( $row['callback_key'] ?? '', '' ),
                $this->bounded_int( $row['sample_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['span_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_float( $row['total_duration_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                $this->bounded_float( $row['span_duration_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                $this->bounded_float( $row['self_duration_ms'] ?? 0, 0.0, 0.0, self::MAX_STORED_MS ),
                $this->bounded_int( $row['total_query_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['total_score'] ?? 0, 0, 0, self::MAX_STORED_COUNT * 100 ),
                $this->bounded_int( $row['scored_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['complete_count'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b0'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b1'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b2'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b3'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b4'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b5'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b6'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b7'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b8'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b9'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b10'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                $this->bounded_int( $row['duration_b11'] ?? 0, 0, 0, self::MAX_STORED_COUNT ),
                gmdate( 'Y-m-d H:i:s' )
            );
            if ( $this->wpdb->query( $sql ) === false ) {
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
                return false;
            }
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        }

        return true;
    }

    private function prune_old_rollups( int $days ): void
    {
        // Daily aggregate buckets expire at day granularity. The partially
        // expired cutoff day remains until the next UTC day rather than
        // deleting still-retained observations from the same bucket.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; days uses a placeholder.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->rollups_table} WHERE bucket_start < DATE(DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))",
                $this->bounded_days( $days )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
    }

    /** @param int[] $histogram */
    private function histogram_percentile( array $histogram, float $percentile ): ?float
    {
        $total = array_sum( $histogram );
        if ( $total <= 0 ) {
            return null;
        }
        $target = max( 1, (int) ceil( $total * max( 0.0, min( 1.0, $percentile ) ) ) );
        $seen = 0;
        foreach ( $histogram as $index => $count ) {
            $seen += max( 0, $count );
            if ( $seen < $target ) {
                continue;
            }
            if ( isset( Rollup::HISTOGRAM_BOUNDS_MS[ $index ] ) ) {
                return (float) Rollup::HISTOGRAM_BOUNDS_MS[ $index ];
            }

            // Overflow is disclosed as the last finite bound; callers label it
            // as a histogram upper-bound estimate, not an exact percentile.
            $bounds = Rollup::HISTOGRAM_BOUNDS_MS;
            return (float) end( $bounds );
        }

        return null;
    }

    private function rollup_pending_count(): int
    {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table is the trusted prefix plus a static suffix; version uses a placeholder.
        $pending = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE rollup_version < %d",
                Rollup::VERSION
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return $this->bounded_int( $pending, 0, 0, PHP_INT_MAX );
    }

    private function acquire_rollup_lock(): string
    {
        $now = time();
        $lock = get_option( 'wp_flame_rollup_lock', [] );
        if ( is_array( $lock ) ) {
            $acquired_at = Config::bounded_int( $lock['acquired_at'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( $acquired_at > 0 && $acquired_at + self::ROLLUP_LOCK_TTL_SECONDS > $now ) {
                return '';
            }
        }

        delete_option( 'wp_flame_rollup_lock' );
        $token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-flame-rollup-', true );
        $created = add_option( 'wp_flame_rollup_lock', [
            'token'       => $token,
            'acquired_at' => $now,
        ], '', false );

        return $created ? $token : '';
    }

    private function release_rollup_lock( string $token ): void
    {
        $lock = get_option( 'wp_flame_rollup_lock', [] );
        if ( is_array( $lock ) && hash_equals( Config::string_value( $lock['token'] ?? '', '' ), $token ) ) {
            delete_option( 'wp_flame_rollup_lock' );
        }
    }

    private function schedule_rollup_continuation(): void
    {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }

        if ( ! wp_next_scheduled( 'wp_flame_rollup_backfill' ) ) {
            wp_schedule_single_event( time() + 60, 'wp_flame_rollup_backfill' );
        }
    }

    private function acquire_migration_lock(): string
    {
        $now = time();
        $lock = get_option( 'wp_flame_migration_lock', [] );
        if ( is_array( $lock ) ) {
            $acquired_at = Config::bounded_int( $lock['acquired_at'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( $acquired_at > 0 && $acquired_at + self::MIGRATION_LOCK_TTL_SECONDS > $now ) {
                return '';
            }
        }

        delete_option( 'wp_flame_migration_lock' );
        $token = function_exists( 'wp_generate_uuid4' )
            ? wp_generate_uuid4()
            : uniqid( 'wp-flame-', true );
        $created = add_option(
            'wp_flame_migration_lock',
            [
                'token'       => $token,
                'acquired_at' => $now,
                'target'      => self::SCHEMA_VERSION,
            ],
            '',
            false
        );

        return $created ? $token : '';
    }

    private function release_migration_lock( string $token ): void
    {
        $lock = get_option( 'wp_flame_migration_lock', [] );
        if ( ! is_array( $lock ) || ! hash_equals( Config::string_value( $lock['token'] ?? '', '' ), $token ) ) {
            return;
        }

        delete_option( 'wp_flame_migration_lock' );
    }

    /**
     * @return array{status: string, current: int, target: int, processed: int, message: string}
     */
    private function migration_result( string $status, int $current, int $processed, string $message ): array
    {
        return [
            'status'    => $status,
            'current'   => $current,
            'target'    => self::SCHEMA_VERSION,
            'processed' => $processed,
            'message'   => $message,
        ];
    }

    /** @param array{status: string, current: int, target: int, processed: int, message: string} $result */
    private function record_migration_health( array $result ): void
    {
        $result['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        update_option( 'wp_flame_migration_health', $result );
    }

    private function schedule_migration_continuation(): void
    {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }

        if ( ! wp_next_scheduled( 'wp_flame_run_migration' ) ) {
            wp_schedule_single_event( time() + 60, 'wp_flame_run_migration' );
        }
    }

    /** @param array{reached: bool, reason: string, count: int, bytes: int, row_limit: int, byte_limit: int, row_percent: float, byte_percent: float} $quota */
    private function record_quota_state( array $quota ): void
    {
        if ( ! function_exists( 'update_option' ) ) {
            return;
        }

        update_option( 'wp_flame_storage_quota_state', $quota );
        if ( $quota['reached'] ) {
            update_option( 'wp_flame_capture_paused_reason', 'storage_quota' );
            return;
        }

        if ( function_exists( 'get_option' ) && get_option( 'wp_flame_capture_paused_reason', '' ) === 'storage_quota' ) {
            delete_option( 'wp_flame_capture_paused_reason' );
        }
    }

    private function schedule_cleanup_continuation(): void
    {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }

        if ( ! wp_next_scheduled( 'wp_flame_prune_traces_continue' ) ) {
            wp_schedule_single_event( time() + 60, 'wp_flame_prune_traces_continue' );
        }
    }

    private function bounded_int( $value, int $default, int $min, int $max ): int
    {
        return Config::bounded_int( $value, $default, $min, $max );
    }

    /**
     * @param mixed $value
     */
    private function nullable_bounded_int( $value, int $min, int $max ): ?int
    {
        if ( $value === null || ! is_numeric( $value ) ) {
            return null;
        }

        $number = (float) $value;
        if ( ! is_finite( $number ) ) {
            return null;
        }

        return min( $max, max( $min, (int) $number ) );
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

            $value = $this->normalize_meta_value( $value, $depth, $key );
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
    private function normalize_meta_value( $value, int $depth, string $key = '' )
    {
        if ( is_bool( $value ) || is_int( $value ) ) {
            return $value;
        }

        if ( is_float( $value ) ) {
            return is_finite( $value ) ? $value : 0.0;
        }

        if ( is_string( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
            return $this->limit_string( Config::string_value( $value, '' ), $this->max_meta_string_bytes( $key, $depth ) );
        }

        if ( is_array( $value ) && $depth < self::MAX_META_ARRAY_DEPTH ) {
            return $this->normalize_meta( $value, $depth + 1 );
        }

        return null;
    }

    private function max_meta_string_bytes( string $key, int $depth ): int
    {
        if ( $depth !== 0 ) {
            return self::MAX_META_STRING_BYTES;
        }

        if ( $key === 'query' ) {
            return Redactor::MAX_SQL_LABEL_BYTES;
        }

        if ( $key === 'graphql_query' ) {
            return self::MAX_GRAPHQL_QUERY_BYTES;
        }

        if ( $key === 'url' ) {
            return self::MAX_HTTP_URL_BYTES;
        }

        return self::MAX_META_STRING_BYTES;
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
