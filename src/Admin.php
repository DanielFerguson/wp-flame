<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin
{
    private const MAX_TRACE_ID_BYTES = 36;
    private const MAX_REQUEST_STRING_BYTES = 2048;

    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_action('admin_menu', [ $this, 'add_menu' ]);
        add_action('admin_notices', [ $this, 'render_notices' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
        add_action('admin_post_wp_flame_start_capture', [ $this, 'start_capture_session' ]);
        add_action('admin_post_wp_flame_stop_capture', [ $this, 'stop_capture_session' ]);
        add_action('admin_post_wp_flame_export_comparison', [ $this, 'export_comparison' ]);
    }

    public function start_capture_session(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wp-flame' ) );
        }
        check_admin_referer( 'wp_flame_start_capture' );

        $mode = $this->request_string( $_POST, 'mode', 20 );
        $mode = $mode === 'deep' ? 'deep' : 'standard';
        $phase = $this->request_string( $_POST, 'capture_phase', 20 );
        if ( ! in_array( $phase, [ 'observation', 'baseline', 'after' ], true ) ) {
            $phase = 'observation';
        }
        $requested = $mode === 'deep'
            ? 1
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; value is bounded immediately.
            : Config::bounded_int( $_POST['request_count'] ?? 10, 10, 1, CaptureSession::MAX_STANDARD_REQUESTS );
        $ttl = $mode === 'deep' ? CaptureSession::MAX_DEEP_TTL_SECONDS : CaptureSession::MAX_STANDARD_TTL_SECONDS;
        $session_id = wp_generate_uuid4();
        $session = new CaptureSession( [
            'id'                   => $session_id,
            'capture_policy'       => $mode === 'deep' ? 'guided_deep_one_shot' : 'guided_standard_session',
            'instrumentation_mode' => $mode,
            'requested_count'      => $requested,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; value is enum-bounded immediately.
            'request_type'         => CaptureSession::browser_request_type( $_POST['request_population'] ?? 'frontend' ),
            'expires_at'           => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
            'phase'                => $phase,
        ] );

        if ( ! $this->storage->save_capture_session( $session ) ) {
            set_transient( 'wp_flame_capture_start_error_' . get_current_user_id(), true, 60 );
            wp_safe_redirect( admin_url( 'tools.php?page=wp-flame' ) );
            exit;
        }

        $nonce = wp_create_nonce( Instrumentation::CAPTURE_SESSION_NONCE_PREFIX . $session_id );
        setcookie( 'wp_flame_capture_session', $session_id . '.' . $nonce, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ] );
        set_transient( 'wp_flame_capture_started_' . get_current_user_id(), $session_id, 60 );
        wp_safe_redirect( admin_url( 'tools.php?page=wp-flame' ) );
        exit;
    }

    public function stop_capture_session(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wp-flame' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Session ID is needed to select the nonce action and is bounded before verification.
        $session_id = $this->request_string( $_POST, 'session_id', 36 );
        check_admin_referer( 'wp_flame_stop_capture_' . $session_id );
        if ( $session_id !== '' ) {
            $this->storage->cancel_capture_session( $session_id );
        }
        if ( function_exists( '\wp_flame_clear_capture_session_cookie' ) ) {
            \wp_flame_clear_capture_session_cookie();
        }
        wp_safe_redirect( admin_url( 'tools.php?page=wp-flame' ) );
        exit;
    }

    public function export_comparison(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wp-flame' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- IDs are needed to select the nonce action and are UUID-validated before verification.
        $baseline_id = $this->request_string( $_POST, 'baseline_session', 36 );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- IDs are needed to select the nonce action and are UUID-validated before verification.
        $after_id = $this->request_string( $_POST, 'after_session', 36 );
        if (
            ! preg_match( '/^[a-f0-9-]{36}$/i', $baseline_id )
            || ! preg_match( '/^[a-f0-9-]{36}$/i', $after_id )
        ) {
            wp_die( esc_html__( 'Invalid comparison sessions.', 'wp-flame' ) );
        }
        check_admin_referer( 'wp_flame_export_comparison_' . $baseline_id . '_' . $after_id );
        $baseline_traces = $this->storage->get_capture_session_traces( $baseline_id, 100 );
        $after_traces = $this->storage->get_capture_session_traces( $after_id, 100 );
        $environments = [];
        foreach ( array_merge( $baseline_traces, $after_traces ) as $trace ) {
            $snapshot_id = $trace->capture_report->environment_snapshot_id;
            if ( $snapshot_id === null || isset( $environments[ $snapshot_id ] ) ) {
                continue;
            }
            $snapshot = $this->storage->get_environment_snapshot( $snapshot_id );
            if ( $snapshot !== null ) {
                $environments[ $snapshot_id ] = $snapshot;
            }
        }
        $comparison = CohortComparison::compare( $baseline_traces, $after_traces, $environments );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; boolean is normalized immediately.
        $include_sensitive = Config::boolean( $_POST['include_sensitive'] ?? false );
        $json = ComparisonReport::json( $comparison, $include_sensitive );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wp-flame-comparison-' . gmdate( 'Ymd-His' ) . '.json"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download is encoded by ComparisonReport and served as application/json.
        exit;
    }

    public function add_menu(): void
    {
        add_management_page(
            __('WP Flame', 'wp-flame'),
            __('WP Flame', 'wp-flame'),
            'manage_options',
            'wp-flame',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-flame'));
        }

        $this->handle_delete();

        $trace_id = $this->request_string($_GET, 'trace_id', self::MAX_TRACE_ID_BYTES);

        if ($trace_id) {
            $view = new \WPFlame\Admin\FlameGraphView($this->storage);
            $view->render($trace_id);
        } else {
            $view = new \WPFlame\Admin\ListView($this->storage);
            $view->render();
        }
    }

    public function handle_delete(): void
    {
        if (! isset($_POST['wp_flame_delete_trace'])) {
            return;
        }

        $trace_id = $this->request_string($_POST, 'wp_flame_delete_trace', self::MAX_TRACE_ID_BYTES);
        if ($trace_id === '') {
            wp_die(esc_html__('Invalid trace ID.', 'wp-flame'));
        }

        check_admin_referer('wp_flame_delete_' . $trace_id);

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'wp-flame'));
        }

        $this->storage->delete_trace($trace_id);

        set_transient('wp_flame_deleted_' . get_current_user_id(), true, 30);
        wp_safe_redirect(admin_url('tools.php?page=wp-flame'));
        exit;
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'tools_page_wp-flame') {
            return;
        }

        wp_enqueue_style(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/css/admin.css',
            [],
            WP_FLAME_VERSION
        );

        wp_enqueue_script(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/js/admin.js',
            [],
            WP_FLAME_VERSION,
            true
        );

        if ($this->request_string($_GET, 'trace_id') !== '') {
            wp_enqueue_script(
                'wp-flame-graph',
                WP_FLAME_URL . 'assets/js/flame-graph.js',
                [],
                WP_FLAME_VERSION,
                true
            );
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function request_string(array $source, string $key, int $max_bytes = self::MAX_REQUEST_STRING_BYTES): string
    {
        if (! array_key_exists($key, $source)) {
            return '';
        }

        return $this->limit_string(
            sanitize_text_field(Config::string_value(wp_unslash($source[$key]), '')),
            $max_bytes
        );
    }

    private function limit_string(string $value, int $max_bytes): string
    {
        if (strlen($value) <= $max_bytes) {
            return $value;
        }

        return substr($value, 0, $max_bytes);
    }

    public function render_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $mu_dir = function_exists( '\wp_flame_mu_plugin_dir' ) ? \wp_flame_mu_plugin_dir() : ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
        $mu_file = $mu_dir !== '' ? $mu_dir . '/wp-flame-early-hooks.php' : '';
        $mu_state = Config::string_value( get_option( 'wp_flame_mu_plugin_state', '' ), '' );
        $mu_failed = Config::boolean( get_option( 'wp_flame_mu_plugin_failed', false ) );
        $mu_expected_hash = function_exists( '\wp_flame_mu_plugin_expected_hash' ) ? \wp_flame_mu_plugin_expected_hash() : '';
        $mu_actual_hash = $mu_file !== '' ? MuPluginManager::file_hash( $mu_file ) : '';
        if ( $mu_expected_hash !== '' && $mu_actual_hash !== '' && ! hash_equals( $mu_expected_hash, $mu_actual_hash ) ) {
            $mu_failed = true;
            $mu_state = MuPluginManager::MODIFIED;
        }
        if ( $mu_failed || $mu_file === '' || ! file_exists( $mu_file ) ) {
            $recovery = __( 'Check that wp-content/mu-plugins is writable, then reactivate WP Flame or copy its early-hooks file manually.', 'wp-flame' );
            if ( $mu_state === MuPluginManager::UNOWNED_COLLISION ) {
                $recovery = __( 'A different file already uses wp-flame-early-hooks.php. Move or rename that file only after confirming who owns it, then reactivate WP Flame.', 'wp-flame' );
            } elseif ( $mu_state === MuPluginManager::MODIFIED ) {
                $recovery = __( 'The installed early-hooks file changed after WP Flame verified it. Review the change, then remove the file and reactivate WP Flame if replacement is safe.', 'wp-flame' );
            }

            echo '<div class="notice notice-warning"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: recovery guidance for early capture */
                    __('<strong>WP Flame</strong> is running in limited mode &mdash; early lifecycle timing is unavailable. %s', 'wp-flame'),
                    esc_html( $recovery )
                )
            );
            echo '</p></div>';
        }

        global $wpdb;
        $mode = Config::string_value(get_option('wp_flame_instrumentation_mode', 'standard'), 'standard');
        if ($this->should_show_db_layer_notice($wpdb ?? null, $mode)) {
            echo '<div class="notice notice-info"><p>';
            echo wp_kses_post(__('<strong>WP Flame</strong>: DB query instrumentation is disabled &mdash; the database layer is unavailable or modified.', 'wp-flame'));
            echo '</p></div>';
        }

        $table_health = $this->storage->table_health();
        if ( $table_health['status'] !== 'ready' ) {
            echo '<div class="notice notice-error"><p>';
            echo wp_kses_post( sprintf(
                /* translators: %s: bounded database error or missing-table message */
                __( '<strong>WP Flame:</strong> Trace storage is unavailable. Automatic and manual captures will not be retained. Run <code>wp flame migrate</code> or reactivate the plugin, then check database permissions. %s', 'wp-flame' ),
                esc_html( $table_health['message'] )
            ) );
            echo '</p></div>';
            return;
        }

        $migration = $this->storage->migration_health();
        if ( $migration['status'] !== 'complete' ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Flame storage migration is pending or failed. Capture remains limited while bounded background migration retries; run wp flame migrate for an explicit retry.', 'wp-flame' ) . '</p></div>';
        }

        $retention_days = Config::bounded_int( get_option( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ), Config::DEFAULT_RETENTION_DAYS, 1, Config::MAX_RETENTION_DAYS );
        $storage_health = $this->storage->get_storage_health( $retention_days );
        if ( ! empty( $storage_health['quota']['reached'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Flame storage quota is reached. Automatic capture is paused; purge traces or increase the per-site quota before retrying a manual capture.', 'wp-flame' ) . '</p></div>';
        } elseif ( $storage_health['oldest_expired'] !== '' ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Flame has an expired-trace cleanup backlog. Bounded continuation jobs are scheduled; WP-CLI can finish it with wp flame prune --until-complete.', 'wp-flame' ) . '</p></div>';
        }

        $persistence = $this->storage->persistence_health();
        if ( $persistence['count'] >= 3 ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Flame has repeatedly failed to persist traces. The monitored response is unaffected; check database availability, permissions, and free disk space before retrying.', 'wp-flame' ) . '</p></div>';
        }

        $rollups = $this->storage->rollup_health();
        if ( $rollups['pending'] > Storage::ROLLUP_BACKFILL_BATCH_LIMIT * 4 ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Flame trend summaries are catching up in bounded background batches. Raw traces remain available; run wp flame rollups --until-complete to finish immediately.', 'wp-flame' ) . '</p></div>';
        }
    }

    /**
     * @param mixed $wpdb
     */
    private function should_show_db_layer_notice($wpdb, string $mode): bool
    {
        if ($mode === 'safe') {
            return false;
        }

        if ($wpdb instanceof DB) {
            return false;
        }

        if ($wpdb instanceof \wpdb && get_class($wpdb) === 'wpdb') {
            return false;
        }

        return true;
    }
}
