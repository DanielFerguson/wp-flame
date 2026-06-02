<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Span
{
    public const TYPE_CORE   = 'core';
    public const TYPE_PLUGIN = 'plugin';
    public const TYPE_THEME  = 'theme';
    public const TYPE_DB     = 'db';
    public const TYPE_HTTP   = 'http';
    public const TYPE_PHP    = 'php';
    private const MAX_ID_BYTES = 128;
    private const MAX_NAME_BYTES = 300;
    private const MAX_TYPE_BYTES = 40;
    private const MAX_SOURCE_BYTES = 200;
    private const MAX_META_ENTRIES = 50;
    private const MAX_META_ARRAY_ENTRIES = 20;
    private const MAX_META_ARRAY_DEPTH = 2;
    private const MAX_META_KEY_BYTES = 80;
    private const MAX_META_STRING_BYTES = 500;
    private const MAX_GRAPHQL_QUERY_BYTES = 65536;

    public string $id;
    public ?string $parent_id;
    public string $name;
    public string $type;
    public string $source;
    public float $start_ms;
    public float $duration_ms;
    public array $meta;

    public function __construct(
        string $id,
        ?string $parent_id,
        string $name,
        string $type,
        string $source,
        float $start_ms,
        float $duration_ms,
        array $meta = []
    ) {
        $this->id          = self::limit_string( $id, self::MAX_ID_BYTES );
        $this->parent_id   = $parent_id !== null ? self::limit_string( $parent_id, self::MAX_ID_BYTES ) : null;
        if ( $this->parent_id === '' ) {
            $this->parent_id = null;
        }
        $this->name        = self::limit_string( $name, self::MAX_NAME_BYTES );
        $this->type        = self::limit_string( $type, self::MAX_TYPE_BYTES );
        $this->source      = self::limit_string( $source, self::MAX_SOURCE_BYTES );
        $this->start_ms    = is_finite($start_ms) ? max(0.0, $start_ms) : 0.0;
        $this->duration_ms = is_finite($duration_ms) ? max(0.0, $duration_ms) : 0.0;
        $this->meta        = self::normalize_meta( $meta );
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'name'        => $this->name,
            'type'        => $this->type,
            'source'      => $this->source,
            'start_ms'    => $this->start_ms,
            'duration_ms' => $this->duration_ms,
            'meta'        => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        $parent_id = $data['parent_id'] ?? null;
        if ( $parent_id !== null ) {
            $parent_id = self::limit_string( self::string_value( $parent_id, '' ), self::MAX_ID_BYTES );
            if ( $parent_id === '' ) {
                $parent_id = null;
            }
        }

        return new self(
            self::limit_string( self::string_value( $data['id'] ?? '', '' ), self::MAX_ID_BYTES ),
            $parent_id,
            self::limit_string( self::string_value( $data['name'] ?? 'unknown', 'unknown' ), self::MAX_NAME_BYTES ),
            self::limit_string( self::string_value( $data['type'] ?? self::TYPE_PHP, self::TYPE_PHP ), self::MAX_TYPE_BYTES ),
            self::limit_string( self::string_value( $data['source'] ?? 'unknown', 'unknown' ), self::MAX_SOURCE_BYTES ),
            self::float_value( $data['start_ms'] ?? 0, 0.0 ),
            self::float_value( $data['duration_ms'] ?? 0, 0.0 ),
            isset( $data['meta'] ) && is_array( $data['meta'] ) ? self::normalize_meta( $data['meta'] ) : []
        );
    }

    /**
     * @param mixed $value
     */
    private static function string_value( $value, string $fallback ): string
    {
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
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }

    private static function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }

    /**
     * @param array<mixed> $meta
     * @return array<string, mixed>
     */
    private static function normalize_meta( array $meta, int $depth = 0 ): array
    {
        $normalized = [];
        $count      = 0;
        $max_entries = $depth === 0 ? self::MAX_META_ENTRIES : self::MAX_META_ARRAY_ENTRIES;

        foreach ( $meta as $key => $value ) {
            if ( $count >= $max_entries ) {
                break;
            }

            $key = self::limit_string( self::string_value( $key, '' ), self::MAX_META_KEY_BYTES );
            if ( $key === '' ) {
                $key = 'meta_' . ( $count + 1 );
            }

            $value = self::normalize_meta_value( $value, $depth, $key );
            if ( $value === null ) {
                continue;
            }

            $normalized[ $key ] = $value;
            $count++;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed|null
     */
    private static function normalize_meta_value( $value, int $depth, string $key = '' )
    {
        if ( is_bool( $value ) || is_int( $value ) ) {
            return $value;
        }

        if ( is_float( $value ) ) {
            return is_finite( $value ) ? $value : 0.0;
        }

        if ( is_string( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
            return self::limit_string( self::string_value( $value, '' ), self::max_meta_string_bytes( $key, $depth ) );
        }

        if ( is_array( $value ) && $depth < self::MAX_META_ARRAY_DEPTH ) {
            return self::normalize_meta( $value, $depth + 1 );
        }

        return null;
    }

    private static function max_meta_string_bytes( string $key, int $depth ): int
    {
        if ( $depth === 0 && $key === 'query' ) {
            return Redactor::MAX_SQL_LABEL_BYTES;
        }

        if ( $depth === 0 && $key === 'graphql_query' ) {
            return self::MAX_GRAPHQL_QUERY_BYTES;
        }

        return self::MAX_META_STRING_BYTES;
    }

    /**
     * @param mixed $value
     */
    private static function float_value( $value, float $fallback ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? $number : $fallback;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? $number : $fallback;
        }

        return $fallback;
    }
}
