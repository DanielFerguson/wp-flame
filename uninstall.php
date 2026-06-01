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

function wp_flame_uninstall_site(): void {
    global $wpdb;

    // Drop custom table for the current site.
    $table = $wpdb->prefix . 'flame_traces';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

    // Delete all plugin options for the current site.
    $option_like = $wpdb->esc_like( 'wp_flame_' ) . '%';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table name is managed by WordPress.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $option_like
    ) );

    // Delete transients not matched by the wp_flame_% option cleanup above.
    $transient_like         = $wpdb->esc_like( '_transient_wp_flame_' ) . '%';
    $transient_timeout_like = $wpdb->esc_like( '_transient_timeout_wp_flame_' ) . '%';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table name is managed by WordPress.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $transient_like,
        $transient_timeout_like
    ) );

    // Clear scheduled cron events for the current site.
    wp_clear_scheduled_hook( 'wp_flame_prune_traces' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
    foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
        switch_to_blog( (int) $blog_id );
        try {
            wp_flame_uninstall_site();
        } finally {
            restore_current_blog();
        }
    }

    delete_site_option( 'wp_flame_plugin_file' );
} else {
    wp_flame_uninstall_site();
}

// Remove the early-capture mu-plugin when the directory is available.
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
    $mu_file = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
    if ( file_exists( $mu_file ) ) {
        if ( function_exists( 'wp_delete_file' ) ) {
            wp_delete_file( $mu_file );
        } else {
            @unlink( $mu_file );
        }
    }
}
