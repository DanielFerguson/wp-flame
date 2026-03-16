<?php
/**
 * WP Flame Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * @package WPFlame
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom table
$table = $wpdb->prefix . 'flame_traces';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Delete all plugin options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_flame\_%'" );

// Remove mu-plugin
$mu_file = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
if ( file_exists( $mu_file ) ) {
    @unlink( $mu_file );
}
