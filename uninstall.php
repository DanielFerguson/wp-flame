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

$wp_flame_uninstall_autoloader = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $wp_flame_uninstall_autoloader ) ) {
    require_once $wp_flame_uninstall_autoloader;
}

global $wpdb;

$wp_flame_uninstall_mu_hash = is_multisite()
    ? get_site_option( 'wp_flame_mu_plugin_hash', '' )
    : get_option( 'wp_flame_mu_plugin_hash', '' );
$wp_flame_uninstall_mu_hash = is_string( $wp_flame_uninstall_mu_hash ) ? $wp_flame_uninstall_mu_hash : '';

function wp_flame_uninstall_mu_plugin_dir(): string {
    return defined( 'WPMU_PLUGIN_DIR' ) && is_string( WPMU_PLUGIN_DIR ) && WPMU_PLUGIN_DIR !== ''
        ? WPMU_PLUGIN_DIR
        : '';
}

/** @return array<int, int> */
function wp_flame_uninstall_site_batch( int $offset, int $limit = 10 ): array {
    if ( ! function_exists( 'get_sites' ) ) {
        return [];
    }

    $site_ids = get_sites( [
        'fields' => 'ids',
        'number' => max( 1, min( 100, $limit ) ),
        'offset' => max( 0, $offset ),
        'orderby' => 'id',
        'order' => 'ASC',
    ] );

    if ( ! is_array( $site_ids ) ) {
        return [];
    }

    return array_map( 'intval', $site_ids );
}

function wp_flame_uninstall_site(): void {
    global $wpdb;

    // Drop custom table for the current site.
    foreach ( [ 'flame_traces', 'flame_sessions', 'flame_environments', 'flame_rollups' ] as $suffix ) {
        $table = $wpdb->prefix . $suffix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe: prefix + static suffix
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    }

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
    wp_clear_scheduled_hook( 'wp_flame_prune_traces_continue' );
    wp_clear_scheduled_hook( 'wp_flame_run_migration' );
    wp_clear_scheduled_hook( 'wp_flame_rollup_backfill' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
    $wp_flame_uninstall_state = get_site_option( 'wp_flame_uninstall_state', [] );
    $wp_flame_uninstall_offset = is_array( $wp_flame_uninstall_state ) && isset( $wp_flame_uninstall_state['offset'] )
        ? max( 0, (int) $wp_flame_uninstall_state['offset'] )
        : 0;
    do {
        $wp_flame_uninstall_site_ids = wp_flame_uninstall_site_batch( $wp_flame_uninstall_offset, 10 );
        $wp_flame_uninstall_has_more = count( $wp_flame_uninstall_site_ids ) === 10;
        foreach ( $wp_flame_uninstall_site_ids as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            try {
                wp_flame_uninstall_site();
            } finally {
                restore_current_blog();
            }

            $wp_flame_uninstall_offset++;
            update_site_option( 'wp_flame_uninstall_state', [
                'offset'     => $wp_flame_uninstall_offset,
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ] );
        }
    } while ( $wp_flame_uninstall_has_more );

    delete_site_option( 'wp_flame_plugin_file' );
    delete_site_option( 'wp_flame_mu_plugin_hash' );
    delete_site_option( 'wp_flame_mu_plugin_state' );
    delete_site_option( 'wp_flame_mu_plugin_failed' );
    delete_site_option( 'wp_flame_network_maintenance' );
    delete_site_option( 'wp_flame_uninstall_state' );
} else {
    wp_flame_uninstall_site();
}

// Remove the early-capture mu-plugin when the directory is available.
$mu_dir = wp_flame_uninstall_mu_plugin_dir();
if ( $mu_dir !== '' ) {
    $mu_file = $mu_dir . '/wp-flame-early-hooks.php';
    if ( file_exists( $mu_file ) && class_exists( 'WPFlame\MuPluginManager' ) ) {
        WPFlame\MuPluginManager::remove( $mu_file, $wp_flame_uninstall_mu_hash );
    }
}
