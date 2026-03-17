<?php
/**
 * Plugin Name: WP Flame
 * Plugin URI:  https://github.com/your-repo/wp-flame
 * Description: See exactly where your WordPress request spends its time. Interactive flame graph APM.
 * Version:     0.1.0
 * Author:      Your Name
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

define( 'WP_FLAME_VERSION', '0.1.0' );
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

// --- Main plugin initialization ---

add_action( 'plugins_loaded', 'wp_flame_init', 0 );

function wp_flame_init(): void {
    $collector = WPFlame\Collector::instance();

    if ( ! get_option( 'wp_flame_enabled', true ) ) {
        // Stop the collector if the mu-plugin already started it
        if ( $collector->is_initialized() ) {
            $collector->stop();
        }
        return;
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

    // Replace $wpdb with instrumented version
    global $wpdb;
    if ( WPFlame\DB::can_replace( $wpdb ) ) {
        $GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector );
    }

    // HTTP request instrumentation
    new WPFlame\Http( $collector );

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
            'title' => '🔥 Trace This Page',
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
            echo '<strong>WP Flame:</strong> Trace captured! <a href="' . esc_url( $url ) . '">View flame graph &rarr;</a>';
            echo '</p></div>';
        }
    } );

    // Per-callback instrumentation (Phase 2)
    $min_callback_ms = (float) get_option( 'wp_flame_min_callback_ms', 0.5 );

    // Pass 1: wrap callbacks registered before plugins_loaded
    add_action( 'plugins_loaded', function () use ( $collector, $min_callback_ms ) {
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );

    // Pass 2: wrap callbacks registered between plugins_loaded and init
    add_action( 'init', function () use ( $collector, $min_callback_ms ) {
        wp_flame_wrap_callbacks( $collector, $min_callback_ms );
    }, 1 );
}

function wp_flame_shutdown(): void {
    $collector = WPFlame\Collector::instance();

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
    if ( ! empty( $_COOKIE['wp_flame_force_trace'] ) ) {
        $force_trace = true;
        setcookie( 'wp_flame_force_trace', '', time() - 3600, '/' );
    }

    if ( ! $force_trace ) {
        // Audience check
        $audience = get_option( 'wp_flame_trace_audience', 'admins' );
        if ( $audience === 'admins' && empty( $GLOBALS['wp_flame_is_admin_request'] ) ) {
            return;
        } elseif ( $audience === 'logged_in' && ! is_user_logged_in() ) {
            return;
        }
        // 'everyone' always passes

        // Sampling check
        $sample_rate = max( 1, (int) get_option( 'wp_flame_sample_rate', 1 ) );
        if ( $sample_rate > 1 && rand( 1, $sample_rate ) !== 1 ) {
            return;
        }
    }

    // Step 4: Build trace
    $trace = $collector->get_trace();

    // If force-trace, store the trace ID for admin notice
    if ( $force_trace && function_exists( 'set_transient' ) ) {
        set_transient( 'wp_flame_last_force_trace_' . get_current_user_id(), $trace->id, 60 );
    }

    // Step 5: Stop collector (prevents self-instrumentation during save)
    $collector->stop();

    // Step 6: Save trace
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->save_trace( $trace );
}

/**
 * Wrap registered WordPress hook callbacks with timing instrumentation.
 * Iterates all hooks in $wp_filter and replaces each callback's function
 * entry with a CallbackWrapper that adds span timing.
 */
function wp_flame_wrap_callbacks( WPFlame\Collector $collector, float $min_ms ): void {
    foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook_instance ) {
        if ( ! ( $hook_instance instanceof \WP_Hook ) ) {
            continue;
        }

        foreach ( $hook_instance->callbacks as $priority => $priority_callbacks ) {
            foreach ( $priority_callbacks as $id => $the_ ) {
                // Skip already-wrapped callbacks
                if ( $the_['function'] instanceof WPFlame\CallbackWrapper ) {
                    continue;
                }

                $original = $the_['function'];

                // Skip our own plugin's callbacks to avoid self-instrumentation
                $source = WPFlame\CallbackResolver::resolve_source( $id, $original, $collector );
                if ( $source['source'] === 'wp-flame' || $source['source'] === 'wordpress-apm-plugin' ) {
                    continue;
                }

                $name = WPFlame\CallbackResolver::resolve_name( $id, $original );

                $hook_instance->callbacks[ $priority ][ $id ]['function'] = new WPFlame\CallbackWrapper(
                    $original, $collector, $hook_name, (int) $priority,
                    $name, $source, $min_ms
                );
            }
        }
    }
}

// --- Cron handler ---

add_action( 'wp_flame_prune_traces', function () {
    $days = (int) get_option( 'wp_flame_retention_days', 7 );
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->prune_old( $days );
} );
