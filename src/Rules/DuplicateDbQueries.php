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

class DuplicateDbQueries implements InsightRule
{
    private const MAX_QUERY_BYTES = 2048;
    private const MAX_GROUP_KEY_BYTES = 256;
    private const MAX_GROUPS = 100;
    private const MAX_SPAN_IDS_PER_GROUP = 50;
    private const MAX_SPAN_IDS_PER_INSIGHT = 100;

    public function id(): string
    {
        return 'duplicate_db_queries';
    }

    /**
     * Rule 2: TYPE_DB spans with duplicate query text (meta['query']).
     * info if 2-3 duplicates, warning if 4+.
     *
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $groups = [];

        foreach ($trace->spans as $span) {
            if ($span->type !== Span::TYPE_DB) {
                continue;
            }

            $query = Config::string_value( $span->meta['query'] ?? '', '' );
            $query = $this->limit_string( $query, self::MAX_QUERY_BYTES );
            if ($query === '') {
                continue;
            }

            $group_key = Config::string_value( $span->meta['query_hash'] ?? $span->meta['query'] ?? '', '' );
            $group_key = $this->limit_string( $group_key, self::MAX_GROUP_KEY_BYTES );
            if ( $group_key === '' ) {
                $group_key = $this->limit_string( $query, self::MAX_GROUP_KEY_BYTES );
            }

            if (! isset($groups[$group_key])) {
                if (count($groups) >= self::MAX_GROUPS) {
                    continue;
                }

                $groups[$group_key] = ['count' => 0, 'total_ms' => 0.0, 'query' => $query, 'span_ids' => []];
            }

            $groups[$group_key]['count']++;
            $groups[$group_key]['total_ms'] += $span->duration_ms;
            if (count($groups[$group_key]['span_ids']) < self::MAX_SPAN_IDS_PER_GROUP) {
                $groups[$group_key]['span_ids'][] = $span->id;
            }
        }

        $raw_insights = [];

        foreach ($groups as $data) {
            $count = $data['count'];
            if ($count < 2) {
                continue;
            }

            $query        = $data['query'];
            $severity     = $count >= 4 ? 'warning' : 'info';
            $total_ms     = round($data['total_ms']);
            $query_type   = $this->extract_query_type($query);
            $truncated    = strlen($query) > 80 ? substr($query, 0, 80) . '...' : $query;

            /* translators: 1: number of duplicate queries, 2: SQL query type (e.g. SELECT) */
            $title = sprintf(__('%1$d duplicate %2$s queries detected', 'wp-flame'), $count, $query_type);

            $raw_insights[] = [
                'severity' => $severity,
                'title'    => $title,
                /* translators: 1: truncated SQL query, 2: number of times run, 3: total duration in milliseconds */
                'detail'   => sprintf(__("The query '%1\$s' ran %2\$d times totalling %3\$dms. Consider caching with wp_cache or a transient.", 'wp-flame'), $truncated, $count, $total_ms),
                'span_ids' => $data['span_ids'],
            ];
        }

        // Consolidate insights with identical titles (same duplicate count + query type)
        // to avoid flooding the UI when many distinct queries share the same truncated prefix.
        $by_title = [];
        foreach ($raw_insights as $insight) {
            $by_title[$insight['title']][] = $insight;
        }

        $insights = [];
        foreach ($by_title as $title => $group) {
            if (count($group) === 1) {
                $insights[] = new Insight(
                    $this->id(),
                    $group[0]['severity'],
                    $group[0]['title'],
                    $group[0]['detail'],
                    $group[0]['span_ids']
                );
                continue;
            }

            // Merge: keep the highest severity, summarise the count
            $has_warning = false;
            $all_span_ids = [];
            foreach ($group as $item) {
                if ($item['severity'] === 'warning') {
                    $has_warning = true;
                }
                foreach ($item['span_ids'] as $span_id) {
                    if (count($all_span_ids) >= self::MAX_SPAN_IDS_PER_INSIGHT) {
                        break 2;
                    }

                    $all_span_ids[] = $span_id;
                }
            }

            $distinct = count($group);
            $insights[] = new Insight(
                $this->id(),
                $has_warning ? 'warning' : 'info',
                /* translators: 1: original title, 2: number of distinct queries */
                sprintf(__('%1$s (%2$d distinct queries)', 'wp-flame'), $title, $distinct),
                $group[0]['detail'],
                $all_span_ids
            );
        }

        return $insights;
    }

    /**
     * Extract the SQL verb (SELECT, INSERT, UPDATE, DELETE, ...) from a query string.
     */
    private function extract_query_type(string $query): string
    {
        $trimmed = ltrim($query);
        $parts   = preg_split('/\s+/', $trimmed, 2);
        return strtoupper($parts[0] ?? 'SQL');
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
