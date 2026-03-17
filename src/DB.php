<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DB extends \wpdb
{
    private Collector $collector;
    private bool $full_query_text;

    /**
     * Create an instrumented DB instance from an existing wpdb.
     */
    public static function from_wpdb( \wpdb $original, Collector $collector, bool $full_query_text = false ): self
    {
        $reflection = new \ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        // Copy all properties from original
        foreach (get_object_vars($original) as $key => $value) {
            $instance->$key = $value;
        }

        $instance->collector = $collector;
        $instance->full_query_text = $full_query_text;

        return $instance;
    }

    /**
     * Check if $wpdb can be safely replaced.
     */
    public static function can_replace(\wpdb $wpdb): bool
    {
        return get_class($wpdb) === 'wpdb';
    }

    /**
     * Override wpdb::query() to wrap with span timing.
     *
     * @param string $query
     * @return int|bool
     */
    public function query($query)
    {
        $meta = [
            'query'      => $this->truncate_query( $query ),
            'query_hash' => md5( $this->normalize_query( $query ) ),
        ];

        $span_id = $this->collector->start_span(
            $this->extract_query_type($query),
            Span::TYPE_DB,
            $this->get_caller_source(),
            $meta
        );

        $result = parent::query($query);

        $this->collector->end_span($span_id);

        return $result;
    }

    /**
     * Extract the SQL statement type (SELECT, INSERT, UPDATE, DELETE, etc.)
     */
    private function extract_query_type(string $query): string
    {
        $query = ltrim($query);
        $first_word = strtoupper(strtok($query, " \t\n\r"));
        $known_types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'SHOW', 'SET'];

        return in_array($first_word, $known_types, true) ? $first_word : 'QUERY';
    }

    /**
     * Truncate query text based on settings.
     */
    private function truncate_query(string $query): string
    {
        if ($this->full_query_text) {
            return $query;
        }
        return substr($query, 0, 200);
    }

    /**
     * Normalize a query by replacing literal values with placeholders.
     *
     * @param string $query
     * @return string Normalized query suitable for fingerprinting.
     */
    private function normalize_query( string $query ): string
    {
        // Replace string literals
        $normalized = preg_replace( "/'[^']*'/", '?', $query );
        // Replace numeric literals
        $normalized = preg_replace( '/\b\d+\b/', '?', $normalized );
        // Replace IN lists
        $normalized = preg_replace( '/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized );
        // Collapse whitespace
        $normalized = preg_replace( '/\s+/', ' ', trim( $normalized ) );
        return $normalized;
    }

    /**
     * Determine the source of the query via backtrace.
     */
    private function get_caller_source(): string
    {
        return SourceResolver::from_backtrace( $this->collector, 1 );
    }
}
