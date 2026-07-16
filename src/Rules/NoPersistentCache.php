<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Config;
use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Span;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NoPersistentCache implements InsightRule
{
    public function id(): string
    {
        return 'no_persistent_cache';
    }

    /**
     * Rule 6: No persistent object cache detected.
     * Fires when the backend is the default WP_Object_Cache and there are >20 misses.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        if ( Config::boolean( $trace->meta['external_object_cache_configured'] ?? false ) ) {
            return [];
        }

        $backend = Config::string_value( $trace->meta['cache_backend'] ?? '', '' );

        if ($backend !== 'WP_Object_Cache') {
            return [];
        }

        $misses = Config::bounded_int( $trace->meta['cache_misses'] ?? 0, 0, 0, PHP_INT_MAX );

        if ($misses <= 20) {
            return [];
        }

        return [
            new Insight(
                $this->id(),
                'info',
                __('No persistent object cache detected', 'wp-flame'),
                sprintf(
                    /* translators: %d: number of cache misses */
                    __('This request had %d cache misses. A persistent cache (Redis or Memcached) would cache these across requests, reducing database load.', 'wp-flame'),
                    $misses
                )
            ),
        ];
    }
}
