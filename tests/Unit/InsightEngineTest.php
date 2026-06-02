<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Insight;
use WPFlame\InsightEngine;
use WPFlame\InsightRule;
use WPFlame\Trace;

class InsightEngineTest extends TestCase
{
    private function make_trace(): Trace
    {
        return new Trace(
            'trace-insight-engine-test',
            '/test',
            'GET',
            '2026-06-02T00:00:00+00:00',
            100.0,
            1024,
            '8.3',
            '6.7',
            []
        );
    }

    public function test_analyze_skips_throwing_and_malformed_rules(): void
    {
        $engine = new InsightEngine([
            new ThrowingInsightRule(),
            new MalformedInsightRule(),
            new ValidInsightRule(),
        ]);

        $insights = $engine->analyze($this->make_trace());

        $this->assertCount(1, $insights);
        $this->assertSame('valid-rule', $insights[0]->id);
    }

    public function test_analyze_caps_retained_insights(): void
    {
        CountingInsightRule::$calls = 0;
        $engine = new InsightEngine([
            new ManyInsightsRule(),
            new CountingInsightRule(),
        ]);

        $insights = $engine->analyze($this->make_trace());

        $this->assertCount(100, $insights);
        $this->assertSame(0, CountingInsightRule::$calls);
    }
}

class ThrowingInsightRule implements InsightRule
{
    public function id(): string
    {
        return 'throwing-rule';
    }

    public function analyze(Trace $trace): array
    {
        throw new \RuntimeException('rule failed');
    }
}

class MalformedInsightRule implements InsightRule
{
    public function id(): string
    {
        return 'malformed-rule';
    }

    public function analyze(Trace $trace): array
    {
        return [
            'not-an-insight',
            ['severity' => 'warning', 'title' => 'bad', 'detail' => 'bad'],
        ];
    }
}

class ValidInsightRule implements InsightRule
{
    public function id(): string
    {
        return 'valid-rule';
    }

    public function analyze(Trace $trace): array
    {
        return [
            new Insight('valid-rule', 'info', 'Valid rule', 'This rule still runs.'),
        ];
    }
}

class ManyInsightsRule implements InsightRule
{
    public function id(): string
    {
        return 'many-insights';
    }

    public function analyze(Trace $trace): array
    {
        $insights = [];
        for ($i = 0; $i < 150; $i++) {
            $insights[] = new Insight('many-' . $i, 'info', 'Insight ' . $i, 'Many insights.');
        }

        return $insights;
    }
}

class CountingInsightRule implements InsightRule
{
    public static int $calls = 0;

    public function id(): string
    {
        return 'counting-rule';
    }

    public function analyze(Trace $trace): array
    {
        self::$calls++;

        return [
            new Insight('counting-rule', 'info', 'Counting rule', 'This should not run after the cap.'),
        ];
    }
}
