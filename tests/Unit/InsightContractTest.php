<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Config;
use WPFlame\EnvironmentSnapshot;
use WPFlame\Insights;
use WPFlame\Score;
use WPFlame\Span;
use WPFlame\Trace;

class InsightContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
        parent::tearDown();
    }

    public function test_slow_unique_query_has_complete_evidence_action_and_verification_contract(): void
    {
        $trace = $this->trace([
            new Span('db-1', null, 'SELECT', Span::TYPE_DB, 'shop-plugin', 10.0, 180.0, [
                'query'      => 'SELECT * FROM wp_posts WHERE ID = ?',
                'query_hash' => 'known-fingerprint',
            ]),
        ]);
        $environment = new EnvironmentSnapshot([
            'plugins' => [ 'shop-plugin/shop-plugin.php' => '3.4.5' ],
        ]);

        $insights = Insights::analyze($trace, $environment);
        $slow = $this->find($insights, 'A database query took');

        $this->assertSame(['db-1'], $slow['span_ids']);
        $this->assertSame('shop-plugin', $slow['source']);
        $this->assertSame('3.4.5', $slow['source_version']);
        $this->assertSame(180.0, $slow['measured_impact_ms']);
        $this->assertSame(1, $slow['evidence_count']);
        $this->assertSame('high', $slow['confidence']);
        $this->assertSame('database', $slow['required_capability']);
        $this->assertSame('optimize_query', $slow['action_type']);
        $this->assertNotEmpty($slow['remediation']['next_action']);
        $this->assertNotEmpty($slow['verification']);
    }

    public function test_unavailable_database_suppresses_database_conclusions(): void
    {
        $trace = $this->trace([
            new Span('db-1', null, 'SELECT', Span::TYPE_DB, 'custom-db', 0.0, 500.0, [
                'query_failed' => true,
                'query'        => 'SELECT * FROM wp_posts',
            ]),
        ], [
            'database' => [ 'status' => 'unavailable', 'reason' => 'custom_database_subclass' ],
        ], [ 'database_unavailable' ]);

        $insights = Insights::analyze($trace);

        $this->assertCount(1, $insights);
        $this->assertSame('This capture is incomplete', $insights[0]['title']);
        $this->assertSame('low', $insights[0]['confidence']);
    }

    public function test_failed_database_and_http_evidence_are_reported_when_observable(): void
    {
        $trace = $this->trace([
            new Span('db-fail', null, 'UPDATE', Span::TYPE_DB, 'orders-plugin', 0.0, 10.0, [
                'query_failed' => true,
            ]),
            new Span('http-fail', null, 'HTTP api.example.com', Span::TYPE_HTTP, 'orders-plugin', 10.0, 20.0, [
                'host'            => 'api.example.com',
                'status'          => 0,
                'http_error_code' => 'timeout',
            ]),
        ]);

        $insights = Insights::analyze($trace);

        $this->assertNotEmpty($this->find($insights, 'database query failed'));
        $this->assertNotEmpty($this->find($insights, 'HTTP request to api.example.com failed'));
    }

    public function test_cache_guidance_is_suppressed_when_cache_evidence_is_unavailable(): void
    {
        $trace = $this->trace([], [
            'cache_counters' => [ 'status' => 'unavailable', 'reason' => 'counters_not_exposed' ],
        ], [], [
            'cache_backend'                  => 'WP_Object_Cache',
            'cache_hits'                     => 1,
            'cache_misses'                   => 50,
            'external_object_cache_configured' => false,
        ]);

        $insights = Insights::analyze($trace);

        $this->assertSame([], array_values(array_filter($insights, static function (array $insight): bool {
            return $insight['required_capability'] === 'cache_counters';
        })));
    }

    public function test_incomplete_trace_never_produces_high_confidence_findings(): void
    {
        $trace = $this->trace([
            new Span('http-1', null, 'HTTP', Span::TYPE_HTTP, 'plugin-a', 0.0, 250.0, [
                'host' => 'api.example.com',
                'url'  => 'https://api.example.com/data',
                'status' => 200,
            ]),
        ], [], [ 'dropped_spans' ]);

        $insights = Insights::analyze($trace);

        $this->assertNotEmpty($insights);
        foreach ($insights as $insight) {
            $this->assertNotSame('high', $insight['confidence']);
        }
    }

    public function test_route_budget_uses_database_count_only_when_database_was_captured(): void
    {
        Config::instance()->set_override('wp_flame_budget_max_ms', 100);
        Config::instance()->set_override('wp_flame_budget_max_queries', 1);
        $trace = $this->trace([
            new Span('db-1', null, 'SELECT', Span::TYPE_DB, 'plugin-a', 0.0, 1.0),
            new Span('db-2', null, 'SELECT', Span::TYPE_DB, 'plugin-a', 1.0, 1.0),
        ], [
            'database' => [ 'status' => 'unavailable', 'reason' => 'custom_database_subclass' ],
        ]);

        $budget = $this->find(Insights::analyze($trace), 'configured performance budget');

        $this->assertStringContainsString('250ms > 100ms', $budget['detail']);
        $this->assertStringNotContainsString('queries', $budget['detail']);
    }

    public function test_malformed_failure_metadata_does_not_create_false_failure_findings(): void
    {
        $trace = $this->trace([
            new Span('db-bad', null, 'SELECT', Span::TYPE_DB, 'plugin-a', 0.0, 10.0, [
                'query_failed' => [ 'bad' ],
            ]),
            new Span('http-bad', null, 'HTTP', Span::TYPE_HTTP, 'plugin-a', 10.0, 10.0, [
                'status'          => [ 'bad' ],
                'http_error_code' => [ 'bad' ],
            ]),
        ]);

        $insights = Insights::analyze($trace);

        $failed_findings = [];
        foreach ($insights as $insight) {
            if (strpos(strtolower($insight['title']), 'failed') !== false) {
                $failed_findings[] = $insight['title'];
            }
        }
        $this->assertSame([], $failed_findings);
    }

    /**
     * @param Span[] $spans
     * @param array<string, array{status: string, reason: string}> $capability_overrides
     * @param string[] $incomplete_reasons
     * @param array<string, mixed> $meta
     */
    private function trace(
        array $spans,
        array $capability_overrides = [],
        array $incomplete_reasons = [],
        array $meta = []
    ): Trace {
        $capabilities = [
            'early_lifecycle' => [ 'status' => 'captured', 'reason' => '' ],
            'database'        => [ 'status' => 'captured', 'reason' => '' ],
            'callbacks'       => [ 'status' => 'captured', 'reason' => '' ],
            'http'            => [ 'status' => 'captured', 'reason' => '' ],
            'graphql'         => [ 'status' => 'not_requested', 'reason' => 'not_graphql' ],
            'cache_counters'  => [ 'status' => 'captured', 'reason' => '' ],
        ];

        return new Trace(
            'insight-contract', '/checkout', 'POST', '2026-07-16T00:00:00+00:00',
            250.0, 1024, '8.3', '6.8', $spans, $meta, [
                'request_type'        => 'frontend',
                'route_key'           => 'POST /checkout',
                'instrumentation_mode' => 'deep',
                'capture_start_stage' => 'mu_plugin',
                'score_version'       => Score::VERSION,
                'capabilities'        => array_replace($capabilities, $capability_overrides),
                'incomplete_reasons'  => $incomplete_reasons,
            ]
        );
    }

    /** @param array<int, array<string, mixed>> $insights @return array<string, mixed> */
    private function find(array $insights, string $title_fragment): array
    {
        foreach ($insights as $insight) {
            if (strpos($insight['title'], $title_fragment) !== false) {
                return $insight;
            }
        }

        $this->fail('Missing insight containing: ' . $title_fragment);
    }
}
