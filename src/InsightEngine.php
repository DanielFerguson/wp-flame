<?php

declare(strict_types=1);

namespace WPFlame;

class InsightEngine
{
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
            foreach ( $rule->analyze( $trace ) as $insight ) {
                $insights[] = $insight;
            }
        }
        return $insights;
    }
}
