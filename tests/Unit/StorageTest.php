<?php

declare(strict_types=1);

namespace {
    if (! function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return '2026-06-01 00:00:00';
        }
    }
}

namespace WPFlame\Tests\Unit {

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WPFlame\Config;
use WPFlame\Span;
use WPFlame\Storage;
use WPFlame\Trace;

class StorageTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
        parent::tearDown();
    }

    public function test_trim_trace_json_removes_leaf_spans_without_orphaning_children(): void
    {
        $storage = (new ReflectionClass(Storage::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Storage::class, 'trim_trace_json_to_size');
        $method->setAccessible(true);

        $data = [
            'id'             => 'trace-1',
            'url'            => '/test',
            'method'         => 'GET',
            'timestamp'      => '2026-06-01T00:00:00+00:00',
            'total_ms'       => 100.0,
            'peak_memory'    => 1,
            'php_version'    => '8.3.0',
            'wp_version'     => '6.7',
            'query_count'    => 0,
            'total_query_ms' => 0,
            'meta'           => [],
            'spans'          => [
                [
                    'id'          => 'child',
                    'parent_id'   => 'parent',
                    'name'        => str_repeat('child-', 200),
                    'type'        => 'plugin',
                    'source'      => 'test',
                    'start_ms'    => 10,
                    'duration_ms' => 10,
                    'meta'        => [],
                ],
                [
                    'id'          => 'parent',
                    'parent_id'   => null,
                    'name'        => 'parent',
                    'type'        => 'plugin',
                    'source'      => 'test',
                    'start_ms'    => 0,
                    'duration_ms' => 100,
                    'meta'        => [],
                ],
                [
                    'id'          => 'sibling',
                    'parent_id'   => null,
                    'name'        => str_repeat('sibling-', 200),
                    'type'        => 'plugin',
                    'source'      => 'test',
                    'start_ms'    => 20,
                    'duration_ms' => 10,
                    'meta'        => [],
                ],
            ],
        ];

        $json = $method->invoke($storage, $data, 900);
        $this->assertNotNull($json);

        $trimmed = json_decode($json, true);
        $ids = array_fill_keys(array_column($trimmed['spans'], 'id'), true);

        foreach ($trimmed['spans'] as $span) {
            if (! empty($span['parent_id'])) {
                $this->assertArrayHasKey($span['parent_id'], $ids);
            }
        }
    }

    public function test_save_trace_bounds_top_level_trace_data_and_meta_before_encoding(): void
    {
        $wpdb = new \wpdb();
        $storage = new Storage($wpdb);
        $meta = [
            'long_string' => str_repeat('m', 1000),
            'nested'      => [
                'long_nested' => str_repeat('n', 1000),
                'object'      => new class {
                    public function __toString(): string
                    {
                        return str_repeat('o', 1000);
                    }
                },
                'dropped_deep' => [
                    'another' => [
                        'too_deep' => 'drop',
                    ],
                ],
            ],
            'non_finite' => INF,
            'object'     => new \stdClass(),
        ];
        for ($i = 0; $i < 60; $i++) {
            $meta['key_' . $i] = 'value';
        }

        $trace = new Trace(
            str_repeat('t', 300),
            '/' . str_repeat('u', 3000),
            str_repeat('M', 100),
            str_repeat('d', 100),
            12.5,
            1024,
            str_repeat('p', 100),
            str_repeat('w', 100),
            [],
            $meta
        );

        $storage->save_trace($trace, 500, -10, str_repeat('1', 100));

        $this->assertSame(str_repeat('t', 36), $wpdb->last_insert_data['trace_id']);
        $this->assertSame(2048, strlen($wpdb->last_insert_data['url']));
        $this->assertSame(10, strlen($wpdb->last_insert_data['method']));
        $this->assertSame(0, $wpdb->last_insert_data['user_id']);
        $this->assertSame(45, strlen($wpdb->last_insert_data['ip_address']));
        $this->assertSame(100, $wpdb->last_insert_data['score']);

        $data = json_decode($wpdb->last_insert_data['trace_data'], true);

        $this->assertSame(str_repeat('t', 36), $data['id']);
        $this->assertSame(2048, strlen($data['url']));
        $this->assertSame(20, strlen($data['method']));
        $this->assertSame(64, strlen($data['timestamp']));
        $this->assertSame(64, strlen($data['php_version']));
        $this->assertSame(64, strlen($data['wp_version']));
        $this->assertLessThanOrEqual(50, count($data['meta']));
        $this->assertSame(500, strlen($data['meta']['long_string']));
        $this->assertSame(500, strlen($data['meta']['nested']['long_nested']));
        $this->assertSame(500, strlen($data['meta']['nested']['object']));
        $this->assertSame(0, $data['meta']['non_finite']);
        $this->assertArrayNotHasKey('object', $data['meta']);
    }

    public function test_save_trace_bounds_numeric_columns_and_span_rows_before_encoding(): void
    {
        $wpdb = new \wpdb();
        $storage = new Storage($wpdb);

        $trace = new Trace(
            'trace-1',
            '/test',
            'GET',
            '2026-06-01T00:00:00+00:00',
            PHP_FLOAT_MAX,
            PHP_INT_MAX,
            PHP_VERSION,
            '6.7',
            [
                new Span(
                    str_repeat('d', 300),
                    str_repeat('p', 300),
                    str_repeat('Database span ', 100),
                    Span::TYPE_DB,
                    str_repeat('source-', 100),
                    PHP_FLOAT_MAX,
                    PHP_FLOAT_MAX,
                    ['long_meta' => str_repeat('m', 1000)]
                ),
                new Span(
                    str_repeat('h', 300),
                    null,
                    str_repeat('HTTP span ', 100),
                    Span::TYPE_HTTP,
                    str_repeat('source-', 100),
                    PHP_FLOAT_MAX,
                    PHP_FLOAT_MAX
                ),
            ]
        );

        $storage->save_trace($trace);

        $this->assertSame(86400000.0, $wpdb->last_insert_data['total_ms']);
        $this->assertSame(1099511627776, $wpdb->last_insert_data['peak_memory']);
        $this->assertSame(86400000.0, $wpdb->last_insert_data['db_time_ms']);
        $this->assertSame(86400000.0, $wpdb->last_insert_data['http_time_ms']);

        $data = json_decode($wpdb->last_insert_data['trace_data'], true);

        $this->assertSame(86400000, $data['total_ms']);
        $this->assertSame(1099511627776, $data['peak_memory']);
        $this->assertSame(36, strlen($data['spans'][0]['id']));
        $this->assertSame(36, strlen($data['spans'][0]['parent_id']));
        $this->assertSame(300, strlen($data['spans'][0]['name']));
        $this->assertSame(200, strlen($data['spans'][0]['source']));
        $this->assertSame(86400000, $data['spans'][0]['start_ms']);
        $this->assertSame(86400000, $data['spans'][0]['duration_ms']);
        $this->assertSame(86400000, $data['spans'][0]['self_ms']);
        $this->assertSame(500, strlen($data['spans'][0]['meta']['long_meta']));
    }
}
}
