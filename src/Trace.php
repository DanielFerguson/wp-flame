<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Trace
{
    private const MAX_TRACE_ID_BYTES = 128;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_TIMESTAMP_BYTES = 64;
    private const MAX_VERSION_BYTES = 64;
    private const MAX_HYDRATED_SPANS = 5000;
    private const MAX_META_ENTRIES = 50;
    private const MAX_META_ARRAY_ENTRIES = 20;
    private const MAX_META_ARRAY_DEPTH = 2;
    private const MAX_META_KEY_BYTES = 80;
    private const MAX_META_STRING_BYTES = 500;

    public string $id;
    public string $url;
    public string $method;
    public string $timestamp;
    public float $total_ms;
    public int $peak_memory;
    public string $php_version;
    public string $wp_version;
    public int $query_count;
    public float $total_query_ms;
    /** @var Span[] */
    public array $spans;
    public array $meta;

    /**
     * @param Span[] $spans
     */
    public function __construct(
        string $id,
        string $url,
        string $method,
        string $timestamp,
        float $total_ms,
        int $peak_memory,
        string $php_version,
        string $wp_version,
        array $spans,
        array $meta = []
    ) {
        $spans = self::normalize_spans( $spans );

        $this->id          = self::limit_string( $id, self::MAX_TRACE_ID_BYTES );
        $this->url         = self::limit_string( $url, self::MAX_URL_BYTES );
        $this->method      = self::limit_string( $method, self::MAX_METHOD_BYTES );
        $this->timestamp   = self::limit_string( $timestamp, self::MAX_TIMESTAMP_BYTES );
        $this->total_ms    = is_finite($total_ms) ? max(0.0, $total_ms) : 0.0;
        $this->peak_memory = max(0, $peak_memory);
        $this->php_version = self::limit_string( $php_version, self::MAX_VERSION_BYTES );
        $this->wp_version  = self::limit_string( $wp_version, self::MAX_VERSION_BYTES );
        $this->spans       = $spans;
        $this->meta        = self::normalize_meta( $meta );

        // Compute query aggregates from DB-type spans
        $this->query_count    = 0;
        $this->total_query_ms = 0.0;
        foreach ($spans as $span) {
            if ($span->type === Span::TYPE_DB) {
                $this->query_count++;
                $this->total_query_ms += $span->duration_ms;
            }
        }
    }

    /**
     * @param array<mixed> $spans
     * @return Span[]
     */
    private static function normalize_spans( array $spans ): array
    {
        $normalized = [];

        foreach ( $spans as $span ) {
            if ( count( $normalized ) >= self::MAX_HYDRATED_SPANS ) {
                break;
            }

            if ( $span instanceof Span ) {
                $normalized[] = $span;
            }
        }

        return $normalized;
    }

    public function toArray(): array
    {
        return [
            'v'              => 1,
            'id'             => $this->id,
            'url'            => $this->url,
            'method'         => $this->method,
            'timestamp'      => $this->timestamp,
            'total_ms'       => $this->total_ms,
            'peak_memory'    => $this->peak_memory,
            'php_version'    => $this->php_version,
            'wp_version'     => $this->wp_version,
            'query_count'    => $this->query_count,
            'total_query_ms' => $this->total_query_ms,
            'spans'          => $this->spans_to_array(),
            'meta'           => $this->meta,
        ];
    }

    private function spans_to_array(): array
    {
        $rows = [];
        $self_ms = [];
        $first_index_by_id = [];

        foreach ($this->spans as $index => $span) {
            $rows[$index] = $span->toArray();
            $self_ms[$index] = $span->duration_ms;
            if ($span->id !== '' && ! isset($first_index_by_id[$span->id])) {
                $first_index_by_id[$span->id] = $index;
            }
        }

        foreach ($this->spans as $span) {
            if ($span->parent_id !== null && isset($first_index_by_id[$span->parent_id])) {
                $parent_index = $first_index_by_id[$span->parent_id];
                $self_ms[$parent_index] -= $span->duration_ms;
            }
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['self_ms'] = max(0.0, $self_ms[$index] ?? (float) $row['duration_ms']);
        }

        return array_values($rows);
    }

    public static function fromArray(array $data): self
    {
        $version = self::int_value( $data['v'] ?? 1, 1 );
        // Future: if ( $version < 2 ) { $data = self::migrate_v1_to_v2( $data ); }

        $span_rows = isset( $data['spans'] ) && is_array( $data['spans'] ) ? $data['spans'] : [];
        $spans = [];
        foreach ( $span_rows as $span_row ) {
            if ( count( $spans ) >= self::MAX_HYDRATED_SPANS ) {
                break;
            }

            if ( is_array( $span_row ) ) {
                $spans[] = Span::fromArray( $span_row );
            }
        }

        $trace = new self(
            self::limit_string( self::string_value( $data['id'] ?? '', '' ), self::MAX_TRACE_ID_BYTES ),
            self::limit_string( self::string_value( $data['url'] ?? '', '' ), self::MAX_URL_BYTES ),
            self::limit_string( self::string_value( $data['method'] ?? 'GET', 'GET' ), self::MAX_METHOD_BYTES ),
            self::limit_string( self::string_value( $data['timestamp'] ?? '', '' ), self::MAX_TIMESTAMP_BYTES ),
            self::float_value( $data['total_ms'] ?? 0, 0.0 ),
            self::int_value( $data['peak_memory'] ?? 0, 0 ),
            self::limit_string( self::string_value( $data['php_version'] ?? '', '' ), self::MAX_VERSION_BYTES ),
            self::limit_string( self::string_value( $data['wp_version'] ?? '', '' ), self::MAX_VERSION_BYTES ),
            $spans,
            isset( $data['meta'] ) && is_array( $data['meta'] ) ? self::normalize_meta( $data['meta'] ) : []
        );

        if ( array_key_exists( 'query_count', $data ) ) {
            $trace->query_count = max( 0, self::int_value( $data['query_count'], 0 ) );
        }
        if ( array_key_exists( 'total_query_ms', $data ) ) {
            $trace->total_query_ms = max( 0.0, self::float_value( $data['total_query_ms'], 0.0 ) );
        }

        return $trace;
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

            $value = self::normalize_meta_value( $value, $depth );
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
    private static function normalize_meta_value( $value, int $depth )
    {
        if ( is_bool( $value ) || is_int( $value ) ) {
            return $value;
        }

        if ( is_float( $value ) ) {
            return is_finite( $value ) ? $value : 0.0;
        }

        if ( is_string( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
            return self::limit_string( self::string_value( $value, '' ), self::MAX_META_STRING_BYTES );
        }

        if ( is_array( $value ) && $depth < self::MAX_META_ARRAY_DEPTH ) {
            return self::normalize_meta( $value, $depth + 1 );
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function int_value( $value, int $fallback ): int
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? (int) $number : $fallback;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? (int) $number : $fallback;
        }

        return $fallback;
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
