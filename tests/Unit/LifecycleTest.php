<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Lifecycle;

class LifecycleTest extends TestCase
{
    public function test_initial_phase_distinguishes_early_and_degraded_capture(): void
    {
        $this->assertSame( 'Bootstrap', Lifecycle::initial_phase( true ) );
        $this->assertSame( 'Observed Plugin Bootstrap', Lifecycle::initial_phase( false ) );
    }

    public function test_frontend_main_query_starts_before_wp_hook(): void
    {
        $transitions = Lifecycle::transitions( 'frontend' );
        $hooks        = array_column( $transitions, 'hook' );
        $phases       = array_column( $transitions, 'phase' );

        $this->assertSame( [ 'after_setup_theme', 'init', 'parse_request', 'pre_get_posts', 'wp' ], $hooks );
        $this->assertSame( 'Main Query', $phases[3] );
        $this->assertLessThan( array_search( 'wp', $hooks, true ), array_search( 'pre_get_posts', $hooks, true ) );
    }

    /** @dataProvider phaseMapProvider */
    public function test_request_types_use_specific_dispatch_phases( string $type, string $phase ): void
    {
        $this->assertContains( $phase, array_column( Lifecycle::transitions( $type ), 'phase' ) );
    }

    /** @return array<string, array{string, string}> */
    public function phaseMapProvider(): array
    {
        return [
            'rest'    => [ 'rest', 'REST Routing' ],
            'graphql' => [ 'graphql', 'GraphQL Routing' ],
            'ajax'    => [ 'ajax', 'AJAX Dispatch' ],
            'admin'   => [ 'admin', 'Admin Page Execution' ],
            'cron'    => [ 'cron', 'Cron Dispatch' ],
            'cli'     => [ 'cli', 'Command Execution' ],
        ];
    }
}
