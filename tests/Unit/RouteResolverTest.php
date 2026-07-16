<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\RouteResolver;

class RouteResolverTest extends TestCase
{
    public function test_normalizes_frontend_identifiers_and_secrets(): void
    {
        $route = RouteResolver::resolve( 'frontend', 'get', '/orders/123/550e8400-e29b-41d4-a716-446655440000/secretABC1234567890123456789', [] );

        $this->assertSame( 'GET /orders/{id}/{uuid}/{redacted}', $route );
    }

    public function test_resolves_rest_ajax_woocommerce_cron_cli_and_graphql_keys(): void
    {
        $this->assertSame( 'GET REST /wp/v2/posts/{id}', RouteResolver::resolve( 'rest', 'GET', '/', [], [ 'matched_rest_route' => '/wp/v2/posts/42' ] ) );
        $this->assertSame( 'POST AJAX save_widget', RouteResolver::resolve( 'ajax', 'POST', '/', [ 'action' => 'save_widget' ] ) );
        $this->assertSame( 'POST WC_AJAX checkout', RouteResolver::resolve( 'ajax', 'POST', '/', [ 'wc-ajax' => 'checkout' ] ) );
        $this->assertSame( 'CRON runner', RouteResolver::resolve( 'cron', 'GET', '/', [] ) );
        $this->assertSame( 'CLI post list', RouteResolver::resolve( 'cli', 'GET', '/', [], [ 'cli_command' => 'post list --format=json 42' ] ) );
        $this->assertSame( 'POST GRAPHQL GetProducts', RouteResolver::resolve( 'graphql', 'POST', '/graphql', [], [ 'graphql_operation' => 'GetProducts' ] ) );
    }

    public function test_route_keys_are_bounded(): void
    {
        $route = RouteResolver::resolve( 'frontend', 'GET', '/' . str_repeat( 'a', 1000 ), [] );

        $this->assertLessThanOrEqual( RouteResolver::MAX_ROUTE_KEY_BYTES, strlen( $route ) );
    }

    public function test_identifies_wp_flame_admin_and_maintenance_requests(): void
    {
        $this->assertTrue( RouteResolver::is_wp_flame_request( 'admin', [ 'page' => 'wp-flame' ] ) );
        $this->assertTrue( RouteResolver::is_wp_flame_request( 'admin', [ 'page' => 'wp-flame-settings' ] ) );
        $this->assertTrue( RouteResolver::is_wp_flame_request( 'admin', [ 'action' => 'wp_flame_purge' ] ) );
        $this->assertFalse( RouteResolver::is_wp_flame_request( 'frontend', [ 'page' => 'wp-flame' ] ) );
    }
}
