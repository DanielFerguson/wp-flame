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

    public function test_construction_clamps_negative_request_totals(): void
    {
        $trace = new Trace(
            'trace-abc',
            '/test-page',
            'GET',
            '2026-03-16T12:00:00+00:00',
            -100.0,
            -1024,
            '7.4.33',
            '6.4.2',
            []
        );

        $this->assertSame(0.0, $trace->total_ms);
        $this->assertSame(0, $trace->peak_memory);
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
        $this->assertSame(95.0, $array['spans'][0]['self_ms']);
        $this->assertSame(5.0, $array['spans'][1]['self_ms']);
        $this->assertArrayHasKey('meta', $array);
    }

    public function test_to_array_preserves_duplicate_span_ids(): void
    {
        $trace = new Trace(
            't1',
            '/',
            'GET',
            '2026-06-01T00:00:00+00:00',
            100.0,
            1024,
            '8.3',
            '6.7',
            [
                new Span('duplicate', null, 'First', Span::TYPE_CORE, 'wordpress', 0.0, 100.0),
                new Span('duplicate', null, 'Second', Span::TYPE_PLUGIN, 'plugin', 10.0, 20.0),
                new Span('child', 'duplicate', 'Child', Span::TYPE_DB, 'plugin', 15.0, 5.0),
            ]
        );

        $spans = $trace->toArray()['spans'];

        $this->assertCount(3, $spans);
        $this->assertSame('First', $spans[0]['name']);
        $this->assertSame('Second', $spans[1]['name']);
        $this->assertSame('Child', $spans[2]['name']);
        $this->assertSame(95.0, $spans[0]['self_ms']);
        $this->assertSame(20.0, $spans[1]['self_ms']);
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

    public function test_from_array_tolerates_partial_or_malformed_trace_data(): void
    {
        $trace = Trace::fromArray([
            'id'    => 'partial',
            'spans' => [
                'not-a-span',
                ['id' => 's1', 'duration_ms' => 3.5],
            ],
            'meta'  => 'not-an-array',
        ]);

        $this->assertSame('partial', $trace->id);
        $this->assertSame('', $trace->url);
        $this->assertSame('GET', $trace->method);
        $this->assertSame(0.0, $trace->total_ms);
        $this->assertSame([], $trace->meta);
        $this->assertCount(1, $trace->spans);
        $this->assertSame('s1', $trace->spans[0]->id);
        $this->assertSame('unknown', $trace->spans[0]->name);
    }

    public function test_from_array_tolerates_non_scalar_trace_fields_without_warnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $trace = Trace::fromArray([
                'id'             => ['bad'],
                'url'            => ['bad'],
                'method'         => ['bad'],
                'timestamp'      => ['bad'],
                'total_ms'       => ['bad'],
                'peak_memory'    => ['bad'],
                'php_version'    => ['bad'],
                'wp_version'     => ['bad'],
                'query_count'    => ['bad'],
                'total_query_ms' => ['bad'],
                'spans'          => [
                    [
                        'id'          => ['bad'],
                        'duration_ms' => ['bad'],
                    ],
                ],
                'meta'           => 'bad',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('', $trace->id);
        $this->assertSame('', $trace->url);
        $this->assertSame('GET', $trace->method);
        $this->assertSame('', $trace->timestamp);
        $this->assertSame(0.0, $trace->total_ms);
        $this->assertSame(0, $trace->peak_memory);
        $this->assertSame('', $trace->php_version);
        $this->assertSame('', $trace->wp_version);
        $this->assertSame(0, $trace->query_count);
        $this->assertSame(0.0, $trace->total_query_ms);
        $this->assertCount(1, $trace->spans);
        $this->assertSame('', $trace->spans[0]->id);
    }

    public function test_from_array_rejects_non_finite_timing_and_count_values(): void
    {
        $trace = Trace::fromArray([
            'total_ms'       => '1e9999',
            'peak_memory'    => '1e9999',
            'query_count'    => INF,
            'total_query_ms' => NAN,
            'spans'          => [
                [
                    'id'          => 's1',
                    'start_ms'    => '1e9999',
                    'duration_ms' => '1e9999',
                ],
            ],
        ]);

        $this->assertSame(0.0, $trace->total_ms);
        $this->assertSame(0, $trace->peak_memory);
        $this->assertSame(0, $trace->query_count);
        $this->assertSame(0.0, $trace->total_query_ms);
        $this->assertSame(0.0, $trace->spans[0]->start_ms);
        $this->assertSame(0.0, $trace->spans[0]->duration_ms);
    }

    public function test_from_array_preserves_stored_query_aggregates(): void
    {
        $trace = Trace::fromArray([
            'id'             => 'trimmed',
            'url'            => '/shop',
            'method'         => 'GET',
            'timestamp'      => '2026-06-01T00:00:00+00:00',
            'total_ms'       => 120,
            'peak_memory'    => 1024,
            'php_version'    => '8.3',
            'wp_version'     => '6.7',
            'query_count'    => 12,
            'total_query_ms' => 44.5,
            'spans'          => [],
            'meta'           => ['wp_flame_trace_truncated' => true],
        ]);

        $this->assertSame(12, $trace->query_count);
        $this->assertSame(44.5, $trace->total_query_ms);
    }

    public function test_from_array_bounds_legacy_top_level_strings_and_meta(): void
    {
        $meta = [];
        for ($i = 0; $i < 60; $i++) {
            $meta['meta_key_' . $i . str_repeat('k', 100)] = str_repeat('value', 200);
        }

        $trace = Trace::fromArray([
            'id'          => str_repeat('t', 300),
            'url'         => '/' . str_repeat('checkout/', 400),
            'method'      => str_repeat('METHOD', 20),
            'timestamp'   => str_repeat('2026-06-01 ', 20),
            'php_version' => str_repeat('8.3.', 40),
            'wp_version'  => str_repeat('6.7.', 40),
            'spans'       => [],
            'meta'        => $meta,
        ]);

        $this->assertSame(128, strlen($trace->id));
        $this->assertSame(2048, strlen($trace->url));
        $this->assertSame(20, strlen($trace->method));
        $this->assertSame(64, strlen($trace->timestamp));
        $this->assertSame(64, strlen($trace->php_version));
        $this->assertSame(64, strlen($trace->wp_version));
        $this->assertCount(50, $trace->meta);
        $first_key = array_key_first($trace->meta);
        $this->assertIsString($first_key);
        $this->assertSame(80, strlen($first_key));
        $this->assertSame(500, strlen($trace->meta[$first_key]));
    }

    public function test_from_array_bounds_legacy_span_count(): void
    {
        $spans = [];
        for ($i = 0; $i < 5005; $i++) {
            $spans[] = [
                'id'          => 's' . $i,
                'name'        => 'Span',
                'type'        => Span::TYPE_PLUGIN,
                'source'      => 'plugin',
                'start_ms'    => 0,
                'duration_ms' => 1,
            ];
        }

        $trace = Trace::fromArray([
            'id'    => 'too-many-spans',
            'spans' => $spans,
        ]);

        $this->assertCount(5000, $trace->spans);
        $this->assertSame('s4999', $trace->spans[4999]->id);
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
