<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Span;
use WPFlame\Trace;

class SlowCallbacks implements InsightRule
{
    public function id(): string
    {
        return 'slow_callbacks';
    }

    /**
     * Rule 4: Callback spans (has 'hook' in meta) with duration > 50ms -> warning.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $insights = [];

        foreach ($trace->spans as $span) {
            if (! isset($span->meta['hook'])) {
                continue;
            }
            if ($span->duration_ms <= 50) {
                continue;
            }

            $duration = round($span->duration_ms);
            $hook     = $span->meta['hook'];
            $source   = $span->source;

            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: 1: callback/span name, 2: duration in milliseconds, 3: hook name */
                sprintf(__("%1\$s took %2\$dms on the '%3\$s' hook", 'wp-flame'), $span->name, $duration, $hook),
                /* translators: %s: source plugin or theme name */
                sprintf(__('Source: %s. This callback is a performance bottleneck.', 'wp-flame'), $source),
                [$span->id]
            );
        }

        return $insights;
    }
}
