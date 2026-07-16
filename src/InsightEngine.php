<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InsightEngine
{
    private const MAX_INSIGHTS = 100;

    /** @var InsightRule[] */
    private $rules = [];

    /** @param InsightRule[] $rules */
    public function __construct( array $rules = [] )
    {
        $this->rules = $rules;
    }

    public function add_rule( InsightRule $rule ): void
    {
        $this->rules[] = $rule;
    }

    /**
     * @param Trace $trace
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array
    {
        $insights = [];
        foreach ( $this->rules as $rule ) {
            if ( count( $insights ) >= self::MAX_INSIGHTS ) {
                break;
            }

            $contract = self::rule_contract( $rule->id() );
            if ( $trace->capture_report->score_version >= Score::VERSION && $contract['capability'] !== '' ) {
                $status = Config::string_value( $trace->capture_report->capabilities[ $contract['capability'] ]['status'] ?? '', '' );
                if ( $status !== 'captured' ) {
                    continue;
                }
            }

            try {
                $rule_insights = $rule->analyze( $trace );
            } catch ( \Throwable $e ) {
                self::log_rule_failure( $rule, $e );
                continue;
            }

            if ( ! is_array( $rule_insights ) ) {
                continue;
            }

            foreach ( $rule_insights as $insight ) {
                if ( ! ( $insight instanceof Insight ) ) {
                    continue;
                }

                $insight->enrich_from_trace(
                    $trace,
                    $contract['capability'],
                    $contract['action'],
                    $contract['verification']
                );
                $insights[] = $insight;
                if ( count( $insights ) >= self::MAX_INSIGHTS ) {
                    break;
                }
            }
        }
        return $insights;
    }

    /** @return array{capability: string, action: string, verification: string} */
    private static function rule_contract( string $id ): array
    {
        $contracts = [
            'slow_http_requests'      => [ 'http', 'cache_or_defer_http', 'Repeat the same workflow and confirm the HTTP span duration and request duration decrease.' ],
            'failed_http_request'     => [ 'http', 'repair_http_dependency', 'Repeat the workflow and confirm the HTTP status/error is healthy.' ],
            'http_during_early_phases' => [ 'http', 'defer_http', 'Repeat the workflow and confirm the HTTP span no longer blocks the early lifecycle phase.' ],
            'duplicate_db_queries'    => [ 'database', 'deduplicate_or_cache_query', 'Repeat the same route and confirm the fingerprint count and total database time decrease.' ],
            'slow_database_query'     => [ 'database', 'optimize_query', 'Repeat the same route and confirm the query fingerprint duration decreases.' ],
            'failed_database_query'   => [ 'database', 'repair_query', 'Repeat the workflow and confirm the database error is absent.' ],
            'high_query_count'        => [ 'database', 'reduce_query_count', 'Repeat the same route and confirm the query count decreases.' ],
            'slow_callbacks'          => [ 'callbacks', 'optimize_callback', 'Run another Deep capture and confirm the callback duration decreases.' ],
            'no_persistent_cache'     => [ 'cache_counters', 'configure_persistent_cache', 'Repeat the workflow and confirm persistent-cache evidence and database load improve.' ],
            'low_cache_hit_ratio'     => [ 'cache_counters', 'improve_cache_usage', 'Repeat the workflow and confirm the cache hit ratio improves.' ],
            'route_budget_violation'  => [ '', 'investigate_route_budget', 'Capture the same route again and confirm it falls within the configured budget.' ],
            'incomplete_capture'      => [ '', 'restore_capture_capability', 'Resolve the stated capture limitation and repeat the same workflow.' ],
        ];
        $contract = $contracts[ $id ] ?? [ '', 'investigate', 'Repeat the same workflow and compare compatible captures.' ];

        return [
            'capability'   => $contract[0],
            'action'       => $contract[1],
            'verification' => $contract[2],
        ];
    }

    private static function log_rule_failure( InsightRule $rule, \Throwable $e ): void
    {
        $should_log = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $should_log = Config::boolean( apply_filters( 'wp_flame_log_insight_rule_failures', $should_log, $rule, $e ) );

        if ( ! $should_log ) {
            return;
        }

        error_log( 'WP Flame: Insight rule failed for ' . get_class( $rule ) . ': ' . $e->getMessage() );
    }
}
