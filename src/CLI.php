<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CLI
{
    private const MAX_TRACE_ID_BYTES = 128;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_DATE_BYTES = 64;

    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List recent traces.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of traces to show. Default 20.
     *
     * [--url=<url>]
     * : Filter by URL pattern.
     *
     * [--format=<format>]
     * : Output format. Default table. Options: table, json, csv.
     *
     * ## EXAMPLES
     *
     *     wp flame list
     *     wp flame list --limit=50
     *     wp flame list --url=/shop/ --format=json
     *
     * @subcommand list
     */
    public function list_traces($args, $assoc_args): void
    {
        $filters = [];
        $filters['per_page'] = Config::bounded_int( $assoc_args['limit'] ?? 20, 20, 1, 200 );
        $filters['page'] = 1;

        if (!empty($assoc_args['url'])) {
            $filters['url'] = $assoc_args['url'];
        }

        $traces = $this->storage->list_traces($filters);

        if (empty($traces)) {
            \WP_CLI::log('No traces found.');
            return;
        }

        $format = $this->format_arg( $assoc_args['format'] ?? 'table', [ 'table', 'json', 'csv' ], 'table' );

        $items = array_map([$this, 'format_trace_row'], $traces);

        $this->format_items($format, $items, ['trace_id', 'url', 'method', 'duration', 'queries', 'memory', 'date']);
    }

    /**
     * Show a single trace.
     *
     * ## OPTIONS
     *
     * <trace_id>
     * : The trace ID (UUID) to show.
     *
     * [--format=<format>]
     * : Output format. Default json. Options: json, yaml.
     *
     * ## EXAMPLES
     *
     *     wp flame show abc-123-def
     */
    public function show($args, $assoc_args): void
    {
        $trace_id = Config::string_value( $args[0] ?? '', '' );
        $trace_id = $this->limit_string( $trace_id, self::MAX_TRACE_ID_BYTES );
        if ( $trace_id === '' ) {
            \WP_CLI::error( 'Missing trace ID.' );
            return;
        }

        $trace = $this->storage->get_trace($trace_id);

        if (!$trace) {
            \WP_CLI::error('Trace not found: ' . $trace_id);
            return;
        }

        $format = $this->format_arg( $assoc_args['format'] ?? 'json', [ 'json', 'yaml' ], 'json' );
        $data = $trace->toArray();

        if ($format === 'json') {
            $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE );
            \WP_CLI::log( is_string( $json ) ? $json : '{}' );
        } else {
            \WP_CLI::print_value($data, ['format' => $format]);
        }
    }

    /**
     * Prune old traces.
     *
     * ## OPTIONS
     *
     * [--days=<number>]
     * : Delete traces older than this many days. Default: use retention setting.
     *
     * [--all]
     * : Delete all traces.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp flame prune
     *     wp flame prune --days=3
     *     wp flame prune --all --yes
     */
    public function prune($args, $assoc_args): void
    {
        if (!empty($assoc_args['all'])) {
            if (empty($assoc_args['yes'])) {
                \WP_CLI::confirm('Delete ALL traces?');
            }
            $count = $this->storage->purge_all();
            \WP_CLI::success("Deleted {$count} trace(s).");
            return;
        }

        $days = Config::bounded_int(
            $assoc_args['days'] ?? Config::instance()->get( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
            Config::DEFAULT_RETENTION_DAYS,
            1,
            Config::MAX_RETENTION_DAYS
        );
        $max_runs = ! empty( $assoc_args['until-complete'] )
            ? Config::bounded_int( $assoc_args['max-runs'] ?? 100, 100, 1, 10000 )
            : 1;
        $deleted = 0;
        $result = [
            'backlog' => false,
            'deleted' => 0,
        ];
        for ( $run = 0; $run < $max_runs; $run++ ) {
            $result = $this->storage->run_retention_cleanup( $days, 5000 );
            $deleted += Config::bounded_int( $result['deleted'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( empty( $result['backlog'] ) ) {
                break;
            }
        }

        \WP_CLI::success("Pruned {$deleted} trace(s) older than {$days} day(s).");
        if ( ! empty( $result['backlog'] ) ) {
            \WP_CLI::log('Expired traces remain. Rerun with --until-complete or increase --max-runs.');
        }
    }

    /**
     * Run bounded schema migration work.
     *
     * ## OPTIONS
     *
     * [--until-complete]
     * : Continue bounded backfill batches until complete.
     *
     * [--max-runs=<number>]
     * : Safety limit with --until-complete. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp flame migrate
     *     wp flame migrate --until-complete
     */
    public function migrate( $args, $assoc_args ): void
    {
        $max_runs = ! empty( $assoc_args['until-complete'] )
            ? Config::bounded_int( $assoc_args['max-runs'] ?? 100, 100, 1, 10000 )
            : 1;
        $result = [
            'status'    => 'pending',
            'current'   => 0,
            'target'    => Storage::SCHEMA_VERSION,
            'processed' => 0,
            'message'   => '',
        ];
        $processed = 0;

        for ( $run = 0; $run < $max_runs; $run++ ) {
            $result = $this->storage->maybe_upgrade();
            $processed += Config::bounded_int( $result['processed'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( $result['status'] !== 'pending' ) {
                break;
            }
        }

        if ( $result['status'] === 'complete' ) {
            call_user_func( [ '\\WP_CLI', 'success' ], 'WP Flame schema is current at version ' . $result['target'] . ". Processed {$processed} legacy row(s)." );
            return;
        }

        $message = Config::string_value( $result['message'] ?? '', '' );
        call_user_func( [ '\\WP_CLI', 'error' ], 'WP Flame migration stopped with status ' . $result['status'] . ( $message !== '' ? ': ' . $message : '.' ) );
    }

    /**
     * Build versioned trend summaries from stored traces.
     *
     * ## OPTIONS
     *
     * [--until-complete]
     * : Continue bounded batches until all pending traces are summarized.
     *
     * [--max-runs=<number>]
     * : Safety limit with --until-complete. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp flame rollups
     *     wp flame rollups --until-complete
     */
    public function rollups( $args, $assoc_args ): void
    {
        $max_runs = ! empty( $assoc_args['until-complete'] )
            ? Config::bounded_int( $assoc_args['max-runs'] ?? 100, 100, 1, 10000 )
            : 1;
        $processed = 0;
        $failed = 0;
        $result = [ 'backlog' => false ];

        for ( $run = 0; $run < $max_runs; $run++ ) {
            $result = $this->storage->run_rollup_backfill( 5000 );
            $processed += Config::bounded_int( $result['processed'] ?? 0, 0, 0, PHP_INT_MAX );
            $failed += Config::bounded_int( $result['failed'] ?? 0, 0, 0, PHP_INT_MAX );
            if ( empty( $result['backlog'] ) || (int) ( $result['processed'] ?? 0 ) === 0 ) {
                break;
            }
        }

        if ( ! empty( $result['backlog'] ) ) {
            call_user_func( [ '\\WP_CLI', 'warning' ], "Built {$processed} rollup(s) with {$failed} failure(s); pending traces remain." );
            return;
        }

        call_user_func( [ '\\WP_CLI', 'success' ], "Trend rollups are current after processing {$processed} trace(s); {$failed} failure(s)." );
    }

    /**
     * Display bounded storage and migration health.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default table. Options: table, json, csv.
     */
    public function health( $args, $assoc_args ): void
    {
        $retention_days = Config::bounded_int(
            Config::instance()->get( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
            Config::DEFAULT_RETENTION_DAYS,
            1,
            Config::MAX_RETENTION_DAYS
        );
        $storage = $this->storage->get_storage_health( $retention_days );
        $migration = $this->storage->migration_health();
        $rollups = $this->storage->rollup_health();
        $format = $this->format_arg( $assoc_args['format'] ?? 'table', [ 'table', 'json', 'csv' ], 'table' );
        $items = [
            [
                'check'  => 'migration',
                'status' => $migration['status'],
                'detail' => $migration['current'] . ' / ' . $migration['target'],
            ],
            [
                'check'  => 'storage quota',
                'status' => ! empty( $storage['quota']['reached'] ) ? 'reached' : 'available',
                'detail' => $storage['count'] . ' traces',
            ],
            [
                'check'  => 'retention backlog',
                'status' => $storage['oldest_expired'] !== '' ? 'pending' : 'clear',
                'detail' => $storage['oldest_expired'],
            ],
            [
                'check'  => 'trend rollups',
                'status' => $rollups['pending'] > 0 ? 'pending' : 'current',
                'detail' => $rollups['pending'] . ' traces at version ' . $rollups['version'],
            ],
        ];

        $this->format_items($format, $items, [ 'check', 'status', 'detail' ]);
    }

    /**
     * Print a privacy-safe diagnostic bundle for a support request.
     *
     * The default bundle contains no trace rows, request paths, SQL, HTTP URLs,
     * identities, IP addresses, user agents, license data, or site URL.
     *
     * ## OPTIONS
     *
     * [--include-components]
     * : Include active plugin/theme slugs and versions. Review before sharing.
     *
     * ## EXAMPLES
     *
     *     wp flame support-bundle > wp-flame-support.json
     *     wp flame support-bundle --include-components
     */
    public function support_bundle( $args, $assoc_args ): void
    {
        $retention_days = Config::bounded_int(
            Config::instance()->get( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
            Config::DEFAULT_RETENTION_DAYS,
            1,
            Config::MAX_RETENTION_DAYS
        );
        $storage = $this->storage->get_storage_health( $retention_days );
        $migration = $this->storage->migration_health();
        $rollups = $this->storage->rollup_health();
        $table = $this->storage->table_health();
        $persistence = $this->storage->persistence_health();
        $active_plugins = get_option( 'active_plugins', [] );
        $active_plugins = is_array( $active_plugins ) ? $active_plugins : [];

        $bundle = [
            'schema'       => 'wp-flame-support.v1',
            'generated_at' => gmdate( 'c' ),
            'redacted'     => true,
            'excludes'     => [
                'site_url',
                'trace_rows',
                'request_paths',
                'sql',
                'http_urls',
                'graphql_documents',
                'user_ids',
                'ip_addresses',
                'user_agents',
                'license_data',
            ],
            'runtime'      => [
                'wp_flame'        => defined( 'WP_FLAME_VERSION' ) ? $this->limit_string( (string) WP_FLAME_VERSION, 64 ) : 'unknown',
                'wordpress'       => function_exists( 'get_bloginfo' ) ? $this->limit_string( Config::string_value( get_bloginfo( 'version' ), '' ), 64 ) : 'unknown',
                'php'             => $this->limit_string( PHP_VERSION, 64 ),
                'multisite'       => function_exists( 'is_multisite' ) && is_multisite(),
                'environment'     => function_exists( 'wp_get_environment_type' ) ? $this->limit_string( Config::string_value( wp_get_environment_type(), '' ), 32 ) : 'unknown',
                'schema'          => Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX ),
                'trace_schema'    => Trace::SCHEMA_VERSION,
                'score_version'   => Score::VERSION,
                'rollup_version'  => Rollup::VERSION,
            ],
            'capture'      => [
                'automatic_enabled' => Config::boolean( get_option( 'wp_flame_enabled', false ) ),
                'mode'              => $this->limit_string( Config::string_value( get_option( 'wp_flame_instrumentation_mode', 'safe' ), 'safe' ), 20 ),
                'retention_days'    => $retention_days,
                'sensitive_acknowledged' => Config::boolean( get_option( 'wp_flame_sensitive_data_acknowledged', false ) ),
            ],
            'health'       => [
                'mu_plugin'    => $this->limit_string( Config::string_value( get_option( 'wp_flame_mu_plugin_state', 'unknown' ), 'unknown' ), 40 ),
                'table'        => $this->limit_string( Config::string_value( $table['status'] ?? 'unknown', 'unknown' ), 40 ),
                'migration'    => $this->limit_string( Config::string_value( $migration['status'] ?? 'unknown', 'unknown' ), 40 ),
                'migration_current' => Config::bounded_int( $migration['current'] ?? 0, 0, 0, PHP_INT_MAX ),
                'migration_target'  => Config::bounded_int( $migration['target'] ?? Storage::SCHEMA_VERSION, Storage::SCHEMA_VERSION, 0, PHP_INT_MAX ),
                'persistence_failures' => Config::bounded_int( $persistence['count'] ?? 0, 0, 0, PHP_INT_MAX ),
                'persistence_last_status' => $this->limit_string( Config::string_value( $persistence['last_status'] ?? '', '' ), 40 ),
                'trace_count'  => Config::bounded_int( $storage['count'] ?? 0, 0, 0, PHP_INT_MAX ),
                'trace_bytes'  => Config::bounded_int( $storage['bytes'] ?? 0, 0, 0, PHP_INT_MAX ),
                'quota_reached' => ! empty( $storage['quota']['reached'] ),
                'rollup_pending' => Config::bounded_int( $rollups['pending'] ?? 0, 0, 0, PHP_INT_MAX ),
            ],
            'components'   => [
                'active_plugin_count' => count( $active_plugins ),
                'details_included'    => false,
            ],
            'external_requests' => [
                'wp_flame_service_required' => false,
                'telemetry_enabled'         => false,
                'licensing_present'         => false,
            ],
        ];

        if ( ! empty( $assoc_args['include-components'] ) ) {
            $bundle['components'] = $this->component_details( $active_plugins );
        }

        $json = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        call_user_func( [ '\\WP_CLI', 'log' ], is_string( $json ) ? $json : '{}' );
    }

    /**
     * Run a resumable operation across a multisite network.
     *
     * ## OPTIONS
     *
     * <operation>
     * : One of activate, migrate, prune, or deactivate.
     *
     * [--until-complete]
     * : Continue ten-site batches until the operation is complete.
     *
     * [--max-runs=<number>]
     * : Safety limit with --until-complete. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp flame network migrate --until-complete
     *     wp flame network prune --until-complete
     */
    public function network( $args, $assoc_args ): void
    {
        if ( ! is_multisite() ) {
            call_user_func( [ '\\WP_CLI', 'error' ], 'This command requires WordPress multisite.' );
            return;
        }

        $operation = Config::string_value( $args[0] ?? '', '' );
        if ( ! in_array( $operation, [ 'activate', 'migrate', 'prune', 'deactivate' ], true ) ) {
            call_user_func( [ '\\WP_CLI', 'error' ], 'Choose activate, migrate, prune, or deactivate.' );
            return;
        }

        NetworkMaintenance::start( $operation );
        $max_runs = ! empty( $assoc_args['until-complete'] )
            ? Config::bounded_int( $assoc_args['max-runs'] ?? 100, 100, 1, 10000 )
            : 1;
        $state = [];
        for ( $run = 0; $run < $max_runs; $run++ ) {
            $state = NetworkMaintenance::run_batch( '\\wp_flame_network_site_operation' );
            if ( Config::string_value( $state['status'] ?? '', '' ) !== 'pending' ) {
                break;
            }
        }

        $status = Config::string_value( $state['status'] ?? 'unknown', 'unknown' );
        $processed = Config::bounded_int( $state['processed'] ?? 0, 0, 0, PHP_INT_MAX );
        $failures = isset( $state['failures'] ) && is_array( $state['failures'] ) ? count( $state['failures'] ) : 0;
        if ( $status === 'complete' && $failures === 0 ) {
            call_user_func( [ '\\WP_CLI', 'success' ], "Network {$operation} completed after {$processed} site visit(s)." );
            return;
        }

        call_user_func( [ '\\WP_CLI', 'error' ], "Network {$operation} stopped with status {$status}; {$processed} site visit(s), {$failures} failure(s)." );
    }

    /**
     * @param mixed $row
     * @return array<string, mixed>
     */
    private function format_trace_row($row): array
    {
        if (! is_array($row)) {
            $row = [];
        }

        $duration_ms = max(0.0, $this->number($row['total_ms'] ?? 0, 0.0));
        $memory      = Config::bounded_int($row['peak_memory'] ?? 0, 0, 0, PHP_INT_MAX);

        return [
            'trace_id' => $this->limit_string(Config::string_value($row['trace_id'] ?? '', ''), self::MAX_TRACE_ID_BYTES),
            'url'      => $this->limit_string(Config::string_value($row['url'] ?? '', ''), self::MAX_URL_BYTES),
            'method'   => $this->limit_string(Config::string_value($row['method'] ?? '', ''), self::MAX_METHOD_BYTES),
            'duration' => round($duration_ms, 1) . 'ms',
            'queries'  => Config::bounded_int($row['query_count'] ?? 0, 0, 0, PHP_INT_MAX),
            'memory'   => round($memory / 1048576, 1) . 'MB',
            'date'     => $this->limit_string(Config::string_value($row['created_at'] ?? '', ''), self::MAX_DATE_BYTES),
        ];
    }

    /**
     * @param array<int, mixed> $active_plugins
     * @return array<string, mixed>
     */
    private function component_details( array $active_plugins ): array
    {
        $plugins = [];
        if ( function_exists( 'get_plugins' ) ) {
            $metadata = get_plugins();
            $metadata = is_array( $metadata ) ? $metadata : [];
            foreach ( array_slice( $active_plugins, 0, 250 ) as $basename ) {
                $basename = $this->limit_string( Config::string_value( $basename, '' ), 255 );
                if ( $basename === '' ) {
                    continue;
                }

                $plugin = isset( $metadata[ $basename ] ) && is_array( $metadata[ $basename ] ) ? $metadata[ $basename ] : [];
                $plugins[] = [
                    'basename' => $basename,
                    'version'  => $this->limit_string( Config::string_value( $plugin['Version'] ?? '', '' ), 64 ),
                ];
            }
        }

        $theme = [];
        if ( function_exists( 'wp_get_theme' ) ) {
            $active_theme = wp_get_theme();
            if ( is_object( $active_theme ) && method_exists( $active_theme, 'get_stylesheet' ) && method_exists( $active_theme, 'get' ) ) {
                $theme = [
                    'stylesheet' => $this->limit_string( Config::string_value( $active_theme->get_stylesheet(), '' ), 255 ),
                    'version'    => $this->limit_string( Config::string_value( $active_theme->get( 'Version' ), '' ), 64 ),
                ];
            }
        }

        return [
            'active_plugin_count' => count( $active_plugins ),
            'details_included'    => true,
            'active_plugins'      => $plugins,
            'active_theme'        => $theme,
        ];
    }

    /**
     * @param mixed $value
     */
    private function number($value, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : $fallback;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $number = (float) trim($value);
            return is_finite($number) ? $number : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed        $value
     * @param array<int,string> $allowed
     */
    private function format_arg( $value, array $allowed, string $fallback ): string
    {
        $format = strtolower( trim( Config::string_value( $value, $fallback ) ) );

        return in_array( $format, $allowed, true ) ? $format : $fallback;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string>               $fields
     */
    private function format_items( string $format, array $items, array $fields ): void
    {
        \WP_CLI\Utils\format_items( $format, $items, $fields );
    }

    private function limit_string(string $value, int $max_bytes): string
    {
        if (strlen($value) <= $max_bytes) {
            return $value;
        }

        return substr($value, 0, $max_bytes);
    }
}
