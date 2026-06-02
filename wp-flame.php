<?php
/**
 * Plugin Name: WP Flame
 * Plugin URI:  https://www.chepstowe.consulting
 * Description: See exactly where your WordPress request spends its time. Interactive flame graph APM.
 * Version:     1.2.0
 * Author:      Chepstowe Consulting
 * Author URI:  https://www.chepstowe.consulting
 * License:     GPL-2.0-or-later
 * Text Domain: wp-flame
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package WPFlame
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_FLAME_VERSION', '1.2.0' );
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
        foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            try {
                wp_flame_activate_site();
            } finally {
                restore_current_blog();
            }
        }
    } else {
        wp_flame_activate_site();
    }

    wp_flame_install_mu_plugin();
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
    add_option( 'wp_flame_enabled', true );
    add_option( 'wp_flame_trace_audience', 'admins' );
    add_option( 'wp_flame_sample_rate', WPFlame\Config::DEFAULT_SAMPLE_RATE );
    add_option( 'wp_flame_instrumentation_mode', 'standard' );
    add_option( 'wp_flame_retention_days', WPFlame\Config::DEFAULT_RETENTION_DAYS );
    add_option( 'wp_flame_full_query_text', false );
    add_option( 'wp_flame_full_http_url', false );
    add_option( 'wp_flame_full_graphql_query', false );
    add_option( 'wp_flame_min_callback_ms', 0.5 );
    add_option( 'wp_flame_budget_max_ms', 500 );
    add_option( 'wp_flame_budget_max_queries', 100 );
    add_option( 'wp_flame_max_spans', WPFlame\Config::DEFAULT_MAX_SPANS );
    add_option( 'wp_flame_max_trace_bytes', WPFlame\Config::DEFAULT_MAX_TRACE_BYTES );
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

function wp_flame_install_mu_plugin(): void {
    $mu_dir = wp_flame_mu_plugin_dir();
    if ( $mu_dir === '' ) {
        update_option( 'wp_flame_mu_plugin_failed', true );
        return;
    }

    $mu_src  = WP_FLAME_DIR . 'mu-plugin/wp-flame-early-hooks.php';
    $mu_dest = $mu_dir . '/wp-flame-early-hooks.php';

    if ( ! is_dir( $mu_dir ) ) {
        wp_mkdir_p( $mu_dir );
    }

    if ( file_exists( $mu_src ) ) {
        $copied = @copy( $mu_src, $mu_dest );
        if ( ! $copied ) {
            update_option( 'wp_flame_mu_plugin_failed', true );
        } else {
            delete_option( 'wp_flame_mu_plugin_failed' );
        }
    }
}

function wp_flame_deactivate( bool $network_wide = false ): void {
    if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
        foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            try {
                wp_flame_deactivate_site();
            } finally {
                restore_current_blog();
            }
        }
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
        @unlink( $mu_file );
    }
}

function wp_flame_deactivate_site(): void {
    // Clear cron
    $timestamp = wp_next_scheduled( 'wp_flame_prune_traces' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wp_flame_prune_traces' );
    }
}

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

    foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
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
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return 'cli';
    }
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return 'cron';
    }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return 'ajax';
    }
    // REST detection via URL pattern — REST_REQUEST is not defined until rest_api_init.
    $rest_prefix = rest_get_url_prefix();
    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? WPFlame\Config::string_value( wp_unslash( $_SERVER['REQUEST_URI'] ), '' )
        : '';
    if ( false !== strpos( $request_uri, '/' . $rest_prefix . '/' ) || false !== strpos( $request_uri, '/' . $rest_prefix . '?' ) ) {
        return 'rest';
    }
    if ( is_admin() ) {
        return 'admin';
    }
    return 'frontend';
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

function wp_flame_should_instrument_request( ?WPFlame\Config $config = null, ?int $sample_roll = null ): bool {
    $cache_decision = $sample_roll === null;

    if ( $cache_decision && array_key_exists( 'wp_flame_should_instrument_request', $GLOBALS ) ) {
        return (bool) $GLOBALS['wp_flame_should_instrument_request'];
    }

    $config      = $config ?: WPFlame\Config::instance();
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
        WPFlame\Config::boolean( $config->get( 'wp_flame_enabled', true ) ),
        WPFlame\Config::string_value( $config->get( 'wp_flame_trace_audience', 'admins' ), 'admins' ),
        $sample_rate,
        wp_flame_is_force_trace_request(),
        defined( 'DOING_CRON' ) && DOING_CRON,
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
    }

    unset( $_COOKIE['wp_flame_force_trace'] );
}

function wp_flame_current_phase_id(): ?string {
    $phase_id = $GLOBALS['wp_flame_current_phase_id'] ?? null;

    return is_string( $phase_id ) && $phase_id !== '' ? $phase_id : null;
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
    } );
}

function wp_flame_register_instrumentors( WPFlame\Collector $collector, WPFlame\Config $config, \wpdb $wpdb ): array {
    $full_query_text    = WPFlame\Config::boolean( $config->get( 'wp_flame_full_query_text', false ) );
    $full_http_url      = WPFlame\Config::boolean( $config->get( 'wp_flame_full_http_url', false ) );
    $full_graphql_query = WPFlame\Config::boolean( $config->get( 'wp_flame_full_graphql_query', false ) );
    $mode               = WPFlame\Config::string_value( $config->get( 'wp_flame_instrumentation_mode', 'standard' ), 'standard' );
    $allow_db           = in_array( $mode, [ 'standard', 'deep' ], true );
    $allow_callbacks    = $mode === 'deep';

    $instrumentors = [
        new WPFlame\Http( $full_http_url ),
    ];
    if ( $allow_db ) {
        $instrumentors[] = new WPFlame\GraphQL( $full_query_text, $wpdb, $full_graphql_query, true );
        $instrumentors[] = new WPFlame\DbInstrumentor( $wpdb, $full_query_text );
    }
    if ( $allow_callbacks ) {
        $instrumentors[] = new WPFlame\CallbackInstrumentor( $config );
    }
    $filtered_instrumentors = apply_filters( 'wp_flame_instrumentors', $instrumentors );
    if ( is_array( $filtered_instrumentors ) ) {
        $instrumentors = $filtered_instrumentors;
    }

    $result = WPFlame\Instrumentation::register_instrumentors(
        $instrumentors,
        $collector,
        function (): void {
            if ( ! defined( 'SAVEQUERIES' ) ) {
                define( 'SAVEQUERIES', true );
            }
        }
    );

    if ( $result['graphql_active'] ) {
        $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
    }

    return $result;
}

function wp_flame_register_late_phase_hooks( WPFlame\Collector $collector ): void {
    if ( ! defined( 'WP_FLAME_MU_VERSION' ) || WP_FLAME_MU_VERSION !== WP_FLAME_VERSION ) {
        return;
    }

    $request_type = wp_flame_detect_request_type();

    switch ( $request_type ) {
        case 'frontend':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Routing', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            add_action( 'wp', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Main Query', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            add_action( 'template_redirect', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Render', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;

        case 'rest':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Routing', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            add_action( 'rest_api_init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'REST Dispatch', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;

        case 'admin':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Admin Init', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            add_action( 'admin_init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Admin Render', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;

        case 'ajax':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'AJAX Dispatch', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;

        case 'cli':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Command Execution', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;

        case 'cron':
            add_action( 'init', function () use ( $collector ) {
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span( 'Cron Execution', WPFlame\Span::TYPE_CORE, 'wordpress' );
            }, 0 );
            break;
    }
}

// --- Main plugin initialization ---

add_action( 'plugins_loaded', 'wp_flame_init', 0 );

function wp_flame_init(): void {
    $collector = WPFlame\Collector::instance();
    $config    = WPFlame\Config::instance();
    global $wpdb;
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

    // Run schema migrations if needed (cheap no-op when current).
    $schema_version = WPFlame\Config::bounded_int( get_option( 'wp_flame_schema_version', 0 ), 0, 0, PHP_INT_MAX );
    if ( $schema_version < WPFlame\Storage::SCHEMA_VERSION ) {
        $upgrade_storage = new WPFlame\Storage( $wpdb );
        $upgrade_storage->maybe_upgrade();
        unset( $upgrade_storage );
    }

    wp_flame_register_plugin_services( $wpdb );

    if ( ! empty( $_COOKIE['wp_flame_force_trace'] ) && ! wp_flame_is_force_trace_request() ) {
        wp_flame_clear_force_trace_cookie();
    }

    if ( ! wp_flame_should_instrument_request( $config ) ) {
        if ( $collector->is_initialized() ) {
            $collector->stop();
        }
        return;
    }

    if ( wp_flame_is_force_trace_request() ) {
        $GLOBALS['wp_flame_force_trace_request'] = true;
        wp_flame_clear_force_trace_cookie();
    }

    // Degraded mode: if mu-plugin didn't initialize the collector, start now
    if ( ! $collector->is_initialized() ) {
        $collector->start_request( microtime( true ) );

        // Start a Theme Setup span (first phase we can capture)
        $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
            'Theme Setup',
            WPFlame\Span::TYPE_CORE,
            'wordpress'
        );

        // Register remaining phase transitions
        $phases = [
            'after_setup_theme' => 'Init',
            'init'              => 'Routing',
            'wp'                => 'Main Query',
            'template_redirect' => 'Render',
        ];

        foreach ( $phases as $hook => $next_phase_name ) {
            add_action( $hook, function () use ( $next_phase_name ) {
                $collector = WPFlame\Collector::instance();
                $collector->end_span( wp_flame_current_phase_id() );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
                    $next_phase_name,
                    WPFlame\Span::TYPE_CORE,
                    'wordpress'
                );
            }, 0 );
        }
    }

    wp_flame_register_instrumentors( $collector, $config, $wpdb );
    wp_flame_register_late_phase_hooks( $collector );

    // Capture which template file WordPress selects for rendering
    add_filter( 'template_include', function ( $template ) use ( $collector ) {
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

    // Request identity fields are privacy-sensitive and default off.
    $current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    $track_ips = WPFlame\Config::boolean( $config->get( 'wp_flame_track_ips', false ) );
    $identity = \WPFlame\Instrumentation::request_identity(
        WPFlame\Config::boolean( $config->get( 'wp_flame_track_users', false ) ),
        $track_ips,
        WPFlame\Config::boolean( $config->get( 'wp_flame_track_user_agent', false ) ),
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

    // Step 4: Build trace
    $trace = $collector->get_trace( $request_meta );

    // Compute performance score
    $score_result = \WPFlame\Score::calculate( $trace );

    $should_store = WPFlame\Config::boolean( apply_filters( 'wp_flame_should_store_trace', true, $trace ) );
    if ( ! $should_store ) {
        return;
    }

    // If force-trace, store the trace ID for admin notice only after storage is allowed.
    if ( $force_trace && function_exists( 'set_transient' ) ) {
        set_transient( 'wp_flame_last_force_trace_' . $current_user_id, $trace->id, 60 );
    }

    // Step 6: Save trace
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->save_trace( $trace, $score_result['score'], $user_id, $ip_address );
    do_action( 'wp_flame_trace_stored', $trace, $score_result );

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

add_action( 'wp_flame_prune_traces', function () {
    $days = WPFlame\Config::bounded_int(
        get_option( 'wp_flame_retention_days', WPFlame\Config::DEFAULT_RETENTION_DAYS ),
        WPFlame\Config::DEFAULT_RETENTION_DAYS,
        1,
        WPFlame\Config::MAX_RETENTION_DAYS
    );
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->prune_old( $days );
} );

// WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    add_action( 'plugins_loaded', function () {
        global $wpdb;
        $cli = new WPFlame\CLI( new WPFlame\Storage( $wpdb ) );
        \WP_CLI::add_command( 'flame', $cli );
    }, 99 );
}
