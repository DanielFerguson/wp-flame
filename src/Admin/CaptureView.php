<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\CaptureSession;
use WPFlame\CohortComparison;
use WPFlame\Config;
use WPFlame\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CaptureView
{
    private Storage $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function render(): void
    {
        $sessions = $this->storage->list_capture_sessions( 20 );

        $started_key = 'wp_flame_capture_started_' . get_current_user_id();
        if ( get_transient( $started_key ) ) {
            delete_transient( $started_key );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Capture armed. Open the page or workflow in this browser now; the request limit and expiry remain in force.', 'wp-flame' ) . '</p></div>';
        }
        $error_key = 'wp_flame_capture_start_error_' . get_current_user_id();
        if ( get_transient( $error_key ) ) {
            delete_transient( $error_key );
            echo '<div class="notice notice-error"><p>' . esc_html__( 'The capture session could not be saved. Check storage health, then retry.', 'wp-flame' ) . '</p></div>';
        }

        echo '<section class="wp-flame-guided-capture" aria-labelledby="wp-flame-guided-title">';
        echo '<div class="wp-flame-guided-heading"><div>';
        echo '<h2 id="wp-flame-guided-title">' . esc_html__( 'Find what is slowing a workflow', 'wp-flame' ) . '</h2>';
        echo '<p>' . esc_html__( 'Arm a short capture, reproduce the slow page or action in this browser, then return here. WP Flame never signs in or replays an authenticated URL on your behalf.', 'wp-flame' ) . '</p>';
        echo '</div>';
        $this->render_health_badge();
        echo '</div>';

        echo '<div class="wp-flame-capture-options">';
        $this->render_start_form( 'standard', 'baseline', 10 );
        $this->render_start_form( 'deep', 'observation', 1 );
        echo '</div>';

        if ( $sessions !== [] ) {
            $this->render_sessions( $sessions );
            $this->render_comparison( $sessions );
        }
        echo '</section>';
    }

    private function render_health_badge(): void
    {
        $table = $this->storage->table_health();
        $quota = $this->storage->get_storage_health(
            Config::bounded_int(
                get_option( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
                Config::DEFAULT_RETENTION_DAYS,
                1,
                Config::MAX_RETENTION_DAYS
            )
        );
        $mu_dir = function_exists( '\wp_flame_mu_plugin_dir' ) ? \wp_flame_mu_plugin_dir() : '';
        $mu_file = $mu_dir !== '' ? $mu_dir . '/wp-flame-early-hooks.php' : '';
        $expected_hash = function_exists( '\wp_flame_mu_plugin_expected_hash' ) ? \wp_flame_mu_plugin_expected_hash() : '';
        $actual_hash = $mu_file !== '' ? \WPFlame\MuPluginManager::file_hash( $mu_file ) : '';
        $mu_ready = $expected_hash !== '' && $actual_hash !== '' && hash_equals( $expected_hash, $actual_hash );
        $table_status = Config::string_value( $table['status'] ?? 'unavailable', 'unavailable' );
        $ready = $table_status === 'ready' && empty( $quota['quota']['reached'] );

        echo '<div class="wp-flame-health ' . esc_attr( $ready ? 'is-ready' : 'is-blocked' ) . '">';
        echo '<strong>' . esc_html( $ready ? __( 'Ready to capture', 'wp-flame' ) : __( 'Capture needs attention', 'wp-flame' ) ) . '</strong>';
        echo '<span>' . esc_html( $mu_ready ? __( 'Early lifecycle available', 'wp-flame' ) : __( 'Early lifecycle unavailable; results will say so', 'wp-flame' ) ) . '</span>';
        echo '<span>' . esc_html( $table_status === 'ready' ? __( 'Storage ready', 'wp-flame' ) : __( 'Storage unavailable', 'wp-flame' ) ) . '</span>';
        echo '</div>';
    }

    private function render_start_form( string $mode, string $phase, int $count ): void
    {
        $deep = $mode === 'deep';
        echo '<form class="wp-flame-capture-option" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wp_flame_start_capture">';
        echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
        echo '<input type="hidden" name="phase" value="' . esc_attr( $phase ) . '">';
        wp_nonce_field( 'wp_flame_start_capture' );
        echo '<h3>' . esc_html( $deep ? __( 'One Deep trace', 'wp-flame' ) : __( 'Guided Standard session', 'wp-flame' ) ) . '</h3>';
        echo '<p>' . esc_html( $deep
            ? __( 'Captures callback-level evidence once. It has the highest overhead and expires after five minutes.', 'wp-flame' )
            : __( 'Captures database and HTTP evidence with lower overhead. It stops after the request limit or fifteen minutes.', 'wp-flame' ) ) . '</p>';
        echo '<label>' . esc_html__( 'Workflow type', 'wp-flame' ) . ' <select name="request_population">';
        $populations = [
            'frontend' => __( 'Frontend page navigation', 'wp-flame' ),
            'admin'    => __( 'WordPress admin page', 'wp-flame' ),
            'ajax'     => __( 'AJAX action', 'wp-flame' ),
            'rest'     => __( 'REST API request', 'wp-flame' ),
            'graphql'  => __( 'GraphQL request', 'wp-flame' ),
        ];
        foreach ( $populations as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
        echo '<p class="description">' . esc_html__( 'Choose the request population you will reproduce. Background requests from other populations are ignored.', 'wp-flame' ) . '</p>';
        if ( ! $deep ) {
            echo '<label>' . esc_html__( 'Requests to capture', 'wp-flame' ) . ' ';
            echo '<input name="request_count" type="number" min="1" max="' . esc_attr( (string) CaptureSession::MAX_STANDARD_REQUESTS ) . '" value="' . esc_attr( (string) $count ) . '"></label>';
            echo '<label>' . esc_html__( 'Purpose', 'wp-flame' ) . ' <select name="capture_phase">';
            echo '<option value="baseline">' . esc_html__( 'Before a change', 'wp-flame' ) . '</option>';
            echo '<option value="after">' . esc_html__( 'After a change', 'wp-flame' ) . '</option>';
            echo '<option value="observation">' . esc_html__( 'Investigate only', 'wp-flame' ) . '</option>';
            echo '</select></label>';
        }
        echo '<button class="button button-primary" type="submit">' . esc_html( $deep ? __( 'Arm one Deep trace', 'wp-flame' ) : __( 'Arm capture session', 'wp-flame' ) ) . '</button>';
        echo '</form>';
    }

    /** @param CaptureSession[] $sessions */
    private function render_sessions( array $sessions ): void
    {
        echo '<div class="wp-flame-capture-progress">';
        echo '<h3>' . esc_html__( 'Recent guided captures', 'wp-flame' ) . '</h3>';
        echo '<div class="wp-flame-session-list">';
        foreach ( $sessions as $session ) {
            $traces = $this->storage->list_traces( [
                'capture_session_id' => $session->id,
                'per_page'           => CaptureSession::MAX_STANDARD_REQUESTS,
                'page'               => 1,
            ] );
            echo '<article class="wp-flame-session">';
            echo '<div><strong>' . esc_html( ucfirst( $session->phase ) . ' · ' . ucfirst( $session->instrumentation_mode ) ) . '</strong>';
            echo '<span>' . esc_html( sprintf(
                /* translators: 1: captured request count, 2: target request count, 3: session status */
                __( '%1$d of %2$d stored · %3$s', 'wp-flame' ),
                $session->captured_count,
                $session->requested_count,
                $session->status
            ) ) . '</span>';
            if ( $session->route_key !== '' ) {
                echo '<span><code>' . esc_html( $session->request_type . ' · ' . $session->route_key ) . '</code></span>';
            } else {
                echo '<span>' . esc_html__( 'Waiting for the first reproduced route', 'wp-flame' ) . '</span>';
            }
            echo '</div>';
            echo '<progress max="' . esc_attr( (string) $session->requested_count ) . '" value="' . esc_attr( (string) $session->captured_count ) . '">';
            echo esc_html( $session->progress_percent() . '%' ) . '</progress>';
            if ( $traces !== [] ) {
                $trace_id = Config::string_value( $traces[0]['trace_id'] ?? '', '' );
                echo '<a class="button button-small" href="' . esc_url( admin_url( 'tools.php?page=wp-flame&trace_id=' . rawurlencode( $trace_id ) ) ) . '">' . esc_html__( 'Open latest stored trace', 'wp-flame' ) . '</a>';
            }
            if ( $session->status === 'active' ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="wp_flame_stop_capture"><input type="hidden" name="session_id" value="' . esc_attr( $session->id ) . '">';
                wp_nonce_field( 'wp_flame_stop_capture_' . $session->id );
                echo '<button class="button-link" type="submit">' . esc_html__( 'Stop', 'wp-flame' ) . '</button></form>';
            }
            echo '</article>';
        }
        echo '</div></div>';
    }

    /** @param CaptureSession[] $sessions */
    private function render_comparison( array $sessions ): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison selector; UUID-bounded immediately.
        $baseline_id = $this->request_session_id( $_GET['baseline_session'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison selector; UUID-bounded immediately.
        $after_id = $this->request_session_id( $_GET['after_session'] ?? '' );

        echo '<div class="wp-flame-comparison-builder">';
        echo '<h3>' . esc_html__( 'Compare before and after', 'wp-flame' ) . '</h3>';
        echo '<p>' . esc_html__( 'WP Flame only compares the same normalized route, request population, mode, capabilities, score version, and environment. Ten observations per side are directional; twenty are required for a verified label.', 'wp-flame' ) . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="wp-flame">';
        echo '<label>' . esc_html__( 'Baseline session', 'wp-flame' ) . ' <select name="baseline_session"><option value="">' . esc_html__( 'Choose baseline', 'wp-flame' ) . '</option>';
        $this->render_session_options( $sessions, 'baseline', $baseline_id );
        echo '</select></label> ';
        echo '<label>' . esc_html__( 'After session', 'wp-flame' ) . ' <select name="after_session"><option value="">' . esc_html__( 'Choose after', 'wp-flame' ) . '</option>';
        $this->render_session_options( $sessions, 'after', $after_id );
        echo '</select></label> ';
        echo '<button class="button" type="submit">' . esc_html__( 'Compare compatible evidence', 'wp-flame' ) . '</button></form>';

        if ( $baseline_id !== '' && $after_id !== '' ) {
            $baseline_traces = $this->storage->get_capture_session_traces( $baseline_id, 100 );
            $after_traces = $this->storage->get_capture_session_traces( $after_id, 100 );
            $comparison = CohortComparison::compare(
                $baseline_traces,
                $after_traces,
                $this->environment_map( array_merge( $baseline_traces, $after_traces ) )
            );
            $this->render_comparison_result( $comparison, $baseline_id, $after_id );
        }
        echo '</div>';
    }

    /** @param CaptureSession[] $sessions */
    private function render_session_options( array $sessions, string $phase, string $selected_id ): void
    {
        foreach ( $sessions as $session ) {
            if ( $session->phase !== $phase || $session->captured_count < 1 ) {
                continue;
            }
            $label = sprintf(
                /* translators: 1: mode, 2: captured count, 3: session status */
                __( '%1$s · %2$d stored · %3$s', 'wp-flame' ),
                ucfirst( $session->instrumentation_mode ),
                $session->captured_count,
                $session->status
            );
            echo '<option value="' . esc_attr( $session->id ) . '"' . selected( $selected_id, $session->id, false ) . '>' . esc_html( $label ) . '</option>';
        }
    }

    /** @param array<string, mixed> $comparison */
    private function render_comparison_result( array $comparison, string $baseline_id, string $after_id ): void
    {
        $status = Config::string_value( $comparison['status'] ?? 'invalid', 'invalid' );
        echo '<section class="wp-flame-comparison-result is-' . esc_attr( $status ) . '" aria-labelledby="wp-flame-comparison-title">';
        echo '<h4 id="wp-flame-comparison-title">' . esc_html( ucfirst( $status ) . ' comparison' ) . '</h4>';
        echo '<p><strong>' . esc_html( sprintf(
            /* translators: 1: baseline sample count, 2: after sample count */
            __( '%1$d baseline · %2$d after', 'wp-flame' ),
            Config::bounded_int( $comparison['baseline_count'] ?? 0, 0, 0, 1000 ),
            Config::bounded_int( $comparison['after_count'] ?? 0, 0, 0, 1000 )
        ) ) . '</strong></p>';

        if ( ! empty( $comparison['reasons'] ) && is_array( $comparison['reasons'] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No performance conclusion is valid:', 'wp-flame' ) . '</p><ul>';
            foreach ( array_slice( $comparison['reasons'], 0, 10 ) as $reason ) {
                echo '<li>' . esc_html( Config::string_value( $reason, '' ) ) . '</li>';
            }
            echo '</ul></div>';
        }
        if ( ! empty( $comparison['warnings'] ) && is_array( $comparison['warnings'] ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Environment warning:', 'wp-flame' ) . '</p><ul>';
            foreach ( array_slice( $comparison['warnings'], 0, 10 ) as $warning ) {
                echo '<li>' . esc_html( Config::string_value( $warning, '' ) ) . '</li>';
            }
            echo '</ul></div>';
        }

        $baseline = is_array( $comparison['baseline'] ?? null ) ? $comparison['baseline'] : [];
        $after = is_array( $comparison['after'] ?? null ) ? $comparison['after'] : [];
        echo '<table class="widefat striped wp-flame-comparison-table"><thead><tr><th>' . esc_html__( 'Measure', 'wp-flame' ) . '</th><th>' . esc_html__( 'Before', 'wp-flame' ) . '</th><th>' . esc_html__( 'After', 'wp-flame' ) . '</th></tr></thead><tbody>';
        $rows = [
            __( 'p50 response', 'wp-flame' ) => [ $baseline['p50_ms'] ?? 0, $after['p50_ms'] ?? 0, ' ms' ],
            __( 'p95 response', 'wp-flame' ) => [ $baseline['p95_ms'] ?? null, $after['p95_ms'] ?? null, ' ms' ],
            __( 'Average database', 'wp-flame' ) => [ $baseline['average_db_ms'] ?? 0, $after['average_db_ms'] ?? 0, ' ms' ],
            __( 'Average external HTTP', 'wp-flame' ) => [ $baseline['average_http_ms'] ?? 0, $after['average_http_ms'] ?? 0, ' ms' ],
            __( 'Average query count', 'wp-flame' ) => [ $baseline['average_queries'] ?? 0, $after['average_queries'] ?? 0, '' ],
            __( 'Average score', 'wp-flame' ) => [ $baseline['average_score'] ?? 0, $after['average_score'] ?? 0, '/100' ],
            __( 'Complete captures', 'wp-flame' ) => [ $baseline['complete_count'] ?? 0, $after['complete_count'] ?? 0, '' ],
        ];
        foreach ( $rows as $label => $values ) {
            echo '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( $this->metric( $values[0], $values[2] ) ) . '</td><td>' . esc_html( $this->metric( $values[1], $values[2] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->render_distribution_comparison(
            is_array( $baseline['distribution_ms'] ?? null ) ? $baseline['distribution_ms'] : [],
            is_array( $after['distribution_ms'] ?? null ) ? $after['distribution_ms'] : []
        );
        $baseline_signature = is_array( $comparison['baseline_signature'] ?? null ) ? $comparison['baseline_signature'] : [];
        $after_signature = is_array( $comparison['after_signature'] ?? null ) ? $comparison['after_signature'] : [];
        $this->render_capability_comparison(
            is_array( $baseline_signature['capabilities'] ?? null ) ? $baseline_signature['capabilities'] : [],
            is_array( $after_signature['capabilities'] ?? null ) ? $after_signature['capabilities'] : []
        );
        $this->render_map_comparison(
            __( 'Top measured sources', 'wp-flame' ),
            is_array( $baseline['top_sources'] ?? null ) ? $baseline['top_sources'] : [],
            is_array( $after['top_sources'] ?? null ) ? $after['top_sources'] : [],
            ' ms total span time'
        );
        $this->render_map_comparison(
            __( 'Top observed callbacks', 'wp-flame' ),
            is_array( $baseline['top_callbacks'] ?? null ) ? $baseline['top_callbacks'] : [],
            is_array( $after['top_callbacks'] ?? null ) ? $after['top_callbacks'] : [],
            ' ms total span time'
        );
        $this->render_map_comparison(
            __( 'Score factors', 'wp-flame' ),
            is_array( $baseline['score_factors'] ?? null ) ? $baseline['score_factors'] : [],
            is_array( $after['score_factors'] ?? null ) ? $after['score_factors'] : [],
            '/100'
        );
        echo '<p><strong>' . esc_html__( 'Median change:', 'wp-flame' ) . '</strong> ' . esc_html( $this->metric( $comparison['p50_delta_ms'] ?? 0, ' ms' ) . ' (' . $this->metric( $comparison['p50_delta_percent'] ?? 0, '%' ) . ')' ) . '</p>';
        echo '<p class="description">' . esc_html( Config::string_value( $comparison['uncertainty'] ?? '', '' ) ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wp_flame_export_comparison"><input type="hidden" name="baseline_session" value="' . esc_attr( $baseline_id ) . '"><input type="hidden" name="after_session" value="' . esc_attr( $after_id ) . '">';
        wp_nonce_field( 'wp_flame_export_comparison_' . $baseline_id . '_' . $after_id );
        echo '<label><input type="checkbox" name="include_sensitive" value="1"> ' . esc_html__( 'Explicitly include the unredacted normalized route key (may identify content)', 'wp-flame' ) . '</label> ';
        echo '<button class="button" type="submit">' . esc_html__( 'Download local JSON report', 'wp-flame' ) . '</button></form>';
        echo '</section>';
    }

    /** @param array<int, mixed> $baseline @param array<int, mixed> $after */
    private function render_distribution_comparison( array $baseline, array $after ): void
    {
        if ( $baseline === [] && $after === [] ) {
            return;
        }
        echo '<h5>' . esc_html__( 'Observed response distribution', 'wp-flame' ) . '</h5>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Percentile', 'wp-flame' ) . '</th><th>' . esc_html__( 'Before', 'wp-flame' ) . '</th><th>' . esc_html__( 'After', 'wp-flame' ) . '</th></tr></thead><tbody>';
        foreach ( [ [ 0.0, 'Minimum' ], [ 0.25, 'p25' ], [ 0.5, 'p50' ], [ 0.75, 'p75' ], [ 1.0, 'Maximum' ] ] as $percentile ) {
            echo '<tr><th scope="row">' . esc_html( $percentile[1] ) . '</th><td>' . esc_html( $this->metric( $this->distribution_percentile( $baseline, $percentile[0] ), ' ms' ) ) . '</td><td>' . esc_html( $this->metric( $this->distribution_percentile( $after, $percentile[0] ), ' ms' ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<int, mixed> $values */
    private function distribution_percentile( array $values, float $percentile ): ?float
    {
        $values = array_values( array_filter( array_map( 'floatval', $values ), 'is_finite' ) );
        if ( $values === [] ) {
            return null;
        }
        sort( $values, SORT_NUMERIC );
        $rank = ( count( $values ) - 1 ) * min( 1.0, max( 0.0, $percentile ) );
        $lower = (int) floor( $rank );
        $upper = (int) ceil( $rank );
        $value = $values[ $lower ];
        if ( $lower !== $upper ) {
            $value += ( $values[ $upper ] - $values[ $lower ] ) * ( $rank - $lower );
        }
        return round( $value, 2 );
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $after */
    private function render_capability_comparison( array $baseline, array $after ): void
    {
        $keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $after ) ) ) );
        if ( $keys === [] ) {
            return;
        }
        sort( $keys );
        echo '<h5>' . esc_html__( 'Capture capability comparison', 'wp-flame' ) . '</h5>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'wp-flame' ) . '</th><th>' . esc_html__( 'Before', 'wp-flame' ) . '</th><th>' . esc_html__( 'After', 'wp-flame' ) . '</th></tr></thead><tbody>';
        foreach ( $keys as $key ) {
            echo '<tr><th scope="row">' . esc_html( str_replace( '_', ' ', ucfirst( Config::string_value( $key, '' ) ) ) ) . '</th><td>' . esc_html( $this->capability_label( $baseline[ $key ] ?? [] ) ) . '</td><td>' . esc_html( $this->capability_label( $after[ $key ] ?? [] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param mixed $value */
    private function capability_label( $value ): string
    {
        if ( ! is_array( $value ) ) {
            return __( 'Unknown', 'wp-flame' );
        }
        $status = Config::string_value( $value['status'] ?? 'unknown', 'unknown' );
        $reason = Config::string_value( $value['reason'] ?? '', '' );
        return $reason !== '' ? $status . ' — ' . str_replace( '_', ' ', $reason ) : $status;
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $after */
    private function render_map_comparison( string $title, array $baseline, array $after, string $suffix ): void
    {
        $keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $after ) ) ) );
        if ( $keys === [] ) {
            return;
        }
        $keys = array_slice( $keys, 0, 10 );
        echo '<h5>' . esc_html( $title ) . '</h5><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Evidence owner', 'wp-flame' ) . '</th><th>' . esc_html__( 'Before', 'wp-flame' ) . '</th><th>' . esc_html__( 'After', 'wp-flame' ) . '</th></tr></thead><tbody>';
        foreach ( $keys as $key ) {
            $label = substr( Config::string_value( $key, '' ), 0, 300 );
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $this->metric( $baseline[ $key ] ?? 0, $suffix ) ) . '</td><td>' . esc_html( $this->metric( $after[ $key ] ?? 0, $suffix ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param mixed $value */
    private function metric( $value, string $suffix ): string
    {
        if ( $value === null || $value === '' ) {
            return __( 'Not enough samples', 'wp-flame' );
        }
        $number = is_numeric( $value ) ? round( (float) $value, 2 ) : 0.0;
        return (string) $number . $suffix;
    }

    /** @param mixed $value */
    private function request_session_id( $value ): string
    {
        $value = Config::string_value( wp_unslash( $value ), '' );
        return preg_match( '/^[a-f0-9-]{36}$/i', $value ) ? strtolower( $value ) : '';
    }

    /** @param \WPFlame\Trace[] $traces @return array<string, \WPFlame\EnvironmentSnapshot> */
    private function environment_map( array $traces ): array
    {
        $environments = [];
        foreach ( $traces as $trace ) {
            $snapshot_id = $trace->capture_report->environment_snapshot_id;
            if ( $snapshot_id === null || isset( $environments[ $snapshot_id ] ) ) {
                continue;
            }
            $snapshot = $this->storage->get_environment_snapshot( $snapshot_id );
            if ( $snapshot !== null ) {
                $environments[ $snapshot_id ] = $snapshot;
            }
        }
        return $environments;
    }
}
