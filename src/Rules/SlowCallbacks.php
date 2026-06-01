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

class SlowCallbacks implements InsightRule
{
    private const MAX_INSIGHTS = 20;
    private const MAX_LABEL_BYTES = 96;
    private const MAX_SPAN_ID_BYTES = 128;

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
            if (count($insights) >= self::MAX_INSIGHTS) {
                break;
            }

            if (! isset($span->meta['hook'])) {
                continue;
            }
            if ($span->duration_ms <= 50) {
                continue;
            }

            $hook = $this->limit_string( Config::string_value( $span->meta['hook'], '' ), self::MAX_LABEL_BYTES );
            if ( $hook === '' ) {
                continue;
            }

            $duration = round($span->duration_ms);
            $source   = $this->limit_string( $span->source, self::MAX_LABEL_BYTES );
            $name     = $this->limit_string( $span->name, self::MAX_LABEL_BYTES );
            $span_id  = $this->limit_string( $span->id, self::MAX_SPAN_ID_BYTES );

            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: 1: callback/span name, 2: duration in milliseconds, 3: hook name */
                sprintf(__("%1\$s took %2\$dms on the '%3\$s' hook", 'wp-flame'), $name, $duration, $hook),
                /* translators: %s: source plugin or theme name */
                sprintf(__('Source: %s. This callback is a performance bottleneck.', 'wp-flame'), $source),
                [$span_id]
            );
        }

        return $insights;
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
