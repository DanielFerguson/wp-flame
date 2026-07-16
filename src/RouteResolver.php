<?php

declare(strict_types=1);

namespace WPFlame;

/**
 * Build bounded, privacy-conscious cohort keys for supported request types.
 */
class RouteResolver
{
    public const MAX_ROUTE_KEY_BYTES = 255;

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $signals
     */
    public static function resolve( string $request_type, string $method, string $uri, array $request, array $signals = [] ): string
    {
        $method = strtoupper( self::bounded_token( $method, 10, 'GET' ) );

        switch ( $request_type ) {
            case 'rest':
                $route = self::string_value( $signals['matched_rest_route'] ?? ( $signals['rest_route'] ?? ( $request['rest_route'] ?? '' ) ) );
                if ( $route === '' ) {
                    $route = self::path_after_prefix( self::request_path( $uri ), self::string_value( $signals['rest_prefix'] ?? 'wp-json' ) );
                }
                return self::limit( $method . ' REST ' . self::normalize_path( $route ) );

            case 'ajax':
                $wc_action = self::bounded_token( $request['wc-ajax'] ?? ( $signals['wc_ajax_action'] ?? '' ), 100, '' );
                if ( $wc_action !== '' ) {
                    return self::limit( $method . ' WC_AJAX ' . $wc_action );
                }
                $action = self::bounded_token( $request['action'] ?? ( $signals['ajax_action'] ?? '' ), 100, 'unknown' );
                return self::limit( $method . ' AJAX ' . $action );

            case 'cron':
                $event = self::bounded_token( $signals['cron_event'] ?? '', 120, 'runner' );
                return self::limit( 'CRON ' . $event );

            case 'cli':
                $command = self::normalize_cli_command( self::string_value( $signals['cli_command'] ?? '' ) );
                return self::limit( 'CLI ' . ( $command !== '' ? $command : 'unknown' ) );

            case 'graphql':
                $operation = self::bounded_token( $signals['graphql_operation'] ?? '', 128, 'anonymous' );
                return self::limit( $method . ' GRAPHQL ' . $operation );

            case 'admin':
                $page = self::bounded_token( $request['page'] ?? '', 100, '' );
                return self::limit( $method . ' ADMIN ' . ( $page !== '' ? $page : self::normalize_path( self::request_path( $uri ) ) ) );

            default:
                return self::limit( $method . ' ' . self::normalize_path( self::request_path( $uri ) ) );
        }
    }

    /**
     * Exclude WP Flame's own admin and maintenance requests from customer data.
     *
     * @param array<string, mixed> $request
     */
    public static function is_wp_flame_request( string $request_type, array $request ): bool
    {
        $page   = self::bounded_token( $request['page'] ?? '', 100, '' );
        $action = self::bounded_token( $request['action'] ?? '', 100, '' );

        return ( $request_type === 'admin' && in_array( $page, [ 'wp-flame', 'wp-flame-settings' ], true ) )
            || ( $request_type === 'admin' && $action === 'wp_flame_purge' );
    }

    private static function normalize_cli_command( string $command ): string
    {
        $parts = preg_split( '/\s+/', trim( $command ) );
        if ( ! is_array( $parts ) ) {
            $parts = [];
        }
        $safe  = [];
        foreach ( $parts as $part ) {
            if ( $part === '' || $part[0] === '-' ) {
                continue;
            }
            $safe[] = self::bounded_token( $part, 64, '' );
            if ( count( $safe ) >= 2 ) {
                break;
            }
        }

        return trim( implode( ' ', array_filter( $safe ) ) );
    }

    private static function path_after_prefix( string $path, string $prefix ): string
    {
        $prefix = '/' . trim( $prefix, '/' );
        if ( $prefix !== '/' && ( $path === $prefix || strpos( $path, $prefix . '/' ) === 0 ) ) {
            $path = substr( $path, strlen( $prefix ) );
        }

        return $path !== '' ? $path : '/';
    }

    private static function request_path( string $uri ): string
    {
        $path  = substr( $uri, 0, 8192 );
        $query = strpos( $path, '?' );
        if ( $query !== false ) {
            $path = substr( $path, 0, $query );
        }

        return $path !== '' ? '/' . ltrim( $path, '/' ) : '/';
    }

    private static function normalize_path( string $path ): string
    {
        $path     = '/' . ltrim( rawurldecode( substr( $path, 0, 2048 ) ), '/' );
        $segments = explode( '/', $path );
        foreach ( $segments as $index => $segment ) {
            if ( $segment === '' ) {
                continue;
            }
            if ( preg_match( '/^\d+$/', $segment ) ) {
                $segments[ $index ] = '{id}';
            } elseif ( preg_match( '/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment ) ) {
                $segments[ $index ] = '{uuid}';
            } elseif ( preg_match( '/^[^@\s\/]+@[^@\s\/]+\.[^@\s\/]+$/', $segment ) || preg_match( '/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9_-]{24,}$/', $segment ) ) {
                $segments[ $index ] = '{redacted}';
            } else {
                $segments[ $index ] = self::bounded_token( $segment, 100, 'segment' );
            }
        }

        $normalized = implode( '/', $segments );

        return $normalized !== '' ? $normalized : '/';
    }

    /**
     * @param mixed $value
     */
    private static function bounded_token( $value, int $max_bytes, string $fallback ): string
    {
        $value = self::string_value( $value );
        $value = preg_replace( '/[^A-Za-z0-9_.:{}\/-]+/', '-', $value ) ?? '';
        $value = trim( $value, '-' );
        if ( $value === '' ) {
            return $fallback;
        }

        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }

    private static function limit( string $value ): string
    {
        return strlen( $value ) <= self::MAX_ROUTE_KEY_BYTES ? $value : substr( $value, 0, self::MAX_ROUTE_KEY_BYTES );
    }

    /**
     * @param mixed $value
     */
    private static function string_value( $value ): string
    {
        return is_string( $value ) || is_int( $value ) || is_float( $value ) ? trim( (string) $value ) : '';
    }
}
