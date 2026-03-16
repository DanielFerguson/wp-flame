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
    add_option( 'wp_flame_retention_days', 7 );
    add_option( 'wp_flame_full_query_text', false );
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

    // Set admin detection flag at init
    add_action( 'init', function () {
        $GLOBALS['wp_flame_is_admin_request'] = current_user_can( 'manage_options' );
    }, 0 );

    // Register shutdown handler
    add_action( 'shutdown', 'wp_flame_shutdown', 9999 );

    // Register admin UI
    if ( is_admin() ) {
        global $wpdb;
        $admin = new WPFlame\Admin( new WPFlame\Storage( $wpdb ) );
        $admin->register();
    }
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

    // Step 3: Check if we should save
    if ( ! get_option( 'wp_flame_enabled', true ) ) {
        return;
    }
    if ( empty( $GLOBALS['wp_flame_is_admin_request'] ) ) {
        return;
    }

    // Step 4: Build trace
    $trace = $collector->get_trace();

    // Step 5: Stop collector (prevents self-instrumentation during save)
    $collector->stop();

    // Step 6: Save trace
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->save_trace( $trace );
}

// --- Cron handler ---

add_action( 'wp_flame_prune_traces', function () {
    $days = (int) get_option( 'wp_flame_retention_days', 7 );
    global $wpdb;
    $storage = new WPFlame\Storage( $wpdb );
    $storage->prune_old( $days );
} );
