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

if ( ! function_exists( 'wp_flame_mu_string_value' ) ) {
    function wp_flame_mu_string_value( $value, string $fallback = '' ): string {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
            try {
                return (string) $value;
            } catch ( Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }
}

if ( ! function_exists( 'wp_flame_mu_boolean' ) ) {
    function wp_flame_mu_boolean( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        $value = wp_flame_mu_string_value( $value, '' );
        return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
    }
}

if ( ! function_exists( 'wp_flame_mu_bounded_int' ) ) {
    function wp_flame_mu_bounded_int( $value, int $default, int $min, int $max ): int {
        if ( is_int( $value ) ) {
            $number = $value;
        } elseif ( is_float( $value ) ) {
            $number = is_finite( $value ) ? (int) $value : $default;
        } elseif ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $float  = (float) trim( $value );
            $number = is_finite( $float ) ? (int) $float : $default;
        } else {
            $number = $default;
        }

        return min( $max, max( $min, $number ) );
    }
}

if ( ! function_exists( 'wp_flame_mu_plugin_match' ) ) {
    function wp_flame_mu_plugin_match( $plugin_file, string $stored_plugin_file ): bool {
        $plugin_file = wp_flame_mu_string_value( $plugin_file, '' );
        if ( $plugin_file === '' ) {
            return false;
        }

        return ( $stored_plugin_file !== '' && $plugin_file === $stored_plugin_file )
            || strpos( $plugin_file, 'wp-flame/' ) === 0
            || strpos( $plugin_file, 'wp-flame\\' ) === 0;
    }
}

if ( ! function_exists( 'wp_flame_mu_plugin_option_matches' ) ) {
    function wp_flame_mu_plugin_option_matches( array $plugins, string $stored_plugin_file ): bool {
        foreach ( $plugins as $plugin_key => $plugin_value ) {
            if (
                wp_flame_mu_plugin_match( $plugin_key, $stored_plugin_file )
                || wp_flame_mu_plugin_match( $plugin_value, $stored_plugin_file )
            ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'wp_flame_mu_plugin_dir_from_file' ) ) {
    function wp_flame_mu_plugin_dir_from_file( string $plugin_file ): string {
        $plugin_file = str_replace( '\\', '/', trim( $plugin_file ) );
        if (
            $plugin_file === ''
            || $plugin_file[0] === '/'
            || strpos( $plugin_file, ':' ) !== false
            || preg_match( '#(^|/)\.\.(/|$)#', $plugin_file )
        ) {
            return '';
        }

        return WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
    }
}

if ( ! function_exists( 'wp_flame_mu_has_logged_in_cookie' ) ) {
    function wp_flame_mu_has_logged_in_cookie( array $cookies ): bool {
        if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! is_string( LOGGED_IN_COOKIE ) || LOGGED_IN_COOKIE === '' ) {
            return true;
        }

        if ( empty( $cookies[ LOGGED_IN_COOKIE ] ) || ! is_scalar( $cookies[ LOGGED_IN_COOKIE ] ) ) {
            return false;
        }

        return trim( wp_flame_mu_string_value( $cookies[ LOGGED_IN_COOKIE ], '' ) ) !== '';
    }
}

if ( ! function_exists( 'wp_flame_mu_has_non_cookie_auth' ) ) {
    function wp_flame_mu_has_non_cookie_auth( array $server ): bool {
        foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER' ] as $key ) {
            if ( empty( $server[ $key ] ) || ! is_scalar( $server[ $key ] ) ) {
                continue;
            }

            if ( trim( wp_flame_mu_string_value( $server[ $key ], '' ) ) !== '' ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'wp_flame_mu_force_cookie_can_bypass_early_guards' ) ) {
    function wp_flame_mu_force_cookie_can_bypass_early_guards( array $cookies, array $server ): bool {
        $value = $cookies['wp_flame_force_trace'] ?? '';
        if ( ! is_scalar( $value ) ) {
            return false;
        }

        $nonce = trim( wp_flame_mu_string_value( $value, '' ) );
        if ( $nonce === '' || strlen( $nonce ) > 128 ) {
            return false;
        }

        $has_logged_in_cookie = defined( 'LOGGED_IN_COOKIE' )
            && is_string( LOGGED_IN_COOKIE )
            && LOGGED_IN_COOKIE !== ''
            && wp_flame_mu_has_logged_in_cookie( $cookies );

        return $has_logged_in_cookie || wp_flame_mu_has_non_cookie_auth( $server );
    }
}

// Bail if the main plugin is not active (deactivated but mu-plugin not yet removed).
$wp_flame_plugin_file = wp_flame_mu_string_value( get_option( 'wp_flame_plugin_file', '' ), '' );
if ( $wp_flame_plugin_file === '' && is_multisite() ) {
    $wp_flame_plugin_file = wp_flame_mu_string_value( get_site_option( 'wp_flame_plugin_file', '' ), '' );
}

$wp_flame_active_plugins = (array) get_option( 'active_plugins', [] );
$wp_flame_is_active      = wp_flame_mu_plugin_option_matches( $wp_flame_active_plugins, $wp_flame_plugin_file );
// Also check network-active plugins on multisite.
if ( ! $wp_flame_is_active && is_multisite() ) {
    $wp_flame_network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
    $wp_flame_is_active = wp_flame_mu_plugin_option_matches( $wp_flame_network_plugins, $wp_flame_plugin_file );
}
if ( ! $wp_flame_is_active ) {
    return; // Main plugin deactivated — graceful no-op
}

// Avoid allocating spans at all when tracing is disabled or this request misses
// sampling. Audience and capability checks still happen later in the main
// plugin, after WordPress has loaded more of the current user stack.
$wp_flame_enabled = wp_flame_mu_boolean( get_option( 'wp_flame_enabled', true ) );

if ( ! $wp_flame_enabled ) {
    $GLOBALS['wp_flame_should_instrument_request'] = false;
    return;
}

$wp_flame_sample_rate = wp_flame_mu_bounded_int( get_option( 'wp_flame_sample_rate', 1 ), 1, 1, 1000000 );
$wp_flame_force_cookie_present = wp_flame_mu_force_cookie_can_bypass_early_guards( $_COOKIE, $_SERVER );
$wp_flame_is_cron_request = defined( 'DOING_CRON' ) && DOING_CRON;
$wp_flame_is_cli_request  = defined( 'WP_CLI' ) && WP_CLI;
$wp_flame_trace_audience  = wp_flame_mu_string_value( get_option( 'wp_flame_trace_audience', 'admins' ), 'admins' );
if ( ! in_array( $wp_flame_trace_audience, [ 'admins', 'logged_in', 'everyone' ], true ) ) {
    $wp_flame_trace_audience = 'admins';
}

if (
    in_array( $wp_flame_trace_audience, [ 'admins', 'logged_in' ], true )
    && ! $wp_flame_force_cookie_present
    && ! $wp_flame_is_cron_request
    && ! $wp_flame_is_cli_request
    && ! wp_flame_mu_has_non_cookie_auth( $_SERVER )
    && ! wp_flame_mu_has_logged_in_cookie( $_COOKIE )
) {
    $GLOBALS['wp_flame_should_instrument_request'] = false;
    return;
}

if ( $wp_flame_sample_rate > 1 && ! $wp_flame_force_cookie_present && ! $wp_flame_is_cron_request ) {
    $wp_flame_sample_roll = function_exists( 'wp_rand' )
        ? wp_rand( 1, $wp_flame_sample_rate )
        : rand( 1, $wp_flame_sample_rate );
    $GLOBALS['wp_flame_sample_roll'] = $wp_flame_sample_roll;

    if ( $wp_flame_sample_roll !== 1 ) {
        $GLOBALS['wp_flame_should_instrument_request'] = false;
        return;
    }
}

// Load the main plugin's autoloader. Prefer the stored plugin basename so
// renamed folders, symlinked checkouts, and commercial builds still load.
$wp_flame_plugin_dir = wp_flame_mu_plugin_dir_from_file( $wp_flame_plugin_file );
if ( $wp_flame_plugin_dir === '' ) {
    $wp_flame_plugin_dir = WP_PLUGIN_DIR . '/wp-flame';
}
$wp_flame_autoload = $wp_flame_plugin_dir . '/vendor/autoload.php';
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
        $phase_id = $GLOBALS['wp_flame_current_phase_id'] ?? null;
        $collector->end_span( is_string( $phase_id ) && $phase_id !== '' ? $phase_id : null );
        $GLOBALS['wp_flame_current_phase_id'] = $collector->start_span(
            $next_phase_name,
            WPFlame\Span::TYPE_CORE,
            'wordpress'
        );
    }, 0 );
}
