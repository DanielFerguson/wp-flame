<?php
/**
 * Plugin Name: WP Flame
 * Description: Inspect observed WordPress request time with local, bounded flame-style traces.
 * Version:     1.3.0-rc.1
 * Author:      Chepstowe Consulting
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-flame
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package WPFlame
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_FLAME_VERSION', '1.3.0-rc.1' );
define( 'WP_FLAME_FILE', __FILE__ );
define( 'WP_FLAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_FLAME_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader (may already be loaded by mu-plugin)
$wp_flame_autoloader = WP_FLAME_DIR . 'vendor/autoload.php';
if ( file_exists( $wp_flame_autoloader ) ) {
    require_once $wp_flame_autoloader;
}

// --- Activation / Deactivation hooks ---

register_activation_hook( __FILE__, 'wp_flame_activate' );
register_deactivation_hook( __FILE__, 'wp_flame_deactivate' );
add_action( 'wp_initialize_site', 'wp_flame_initialize_new_site', 10, 1 );

function wp_flame_activate( bool $network_wide = false ): void {
    if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
        WPFlame\NetworkMaintenance::start( 'activate' );
        wp_flame_run_network_maintenance();
    } else {
        wp_flame_activate_site();
    }

    wp_flame_install_mu_plugin();
}

/**
 * @return array<int, int>
 */
function wp_flame_all_site_ids(): array {
    if ( ! function_exists( 'get_sites' ) ) {
        return [];
    }

    $site_ids = get_sites( [
        'fields' => 'ids',
        'number' => 0,
    ] );

    if ( ! is_array( $site_ids ) ) {
        return [];
    }

    return array_map( 'intval', $site_ids );
}

function wp_flame_activate_site(): void {
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->create_table();
    update_option( 'wp_flame_schema_version', WPFlame\Storage::SCHEMA_VERSION );
    update_option( 'wp_flame_plugin_file', plugin_basename( WP_FLAME_FILE ) );
    if ( is_multisite() ) {
        update_site_option( 'wp_flame_plugin_file', plugin_basename( WP_FLAME_FILE ) );
    }

    // Schedule daily prune
    if ( ! wp_next_scheduled( 'wp_flame_prune_traces' ) ) {
        wp_schedule_event( time(), 'daily', 'wp_flame_prune_traces' );
    }

    // Set default options (add_option won't overwrite existing values)
    add_option( 'wp_flame_enabled', false );
    add_option( 'wp_flame_trace_audience', 'admins' );
    add_option( 'wp_flame_sample_rate', WPFlame\Config::DEFAULT_SAMPLE_RATE );
    add_option( 'wp_flame_instrumentation_mode', 'standard' );
    add_option( 'wp_flame_retention_days', WPFlame\Config::DEFAULT_RETENTION_DAYS );
    add_option( 'wp_flame_sensitive_data_acknowledged', false );
    add_option( 'wp_flame_full_query_text', false );
    add_option( 'wp_flame_full_http_url', false );
    add_option( 'wp_flame_full_graphql_query', false );
    add_option( 'wp_flame_min_callback_ms', 0.5 );
    add_option( 'wp_flame_budget_max_ms', 500 );
    add_option( 'wp_flame_budget_max_queries', 100 );
    add_option( 'wp_flame_max_spans', WPFlame\Config::DEFAULT_MAX_SPANS );
    add_option( 'wp_flame_max_trace_bytes', WPFlame\Config::DEFAULT_MAX_TRACE_BYTES );
    add_option( 'wp_flame_storage_quota_rows', WPFlame\Config::DEFAULT_STORAGE_QUOTA_ROWS );
    add_option( 'wp_flame_storage_quota_mb', WPFlame\Config::DEFAULT_STORAGE_QUOTA_MB );
    add_option( 'wp_flame_track_users', false );
    add_option( 'wp_flame_track_ips', false );
    add_option( 'wp_flame_track_user_agent', false );
}

function wp_flame_initialize_new_site( $new_site ): void {
    if ( ! is_multisite() || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
        return;
    }

    $plugin_file = plugin_basename( WP_FLAME_FILE );
    if ( ! WPFlame\Instrumentation::is_network_active_plugin( $plugin_file, (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) {
        return;
    }

    $blog_id = 0;
    if ( is_object( $new_site ) && isset( $new_site->blog_id ) ) {
        $blog_id = (int) $new_site->blog_id;
    } elseif ( is_numeric( $new_site ) ) {
        $blog_id = (int) $new_site;
    }

    if ( $blog_id <= 0 ) {
        return;
    }

    switch_to_blog( $blog_id );
    try {
        wp_flame_activate_site();
    } finally {
        restore_current_blog();
    }
}

function wp_flame_mu_plugin_dir(): string {
    return defined( 'WPMU_PLUGIN_DIR' ) && is_string( WPMU_PLUGIN_DIR ) && WPMU_PLUGIN_DIR !== ''
        ? WPMU_PLUGIN_DIR
        : '';
}

function wp_flame_mu_plugin_expected_hash(): string {
    $hash = is_multisite()
        ? get_site_option( 'wp_flame_mu_plugin_hash', '' )
        : get_option( 'wp_flame_mu_plugin_hash', '' );

    return WPFlame\Config::string_value( $hash, '' );
}

function wp_flame_record_mu_plugin_state( string $status, string $hash = '' ): void {
    update_option( 'wp_flame_mu_plugin_state', $status );
    if ( is_multisite() ) {
        update_site_option( 'wp_flame_mu_plugin_state', $status );
    }

    $healthy = in_array( $status, [ WPFlame\MuPluginManager::INSTALLED, WPFlame\MuPluginManager::CURRENT ], true );
    if ( $healthy ) {
        update_option( 'wp_flame_mu_plugin_hash', $hash );
        delete_option( 'wp_flame_mu_plugin_failed' );
        if ( is_multisite() ) {
            update_site_option( 'wp_flame_mu_plugin_hash', $hash );
            delete_site_option( 'wp_flame_mu_plugin_failed' );
        }
        return;
    }

    update_option( 'wp_flame_mu_plugin_failed', true );
    if ( is_multisite() ) {
        update_site_option( 'wp_flame_mu_plugin_failed', true );
    }
}

/**
 * Exact hashes of WP Flame early loaders shipped before the ownership marker.
 *
 * Unknown or modified collisions are never adopted. This list permits a
 * one-time atomic upgrade from known development/pre-RC 1.x builds.
 *
 * @return array<int, string>
 */
function wp_flame_legacy_mu_plugin_hashes(): array {
    return [
        '3f94a5b9ddcaf341b5a8ffa80512fb0dd6a8715da75f7b094f19e07127e12684',
        '0d8bf3a7d60be7849cdfddb82b069e2a10e9df15c3d21cd508e1be41615b3906',
        'd90a604b51c1e71719202fc2ff6fa102e6960c58dbb746aa55930cb294917c35',
        '980a6638091b83b0854348c09bf0a10ec7098cdbd7f9cd04393ef0f3dd39dd15',
        '25e28664d8349f4f400c2ba3ae9f3065dd882f7171ccbffb414e0f6bb66e0c85',
        '08140a4982d1cc77f5ceb46f73c2e4311bdfc699582fea54216a62d9bffef850',
        '184cc8c5ba1b6743707208b5bc6ba35fe251a35b8a5effda4ae707326bd70650',
    ];
}

function wp_flame_install_mu_plugin(): void {
    $mu_dir = wp_flame_mu_plugin_dir();
    if ( $mu_dir === '' ) {
        wp_flame_record_mu_plugin_state( WPFlame\MuPluginManager::WRITE_FAILED );
        return;
    }

    $mu_src  = WP_FLAME_DIR . 'mu-plugin/wp-flame-early-hooks.php';
    $mu_dest = $mu_dir . '/wp-flame-early-hooks.php';

    if ( ! is_dir( $mu_dir ) ) {
        wp_mkdir_p( $mu_dir );
    }

    $result = WPFlame\MuPluginManager::install( $mu_src, $mu_dest, wp_flame_mu_plugin_expected_hash(), wp_flame_legacy_mu_plugin_hashes() );
    wp_flame_record_mu_plugin_state( $result['status'], $result['hash'] );
}

function wp_flame_deactivate( bool $network_wide = false ): void {
    if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
        WPFlame\NetworkMaintenance::start( 'deactivate' );
        wp_flame_run_network_maintenance();
    } else {
        wp_flame_deactivate_site();
    }

    // Remove mu-plugin only when no other activation still needs it.
    $mu_dir = wp_flame_mu_plugin_dir();
    if ( $mu_dir === '' ) {
        return;
    }

    $mu_file = $mu_dir . '/wp-flame-early-hooks.php';
    $still_needed = $network_wide ? wp_flame_is_active_on_any_site() : wp_flame_is_active_elsewhere();
    if ( ! $still_needed && file_exists( $mu_file ) ) {
        $result = WPFlame\MuPluginManager::remove( $mu_file, wp_flame_mu_plugin_expected_hash() );
        wp_flame_record_mu_plugin_state( $result['status'], $result['hash'] );
    }
}

function wp_flame_deactivate_site(): void {
    // Clear recurring and resumable cleanup events.
    foreach ( [ 'wp_flame_prune_traces', 'wp_flame_prune_traces_continue', 'wp_flame_run_migration', 'wp_flame_rollup_backfill' ] as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
}

/**
 * @return array{status: string, message: string}
 */
function wp_flame_network_site_operation( string $operation, int $blog_id ): array {
    unset( $blog_id );
    if ( $operation === 'activate' ) {
        wp_flame_activate_site();
        return [
            'status'  => 'complete',
            'message' => '',
        ];
    }

    if ( $operation === 'deactivate' ) {
        wp_flame_deactivate_site();
        return [
            'status'  => 'complete',
            'message' => '',
        ];
    }

    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    if ( $operation === 'migrate' ) {
        $result = $storage->maybe_upgrade();
        if ( $result['status'] === 'complete' ) {
            return [
                'status'  => 'complete',
                'message' => '',
            ];
        }

        return [
            'status'  => in_array( $result['status'], [ 'pending', 'locked' ], true ) ? 'retry' : 'failed',
            'message' => $result['message'],
        ];
    }

    if ( $operation === 'prune' ) {
        $days = WPFlame\Config::bounded_int(
            get_option( 'wp_flame_retention_days', WPFlame\Config::DEFAULT_RETENTION_DAYS ),
            WPFlame\Config::DEFAULT_RETENTION_DAYS,
            1,
            WPFlame\Config::MAX_RETENTION_DAYS
        );
        $result = $storage->run_retention_cleanup( $days );
        return [
            'status'  => $result['backlog'] ? 'retry' : 'complete',
            'message' => '',
        ];
    }

    return [
        'status'  => 'failed',
        'message' => 'Unsupported network operation.',
    ];
}

function wp_flame_run_network_maintenance(): void {
    if ( ! is_multisite() ) {
        return;
    }

    WPFlame\NetworkMaintenance::run_batch( 'wp_flame_network_site_operation' );
}

add_action( WPFlame\NetworkMaintenance::CRON_HOOK, 'wp_flame_run_network_maintenance' );

function wp_flame_is_active_elsewhere(): bool {
    return wp_flame_has_active_site_plugin( true, function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
}

function wp_flame_is_active_on_any_site(): bool {
    return wp_flame_has_active_site_plugin( false, 0 );
}

function wp_flame_has_active_site_plugin( bool $include_network_active, int $exclude_blog_id ): bool {
    if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
        return false;
    }

    $plugin_file = plugin_basename( WP_FLAME_FILE );
    $network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
    if ( $include_network_active && WPFlame\Instrumentation::is_network_active_plugin( $plugin_file, $network_plugins ) ) {
        return true;
    }

    foreach ( wp_flame_all_site_ids() as $blog_id ) {
        if ( $exclude_blog_id > 0 && (int) $blog_id === $exclude_blog_id ) {
            continue;
        }

        switch_to_blog( (int) $blog_id );
        try {
            $active_plugins = (array) get_option( 'active_plugins', [] );
        } finally {
            restore_current_blog();
        }

        if ( WPFlame\Instrumentation::is_site_active_plugin( $plugin_file, $active_plugins ) ) {
            return true;
        }
    }

    return false;
}

function wp_flame_get_client_ip(): string {
    $headers = apply_filters( 'wp_flame_client_ip_headers', [ 'REMOTE_ADDR' ] );
    if ( ! is_array( $headers ) ) {
        $headers = [ 'REMOTE_ADDR' ];
    }

    return WPFlame\Instrumentation::client_ip_from_server( $_SERVER, array_values( $headers ) );
}

// --- Request type detection ---

/**
 * Detect the current request type for phase map selection.
 */
function wp_flame_detect_request_type(): string
{
    $rest_prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPGraphQL owns this public hook.
    $graphql_endpoint = apply_filters( 'graphql_endpoint', 'graphql' );

    return WPFlame\RequestClassifier::detect(
        [
            'is_cli'          => defined( 'WP_CLI' ) && WP_CLI,
            'is_cron'         => wp_flame_doing_cron(),
            'is_ajax'         => defined( 'DOING_AJAX' ) && DOING_AJAX,
            'is_rest'         => defined( 'REST_REQUEST' ) && REST_REQUEST,
            'is_graphql'      => defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST,
            'is_admin'        => function_exists( 'is_admin' ) && is_admin(),
            'rest_prefix'     => WPFlame\Config::string_value( $rest_prefix, 'wp-json' ),
            'rest_route'      => $GLOBALS['wp_flame_matched_rest_route'] ?? '',
            'graphql_endpoint' => WPFlame\Config::string_value( $graphql_endpoint, 'graphql' ),
        ],
        $_SERVER,
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Request classification is read-only.
        $_REQUEST
    );
}

function wp_flame_doing_cron(): bool {
    if ( function_exists( 'wp_doing_cron' ) ) {
        return wp_doing_cron();
    }

    return defined( 'DOING_CRON' ) && DOING_CRON;
}

function wp_flame_cli_command_name(): string {
    if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
        return '';
    }

    if ( isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ) {
        $parts = array_slice( array_values( $GLOBALS['argv'] ), 1, 3 );
        return implode( ' ', array_map( 'strval', $parts ) );
    }

    return '';
}

function wp_flame_resolve_route_key( string $request_type ): string {
    $method = isset( $_SERVER['REQUEST_METHOD'] )
        ? WPFlame\Config::string_value( wp_unslash( $_SERVER['REQUEST_METHOD'] ), 'GET' )
        : 'GET';
    $uri = isset( $_SERVER['REQUEST_URI'] )
        ? WPFlame\Config::string_value( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' )
        : '/';
    $rest_prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';

    return WPFlame\RouteResolver::resolve(
        $request_type,
        $method,
        $uri,
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route identity is read-only and bounded.
        $_REQUEST,
        [
            'rest_prefix'       => WPFlame\Config::string_value( $rest_prefix, 'wp-json' ),
            'matched_rest_route' => $GLOBALS['wp_flame_matched_rest_route'] ?? '',
            'graphql_operation' => $GLOBALS['wp_flame_graphql_operation_name'] ?? '',
            'cli_command'       => wp_flame_cli_command_name(),
            'cron_event'        => $GLOBALS['wp_flame_cron_event'] ?? '',
        ]
    );
}

function wp_flame_is_self_observation_request( string $request_type ): bool {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Eligibility check does not mutate state.
    $excluded = WPFlame\RouteResolver::is_wp_flame_request( $request_type, $_REQUEST );

    return $excluded && ! WPFlame\Config::boolean( apply_filters( 'wp_flame_allow_self_observation', false, $request_type ) );
}

function wp_flame_is_force_trace_request(): bool {
    if ( empty( $_COOKIE['wp_flame_force_trace'] ) ) {
        return false;
    }

    $cookie_value = wp_unslash( $_COOKIE['wp_flame_force_trace'] );

    return WPFlame\Instrumentation::is_force_trace_request(
        $cookie_value,
        function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
        function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
        function ( string $nonce ): bool {
            return function_exists( 'wp_verify_nonce' )
                && (bool) wp_verify_nonce( $nonce, WPFlame\Instrumentation::FORCE_TRACE_NONCE_ACTION );
        }
    );
}

function wp_flame_capture_session_for_request(): ?WPFlame\CaptureSession {
    if ( array_key_exists( 'wp_flame_capture_session_for_request', $GLOBALS ) ) {
        $cached = $GLOBALS['wp_flame_capture_session_for_request'];
        return $cached instanceof WPFlame\CaptureSession ? $cached : null;
    }

    $GLOBALS['wp_flame_capture_session_for_request'] = false;
    if ( empty( $_COOKIE['wp_flame_capture_session'] ) ) {
        return null;
    }

    $cookie_value = wp_unslash( $_COOKIE['wp_flame_capture_session'] );
    $session_id = WPFlame\Instrumentation::capture_session_id_from_cookie(
        $cookie_value,
        function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
        function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
        static function ( string $nonce, string $action ): bool {
            return function_exists( 'wp_verify_nonce' ) && (bool) wp_verify_nonce( $nonce, $action );
        }
    );
    if ( $session_id === '' ) {
        return null;
    }

    global $wpdb;
    if ( ! $wpdb instanceof \wpdb ) {
        return null;
    }

    $session = ( new WPFlame\Storage( $wpdb ) )->get_capture_session( $session_id );
    if ( ! $session instanceof WPFlame\CaptureSession || ! $session->accepts_trace() ) {
        return null;
    }

    $GLOBALS['wp_flame_capture_session_cookie_valid'] = true;
    $request_type = wp_flame_detect_request_type();
    $route_key = wp_flame_resolve_route_key( $request_type );
    $session = ( new WPFlame\Storage( $wpdb ) )->claim_capture_session_route(
        $session_id,
        $route_key,
        $request_type
    );
    if ( ! $session instanceof WPFlame\CaptureSession ) {
        return null;
    }

    $GLOBALS['wp_flame_capture_session_for_request'] = $session;
    return $session;
}

function wp_flame_should_instrument_request( ?WPFlame\Config $config = null, ?int $sample_roll = null ): bool {
    $cache_decision = $sample_roll === null;

    if ( $cache_decision && array_key_exists( 'wp_flame_should_instrument_request', $GLOBALS ) ) {
        return (bool) $GLOBALS['wp_flame_should_instrument_request'];
    }

    $config      = $config ?: WPFlame\Config::instance();
    $capture_session = wp_flame_capture_session_for_request();
    $force_trace = wp_flame_is_force_trace_request() || $capture_session instanceof WPFlame\CaptureSession;
    if ( get_option( 'wp_flame_capture_paused_reason', '' ) === 'storage_quota' && ! $force_trace ) {
        if ( $cache_decision ) {
            $GLOBALS['wp_flame_should_instrument_request'] = false;
        }
        return false;
    }

    $sample_rate = WPFlame\Config::bounded_int(
        $config->get( 'wp_flame_sample_rate', WPFlame\Config::DEFAULT_SAMPLE_RATE ),
        WPFlame\Config::DEFAULT_SAMPLE_RATE,
        1,
        WPFlame\Config::MAX_SAMPLE_RATE
    );

	if ( $sample_roll === null && isset( $GLOBALS['wp_flame_sample_roll'] ) ) {
		$sample_roll = WPFlame\Config::bounded_int( $GLOBALS['wp_flame_sample_roll'], 1, 1, $sample_rate );
	}

    if ( $sample_roll === null ) {
        $sample_roll = $sample_rate > 1
            ? ( function_exists( 'wp_rand' ) ? wp_rand( 1, $sample_rate ) : rand( 1, $sample_rate ) )
            : 1;
    }

    $decision = WPFlame\Instrumentation::should_instrument(
        WPFlame\Config::boolean( $config->get( 'wp_flame_enabled', false ) ),
        WPFlame\Config::string_value( $config->get( 'wp_flame_trace_audience', 'admins' ), 'admins' ),
        $sample_rate,
        $force_trace,
        wp_flame_doing_cron(),
        function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
        function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
        $sample_roll
    );

    if ( $cache_decision ) {
        $GLOBALS['wp_flame_should_instrument_request'] = $decision;
    }

    return $decision;
}

function wp_flame_clear_force_trace_cookie(): void {
    if ( empty( $_COOKIE['wp_flame_force_trace'] ) ) {
        return;
    }

    $paths = [ '/' ];
    if ( defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && COOKIEPATH !== '' && COOKIEPATH !== '/' ) {
        $paths[] = COOKIEPATH;
    }

    $domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
    $secure = function_exists( 'is_ssl' ) && is_ssl();
    foreach ( WPFlame\Instrumentation::force_trace_cookie_delete_options( $paths, $domain, $secure ) as $options ) {
        setcookie( 'wp_flame_force_trace', '', $options );
        setcookie( 'wp_flame_force_mode', '', $options );
    }

    unset( $_COOKIE['wp_flame_force_trace'] );
    unset( $_COOKIE['wp_flame_force_mode'] );
}

function wp_flame_clear_capture_session_cookie(): void {
    if ( empty( $_COOKIE['wp_flame_capture_session'] ) ) {
        return;
    }

    $paths = [ '/' ];
    if ( defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && COOKIEPATH !== '' && COOKIEPATH !== '/' ) {
        $paths[] = COOKIEPATH;
    }
    $domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
    $secure = function_exists( 'is_ssl' ) && is_ssl();
    foreach ( WPFlame\Instrumentation::force_trace_cookie_delete_options( $paths, $domain, $secure ) as $options ) {
        setcookie( 'wp_flame_capture_session', '', $options );
    }
    unset( $_COOKIE['wp_flame_capture_session'] );
}

function wp_flame_requested_force_mode(): string {
    if ( empty( $_COOKIE['wp_flame_force_mode'] ) ) {
        return 'standard';
    }

    $mode = WPFlame\Config::string_value( wp_unslash( $_COOKIE['wp_flame_force_mode'] ), 'standard' );

    return in_array( $mode, [ 'safe', 'standard', 'deep' ], true ) ? $mode : 'standard';
}

function wp_flame_effective_instrumentation_mode( WPFlame\Config $config ): string {
    $override = WPFlame\Config::string_value( $GLOBALS['wp_flame_request_mode_override'] ?? '', '' );
    if ( in_array( $override, [ 'safe', 'standard', 'deep' ], true ) ) {
        return $override;
    }

    $deep_expires_at = WPFlame\Config::bounded_int( get_option( 'wp_flame_deep_mode_expires_at', 0 ), 0, 0, PHP_INT_MAX );
    $now = time();
    if ( $deep_expires_at > $now && $deep_expires_at <= $now + 15 * MINUTE_IN_SECONDS ) {
        $GLOBALS['wp_flame_deep_expiring_override'] = true;
        return 'deep';
    }

    $configured = WPFlame\Settings::sanitize_instrumentation_mode( $config->get( 'wp_flame_instrumentation_mode', 'standard' ) );

    return $configured === 'deep' ? 'standard' : $configured;
}

function wp_flame_current_phase_id(): ?string {
    $phase_id = $GLOBALS['wp_flame_current_phase_id'] ?? null;

    return is_string( $phase_id ) && $phase_id !== '' ? $phase_id : null;
}

function wp_flame_transition_phase( WPFlame\Collector $collector, string $phase, string $transition_key ): void {
    if ( ! empty( $GLOBALS['wp_flame_phase_transitions'][ $transition_key ] ) ) {
        return;
    }
    $GLOBALS['wp_flame_phase_transitions'][ $transition_key ] = true;
    $collector->end_span( wp_flame_current_phase_id() );
    $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( $phase, WPFlame\Span::TYPE_CORE, 'wordpress' );
}

function wp_flame_register_plugin_services( \wpdb $wpdb ): void {
    if ( is_admin() ) {
        $admin = new WPFlame\Admin( new WPFlame\Storage( $wpdb ) );
        $admin->register();
    }

    $settings = new WPFlame\Settings( new WPFlame\Storage( $wpdb ) );
    $settings->register();

    $privacy = new WPFlame\Privacy( new WPFlame\Storage( $wpdb ) );
    $privacy->register();

    add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( is_admin() ) {
            return;
        }
        $wp_admin_bar->add_node( [
            'id'    => 'wp-flame-trace',
            'title' => '🔥 ' . esc_html__( 'Trace This Page', 'wp-flame' ),
            'href'  => '#',
        ] );
        $wp_admin_bar->add_node( [
            'id'     => 'wp-flame-trace-deep',
            'parent' => 'wp-flame-trace',
            'title'  => esc_html__( 'Run one Deep trace', 'wp-flame' ),
            'href'   => '#',
            'meta'   => [ 'class' => 'wp-flame-trace-deep' ],
        ] );
    }, 999 );

    add_action( 'wp_enqueue_scripts', function () {
        if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
            return;
        }
        wp_enqueue_script( 'wp-flame-admin-bar', WP_FLAME_URL . 'assets/js/admin-bar.js', [], WP_FLAME_VERSION, true );
        $data = wp_json_encode(
            [
                'forceTraceNonce' => wp_create_nonce( WPFlame\Instrumentation::FORCE_TRACE_NONCE_ACTION ),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ( is_string( $data ) ) {
            wp_add_inline_script( 'wp-flame-admin-bar', 'window.wpFlameAdminBar = ' . $data . ';', 'before' );
        }
    } );

	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key   = 'wp_flame_budget_violations';
		$count = WPFlame\Config::bounded_int( get_transient( $key ), 0, 0, PHP_INT_MAX );
		if ( $count > 0 ) {
			$budget_max_ms = WPFlame\Config::bounded_int( get_option( 'wp_flame_budget_max_ms', 500 ), 500, 0, PHP_INT_MAX );
			$url = admin_url( 'tools.php?page=wp-flame&min_duration=' . $budget_max_ms );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo wp_kses_post( sprintf(
                /* translators: %1$d: number of violations, %2$s: URL to view traces */
                __( '<strong>WP Flame:</strong> %1$d request(s) exceeded your performance budget in the last hour. <a href="%2$s">View slow traces &rarr;</a>', 'wp-flame' ),
                $count,
                esc_url( $url )
            ) );
            echo '</p></div>';
            delete_transient( $key );
        }
    } );

	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$trace_id = WPFlame\Instrumentation::notice_trace_id( get_transient( 'wp_flame_last_force_trace_' . get_current_user_id() ) );
		if ( $trace_id !== '' ) {
			delete_transient( 'wp_flame_last_force_trace_' . get_current_user_id() );
			$url = admin_url( 'tools.php?page=wp-flame&trace_id=' . urlencode( $trace_id ) );
			echo '<div class="notice notice-success is-dismissible"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: URL to view the flame graph */
                    __( '<strong>WP Flame:</strong> Trace captured! <a href="%s">View flame graph &rarr;</a>', 'wp-flame' ),
                    esc_url( $url )
                )
            );
            echo '</p></div>';
        }

		$error = WPFlame\Config::string_value( get_transient( 'wp_flame_last_force_trace_error_' . get_current_user_id() ), '' );
		if ( $error === WPFlame\StorageResult::QUOTA_REACHED ) {
			delete_transient( 'wp_flame_last_force_trace_error_' . get_current_user_id() );
			$url = admin_url( 'options-general.php?page=wp-flame-settings' );
			echo '<div class="notice notice-error"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: URL to WP Flame storage settings */
                    __( '<strong>WP Flame:</strong> The trace was not stored because the site storage quota is full. <a href="%s">Purge traces or increase the quota, then retry.</a>', 'wp-flame' ),
                    esc_url( $url )
                )
            );
            echo '</p></div>';
		}
    } );
}

function wp_flame_sensitive_capture_enabled( WPFlame\Config $config, string $option ): bool {
    $allowed = [
        'wp_flame_full_query_text',
        'wp_flame_full_http_url',
        'wp_flame_full_graphql_query',
        'wp_flame_track_users',
        'wp_flame_track_ips',
        'wp_flame_track_user_agent',
    ];

    return in_array( $option, $allowed, true )
        && WPFlame\Config::boolean( $config->get( 'wp_flame_sensitive_data_acknowledged', false ) )
        && WPFlame\Config::boolean( $config->get( $option, false ) );
}

function wp_flame_register_instrumentors( WPFlame\Collector $collector, WPFlame\Config $config, \wpdb $wpdb ): array {
    $full_query_text    = wp_flame_sensitive_capture_enabled( $config, 'wp_flame_full_query_text' );
    $full_http_url      = wp_flame_sensitive_capture_enabled( $config, 'wp_flame_full_http_url' );
    $full_graphql_query = wp_flame_sensitive_capture_enabled( $config, 'wp_flame_full_graphql_query' );
    $mode               = wp_flame_effective_instrumentation_mode( $config );
    $allow_db           = in_array( $mode, [ 'standard', 'deep' ], true );
    $allow_callbacks    = $mode === 'deep';

    $instrumentors = [
        new WPFlame\Http( $full_http_url ),
        new WPFlame\GraphQL( $full_query_text, $full_graphql_query, $allow_db ),
    ];
    if ( $allow_db ) {
        $instrumentors[] = new WPFlame\DbInstrumentor( $wpdb, $full_query_text );
    }
    if ( $allow_callbacks ) {
        $instrumentors[] = new WPFlame\CallbackInstrumentor( $config );
    }
    $filtered_instrumentors = apply_filters( 'wp_flame_instrumentors', $instrumentors );
    if ( is_array( $filtered_instrumentors ) ) {
        $instrumentors = $filtered_instrumentors;
    }

    $core_wpdb_compatible = WPFlame\DB::can_replace( $wpdb );
    $result = WPFlame\Instrumentation::register_instrumentors(
        $instrumentors,
        $collector,
        function (): bool {
            if ( ! defined( 'SAVEQUERIES' ) ) {
                define( 'SAVEQUERIES', true );
            }

            return defined( 'SAVEQUERIES' ) && SAVEQUERIES;
        }
    );

    if ( $result['graphql_active'] ) {
        $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
    }

    $result['database_strategy'] = $allow_db && $result['graphql_db_active']
        ? 'wordpress_savequeries_hook'
        : ( in_array( WPFlame\DbInstrumentor::class, $result['registered'], true ) ? 'core_wpdb_wrapper' : 'unavailable' );
    $result['database_unavailable_reason'] = $core_wpdb_compatible ? 'instrumentor_not_registered' : 'custom_database_layer_unsupported';

    return $result;
}

function wp_flame_register_late_phase_hooks( WPFlame\Collector $collector ): void {
    $request_type = wp_flame_detect_request_type();

    foreach ( WPFlame\Lifecycle::transitions( $request_type ) as $transition ) {
        $hook  = $transition['hook'];
        $phase = $transition['phase'];
        add_action( $hook, function ( $value = null ) use ( $collector, $hook, $phase ) {
            if ( $hook === 'pre_get_posts' && is_object( $value ) && method_exists( $value, 'is_main_query' ) && ! $value->is_main_query() ) {
                return;
            }
            wp_flame_transition_phase( $collector, $phase, $hook );
        }, 0, 1 );
    }

    if ( $request_type === 'rest' ) {
        add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) use ( $collector ) {
            if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
                $GLOBALS['wp_flame_matched_rest_route'] = WPFlame\Config::string_value( $request->get_route(), '' );
            }
            wp_flame_transition_phase( $collector, 'REST Dispatch', 'rest_request_before_callbacks' );
            return $response;
        }, 0, 3 );
    }
}

// --- Main plugin initialization ---

add_action( 'plugins_loaded', 'wp_flame_init', 0 );

function wp_flame_init(): void {
    $collector = WPFlame\Collector::instance();
    $config    = WPFlame\Config::instance();
    global $wpdb;
    $GLOBALS['wp_flame_capture_start_stage'] = $collector->is_initialized()
        && defined( 'WP_FLAME_MU_VERSION' )
        && hash_equals( WP_FLAME_VERSION, WP_FLAME_MU_VERSION )
            ? 'mu_plugin'
            : 'plugins_loaded';
    $request_type             = wp_flame_detect_request_type();
    $self_observation_request = wp_flame_is_self_observation_request( $request_type );
    $collector->set_limits(
        WPFlame\Config::bounded_int(
            $config->get( 'wp_flame_max_spans', WPFlame\Config::DEFAULT_MAX_SPANS ),
            WPFlame\Config::DEFAULT_MAX_SPANS,
            WPFlame\Config::MIN_MAX_SPANS,
            WPFlame\Config::MAX_MAX_SPANS
        )
    );

    // Check for mu-plugin version drift and auto-update if needed.
    if ( defined( 'WP_FLAME_MU_VERSION' ) && WP_FLAME_MU_VERSION !== WP_FLAME_VERSION ) {
        wp_flame_install_mu_plugin();
    }

    // Normal application requests only enqueue migration work. DDL and legacy
    // backfill batches run through cron or the explicit WP-CLI command.
    $schema_version = WPFlame\Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );
    if ( $schema_version < WPFlame\Storage::SCHEMA_VERSION ) {
        wp_flame_schedule_migration();
    }

    if ( $self_observation_request ) {
        wp_flame_register_plugin_services( $wpdb );
        if ( $collector->is_initialized() ) {
            $collector->stop();
        }
        return;
    }

    if ( ! empty( $_COOKIE['wp_flame_force_trace'] ) && ! wp_flame_is_force_trace_request() ) {
        wp_flame_clear_force_trace_cookie();
    }

    $capture_session = wp_flame_capture_session_for_request();
    if (
        ! empty( $_COOKIE['wp_flame_capture_session'] )
        && ! $capture_session instanceof WPFlame\CaptureSession
        && empty( $GLOBALS['wp_flame_capture_session_cookie_valid'] )
    ) {
        wp_flame_clear_capture_session_cookie();
    }

    if ( ! wp_flame_should_instrument_request( $config ) ) {
        wp_flame_register_plugin_services( $wpdb );
        if ( $collector->is_initialized() ) {
            $collector->stop();
        }
        return;
    }

    if ( wp_flame_is_force_trace_request() ) {
        $GLOBALS['wp_flame_force_trace_request'] = true;
        $GLOBALS['wp_flame_request_mode_override'] = wp_flame_requested_force_mode();
        wp_flame_clear_force_trace_cookie();
    } elseif ( $capture_session instanceof WPFlame\CaptureSession ) {
        $GLOBALS['wp_flame_force_trace_request'] = true;
        $GLOBALS['wp_flame_capture_session_id'] = $capture_session->id;
        $GLOBALS['wp_flame_capture_phase'] = $capture_session->phase;
        $GLOBALS['wp_flame_capture_policy'] = $capture_session->capture_policy;
        $GLOBALS['wp_flame_request_mode_override'] = $capture_session->instrumentation_mode;
    }

    // Degraded mode: if mu-plugin didn't initialize the collector, start now
    if ( ! $collector->is_initialized() ) {
        $collector->start_request( microtime( true ) );

        // The request began before WP Flame could observe it. Start an explicit
        // degraded phase rather than implying plugin bootstrap was captured.
        $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
            WPFlame\Lifecycle::initial_phase( false ),
            WPFlame\Span::TYPE_CORE,
            'wordpress'
        );
    }

    $GLOBALS['wp_flame_instrumentation_result'] = wp_flame_register_instrumentors( $collector, $config, $wpdb );
    // Register storage-backed services against the effective database object.
    // DbInstrumentor may replace the global core wpdb instance; retaining the
    // pre-replacement object can leave it sharing a closed mysqli result.
    $effective_wpdb = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb
        ? $GLOBALS['wpdb']
        : $wpdb;
    wp_flame_register_plugin_services( $effective_wpdb );
    wp_flame_register_late_phase_hooks( $collector );

    // Capture which template file WordPress selects for rendering
    add_filter( 'template_include', function ( $template ) use ( $collector ) {
        if ( wp_flame_detect_request_type() === 'frontend' ) {
            wp_flame_transition_phase( $collector, 'Template Render', 'template_include' );
        }
        $phase_id = wp_flame_current_phase_id();
        $path     = WPFlame\Config::string_value( $template, '' );
        if ( $phase_id !== null && $path !== '' ) {
            $name = basename( $path );
            $collector->add_span_meta( $phase_id, [
                'template' => $name,
            ] );
        }
        return $template;
    }, 9999 );

    // Set admin detection flag at init
    add_action( 'init', function () {
        $GLOBALS['wp_flame_is_admin_request'] = current_user_can( 'manage_options' );
    }, 0 );

    // Register shutdown handler
    add_action( 'shutdown', 'wp_flame_shutdown', 9999 );

}

/**
 * Build version context after collection has stopped so discovery work is not
 * attributed to the customer request timeline.
 */
function wp_flame_environment_snapshot(): WPFlame\EnvironmentSnapshot {
    if ( ! function_exists( 'get_plugins' ) ) {
        $plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
        if ( is_readable( $plugin_api ) ) {
            require_once $plugin_api;
        }
    }

    $all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
    $active_plugins = array_values( array_filter( array_map( 'strval', (array) get_option( 'active_plugins', [] ) ) ) );
    if ( is_multisite() ) {
        $active_plugins = array_merge( $active_plugins, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) );
    }
    $active_plugins = array_values( array_unique( $active_plugins ) );
    sort( $active_plugins );

    $plugin_versions = [];
    foreach ( array_slice( $active_plugins, 0, 500 ) as $plugin_file ) {
        $plugin_data = isset( $all_plugins[ $plugin_file ] ) && is_array( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : [];
        $plugin_versions[ $plugin_file ] = WPFlame\Config::string_value( $plugin_data['Version'] ?? 'unknown', 'unknown' );
    }

    $theme_data = [];
    if ( function_exists( 'wp_get_theme' ) ) {
        $theme = wp_get_theme();
        if ( is_object( $theme ) ) {
            $theme_data['stylesheet'] = WPFlame\Config::string_value( $theme->get_stylesheet(), '' );
            $theme_data['stylesheet_version'] = WPFlame\Config::string_value( $theme->get( 'Version' ), '' );
            $parent = $theme->parent();
            if ( is_object( $parent ) ) {
                $theme_data['template'] = WPFlame\Config::string_value( $parent->get_stylesheet(), '' );
                $theme_data['template_version'] = WPFlame\Config::string_value( $parent->get( 'Version' ), '' );
            }
        }
    }

    return new WPFlame\EnvironmentSnapshot( [
        'wordpress_version'               => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version', 'raw' ) : '',
        'php_version'                     => PHP_VERSION,
        'plugins'                         => $plugin_versions,
        'theme'                           => $theme_data,
        'multisite'                       => is_multisite(),
        'external_object_cache'           => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
        'page_cache_constant'              => defined( 'WP_CACHE' ) && WP_CACHE,
        'permalink_structure_hash'        => hash( 'sha256', WPFlame\Config::string_value( get_option( 'permalink_structure', '' ), '' ) ),
        'environment_snapshot_schema'     => WPFlame\EnvironmentSnapshot::SCHEMA_VERSION,
    ] );
}

function wp_flame_shutdown(): void {
    $collector = WPFlame\Collector::instance();
    $config = WPFlame\Config::instance();

    if ( ! $collector->is_initialized() ) {
        return;
    }

    // Step 1: Close current phase span explicitly
    $collector->end_span( wp_flame_current_phase_id() );

    // Step 2: Safety net — close any remaining open spans
    $collector->close_open_spans();

    $force_trace = ! empty( $GLOBALS['wp_flame_force_trace_request'] );

    // Freeze request timing before extension filters, scoring, and persistence
    // do their own work. get_trace() can still attach metadata after stop().
    $collector->stop();

    // Collect request-level metadata
    $request_meta = [];

    // Object cache stats
    if ( isset( $GLOBALS['wp_object_cache'] ) ) {
        $request_meta = array_merge(
            $request_meta,
            \WPFlame\Instrumentation::cache_meta( $GLOBALS['wp_object_cache'] )
        );
    }
    $request_meta['external_object_cache_configured'] = function_exists( 'wp_using_ext_object_cache' )
        ? wp_using_ext_object_cache()
        : false;
    $request_meta['cache_backend_health'] = 'unknown';
    if ( isset( $GLOBALS['wp_flame_callback_wrap_limitations'] ) && is_array( $GLOBALS['wp_flame_callback_wrap_limitations'] ) ) {
        $request_meta['callback_wrap_limitations'] = array_slice( $GLOBALS['wp_flame_callback_wrap_limitations'], 0, 10, true );
    }

    // Request identity fields are privacy-sensitive and default off.
    $current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    $track_ips = wp_flame_sensitive_capture_enabled( $config, 'wp_flame_track_ips' );
    $identity = \WPFlame\Instrumentation::request_identity(
        wp_flame_sensitive_capture_enabled( $config, 'wp_flame_track_users' ),
        $track_ips,
        wp_flame_sensitive_capture_enabled( $config, 'wp_flame_track_user_agent' ),
        $current_user_id,
        $track_ips ? wp_flame_get_client_ip() : '',
        isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( WPFlame\Config::string_value( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), '' ) )
            : ''
    );
    $user_id = $identity['user_id'];
    $ip_address = $identity['ip_address'];
    $request_meta = array_merge( $request_meta, $identity['meta'] );

    $filtered_request_meta = apply_filters( 'wp_flame_trace_meta', $request_meta );
    if ( is_array( $filtered_request_meta ) ) {
        $request_meta = $filtered_request_meta;
    }

    $mode = wp_flame_effective_instrumentation_mode( $config );
    $sample_rate = WPFlame\Config::bounded_int(
        $config->get( 'wp_flame_sample_rate', WPFlame\Config::DEFAULT_SAMPLE_RATE ),
        WPFlame\Config::DEFAULT_SAMPLE_RATE,
        1,
        WPFlame\Config::MAX_SAMPLE_RATE
    );
    $request_type = wp_flame_detect_request_type();
    $capture_start_stage = WPFlame\Config::string_value( $GLOBALS['wp_flame_capture_start_stage'] ?? 'unknown', 'unknown' );
    $registration = isset( $GLOBALS['wp_flame_instrumentation_result'] ) && is_array( $GLOBALS['wp_flame_instrumentation_result'] )
        ? $GLOBALS['wp_flame_instrumentation_result']
        : [];
    $registered = isset( $registration['registered'] ) && is_array( $registration['registered'] )
        ? $registration['registered']
        : [];
    $instrumentor_statuses = isset( $registration['statuses'] ) && is_array( $registration['statuses'] )
        ? $registration['statuses']
        : [];

    $db_requested = in_array( $mode, [ 'standard', 'deep' ], true );
    $callbacks_requested = $mode === 'deep';
    $db_captured = in_array( WPFlame\DbInstrumentor::class, $registered, true )
        || in_array( WPFlame\GraphQL::class, $registered, true );
    $callbacks_captured = in_array( WPFlame\CallbackInstrumentor::class, $registered, true );
    $callback_limitations = isset( $request_meta['callback_wrap_limitations'] ) && is_array( $request_meta['callback_wrap_limitations'] )
        ? $request_meta['callback_wrap_limitations']
        : [];
    $http_captured = in_array( WPFlame\Http::class, $registered, true );
    $graphql_captured = ! empty( $registration['graphql_active'] );
    $graphql_requested = $request_type === 'graphql';
    $cache_counters_captured = array_key_exists( 'cache_hits', $request_meta )
        || array_key_exists( 'cache_misses', $request_meta );
    $database_strategy = WPFlame\Config::string_value( $registration['database_strategy'] ?? 'unavailable', 'unavailable' );
    $database_reason = $db_captured
        ? $database_strategy . '_from_' . $capture_start_stage
        : WPFlame\Config::string_value( $registration['database_unavailable_reason'] ?? 'unsupported_or_unregistered_database', 'unsupported_or_unregistered_database' );
    if ( ( $instrumentor_statuses[ WPFlame\DbInstrumentor::class ] ?? '' ) === 'failed' ) {
        $database_reason = 'database_instrumentor_failed';
    }

    $incomplete_reasons = [];
    if ( $capture_start_stage !== 'mu_plugin' ) {
        $incomplete_reasons[] = 'early_lifecycle_unavailable';
    }
    if ( $db_requested && ! $db_captured ) {
        $incomplete_reasons[] = 'database_unavailable';
    }
    if ( $callbacks_requested && ! $callbacks_captured ) {
        $incomplete_reasons[] = 'callbacks_unavailable';
    }
    if ( ! $http_captured ) {
        $incomplete_reasons[] = 'http_instrumentor_unavailable';
    }
    if ( $graphql_requested && ! $graphql_captured ) {
        $incomplete_reasons[] = 'graphql_instrumentor_unavailable';
    }

    $request_start_reference_ms = null;
    $unobserved_prebootstrap_ms = null;
    if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
        $request_start_reference = (float) $_SERVER['REQUEST_TIME_FLOAT'];
        if ( is_finite( $request_start_reference ) && $request_start_reference > 0.0 ) {
            $request_start_reference_ms = $request_start_reference * 1000;
            $unobserved_prebootstrap_ms = max( 0.0, ( $collector->request_start() - $request_start_reference ) * 1000 );
        }
    }

    $http_status = function_exists( 'http_response_code' ) ? http_response_code() : false;
    $capture_report = [
        'capture_phase'                  => WPFlame\Config::string_value( $GLOBALS['wp_flame_capture_phase'] ?? 'observation', 'observation' ),
        'capture_origin'                 => ! empty( $GLOBALS['wp_flame_capture_session_id'] ) ? 'session' : ( $force_trace ? 'forced' : ( $request_type === 'cron' ? 'background' : 'sampled' ) ),
        'capture_policy'                 => WPFlame\Config::string_value( $GLOBALS['wp_flame_capture_policy'] ?? ( $force_trace ? 'forced_one_shot' : ( ! empty( $GLOBALS['wp_flame_deep_expiring_override'] ) ? 'expiring_deep_diagnostic' : ( $request_type === 'cron' ? 'background' : 'configured_sampling' ) ) ), 'configured_sampling' ),
        'capture_session_id'             => WPFlame\Config::string_value( $GLOBALS['wp_flame_capture_session_id'] ?? '', '' ),
        'request_type'                   => $request_type,
        'route_key'                      => wp_flame_resolve_route_key( $request_type ),
        'http_status'                    => is_int( $http_status ) ? $http_status : null,
        'instrumentation_mode'           => $mode,
        'sample_rate'                    => $sample_rate,
        'effective_sample_probability'   => ( $force_trace || $request_type === 'cron' ) ? 1.0 : 1.0 / $sample_rate,
        'capture_start_stage'            => $capture_start_stage,
        'request_start_reference_ms'     => $request_start_reference_ms,
        'unobserved_prebootstrap_ms'      => $unobserved_prebootstrap_ms,
        'wp_flame_version'               => WP_FLAME_VERSION,
        'score_version'                  => WPFlame\Score::VERSION,
        'capabilities'                   => [
            'early_lifecycle' => [
                'status' => $capture_start_stage === 'mu_plugin' ? 'captured' : 'unavailable',
                'reason' => $capture_start_stage === 'mu_plugin' ? '' : 'mu_plugin_unavailable_or_stale',
            ],
            'database' => [
                'status' => ! $db_requested ? 'not_requested' : ( $db_captured ? 'captured' : 'unavailable' ),
                'reason' => ! $db_requested ? 'safe_mode' : $database_reason,
            ],
            'callbacks' => [
                'status' => ! $callbacks_requested ? 'not_requested' : ( $callbacks_captured ? 'captured' : 'unavailable' ),
                'reason' => ! $callbacks_requested ? $mode . '_mode' : ( $callbacks_captured ? ( ! empty( $callback_limitations ) ? 'captured_with_compatibility_exclusions' : 'supported_callbacks_wrapped' ) : 'callback_instrumentor_not_registered' ),
            ],
            'http' => [
                'status' => $http_captured ? 'captured' : 'unavailable',
                'reason' => $http_captured ? '' : 'http_instrumentor_not_registered',
            ],
            'graphql' => [
                'status' => $graphql_captured ? 'captured' : ( $graphql_requested ? 'unavailable' : 'not_requested' ),
                'reason' => $graphql_captured ? 'wpgraphql_operation_and_root_resolver_hooks' : ( $graphql_requested ? 'wpgraphql_hooks_unavailable' : 'not_graphql_request' ),
            ],
            'cache_counters' => [
                'status' => $cache_counters_captured ? 'captured' : 'unavailable',
                'reason' => $cache_counters_captured ? '' : 'counters_not_exposed',
            ],
        ],
        'incomplete_reasons'             => $incomplete_reasons,
    ];

    // Step 4: Build trace
    $trace = $collector->get_trace( $request_meta, $capture_report );

    // Compute performance score
    $score_result = \WPFlame\Score::calculate( $trace );
    $trace->set_score_snapshot( $score_result );

    $should_store = WPFlame\Config::boolean( apply_filters( 'wp_flame_should_store_trace', true, $trace ) );
    if ( ! $should_store ) {
        return;
    }

    // Step 6: Discover and persist environment context outside the customer
    // trace, then save the trace with only the deduplicated fingerprint.
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $environment_snapshot_id = $storage->save_environment_snapshot( wp_flame_environment_snapshot() );
    if ( $environment_snapshot_id !== '' ) {
        $trace->capture_report->environment_snapshot_id = $environment_snapshot_id;
    } elseif ( ! in_array( 'environment_snapshot_unavailable', $trace->capture_report->incomplete_reasons, true ) ) {
        $trace->capture_report->incomplete_reasons[] = 'environment_snapshot_unavailable';
    }
    $storage_result = $storage->save_trace( $trace, $score_result['score'], $user_id, $ip_address );
    if ( ! $storage_result->success ) {
        if ( $force_trace && function_exists( 'set_transient' ) ) {
            set_transient( 'wp_flame_last_force_trace_error_' . $current_user_id, $storage_result->status, 300 );
        }
        return;
    }

    if ( $force_trace && function_exists( 'set_transient' ) ) {
        set_transient( 'wp_flame_last_force_trace_' . $current_user_id, $trace->id, 60 );
    }
    do_action( 'wp_flame_trace_stored', $trace, $score_result, $storage_result );

    // Step 7: Check performance budget thresholds
    $budget_max_ms      = WPFlame\Config::bounded_int( $config->get( 'wp_flame_budget_max_ms', 500 ), 500, 0, PHP_INT_MAX );
    $budget_max_queries = WPFlame\Config::bounded_int( $config->get( 'wp_flame_budget_max_queries', 100 ), 100, 0, PHP_INT_MAX );

    $exceeded = false;
    if ( $budget_max_ms > 0 && $trace->total_ms > $budget_max_ms ) {
        $exceeded = true;
    }
    if ( $budget_max_queries > 0 && $trace->query_count > $budget_max_queries ) {
        $exceeded = true;
    }

	if ( $exceeded ) {
		$key   = 'wp_flame_budget_violations';
		$count = WPFlame\Config::bounded_int( get_transient( $key ), 0, 0, PHP_INT_MAX - 1 );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}

// --- Cron handler ---

function wp_flame_run_retention_cleanup(): void {
    $days = WPFlame\Config::bounded_int(
        get_option( 'wp_flame_retention_days', WPFlame\Config::DEFAULT_RETENTION_DAYS ),
        WPFlame\Config::DEFAULT_RETENTION_DAYS,
        1,
        WPFlame\Config::MAX_RETENTION_DAYS
    );
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $result = $storage->run_retention_cleanup( $days );
    if ( $result['backlog'] && ! wp_next_scheduled( 'wp_flame_prune_traces_continue' ) ) {
        wp_schedule_single_event( time() + 60, 'wp_flame_prune_traces_continue' );
    }
}

function wp_flame_schedule_migration(): void {
    if ( ! wp_next_scheduled( 'wp_flame_run_migration' ) ) {
        wp_schedule_single_event( time() + 10, 'wp_flame_run_migration' );
    }
}

function wp_flame_run_migration(): void {
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $result = $storage->maybe_upgrade();
    if ( in_array( $result['status'], [ 'pending', 'failed', 'locked' ], true ) ) {
        wp_flame_schedule_migration();
    }
}

function wp_flame_run_rollup_backfill(): void {
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->run_rollup_backfill();
}

add_action( 'wp_flame_prune_traces', 'wp_flame_run_retention_cleanup' );
add_action( 'wp_flame_prune_traces_continue', 'wp_flame_run_retention_cleanup' );
add_action( 'wp_flame_run_migration', 'wp_flame_run_migration' );
add_action( 'wp_flame_rollup_backfill', 'wp_flame_run_rollup_backfill' );

// WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    add_action( 'plugins_loaded', function () {
        global $wpdb;
        $cli = new WPFlame\CLI( new WPFlame\Storage( $wpdb ) );
        \WP_CLI::add_command( 'flame', $cli );
    }, 99 );
}
