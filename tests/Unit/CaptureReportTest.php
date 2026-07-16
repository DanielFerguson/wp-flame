<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CaptureReport;

class CaptureReportTest extends TestCase
{
    public function test_defaults_are_bounded_and_self_describing(): void
    {
        $report = new CaptureReport([], 25.0);

        $this->assertSame('observation', $report->capture_phase);
        $this->assertSame('sampled', $report->capture_origin);
        $this->assertSame('configured_sampling', $report->capture_policy);
        $this->assertSame('unknown', $report->request_type);
        $this->assertSame('unknown', $report->instrumentation_mode);
        $this->assertSame(25.0, $report->observed_duration_ms);
        $this->assertSame(CaptureReport::CAPABILITY_KEYS, array_keys($report->capabilities));

        foreach ($report->capabilities as $capability) {
            $this->assertSame('not_requested', $capability['status']);
            $this->assertSame('', $capability['reason']);
        }
    }

    public function test_malformed_capture_values_fall_back_without_warnings(): void
    {
        $report = new CaptureReport([
            'capture_session_id'           => ['bad'],
            'capture_phase'                => 'invalid',
            'capture_origin'               => 'invalid',
            'capture_policy'               => str_repeat('p', 200),
            'request_type'                 => [],
            'route_key'                    => str_repeat('r', 700),
            'http_status'                  => 999,
            'instrumentation_mode'         => 'invalid',
            'sample_rate'                  => PHP_INT_MAX,
            'effective_sample_probability' => 4,
            'capture_start_stage'          => [],
            'request_start_reference_ms'   => INF,
            'unobserved_prebootstrap_ms'    => -5,
            'score_version'                => 0,
            'capabilities'                 => [
                'database' => [
                    'status' => 'invalid',
                    'reason' => str_repeat('x', 300),
                ],
            ],
            'incomplete_reasons'           => array_fill(0, 30, str_repeat('z', 300)),
            'dropped_span_count'           => PHP_INT_MAX,
        ], INF);

        $this->assertNull($report->capture_session_id);
        $this->assertSame('observation', $report->capture_phase);
        $this->assertSame('sampled', $report->capture_origin);
        $this->assertSame(128, strlen($report->capture_policy));
        $this->assertSame('unknown', $report->request_type);
        $this->assertSame(512, strlen($report->route_key));
        $this->assertSame(599, $report->http_status);
        $this->assertSame('unknown', $report->instrumentation_mode);
        $this->assertSame(1000000, $report->sample_rate);
        $this->assertSame(1.0, $report->effective_sample_probability);
        $this->assertSame('unknown', $report->capture_start_stage);
        $this->assertSame(0.0, $report->observed_duration_ms);
        $this->assertNull($report->request_start_reference_ms);
        $this->assertSame(0.0, $report->unobserved_prebootstrap_ms);
        $this->assertSame(1, $report->score_version);
        $this->assertSame('not_requested', $report->capabilities['database']['status']);
        $this->assertSame(200, strlen($report->capabilities['database']['reason']));
        $this->assertCount(1, $report->incomplete_reasons);
        $this->assertSame(200, strlen($report->incomplete_reasons[0]));
        $this->assertSame(1000000, $report->dropped_span_count);
    }
}
