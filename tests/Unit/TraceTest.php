<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Span;
use WPFlame\Trace;

class TraceTest extends TestCase
{
    private function make_span(string $id, string $type, float $start, float $duration, ?string $parent = null): Span
    {
        return new Span($id, $parent, "Span {$id}", $type, 'test', $start, $duration);
    }

    public function test_construction_sets_all_fields(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
        ];

        $trace = new Trace(
            'trace-abc',
            '/test-page',
            'GET',
            '2026-03-16T12:00:00+00:00',
            100.0,
            16777216,
            '7.4.33',
            '6.4.2',
            $spans
        );

        $this->assertSame('trace-abc', $trace->id);
        $this->assertSame('/test-page', $trace->url);
        $this->assertSame('GET', $trace->method);
        $this->assertSame('2026-03-16T12:00:00+00:00', $trace->timestamp);
        $this->assertSame(100.0, $trace->total_ms);
        $this->assertSame(16777216, $trace->peak_memory);
        $this->assertSame('7.4.33', $trace->php_version);
        $this->assertSame('6.4.2', $trace->wp_version);
        $this->assertCount(1, $trace->spans);
    }

    public function test_query_count_computed_from_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
            $this->make_span('s3', Span::TYPE_DB, 20.0, 3.0, 's1'),
            $this->make_span('s4', Span::TYPE_PLUGIN, 30.0, 10.0, 's1'),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertSame(2, $trace->query_count);
    }

    public function test_total_query_ms_computed_from_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.5, 's1'),
            $this->make_span('s3', Span::TYPE_DB, 20.0, 3.2, 's1'),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertEqualsWithDelta(8.7, $trace->total_query_ms, 0.001);
    }

    public function test_zero_queries_when_no_db_spans(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
        ];

        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', $spans);

        $this->assertSame(0, $trace->query_count);
        $this->assertSame(0.0, $trace->total_query_ms);
    }

    public function test_to_array_includes_all_fields(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
        ];

        $trace = new Trace('t1', '/page', 'POST', '2026-03-16T00:00:00+00:00', 100.0, 2048, '8.1', '6.4', $spans);
        $array = $trace->toArray();

        $this->assertSame('t1', $array['id']);
        $this->assertSame('/page', $array['url']);
        $this->assertSame('POST', $array['method']);
        $this->assertSame(100.0, $array['total_ms']);
        $this->assertSame(1, $array['query_count']);
        $this->assertSame(5.0, $array['total_query_ms']);
        $this->assertCount(2, $array['spans']);
        $this->assertSame('s1', $array['spans'][0]['id']);
        $this->assertArrayHasKey('meta', $array);
    }

    public function test_meta_defaults_to_empty_array(): void
    {
        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', []);

        $this->assertSame([], $trace->meta);
    }

    public function test_meta_round_trip(): void
    {
        $meta = ['cache_hits' => 100, 'cache_misses' => 5];
        $trace = new Trace('t1', '/', 'GET', '2026-01-01T00:00:00+00:00', 100.0, 1024, '8.1', '6.4', [], $meta);

        $array = $trace->toArray();
        $this->assertSame(100, $array['meta']['cache_hits']);
        $this->assertSame(5, $array['meta']['cache_misses']);

        $reconstructed = Trace::fromArray($array);
        $this->assertSame(100, $reconstructed->meta['cache_hits']);
        $this->assertSame(5, $reconstructed->meta['cache_misses']);
    }

    public function test_to_array_includes_schema_version(): void
    {
        $trace = new Trace(
            'test-id', '/test', 'GET', '2024-01-01T00:00:00Z',
            100.0, 1024, '8.1', '6.4', [], []
        );
        $arr = $trace->toArray();
        $this->assertArrayHasKey('v', $arr);
        $this->assertSame(1, $arr['v']);
    }

    public function test_from_array_handles_missing_version(): void
    {
        $data = [
            'id' => 'test-id', 'url' => '/test', 'method' => 'GET',
            'timestamp' => '2024-01-01T00:00:00Z', 'total_ms' => 100.0,
            'peak_memory' => 1024, 'php_version' => '8.1', 'wp_version' => '6.4',
            'spans' => [], 'meta' => [],
        ];
        $trace = Trace::fromArray($data);
        $this->assertSame('test-id', $trace->id);
    }

    public function test_from_array_round_trip(): void
    {
        $spans = [
            $this->make_span('s1', Span::TYPE_CORE, 0.0, 100.0),
            $this->make_span('s2', Span::TYPE_DB, 10.0, 5.0, 's1'),
        ];

        $original = new Trace('t1', '/page', 'GET', '2026-03-16T00:00:00+00:00', 100.0, 2048, '8.1', '6.4', $spans);
        $reconstructed = Trace::fromArray($original->toArray());

        $this->assertSame($original->id, $reconstructed->id);
        $this->assertSame($original->url, $reconstructed->url);
        $this->assertSame($original->method, $reconstructed->method);
        $this->assertSame($original->total_ms, $reconstructed->total_ms);
        $this->assertSame($original->query_count, $reconstructed->query_count);
        $this->assertEqualsWithDelta($original->total_query_ms, $reconstructed->total_query_ms, 0.001);
        $this->assertCount(2, $reconstructed->spans);
        $this->assertSame('s2', $reconstructed->spans[1]->id);
    }
}
