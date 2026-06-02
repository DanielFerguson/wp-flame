<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instrumentation
{
    public const FORCE_TRACE_NONCE_ACTION = 'wp_flame_force_trace';
    private const MAX_NOTICE_TRACE_ID_BYTES = 128;

    /**
     * Decide whether this request should pay the full instrumentation cost.
     */
    public static function should_instrument(
        bool $enabled,
        string $audience,
        int $sample_rate,
        bool $force_trace,
        bool $is_cron,
        bool $is_logged_in,
        bool $can_manage_options,
        int $sample_roll = 1
    ): bool {
        if ( ! $enabled ) {
            return false;
        }

        if ( $force_trace || $is_cron ) {
            return true;
        }

        if ( $audience === 'admins' && ! $can_manage_options ) {
            return false;
        }

        if ( $audience === 'logged_in' && ! $is_logged_in ) {
            return false;
        }

        if ( ! in_array( $audience, [ 'admins', 'logged_in', 'everyone' ], true ) ) {
            return false;
        }

        $sample_rate = max( 1, $sample_rate );
        if ( $sample_rate === 1 ) {
            return true;
        }

        return $sample_roll === 1;
    }

    /**
     * Build request identity fields according to explicit privacy opt-ins.
     *
     * @return array{user_id: int, ip_address: string, meta: array<string, string>}
     */
    public static function request_identity(
        bool $track_users,
        bool $track_ips,
        bool $track_user_agent,
        int $current_user_id,
        string $ip_address,
        string $user_agent
    ): array {
        $meta = [];
        $user_agent = substr( $user_agent, 0, 500 );

        if ( $track_user_agent && $user_agent !== '' ) {
            $meta['user_agent'] = $user_agent;
        }

        return [
            'user_id'    => $track_users ? max( 0, $current_user_id ) : 0,
            'ip_address' => $track_ips ? $ip_address : '',
            'meta'       => $meta,
        ];
    }

    /**
     * Resolve the request IP from a caller-provided list of trusted server keys.
     *
     * @param array<string, mixed> $server
     * @param array<int, string>   $trusted_headers
     */
    public static function client_ip_from_server( array $server, array $trusted_headers ): string
    {
        foreach ( $trusted_headers as $header ) {
            if ( ! is_string( $header ) || $header === '' ) {
                continue;
            }

            if ( empty( $server[ $header ] ) ) {
                continue;
            }

            $ip = sanitize_text_field( Config::string_value( wp_unslash( $server[ $header ] ), '' ) );
            if ( $ip === '' ) {
                continue;
            }

            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }

            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        return '';
    }

    /**
     * Validate an admin-requested one-shot trace cookie.
     *
     * @param mixed    $cookie_value
     * @param callable $verify_nonce Receives the cookie nonce and returns true when valid.
     */
    public static function is_force_trace_request(
        $cookie_value,
        bool $is_logged_in,
        bool $can_manage_options,
        callable $verify_nonce
    ): bool {
        if ( ! $is_logged_in || ! $can_manage_options ) {
            return false;
        }

        if ( ! is_scalar( $cookie_value ) ) {
            return false;
        }

        $nonce = trim( (string) $cookie_value );
        if ( $nonce === '' ) {
            return false;
        }

        return (bool) $verify_nonce( $nonce );
    }

    /**
     * Build deletion headers for the one-shot force-trace cookie.
     *
     * @param array<int, string> $paths
     * @return array<int, array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}>
     */
    public static function force_trace_cookie_delete_options( array $paths, string $domain = '', bool $secure = false ): array
    {
        $options = [];
        $seen    = [];
        $domain  = trim( $domain );

        foreach ( $paths as $path ) {
            $path = is_string( $path ) ? trim( $path ) : '';
            if ( $path === '' ) {
                $path = '/';
            }

            if ( $path[0] !== '/' ) {
                $path = '/' . $path;
            }

            if ( isset( $seen[ $path ] ) ) {
                continue;
            }

            $seen[ $path ] = true;
            $options[] = [
                'expires'  => time() - 3600,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Strict',
            ];
        }

        return $options;
    }

    /**
     * Normalize transient-provided trace IDs before using them in admin notices.
     *
     * @param mixed $value
     */
    public static function notice_trace_id( $value ): string
    {
        $trace_id = trim( Config::string_value( $value, '' ) );
        if ( $trace_id === '' ) {
            return '';
        }

        return substr( $trace_id, 0, self::MAX_NOTICE_TRACE_ID_BYTES );
    }

    /**
     * Determine whether this plugin is active network-wide from WordPress'
     * active_sitewide_plugins option shape.
     *
     * @param array<mixed> $active_sitewide_plugins
     */
    public static function is_network_active_plugin( string $plugin_file, array $active_sitewide_plugins ): bool
    {
        if ( $plugin_file === '' ) {
            return false;
        }

        if ( array_key_exists( $plugin_file, $active_sitewide_plugins ) ) {
            return true;
        }

        return in_array( $plugin_file, $active_sitewide_plugins, true );
    }

    /**
     * Determine whether this plugin is active on a single site from WordPress'
     * active_plugins option shape, with a conservative fallback for keyed arrays.
     *
     * @param array<mixed> $active_plugins
     */
    public static function is_site_active_plugin( string $plugin_file, array $active_plugins ): bool
    {
        if ( $plugin_file === '' ) {
            return false;
        }

        if ( array_key_exists( $plugin_file, $active_plugins ) ) {
            return true;
        }

        return in_array( $plugin_file, $active_plugins, true );
    }

    /**
     * Read public object-cache counters without touching private/protected
     * properties that some persistent cache drop-ins expose internally.
     *
     * @param mixed $cache
     * @return array{cache_hits?: int, cache_misses?: int, cache_backend?: string}
     */
    public static function cache_meta( $cache ): array
    {
        if ( ! is_object( $cache ) ) {
            return [];
        }

        $cache_vars = get_object_vars( $cache );

        return [
            'cache_hits'    => Config::bounded_int( $cache_vars['cache_hits'] ?? 0, 0, 0, PHP_INT_MAX ),
            'cache_misses'  => Config::bounded_int( $cache_vars['cache_misses'] ?? 0, 0, 0, PHP_INT_MAX ),
            'cache_backend' => get_class( $cache ),
        ];
    }

    /**
     * Register instrumentors with GraphQL/DB mutual exclusion.
     *
     * @param Instrumentor[] $instrumentors
     * @return array{graphql_active: bool, registered: array<int, string>}
     */
    public static function register_instrumentors(
        array $instrumentors,
        Collector $collector,
        ?callable $define_savequeries = null
    ): array {
        $graphql_active = false;
        $registered     = [];

        foreach ( $instrumentors as $instrumentor ) {
            if ( ! ( $instrumentor instanceof GraphQL ) ) {
                continue;
            }

            if ( ! self::instrumentor_is_applicable( $instrumentor ) ) {
                continue;
            }

            $requires_savequeries = ! method_exists( $instrumentor, 'requires_savequeries' )
                || $instrumentor->requires_savequeries();

            if ( $define_savequeries !== null && $requires_savequeries ) {
                $define_savequeries();
            }

            if ( self::register_instrumentor( $instrumentor, $collector ) ) {
                $graphql_active = true;
                $registered[]   = get_class( $instrumentor );
            }
        }

        foreach ( $instrumentors as $instrumentor ) {
            if ( ! ( $instrumentor instanceof Instrumentor ) ) {
                continue;
            }

            if ( $instrumentor instanceof GraphQL ) {
                continue;
            }

            if ( $instrumentor instanceof DbInstrumentor && $graphql_active ) {
                continue;
            }

            if ( ! self::instrumentor_is_applicable( $instrumentor ) ) {
                continue;
            }

            if ( self::register_instrumentor( $instrumentor, $collector ) ) {
                $registered[] = get_class( $instrumentor );
            }
        }

        return [
            'graphql_active' => $graphql_active,
            'registered'     => $registered,
        ];
    }

    private static function instrumentor_is_applicable( Instrumentor $instrumentor ): bool
    {
        try {
            return $instrumentor->is_applicable();
        } catch ( \Throwable $e ) {
            self::log_instrumentor_failure( 'applicability', $instrumentor, $e );
            return false;
        }
    }

    private static function register_instrumentor( Instrumentor $instrumentor, Collector $collector ): bool
    {
        try {
            $instrumentor->register( $collector );
            return true;
        } catch ( \Throwable $e ) {
            self::log_instrumentor_failure( 'registration', $instrumentor, $e );
            return false;
        }
    }

    private static function log_instrumentor_failure( string $phase, Instrumentor $instrumentor, \Throwable $e ): void
    {
        $should_log = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $should_log = Config::boolean( apply_filters( 'wp_flame_log_instrumentor_failures', $should_log, $phase, $instrumentor, $e ) );

        if ( ! $should_log ) {
            return;
        }

        error_log( 'WP Flame: Instrumentor ' . $phase . ' failed for ' . get_class( $instrumentor ) . ': ' . $e->getMessage() );
    }
}
