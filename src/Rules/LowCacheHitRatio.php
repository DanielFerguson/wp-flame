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

class LowCacheHitRatio implements InsightRule
{
    public function id(): string
    {
        return 'low_cache_hit_ratio';
    }

    /**
     * Rule 7: Low cache hit ratio.
     * Fires when hit ratio < 80% and total operations > 10.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        if (! isset($trace->meta['cache_hits'])) {
            return [];
        }

        $hits   = Config::bounded_int( $trace->meta['cache_hits'], 0, 0, PHP_INT_MAX );
        $misses = Config::bounded_int( $trace->meta['cache_misses'] ?? 0, 0, 0, PHP_INT_MAX );
        $ratio_total = (float) $hits + (float) $misses;
        $total = $hits > PHP_INT_MAX - $misses ? PHP_INT_MAX : $hits + $misses;

        if ($ratio_total <= 10.0) {
            return [];
        }

        $ratio = (int) round(($hits / $ratio_total) * 100);

        if ($ratio >= 80) {
            return [];
        }

        return [
            new Insight(
                $this->id(),
                'warning',
                /* translators: %d: cache hit ratio as a percentage */
                sprintf(__('Low cache hit ratio (%d%%)', 'wp-flame'), $ratio),
                /* translators: 1: number of cache misses, 2: total cache operations */
                sprintf(
                    __('%1$d cache misses out of %2$d operations. Investigate which cache groups are missing frequently.', 'wp-flame'),
                    $misses,
                    $total
                )
            ),
        ];
    }
}
