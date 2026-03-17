<?php

declare(strict_types=1);

namespace WPFlame\Rules;

use WPFlame\Insight;
use WPFlame\InsightRule;
use WPFlame\Span;
use WPFlame\Trace;

class HighQueryCount implements InsightRule
{
    public function id(): string
    {
        return 'high_query_count';
    }

    /**
     * Rule 3: High total DB span count. info if > 50, warning if > 100.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $count = 0;

        foreach ($trace->spans as $span) {
            if ($span->type === Span::TYPE_DB) {
                $count++;
            }
        }

        if ($count <= 50) {
            return [];
        }

        $severity = $count > 100 ? 'warning' : 'info';

        return [
            new Insight(
                $this->id(),
                $severity,
                /* translators: %d: number of database queries */
                sprintf(__('%d database queries on this page', 'wp-flame'), $count),
                __('Consider enabling object caching or reducing queries.', 'wp-flame')
            ),
        ];
    }
}
