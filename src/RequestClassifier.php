<?php

declare(strict_types=1);

namespace WPFlame;

/**
 * Classify a WordPress request without relying on late-defined core constants.
 */
class RequestClassifier
{
    /**
     * @param array<string, mixed> $signals
     * @param array<string, mixed> $server
     * @param array<string, mixed> $request
     */
    public static function detect( array $signals, array $server, array $request ): string
    {
        if ( ! empty( $signals['is_cli'] ) ) {
            return 'cli';
        }
        if ( ! empty( $signals['is_cron'] ) ) {
            return 'cron';
        }
        if ( ! empty( $signals['is_ajax'] ) ) {
            return 'ajax';
        }

        $uri  = self::string_value( $server['REQUEST_URI'] ?? '' );
        $path = self::request_path( $uri );
        if ( ! empty( $signals['is_graphql'] ) || self::is_graphql_path( $path, self::string_value( $signals['graphql_endpoint'] ?? 'graphql' ) ) ) {
            return 'graphql';
        }

        $rest_route  = self::string_value( $signals['rest_route'] ?? ( $request['rest_route'] ?? '' ) );
        $rest_prefix = trim( self::string_value( $signals['rest_prefix'] ?? 'wp-json' ), '/' );
        if ( ! empty( $signals['is_rest'] ) || $rest_route !== '' || self::is_rest_path( $path, $rest_prefix ) ) {
            return 'rest';
        }

        return ! empty( $signals['is_admin'] ) ? 'admin' : 'frontend';
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

    private static function is_rest_path( string $path, string $prefix ): bool
    {
        if ( $prefix === '' ) {
            return false;
        }

        return $path === '/' . $prefix || strpos( $path, '/' . $prefix . '/' ) === 0;
    }

    private static function is_graphql_path( string $path, string $endpoint ): bool
    {
        $endpoint = trim( $endpoint, '/' );
        if ( $endpoint === '' ) {
            return false;
        }

        return $path === '/' . $endpoint || substr( $path, -strlen( '/' . $endpoint ) ) === '/' . $endpoint;
    }

    /**
     * @param mixed $value
     */
    private static function string_value( $value ): string
    {
        return is_string( $value ) || is_int( $value ) || is_float( $value ) ? trim( (string) $value ) : '';
    }
}
