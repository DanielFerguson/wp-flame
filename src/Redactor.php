<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Redactor
{
    public const MAX_SQL_LABEL_BYTES = 4096;

    private const REDACTED = '[redacted]';
    private const MAX_URI_BYTES = 8192;
    private const MAX_PATH_BYTES = 2048;
    private const MAX_QUERY_BYTES = 4096;
    private const MAX_QUERY_PARAMS = 50;
    private const MAX_QUERY_ARRAY_DEPTH = 2;
    private const MAX_SQL_NORMALIZE_BYTES = 8192;
    private const DEFAULT_SQL_LABEL_BYTES = 200;

    /** @var array<string, bool> */
    private const SAFE_QUERY_KEYS = [
        'page'     => true,
        'paged'    => true,
        'cpage'    => true,
        'per_page' => true,
        'offset'   => true,
        'limit'    => true,
        'orderby'  => true,
        'order'    => true,
        'sort'     => true,
    ];

    /**
     * Redact a request URI for storage. Keeps the path and only safe query
     * values, replacing all other query values with a marker.
     */
    public static function redact_request_uri( string $uri ): string
    {
        $truncated = false;
        if ( strlen( $uri ) > self::MAX_URI_BYTES ) {
            $uri = substr( $uri, 0, self::MAX_URI_BYTES );
            $truncated = true;
        }

        $parts = parse_url( $uri );
        if ( $parts === false ) {
            return '/';
        }

        $path = isset( $parts['path'] ) && $parts['path'] !== '' ? self::redact_path( self::limit_bytes( $parts['path'], self::MAX_PATH_BYTES ) ) : '/';
        if ( empty( $parts['query'] ) ) {
            return $truncated ? $path . '?_wp_flame_query_truncated=1' : $path;
        }

        $query = (string) $parts['query'];
        if ( strlen( $query ) > self::MAX_QUERY_BYTES ) {
            $query = substr( $query, 0, self::MAX_QUERY_BYTES );
            $truncated = true;
        }

        $pairs = explode( '&', $query );
        if ( count( $pairs ) > self::MAX_QUERY_PARAMS ) {
            $pairs = array_slice( $pairs, 0, self::MAX_QUERY_PARAMS );
            $truncated = true;
        }

        parse_str( implode( '&', $pairs ), $params );
        if ( empty( $params ) ) {
            return $truncated ? $path . '?_wp_flame_query_truncated=1' : $path;
        }

        $redacted = [];
        $position = 0;
        foreach ( $params as $key => $value ) {
            $key = (string) $key;
            $position++;
            $redacted[ self::safe_query_key( $key, $position ) ] = self::redact_query_value( $key, $value );
        }
        if ( $truncated ) {
            $redacted['_wp_flame_query_truncated'] = '1';
        }

        return $path . '?' . self::build_query( $redacted );
    }

    /**
     * Normalize SQL so default traces retain useful fingerprints without
     * storing customer data, tokens, emails, or other literal values.
     */
    public static function normalize_sql( string $query ): string
    {
        $query = self::strip_sql_comments( $query );
        if ( strlen( $query ) > self::MAX_SQL_NORMALIZE_BYTES ) {
            $query = substr( $query, 0, self::MAX_SQL_NORMALIZE_BYTES ) . ' /* wp_flame_truncated */';
        }

        $normalized = preg_replace( "/'(?:\\\\.|''|[^'])*'/", '?', $query ) ?? $query;
        $normalized = preg_replace( '/"(?:\\\\.|""|[^"])*"/', '?', $normalized ) ?? $normalized;
        $normalized = preg_replace( '/\b0x[0-9a-f]+\b/i', '?', $normalized ) ?? $normalized;
        $normalized = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $normalized ) ?? $normalized;
        $normalized = preg_replace( '/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized ) ?? $normalized;
        $normalized = preg_replace( '/\s+/', ' ', trim( $normalized ) ) ?? trim( $normalized );

        return self::limit_bytes( $normalized, self::MAX_SQL_NORMALIZE_BYTES );
    }

    public static function sql_label( string $query, bool $full_query_text ): string
    {
        $query = self::strip_sql_comments( $query );
        if ( $full_query_text ) {
            return self::limit_bytes( $query, self::MAX_SQL_LABEL_BYTES );
        }

        return self::limit_bytes( self::normalize_sql( $query ), self::DEFAULT_SQL_LABEL_BYTES );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function redact_query_value( string $key, $value, int $depth = 0 )
    {
        if ( is_array( $value ) ) {
            if ( $depth >= self::MAX_QUERY_ARRAY_DEPTH ) {
                return self::REDACTED;
            }

            $redacted = [];
            foreach ( $value as $nested_key => $nested_value ) {
                $redacted[ $nested_key ] = self::redact_query_value( $key, $nested_value, $depth + 1 );
            }
            return $redacted;
        }

        if ( isset( self::SAFE_QUERY_KEYS[ strtolower( $key ) ] ) && self::is_safe_query_value( (string) $value ) ) {
            return (string) $value;
        }

        return self::REDACTED;
    }

    private static function is_safe_query_value( string $value ): bool
    {
        return (bool) preg_match( '/^[A-Za-z0-9_.:-]{0,80}$/', $value );
    }

    private static function redact_path( string $path ): string
    {
        $segments = explode( '/', $path );
        $sensitive_parent = false;
        foreach ( $segments as $index => $segment ) {
            if ( $segment === '' ) {
                continue;
            }

            $decoded = rawurldecode( $segment );
            if ( $sensitive_parent || self::is_sensitive_path_segment( $decoded ) ) {
                $segments[ $index ] = self::REDACTED;
            }
            $sensitive_parent = self::is_identity_parent_segment( $decoded );
        }

        $redacted_path = implode( '/', $segments );
        return $redacted_path !== '' ? $redacted_path : '/';
    }

    private static function is_sensitive_path_segment( string $segment ): bool
    {
        if ( preg_match( '/^[^@\s\/]+@[^@\s\/]+\.[^@\s\/]+$/', $segment ) ) {
            return true;
        }

        return (bool) preg_match( '/^\d+$/', $segment )
            || (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment )
            || (bool) preg_match( '/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9_-]{16,}$/', $segment );
    }

    private static function is_identity_parent_segment( string $segment ): bool
    {
        return in_array(
            strtolower( $segment ),
            [
                'account',
                'accounts',
                'booking',
                'bookings',
                'customer',
                'customers',
                'download',
                'downloads',
                'invoice',
                'invoices',
                'member',
                'members',
                'order',
                'orders',
                'profile',
                'profiles',
                'reset',
                'token',
                'user',
                'users',
                'verify',
            ],
            true
        );
    }

    /**
     * Remove SQL comments without treating comment markers inside quoted
     * strings or identifiers as comments.
     */
    public static function strip_sql_comments( string $query ): string
    {
        $query = self::limit_bytes( $query, self::MAX_SQL_NORMALIZE_BYTES );
        $length = strlen( $query );
        $output = '';
        $quote = '';
        for ( $index = 0; $index < $length; $index++ ) {
            $char = $query[ $index ];
            $next = $index + 1 < $length ? $query[ $index + 1 ] : '';
            if ( $quote !== '' ) {
                $output .= $char;
                if ( $char === '\\' && $index + 1 < $length ) {
                    $output .= $query[ ++$index ];
                } elseif ( $char === $quote ) {
                    if ( $next === $quote ) {
                        $output .= $query[ ++$index ];
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }

            if ( in_array( $char, [ "'", '"', '`' ], true ) ) {
                $quote = $char;
                $output .= $char;
                continue;
            }

            if ( $char === '/' && $next === '*' ) {
                $index += 2;
                while ( $index < $length && ! ( $query[ $index ] === '*' && $index + 1 < $length && $query[ $index + 1 ] === '/' ) ) {
                    $index++;
                }
                $index++;
                $output .= ' ';
                continue;
            }

            if ( $char === '#' || ( $char === '-' && $next === '-' && ( $index + 2 >= $length || ctype_space( $query[ $index + 2 ] ) ) ) ) {
                while ( $index < $length && $query[ $index ] !== "\n" && $query[ $index ] !== "\r" ) {
                    $index++;
                }
                $output .= ' ';
                continue;
            }

            $output .= $char;
        }

        return trim( preg_replace( '/\s+/', ' ', $output ) ?? $output );
    }

    private static function safe_query_key( string $key, int $position ): string
    {
        if ( (bool) preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', $key ) ) {
            return $key;
        }

        return '_wp_flame_redacted_key_' . max( 1, $position );
    }

    private static function limit_bytes( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function build_query( array $params ): string
    {
        $pairs = [];
        foreach ( $params as $key => $value ) {
            self::append_query_pair( $pairs, (string) $key, $value );
        }

        return implode( '&', $pairs );
    }

    /**
     * @param array<int, string> $pairs
     * @param mixed              $value
     */
    private static function append_query_pair( array &$pairs, string $key, $value ): void
    {
        if ( is_array( $value ) ) {
            $position = 0;
            foreach ( $value as $nested_key => $nested_value ) {
                $position++;
                self::append_query_pair(
                    $pairs,
                    $key . '[' . self::safe_query_key( (string) $nested_key, $position ) . ']',
                    $nested_value
                );
            }
            return;
        }

        $pairs[] = rawurlencode( $key ) . '=' . ( $value === self::REDACTED ? self::REDACTED : rawurlencode( (string) $value ) );
    }
}
