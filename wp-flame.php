<?php
/**
 * Plugin Name: WP Flame
 * Plugin URI:  https://www.chepstowe.consulting
 * Description: See exactly where your WordPress request spends its time. Interactive flame graph APM.
 * Version:     1.1.1
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

define( 'WP_FLAME_VERSION', '1.1.1' );
define( 'WP_FLAME_FILE', __FILE__ );
define( 'WP_FLAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_FLAME_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader (may already be loaded by mu-plugin)
$wp_flame_autoloader = WP_FLAME_DIR . 'vendor/autoload.php';
if ( file_exists( $wp_flame_autoloader ) && ! class_exists( 'WPFlame\\Collector' ) ) {
    require_once $wp_flame_autoloader;
}

// --- Activation / Deactivation hooks ---

register_activation_hook( __FILE__, 'wp_flame_activate' );
register_deactivation_hook( __FILE__, 'wp_flame_deactivate' );

function wp_flame_activate(): void {
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->create_table();
    update_option( 'wp_flame_schema_version', WPFlame\Storage::SCHEMA_VERSION );

    // Attempt to copy mu-plugin
    $mu_dir  = WPMU_PLUGIN_DIR;
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

    // Schedule daily prune
    if ( ! wp_next_scheduled( 'wp_flame_prune_traces' ) ) {
        wp_schedule_event( time(), 'daily', 'wp_flame_prune_traces' );
    }

    // Set default options (add_option won't overwrite existing values)
    add_option( 'wp_flame_enabled', true );
    add_option( 'wp_flame_trace_audience', 'admins' );
    add_option( 'wp_flame_sample_rate', 1 );
    add_option( 'wp_flame_retention_days', 7 );
    add_option( 'wp_flame_full_query_text', false );
    add_option( 'wp_flame_min_callback_ms', 0.5 );
    add_option( 'wp_flame_budget_max_ms', 500 );
    add_option( 'wp_flame_budget_max_queries', 100 );
    add_option( 'wp_flame_track_ips', true );
}

function wp_flame_deactivate(): void {
    // Remove mu-plugin
    $mu_file = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
    if ( file_exists( $mu_file ) ) {
        @unlink( $mu_file );
    }

    // Clear cron
    $timestamp = wp_next_scheduled( 'wp_flame_prune_traces' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wp_flame_prune_traces' );
    }
}

function wp_flame_get_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

// --- Main plugin initialization ---

add_action( 'plugins_loaded', 'wp_flame_init', 0 );

function wp_flame_init(): void {
    $collector = WPFlame\Collector::instance();
    $config = WPFlame\Config::instance();

    if ( ! $config->get( 'wp_flame_enabled', true ) ) {
        // Stop the collector if the mu-plugin already started it
        if ( $collector->is_initialized() ) {
            $collector->stop();
        }
        return;
    }

	// Check for mu-plugin version drift and auto-update if needed.
	if ( defined( 'WP_FLAME_MU_VERSION' ) && WP_FLAME_MU_VERSION !== WP_FLAME_VERSION ) {
		$mu_source = WP_FLAME_DIR . 'mu-plugin/wp-flame-early-hooks.php';
		$mu_dest   = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
		if ( file_exists( $mu_source ) ) {
			@copy( $mu_source, $mu_dest );
		}
	}

    global $wpdb;

	// Run schema migrations if needed (cheap no-op when current).
	if ( (int) get_option( 'wp_flame_schema_version', 0 ) < WPFlame\Storage::SCHEMA_VERSION ) {
		$upgrade_storage = new WPFlame\Storage( $wpdb );
		$upgrade_storage->maybe_upgrade();
		unset( $upgrade_storage );
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
                $collector->end_span( $GLOBALS['wp_flame_current_phase_id'] );
                $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
                    $next_phase_name,
                    WPFlame\Span::TYPE_CORE,
                    'wordpress'
                );
            }, 0 );
        }
    }

    // --- Instrumentor loop (DB/GraphQL mutual exclusion) ---
    $full_query_text = (bool) $config->get( 'wp_flame_full_query_text', false );
    $instrumentors = [
        new WPFlame\DbInstrumentor( $wpdb, $full_query_text ),
        new WPFlame\Http(),
        new WPFlame\GraphQL( $full_query_text, $wpdb ),
        new WPFlame\CallbackInstrumentor( $config ),
    ];
    $instrumentors = apply_filters( 'wp_flame_instrumentors', $instrumentors );

    $graphql_active = false;
    foreach ( $instrumentors as $inst ) {
        if ( ! ( $inst instanceof WPFlame\Instrumentor ) ) {
            continue;
        }
        if ( $inst instanceof WPFlame\GraphQL ) {
            if ( $inst->is_applicable() ) {
                if ( ! defined( 'SAVEQUERIES' ) ) {
                    define( 'SAVEQUERIES', true );
                }
                $graphql_active = true;
                $inst->register( $collector );
            }
        } elseif ( $inst instanceof WPFlame\DbInstrumentor ) {
            if ( ! $graphql_active && $inst->is_applicable() ) {
                $inst->register( $collector );
            }
        } else {
            if ( $inst->is_applicable() ) {
                $inst->register( $collector );
            }
        }
    }

    if ( $graphql_active ) {
        $GLOBALS['wp_flame_skip_callback_wrapping'] = true;
    }

    // Capture which template file WordPress selects for rendering
    add_filter( 'template_include', function ( $template ) use ( $collector ) {
        if ( isset( $GLOBALS['wp_flame_current_phase_id'] ) ) {
            $name = basename( $template );
            $collector->add_span_meta( $GLOBALS['wp_flame_current_phase_id'], [
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

    // Register admin UI
    if ( is_admin() ) {
        $admin = new WPFlame\Admin( new WPFlame\Storage( $wpdb ) );
        $admin->register();
    }

    // Register settings page
    $settings = new WPFlame\Settings( new WPFlame\Storage( $wpdb ) );
    $settings->register();

    // Register GDPR data export/erasure hooks (must run on all requests, not just admin)
    $privacy = new WPFlame\Privacy( new WPFlame\Storage( $wpdb ) );
    $privacy->register();

    // Admin bar button — only on frontend pages for users with manage_options
    add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( is_admin() ) {
            return; // Only show on frontend
        }
        $wp_admin_bar->add_node( [
            'id'    => 'wp-flame-trace',
            'title' => '🔥 ' . esc_html__( 'Trace This Page', 'wp-flame' ),
            'href'  => '#',
        ] );
    }, 999 );

    // Enqueue admin bar JS on frontend (avoids inline onclick blocked by CSP)
    add_action( 'wp_enqueue_scripts', function () {
        if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
            return;
        }
        wp_enqueue_script( 'wp-flame-admin-bar', WP_FLAME_URL . 'assets/js/admin-bar.js', [], WP_FLAME_VERSION, true );
    } );

    // Performance budget violation notice
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $key   = 'wp_flame_budget_violations';
        $count = (int) get_transient( $key );
        if ( $count > 0 ) {
            $url = admin_url( 'tools.php?page=wp-flame&min_duration=' . (int) get_option( 'wp_flame_budget_max_ms', 500 ) );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo wp_kses_post( sprintf(
                /* translators: %1$d: number of violations, %2$s: URL to view traces */
                __( '<strong>WP Flame:</strong> %1$d request(s) exceeded your performance budget in the last hour. <a href="%2$s">View slow traces &rarr;</a>', 'wp-flame' ),
                $count,
                esc_url( $url )
            ) );
            echo '</p></div>';
            // Clear after showing
            delete_transient( $key );
        }
    } );

    // Admin notice for force-traced pages
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $trace_id = get_transient( 'wp_flame_last_force_trace_' . get_current_user_id() );
        if ( $trace_id ) {
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

function wp_flame_shutdown(): void {
    $collector = WPFlame\Collector::instance();
    $config = WPFlame\Config::instance();

    if ( ! $collector->is_initialized() ) {
        return;
    }

    // Step 1: Close current phase span explicitly
    if ( isset( $GLOBALS['wp_flame_current_phase_id'] ) ) {
        $collector->end_span( $GLOBALS['wp_flame_current_phase_id'] );
    }

    // Step 2: Safety net — close any remaining open spans
    $collector->close_open_spans();

    // Force-trace via admin bar button (cookie)
    $force_trace = false;
    if ( ! empty( $_COOKIE['wp_flame_force_trace'] ) && function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
        $force_trace = true;
        setcookie( 'wp_flame_force_trace', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
    }

    // Cron requests are always traced (infrequent, server-initiated)
    $is_cron = defined( 'DOING_CRON' ) && DOING_CRON;

    if ( ! $force_trace && ! $is_cron ) {
        // Audience check
        $audience = $config->get( 'wp_flame_trace_audience', 'admins' );
        if ( $audience === 'admins' && empty( $GLOBALS['wp_flame_is_admin_request'] ) ) {
            return;
        } elseif ( $audience === 'logged_in' && ! is_user_logged_in() ) {
            return;
        }
        // 'everyone' always passes

        // Sampling check
        $sample_rate = max( 1, (int) $config->get( 'wp_flame_sample_rate', 1 ) );
        if ( $sample_rate > 1 && rand( 1, $sample_rate ) !== 1 ) {
            return;
        }
    }

    // Collect request-level metadata
    $request_meta = [];

    // Object cache stats
    if ( isset( $GLOBALS['wp_object_cache'] ) ) {
        $cache = $GLOBALS['wp_object_cache'];
        $request_meta['cache_hits']    = property_exists( $cache, 'cache_hits' ) ? (int) $cache->cache_hits : 0;
        $request_meta['cache_misses']  = property_exists( $cache, 'cache_misses' ) ? (int) $cache->cache_misses : 0;
        $request_meta['cache_backend'] = get_class( $cache );
    }

    // User identity
    $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
    $request_meta['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
        ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
        : '';

    // IP address (if tracking enabled)
    $track_ips  = $config->get( 'wp_flame_track_ips', true );
    $ip_address = $track_ips ? wp_flame_get_client_ip() : '';

	$request_meta = apply_filters( 'wp_flame_trace_meta', $request_meta );

    // Step 4: Build trace
    $trace = $collector->get_trace( $request_meta );

    // Compute performance score
    $score_result = \WPFlame\Score::calculate( $trace );

    // If force-trace, store the trace ID for admin notice
    if ( $force_trace && function_exists( 'set_transient' ) ) {
        set_transient( 'wp_flame_last_force_trace_' . $user_id, $trace->id, 60 );
    }

    $should_store = apply_filters( 'wp_flame_should_store_trace', true, $trace );
    if ( ! $should_store ) {
        $collector->stop();
        return;
    }

    // Step 5: Stop collector (prevents self-instrumentation during save)
    $collector->stop();

    // Step 6: Save trace
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->save_trace( $trace, $score_result['score'], $user_id, $ip_address );
    do_action( 'wp_flame_trace_stored', $trace, $score_result );

    // Step 7: Check performance budget thresholds
    $budget_max_ms      = (int) $config->get( 'wp_flame_budget_max_ms', 500 );
    $budget_max_queries = (int) $config->get( 'wp_flame_budget_max_queries', 100 );

    $exceeded = false;
    if ( $budget_max_ms > 0 && $trace->total_ms > $budget_max_ms ) {
        $exceeded = true;
    }
    if ( $budget_max_queries > 0 && $trace->query_count > $budget_max_queries ) {
        $exceeded = true;
    }

    if ( $exceeded ) {
        $key   = 'wp_flame_budget_violations';
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }
}

// --- Cron handler ---

add_action( 'wp_flame_prune_traces', function () {
    $days = (int) get_option( 'wp_flame_retention_days', 7 );
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
