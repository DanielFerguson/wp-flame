<?php

declare(strict_types=1);

namespace WPFlame;

interface Instrumentor
{
    public function is_applicable(): bool;
    public function register( Collector $collector ): void;
}
