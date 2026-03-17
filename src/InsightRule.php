<?php

declare(strict_types=1);

namespace WPFlame;

interface InsightRule
{
    public function id(): string;

    /**
     * @param Trace $trace
     * @return Insight[]
     */
    public function analyze( Trace $trace ): array;
}
