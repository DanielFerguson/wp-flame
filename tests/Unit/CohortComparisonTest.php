<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CohortComparison;
use WPFlame\ComparisonReport;
use WPFlame\EnvironmentSnapshot;
use WPFlame\Trace;

class CohortComparisonTest extends TestCase
{
    public function test_twenty_compatible_observations_per_side_can_be_verified(): void
    {
        $comparison = CohortComparison::compare(
            $this->cohort('baseline', 20, 200.0),
            $this->cohort('after', 20, 150.0)
        );

        $this->assertTrue($comparison['valid']);
        $this->assertSame('verified', $comparison['status']);
        $this->assertSame(-50.0, $comparison['p50_delta_ms']);
        $this->assertSame(-25.0, $comparison['p50_delta_percent']);
        $this->assertSame(200.0, $comparison['baseline']['p95_ms']);
        $this->assertSame('captured', $comparison['baseline_signature']['capabilities']['database']['status']);
    }

    public function test_ten_is_directional_and_one_is_never_verified(): void
    {
        $directional = CohortComparison::compare(
            $this->cohort('baseline', 10, 200.0),
            $this->cohort('after', 10, 150.0)
        );
        $single = CohortComparison::compare(
            $this->cohort('baseline', 1, 200.0),
            $this->cohort('after', 1, 150.0)
        );

        $this->assertSame('directional', $directional['status']);
        $this->assertNull($directional['baseline']['p95_ms']);
        $this->assertSame('insufficient', $single['status']);
        $this->assertStringContainsString('Too few observations', $single['uncertainty']);
    }

    public function test_mixed_route_mode_capability_score_and_environment_are_invalid(): void
    {
        $baseline = $this->cohort('baseline', 10, 200.0);
        $after = $this->cohort('after', 10, 150.0, '/different', 'environment-b');
        $after[0]->capture_report->instrumentation_mode = 'deep';
        $after[1]->capture_report->score_version = 99;
        $after[2]->capture_report->capabilities['database']['status'] = 'unavailable';

        $comparison = CohortComparison::compare($baseline, $after);

        $this->assertFalse($comparison['valid']);
        $this->assertSame('invalid', $comparison['status']);
        $this->assertNotEmpty($comparison['reasons']);
        $this->assertStringContainsString('No performance conclusion', $comparison['uncertainty']);
    }

    public function test_report_redacts_route_by_default_and_sensitive_route_is_explicit(): void
    {
        $comparison = CohortComparison::compare(
            $this->cohort('baseline', 10, 200.0, '/users/123?token=secret'),
            $this->cohort('after', 10, 150.0, '/users/123?token=secret')
        );

        $redacted = json_decode(ComparisonReport::json($comparison), true);
        $explicit = json_decode(ComparisonReport::json($comparison, true), true);

        $this->assertSame('/users/[redacted]?token=[redacted]', $redacted['signature']['route_key']);
        $this->assertFalse($redacted['report']['includes_sensitive_fields']);
        $this->assertSame('/users/123?token=secret', $explicit['signature']['route_key']);
        $this->assertTrue($explicit['report']['includes_sensitive_fields']);
    }

    public function test_controlled_plugin_change_warns_without_invalidating_stable_runtime_context(): void
    {
        $baselineEnvironment = new EnvironmentSnapshot($this->environmentData('1.0.0'));
        $afterEnvironment = new EnvironmentSnapshot($this->environmentData('1.1.0'));
        $comparison = CohortComparison::compare(
            $this->cohort('baseline', 20, 200.0, '/checkout', $baselineEnvironment->fingerprint),
            $this->cohort('after', 20, 150.0, '/checkout', $afterEnvironment->fingerprint),
            [
                $baselineEnvironment->fingerprint => $baselineEnvironment,
                $afterEnvironment->fingerprint    => $afterEnvironment,
            ]
        );

        $this->assertTrue($comparison['valid']);
        $this->assertSame('verified', $comparison['status']);
        $this->assertStringContainsString('plugin', strtolower($comparison['warnings'][0]));
    }

    /** @return Trace[] */
    private function cohort(
        string $phase,
        int $count,
        float $duration,
        string $route = '/checkout',
        string $environment = 'environment-a'
    ): array {
        $traces = [];
        for ($index = 0; $index < $count; $index++) {
            $traces[] = new Trace(
                $phase . '-' . $index,
                $route,
                'GET',
                '2026-07-16T00:00:00+00:00',
                $duration,
                1024,
                '8.3',
                '6.8',
                [],
                [],
                [
                    'capture_phase'           => $phase,
                    'capture_origin'          => 'session',
                    'request_type'            => 'frontend',
                    'route_key'               => $route,
                    'instrumentation_mode'    => 'standard',
                    'score_version'           => 2,
                    'environment_snapshot_id' => $environment,
                    'capabilities'            => [
                        'database' => ['status' => 'captured', 'reason' => ''],
                        'http'     => ['status' => 'captured', 'reason' => ''],
                    ],
                ]
            );
        }
        return $traces;
    }

    /** @return array<string, mixed> */
    private function environmentData(string $pluginVersion): array
    {
        return [
            'wordpress_version'           => '6.8',
            'php_version'                 => '8.3',
            'multisite'                   => false,
            'external_object_cache'       => false,
            'page_cache_constant'         => false,
            'permalink_structure_hash'    => 'same',
            'environment_snapshot_schema' => 1,
            'plugins'                     => ['shop/shop.php' => $pluginVersion],
            'theme'                       => ['stylesheet' => 'theme', 'stylesheet_version' => '1.0'],
        ];
    }
}
