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
        $child_ref = new \ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $child_ref->newInstanceWithoutConstructor();

        // Copy ALL properties — including private wpdb members that
        // get_object_vars() cannot see from a subclass scope.
        $class = new \ReflectionClass( $original );
        do {
            foreach ( $class->getProperties() as $prop ) {
                if ( $prop->isStatic() ) {
                    continue;
                }
                $prop->setAccessible( true );
                try {
                    $prop->setValue( $instance, $prop->getValue( $original ) );
                } catch ( \Throwable $e ) {
                    // Skip uninitialized typed properties (PHP 7.4+)
                }
            }
        } while ( $class = $class->getParentClass() );

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
        $query_string = is_scalar( $query ) || ( is_object( $query ) && method_exists( $query, '__toString' ) )
            ? (string) $query
            : '';
        $meta = [
            'query'          => Redactor::sql_label( $query_string, $this->full_query_text ),
            'query_hash'     => md5( Redactor::normalize_sql( $query_string ) ),
            'query_redacted' => ! $this->full_query_text,
        ];
        if ( $this->full_query_text && strlen( $query_string ) > Redactor::MAX_SQL_LABEL_BYTES ) {
            $meta['query_truncated'] = true;
        }

        $span_id = $this->collector->start_span(
            $this->extract_query_type($query_string),
            Span::TYPE_DB,
            $this->get_caller_source(),
            $meta
        );

        try {
            return parent::query($query);
        } finally {
            $this->collector->end_span($span_id);
        }
    }

    /**
     * Extract the SQL statement type (SELECT, INSERT, UPDATE, DELETE, etc.)
     */
    private function extract_query_type(string $query): string
    {
        $query = ltrim($query);
        $first = strtok($query, " \t\n\r");
        if ( ! is_string( $first ) || $first === '' ) {
            return 'QUERY';
        }

        $first_word = strtoupper($first);
        $known_types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'SHOW', 'SET'];

        return in_array($first_word, $known_types, true) ? $first_word : 'QUERY';
    }

    /**
     * Determine the source of the query via backtrace.
     */
    private function get_caller_source(): string
    {
        return SourceResolver::from_backtrace( $this->collector, 1 );
    }
}
