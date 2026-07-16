<?php

/**
 * Plugin Name: WP Flame benchmark fixture
 * Description: Disposable local-only workload fixtures used by the M6 benchmark harness.
 */

if ( ! defined( 'ABSPATH' ) || function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() !== 'local' ) {
    return;
}

function wp_flame_benchmark_fixture_enabled(): bool
{
    return isset( $_GET['wp_flame_benchmark'] ) && $_GET['wp_flame_benchmark'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only local benchmark selector.
}

function wp_flame_benchmark_peak_marker(): void
{
    if ( wp_flame_benchmark_fixture_enabled() ) {
        global $wpdb;
        echo '<!-- wp-flame-benchmark-peak:' . esc_html( (string) memory_get_peak_usage( true ) ) . ' -->';
        echo '<!-- wp-flame-benchmark-query-count:' . esc_html( (string) $wpdb->num_queries ) . ' -->';
    }
}

add_action( 'wp_footer', 'wp_flame_benchmark_peak_marker', PHP_INT_MAX );
add_action( 'admin_footer', 'wp_flame_benchmark_peak_marker', PHP_INT_MAX );

add_action( 'wp', static function (): void {
    if ( ! wp_flame_benchmark_fixture_enabled() ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only local benchmark selector.
    $fixture = isset( $_GET['wp_flame_fixture'] ) ? sanitize_key( wp_unslash( $_GET['wp_flame_fixture'] ) ) : '';
    if ( $fixture === 'queries-100' ) {
        global $wpdb;
        for ( $query = 0; $query < 100; $query++ ) {
            $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional measured query fixture.
                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'blogname' )
            );
        }
        return;
    }

    if ( ! in_array( $fixture, [ 'spans-2000', 'spans-maximum' ], true ) || ! class_exists( '\WPFlame\Collector' ) ) {
        return;
    }
    $count = $fixture === 'spans-maximum' ? 6000 : 2000;
    $collector = \WPFlame\Collector::instance();
    for ( $index = 0; $index < $count; $index++ ) {
        $span_id = $collector->start_span( 'benchmark-span-' . $index, \WPFlame\Span::TYPE_PHP, 'benchmark-fixture' );
        $collector->end_span( $span_id );
    }
}, 1 );
