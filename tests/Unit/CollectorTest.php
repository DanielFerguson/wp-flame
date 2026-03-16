<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Collector;
use WPFlame\Span;

class CollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    public function test_instance_returns_singleton(): void
    {
        $a = Collector::instance();
        $b = Collector::instance();
        $this->assertSame($a, $b);
    }

    public function test_reset_creates_new_instance(): void
    {
        $a = Collector::instance();
        Collector::reset();
        $b = Collector::instance();
        $this->assertNotSame($a, $b);
    }

    public function test_is_initialized_false_before_start_request(): void
    {
        $this->assertFalse(Collector::instance()->is_initialized());
    }

    public function test_is_initialized_true_after_start_request(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);
        $this->assertTrue($collector->is_initialized());
    }

    public function test_start_and_end_span_creates_span(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $id = $collector->start_span('Test Span', Span::TYPE_CORE, 'test-source');
        $this->assertIsString($id);
        $this->assertNotEmpty($id);

        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertSame('Test Span', $trace->spans[0]->name);
        $this->assertSame(Span::TYPE_CORE, $trace->spans[0]->type);
        $this->assertSame('test-source', $trace->spans[0]->source);
    }

    public function test_span_duration_is_positive(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $id = $collector->start_span('Timed', Span::TYPE_CORE, 'test');
        usleep(1000); // 1ms
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertGreaterThan(0, $trace->spans[0]->duration_ms);
    }

    public function test_span_start_ms_relative_to_request_start(): void
    {
        $request_start = microtime(true);
        $collector = Collector::instance();
        $collector->start_request($request_start);

        usleep(5000); // 5ms
        $id = $collector->start_span('Delayed', Span::TYPE_CORE, 'test');
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertGreaterThan(4.0, $trace->spans[0]->start_ms);
    }

    public function test_end_span_without_id_pops_stack_top(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $collector->start_span('First', Span::TYPE_CORE, 'test');
        $collector->end_span();

        $trace = $collector->get_trace();
        $this->assertCount(1, $trace->spans);
        $this->assertSame('First', $trace->spans[0]->name);
    }

    public function test_span_meta_is_stored(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $id = $collector->start_span('Query', Span::TYPE_DB, 'test', ['query' => 'SELECT 1']);
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $this->assertSame(['query' => 'SELECT 1'], $trace->spans[0]->meta);
    }
}
