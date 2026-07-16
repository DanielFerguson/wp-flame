<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Instrumentation
{
    public const FORCE_TRACE_NONCE_ACTION = 'wp_flame_force_trace';
    public const CAPTURE_SESSION_NONCE_PREFIX = 'wp_flame_capture_session_';
    private const MAX_FORCE_TRACE_NONCE_BYTES = 128;
    private const MAX_FORCE_TRACE_COOKIE_DELETE_PATHS = 4;
    private const MAX_COOKIE_PATH_BYTES = 256;
    private const MAX_COOKIE_DOMAIN_BYTES = 253;
    private const MAX_NOTICE_TRACE_ID_BYTES = 128;
    private const MAX_CAPTURE_SESSION_COOKIE_BYTES = 180;

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
        if ( $force_trace ) {
            return true;
        }

        if ( ! $enabled ) {
            return false;
        }

        if ( $is_cron ) {
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

        if ( strlen( $nonce ) > self::MAX_FORCE_TRACE_NONCE_BYTES ) {
            return false;
        }

        return (bool) $verify_nonce( $nonce );
    }

    /**
     * Validate a bounded, administrator-bound capture-session cookie.
     *
     * The returned session ID is only an identifier. Callers must still load
     * the server-side session and check its status, expiry, and request quota.
     *
     * @param mixed    $cookie_value
     * @param callable $verify_nonce Receives nonce and session-specific action.
     */
    public static function capture_session_id_from_cookie(
        $cookie_value,
        bool $is_logged_in,
        bool $can_manage_options,
        callable $verify_nonce
    ): string {
        if ( ! $is_logged_in || ! $can_manage_options || ! is_scalar( $cookie_value ) ) {
            return '';
        }

        $value = trim( (string) $cookie_value );
        if ( $value === '' || strlen( $value ) > self::MAX_CAPTURE_SESSION_COOKIE_BYTES ) {
            return '';
        }

        $separator = strpos( $value, '.' );
        if ( $separator === false ) {
            return '';
        }

        $session_id = substr( $value, 0, $separator );
        $nonce = substr( $value, $separator + 1 );
        if (
            ! preg_match( '/^[a-f0-9-]{36}$/i', $session_id )
            || $nonce === ''
            || strlen( $nonce ) > self::MAX_FORCE_TRACE_NONCE_BYTES
        ) {
            return '';
        }

        $action = self::CAPTURE_SESSION_NONCE_PREFIX . strtolower( $session_id );

        return (bool) $verify_nonce( $nonce, $action ) ? strtolower( $session_id ) : '';
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
        if ( strlen( $domain ) > self::MAX_COOKIE_DOMAIN_BYTES ) {
            $domain = '';
        }
        $domains = $domain !== '' ? [ $domain, '' ] : [ '' ];
        $path_count = 0;

        foreach ( $paths as $path ) {
            $path = is_string( $path ) ? trim( $path ) : '';
            if ( $path === '' ) {
                $path = '/';
            }

            if ( $path[0] !== '/' ) {
                $path = '/' . $path;
            }

            if ( strlen( $path ) > self::MAX_COOKIE_PATH_BYTES ) {
                continue;
            }

            if ( isset( $seen[ $path ] ) ) {
                continue;
            }

            $seen[ $path ] = true;
            $path_count++;

            foreach ( $domains as $delete_domain ) {
                $options[] = [
                    'expires'  => time() - 3600,
                    'path'     => $path,
                    'domain'   => $delete_domain,
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ];
            }

            if ( $path_count >= self::MAX_FORCE_TRACE_COOKIE_DELETE_PATHS ) {
                break;
            }
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
        if ( ! is_string( $value ) ) {
            return '';
        }
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

        $meta = [ 'cache_backend' => get_class( $cache ) ];
        if ( array_key_exists( 'cache_hits', $cache_vars ) ) {
            $meta['cache_hits'] = Config::bounded_int( $cache_vars['cache_hits'], 0, 0, PHP_INT_MAX );
        }
        if ( array_key_exists( 'cache_misses', $cache_vars ) ) {
            $meta['cache_misses'] = Config::bounded_int( $cache_vars['cache_misses'], 0, 0, PHP_INT_MAX );
        }

        return $meta;
    }

    /**
     * Register instrumentors with GraphQL/DB mutual exclusion.
     *
     * @param Instrumentor[] $instrumentors
     * @param callable|null $define_savequeries Enables SAVEQUERIES and returns whether it is active.
     * @return array{graphql_active: bool, graphql_db_active: bool, registered: array<int, string>, statuses: array<string, string>}
     */
    public static function register_instrumentors(
        array $instrumentors,
        Collector $collector,
        ?callable $define_savequeries = null
    ): array {
        $graphql_active    = false;
        $graphql_db_active = false;
        $registered        = [];
        $statuses          = [];

        foreach ( $instrumentors as $instrumentor ) {
            if ( ! ( $instrumentor instanceof GraphQL ) ) {
                continue;
            }

            $applicability = self::instrumentor_applicability( $instrumentor );
            if ( $applicability !== 'applicable' ) {
                $statuses[ get_class( $instrumentor ) ] = $applicability;
                continue;
            }

            $requires_savequeries = ! method_exists( $instrumentor, 'requires_savequeries' )
                || $instrumentor->requires_savequeries();

            $savequeries_active = false;
            if ( $requires_savequeries ) {
                $savequeries_active = $define_savequeries !== null
                    ? (bool) $define_savequeries()
                    : ( defined( 'SAVEQUERIES' ) && SAVEQUERIES );
            }

            if ( self::register_instrumentor( $instrumentor, $collector ) ) {
                $graphql_active    = true;
                $graphql_db_active = $requires_savequeries && $savequeries_active;
                $registered[]      = get_class( $instrumentor );
                $statuses[ get_class( $instrumentor ) ] = 'registered';
            } else {
                $statuses[ get_class( $instrumentor ) ] = 'failed';
            }
        }

        foreach ( $instrumentors as $instrumentor ) {
            if ( ! ( $instrumentor instanceof Instrumentor ) ) {
                continue;
            }

            if ( $instrumentor instanceof GraphQL ) {
                continue;
            }

            if ( $instrumentor instanceof DbInstrumentor && $graphql_db_active ) {
                $statuses[ get_class( $instrumentor ) ] = 'superseded';
                continue;
            }

            $applicability = self::instrumentor_applicability( $instrumentor );
            if ( $applicability !== 'applicable' ) {
                $statuses[ get_class( $instrumentor ) ] = $applicability;
                continue;
            }

            if ( self::register_instrumentor( $instrumentor, $collector ) ) {
                $registered[] = get_class( $instrumentor );
                $statuses[ get_class( $instrumentor ) ] = 'registered';
            } else {
                $statuses[ get_class( $instrumentor ) ] = 'failed';
            }
        }

        return [
            'graphql_active'    => $graphql_active,
            'graphql_db_active' => $graphql_db_active,
            'registered'        => $registered,
            'statuses'          => $statuses,
        ];
    }

    private static function instrumentor_applicability( Instrumentor $instrumentor ): string
    {
        try {
            return $instrumentor->is_applicable() ? 'applicable' : 'unavailable';
        } catch ( \Throwable $e ) {
            self::log_instrumentor_failure( 'applicability', $instrumentor, $e );
            return 'failed';
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
