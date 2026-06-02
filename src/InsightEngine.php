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

                $insights[] = $insight;
                if ( count( $insights ) >= self::MAX_INSIGHTS ) {
                    break;
                }
            }
        }
        return $insights;
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
