<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\RequestClassifier;

class RequestClassifierTest extends TestCase
{
    /**
     * @dataProvider requestTypeProvider
     * @param array<string, mixed> $signals
     * @param array<string, mixed> $server
     * @param array<string, mixed> $request
     */
    public function test_detects_supported_request_types( string $expected, array $signals, array $server = [], array $request = [] ): void
    {
        $this->assertSame( $expected, RequestClassifier::detect( $signals, $server, $request ) );
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>, array<string, mixed>}>
     */
    public function requestTypeProvider(): array
    {
        return [
            'cli'             => [ 'cli', [ 'is_cli' => true ], [], [] ],
            'cron'            => [ 'cron', [ 'is_cron' => true ], [], [] ],
            'ajax'            => [ 'ajax', [ 'is_ajax' => true ], [], [] ],
            'graphql path'    => [ 'graphql', [], [ 'REQUEST_URI' => '/graphql?x=1' ], [] ],
            'pretty REST'     => [ 'rest', [ 'rest_prefix' => 'wp-json' ], [ 'REQUEST_URI' => '/wp-json/wp/v2/posts/42' ], [] ],
            'query REST'      => [ 'rest', [], [ 'REQUEST_URI' => '/index.php?rest_route=/wp/v2/posts' ], [ 'rest_route' => '/wp/v2/posts' ] ],
            'admin'           => [ 'admin', [ 'is_admin' => true ], [], [] ],
            'frontend'        => [ 'frontend', [], [ 'REQUEST_URI' => '/shop' ], [] ],
        ];
    }
}
