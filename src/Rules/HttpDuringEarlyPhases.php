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

class HttpDuringEarlyPhases implements InsightRule
{
    public function id(): string
    {
        return 'http_during_early_phases';
    }

    /**
     * Rule 5: TYPE_HTTP spans whose parent chain includes a lifecycle span named
     * "Init", "Plugin Load", or "Theme Setup" -> warning.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $early_phases = ['Init', 'Plugin Load', 'Theme Setup'];

        // Build a map from span id -> span for quick lookup
        $span_map = [];
        foreach ($trace->spans as $span) {
            $span_map[$span->id] = $span;
        }

        // Collect IDs of all early-phase lifecycle spans
        $early_span_ids = [];
        foreach ($trace->spans as $span) {
            if (in_array($span->name, $early_phases, true)) {
                $early_span_ids[$span->id] = $span->name;
            }
        }

        if (empty($early_span_ids)) {
            return [];
        }

        $insights = [];

        foreach ($trace->spans as $span) {
            if ($span->type !== Span::TYPE_HTTP) {
                continue;
            }

            $phase = $this->find_early_phase_ancestor($span, $span_map, $early_span_ids);
            if ($phase === null) {
                continue;
            }

            $url  = Config::string_value( $span->meta['url'] ?? '', '' );
            $host = Config::string_value( $span->meta['host'] ?? '', '' );
            if ($host === '' && $url !== '') {
                $parsed = parse_url($url);
                $host   = is_array($parsed) ? ($parsed['host'] ?? $url) : $url;
            }
            if ($host === '') {
                $host = 'unknown';
            }

            $insights[] = new Insight(
                $this->id(),
                'warning',
                /* translators: %s: WordPress lifecycle phase name (e.g. Init, Plugin Load) */
                sprintf(__('HTTP request during %s blocks page load', 'wp-flame'), $phase),
                /* translators: 1: hostname, 2: WordPress lifecycle phase name */
                sprintf(__('%1$s called during %2$s — consider deferring to a later hook or using a transient.', 'wp-flame'), $host, $phase),
                [$span->id]
            );
        }

        return $insights;
    }

    /**
     * Walk the parent chain of $span; return the phase name if an early-phase
     * ancestor is found, or null otherwise.
     *
     * @param array<string, Span>   $span_map
     * @param array<string, string> $early_span_ids  id -> phase name
     */
    private function find_early_phase_ancestor(
        Span $span,
        array $span_map,
        array $early_span_ids
    ): ?string {
        $current_id = $span->parent_id;
        $visited    = [];

        while ($current_id !== null) {
            if (isset($visited[$current_id])) {
                break;
            }

            $visited[$current_id] = true;

            if (isset($early_span_ids[$current_id])) {
                return $early_span_ids[$current_id];
            }

            if (! isset($span_map[$current_id])) {
                break;
            }

            $current_id = $span_map[$current_id]->parent_id;
        }

        return null;
    }
}
