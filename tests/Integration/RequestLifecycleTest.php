<?php

declare(strict_types=1);

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\DB;
use WPFlame\DbInstrumentor;
use WPFlame\Lifecycle;
use WPFlame\RequestClassifier;
use WPFlame\RouteResolver;

class RequestLifecycleTest extends WP_UnitTestCase
{
    /**
     * @dataProvider requestFixtureProvider
     * @param array<string, mixed> $signals
     * @param array<string, mixed> $server
     * @param array<string, mixed> $request
     */
    public function test_supported_request_fixture_has_specific_type_route_and_phase(
        string $expected_type,
        string $expected_route,
        string $required_phase,
        array $signals,
        array $server,
        array $request
    ): void {
        $type = RequestClassifier::detect( $signals, $server, $request );
        $route = RouteResolver::resolve(
            $type,
            isset( $server['REQUEST_METHOD'] ) ? (string) $server['REQUEST_METHOD'] : 'GET',
            isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/',
            $request,
            $signals
        );

        $this->assertSame( $expected_type, $type );
        $this->assertSame( $expected_route, $route );
        $this->assertContains( $required_phase, array_column( Lifecycle::transitions( $type ), 'phase' ) );
    }

    /**
     * @return array<string, array{string, string, string, array<string, mixed>, array<string, mixed>, array<string, mixed>}>
     */
    public function requestFixtureProvider(): array
    {
        return [
            'frontend' => [ 'frontend', 'GET /shop/product/{id}', 'Main Query', [], [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/shop/product/42' ], [] ],
            'rest' => [ 'rest', 'GET REST /wp/v2/posts/{id}', 'REST Routing', [ 'rest_route' => '/wp/v2/posts/42' ], [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?rest_route=/wp/v2/posts/42' ], [] ],
            'ajax' => [ 'ajax', 'POST AJAX save_widget', 'AJAX Dispatch', [ 'is_ajax' => true ], [ 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/wp-admin/admin-ajax.php' ], [ 'action' => 'save_widget' ] ],
            'woocommerce ajax' => [ 'ajax', 'POST WC_AJAX checkout', 'AJAX Dispatch', [ 'is_ajax' => true ], [ 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/?wc-ajax=checkout' ], [ 'wc-ajax' => 'checkout' ] ],
            'cron' => [ 'cron', 'CRON runner', 'Cron Dispatch', [ 'is_cron' => true ], [ 'REQUEST_URI' => '/wp-cron.php' ], [] ],
            'cli' => [ 'cli', 'CLI plugin list', 'Command Execution', [ 'is_cli' => true, 'cli_command' => 'plugin list --format=json' ], [], [] ],
            'graphql' => [ 'graphql', 'POST GRAPHQL Products', 'GraphQL Routing', [ 'graphql_operation' => 'Products' ], [ 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/graphql' ], [] ],
            'admin' => [ 'admin', 'GET ADMIN /wp-admin/themes.php', 'Admin Page Execution', [ 'is_admin' => true ], [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-admin/themes.php' ], [] ],
        ];
    }

    public function test_frontend_phase_order_places_main_query_before_wp_transition(): void
    {
        $transitions = Lifecycle::transitions( 'frontend' );
        $hooks = array_column( $transitions, 'hook' );

        $this->assertSame( [ 'after_setup_theme', 'init', 'parse_request', 'pre_get_posts', 'wp' ], $hooks );
        $this->assertLessThan( array_search( 'wp', $hooks, true ), array_search( 'pre_get_posts', $hooks, true ) );
    }

    public function test_custom_database_subclass_remains_untouched(): void
    {
        global $wpdb;
        $custom = new class extends \wpdb {
            public function __construct()
            {
            }
        };
        $instrumentor = new DbInstrumentor( $custom );

        $this->assertFalse( DB::can_replace( $custom ) );
        $this->assertFalse( $instrumentor->is_applicable() );
        $this->assertNotSame( $custom, $wpdb );
    }

    public function test_wp_flame_admin_routes_are_excluded_from_customer_observation(): void
    {
        $this->assertTrue( RouteResolver::is_wp_flame_request( 'admin', [ 'page' => 'wp-flame' ] ) );
        $this->assertTrue( RouteResolver::is_wp_flame_request( 'admin', [ 'page' => 'wp-flame-settings' ] ) );
        $this->assertFalse( RouteResolver::is_wp_flame_request( 'admin', [ 'page' => 'themes' ] ) );
    }
}
