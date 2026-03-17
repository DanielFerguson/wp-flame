<?php
/**
 * WP Flame Early Hooks
 *
 * This file is copied to wp-content/mu-plugins/ on plugin activation.
 * It ensures instrumentation loads before all other plugins.
 *
 * @package WPFlame
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Record request start as early as possible
$wp_flame_request_start = microtime(true);

define( 'WP_FLAME_MU_VERSION', '1.2.0' );

// Load the main plugin's autoloader
$wp_flame_autoload = WP_PLUGIN_DIR . '/wp-flame/vendor/autoload.php';
if ( ! file_exists( $wp_flame_autoload ) ) {
    return; // Main plugin missing — graceful no-op
}
require_once $wp_flame_autoload;

// Initialize collector with the precise start time
$collector = WPFlame\Collector::instance();
$collector->start_request( $wp_flame_request_start );

// Start the Bootstrap phase span
$wp_flame_bootstrap_id = $collector->start_span( 'Bootstrap', WPFlame\Span::TYPE_CORE, 'wordpress' );

// Store the current phase span ID so we can close it at the next transition
$GLOBALS['wp_flame_current_phase_id'] = $wp_flame_bootstrap_id;

// Register early universal phase transitions only.
// Late phases (init, wp, template_redirect) are registered by wp_flame_init()
// based on request type (frontend/REST/admin/AJAX/CLI/cron).
$wp_flame_phases = [
    'muplugins_loaded'   => 'Plugin Load',
    'plugins_loaded'     => 'Theme Setup',
    'after_setup_theme'  => 'Init',
];

foreach ( $wp_flame_phases as $hook => $next_phase_name ) {
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
