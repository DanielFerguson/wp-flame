<?php

declare(strict_types=1);

namespace WPFlame;

class DbInstrumentor implements Instrumentor
{
    /** @var \wpdb */
    private $wpdb;
    /** @var bool */
    private $full_query_text;

    public function __construct( \wpdb $wpdb, bool $full_query_text = false )
    {
        $this->wpdb            = $wpdb;
        $this->full_query_text = $full_query_text;
    }

    public function is_applicable(): bool
    {
        return DB::can_replace( $this->wpdb );
    }

    public function register( Collector $collector ): void
    {
        $GLOBALS['wpdb'] = DB::from_wpdb( $this->wpdb, $collector, $this->full_query_text );
    }
}
