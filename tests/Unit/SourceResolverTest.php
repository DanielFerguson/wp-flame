<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\SourceResolver;
use WPFlame\Collector;

class SourceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    public function test_from_backtrace_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_backtrace($collector);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $result = SourceResolver::from_trace_array($collector, $trace);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_with_empty_trace_returns_wordpress(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_trace_array($collector, []);
        $this->assertSame('wordpress', $result);

        $collector->reset();
    }

    public function test_from_trace_array_skips_malformed_file_values(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_trace_array($collector, [
            ['file' => ['not-a-path']],
            ['file' => null],
        ]);

        $this->assertSame('wordpress', $result);

        $collector->reset();
    }
}
