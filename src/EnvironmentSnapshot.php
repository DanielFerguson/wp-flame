<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EnvironmentSnapshot
{
    public const SCHEMA_VERSION = 1;
    private const MAX_MAP_ENTRIES = 500;
    private const MAX_KEY_BYTES = 160;
    private const MAX_VALUE_BYTES = 200;

    /** @var array<string, mixed> */
    public array $data;
    public string $fingerprint;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct( array $data )
    {
        $this->data = self::normalize_map( $data );
        $canonical = $this->data;
        self::sort_recursive( $canonical );
        $json = wp_json_encode( [
            'v'    => self::SCHEMA_VERSION,
            'data' => $canonical,
        ] );
        $this->fingerprint = hash( 'sha256', is_string( $json ) ? $json : '' );
    }

    /**
     * @return array{v: int, fingerprint: string, data: array<string, mixed>}
     */
    public function to_array(): array
    {
        return [
            'v'           => self::SCHEMA_VERSION,
            'fingerprint' => $this->fingerprint,
            'data'        => $this->data,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function normalize_map( array $data ): array
    {
        $normalized = [];
        foreach ( $data as $key => $value ) {
            if ( count( $normalized ) >= self::MAX_MAP_ENTRIES ) {
                break;
            }

            $key = self::bounded_string( $key, self::MAX_KEY_BYTES );
            if ( $key === '' ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $normalized[ $key ] = self::normalize_map( $value );
            } elseif ( is_bool( $value ) || is_int( $value ) ) {
                $normalized[ $key ] = $value;
            } elseif ( is_float( $value ) ) {
                $normalized[ $key ] = is_finite( $value ) ? $value : 0.0;
            } else {
                $normalized[ $key ] = self::bounded_string( $value, self::MAX_VALUE_BYTES );
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function sort_recursive( array &$data ): void
    {
        ksort( $data );
        foreach ( $data as &$value ) {
            if ( is_array( $value ) ) {
                self::sort_recursive( $value );
            }
        }
        unset( $value );
    }

    /**
     * @param mixed $value
     */
    private static function bounded_string( $value, int $max_bytes ): string
    {
        $value = Config::string_value( $value, '' );

        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }
}
