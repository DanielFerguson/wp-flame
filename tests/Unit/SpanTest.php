<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Redactor;
use WPFlame\Span;

class SpanTest extends TestCase
{
    public function test_construction_sets_all_fields(): void
    {
        $span = new Span(
            'abc-123',
            'parent-456',
            'WooCommerce init',
            Span::TYPE_PLUGIN,
            'woocommerce/woocommerce.php',
            10.5,
            25.3,
            ['key' => 'value']
        );

        $this->assertSame('abc-123', $span->id);
        $this->assertSame('parent-456', $span->parent_id);
        $this->assertSame('WooCommerce init', $span->name);
        $this->assertSame(Span::TYPE_PLUGIN, $span->type);
        $this->assertSame('woocommerce/woocommerce.php', $span->source);
        $this->assertSame(10.5, $span->start_ms);
        $this->assertSame(25.3, $span->duration_ms);
        $this->assertSame(['key' => 'value'], $span->meta);
    }

    public function test_construction_with_null_parent(): void
    {
        $span = new Span(
            'abc-123',
            null,
            'Bootstrap',
            Span::TYPE_CORE,
            'wordpress',
            0.0,
            50.0
        );

        $this->assertNull($span->parent_id);
        $this->assertSame([], $span->meta);
    }

    public function test_construction_clamps_negative_timings(): void
    {
        $span = new Span(
            'abc-123',
            null,
            'Malformed legacy span',
            Span::TYPE_PHP,
            'unknown',
            -10.0,
            -25.0
        );

        $this->assertSame(0.0, $span->start_ms);
        $this->assertSame(0.0, $span->duration_ms);
    }

    public function test_constructor_bounds_live_string_fields_and_meta(): void
    {
        $meta = [];
        for ($i = 0; $i < 60; $i++) {
            $meta['meta_key_' . $i . str_repeat('k', 100)] = str_repeat('value', 200);
        }

        $span = new Span(
            str_repeat('i', 300),
            str_repeat('p', 300),
            str_repeat('n', 400),
            str_repeat('t', 80),
            str_repeat('s', 300),
            1.0,
            2.0,
            $meta
        );

        $this->assertSame(128, strlen($span->id));
        $this->assertSame(128, strlen((string) $span->parent_id));
        $this->assertSame(300, strlen($span->name));
        $this->assertSame(40, strlen($span->type));
        $this->assertSame(200, strlen($span->source));
        $this->assertCount(50, $span->meta);
        $first_key = array_key_first($span->meta);
        $this->assertIsString($first_key);
        $this->assertSame(80, strlen($first_key));
        $this->assertSame(500, strlen($span->meta[$first_key]));
    }

    public function test_constructor_treats_empty_bounded_parent_id_as_root(): void
    {
        $span = new Span('s1', '', 'Root', Span::TYPE_PHP, 'test', 0.0, 1.0);

        $this->assertNull($span->parent_id);
    }

    public function test_constructor_preserves_explicit_opt_in_query_metadata_caps(): void
    {
        $span = new Span(
            's1',
            null,
            'Query',
            Span::TYPE_DB,
            'test',
            0.0,
            1.0,
            [
                'query'         => str_repeat('q', Redactor::MAX_SQL_LABEL_BYTES + 100),
                'graphql_query' => str_repeat('g', 65536 + 100),
                'other'         => str_repeat('o', 1000),
            ]
        );

        $this->assertSame(Redactor::MAX_SQL_LABEL_BYTES, strlen($span->meta['query']));
        $this->assertSame(65536, strlen($span->meta['graphql_query']));
        $this->assertSame(500, strlen($span->meta['other']));
    }

    public function test_type_constants_exist(): void
    {
        $this->assertSame('core', Span::TYPE_CORE);
        $this->assertSame('plugin', Span::TYPE_PLUGIN);
        $this->assertSame('theme', Span::TYPE_THEME);
        $this->assertSame('db', Span::TYPE_DB);
        $this->assertSame('http', Span::TYPE_HTTP);
        $this->assertSame('php', Span::TYPE_PHP);
    }

    public function test_to_array_returns_all_fields(): void
    {
        $span = new Span(
            'abc-123',
            'parent-456',
            'SELECT query',
            Span::TYPE_DB,
            'woocommerce/woocommerce.php',
            10.0,
            5.0,
            ['query' => 'SELECT * FROM wp_posts']
        );

        $array = $span->toArray();

        $this->assertSame([
            'id' => 'abc-123',
            'parent_id' => 'parent-456',
            'name' => 'SELECT query',
            'type' => 'db',
            'source' => 'woocommerce/woocommerce.php',
            'start_ms' => 10.0,
            'duration_ms' => 5.0,
            'meta' => ['query' => 'SELECT * FROM wp_posts'],
        ], $array);
    }

    public function test_from_array_reconstructs_span(): void
    {
        $original = new Span(
            'abc-123',
            'parent-456',
            'Theme Setup',
            Span::TYPE_THEME,
            'twentytwentyfour',
            5.0,
            15.0,
            ['template' => 'index.php']
        );

        $reconstructed = Span::fromArray($original->toArray());

        $this->assertSame($original->id, $reconstructed->id);
        $this->assertSame($original->parent_id, $reconstructed->parent_id);
        $this->assertSame($original->name, $reconstructed->name);
        $this->assertSame($original->type, $reconstructed->type);
        $this->assertSame($original->source, $reconstructed->source);
        $this->assertSame($original->start_ms, $reconstructed->start_ms);
        $this->assertSame($original->duration_ms, $reconstructed->duration_ms);
        $this->assertSame($original->meta, $reconstructed->meta);
    }

    public function test_from_array_handles_null_parent_id(): void
    {
        $data = [
            'id' => 'abc',
            'parent_id' => null,
            'name' => 'Root',
            'type' => 'core',
            'source' => 'wordpress',
            'start_ms' => 0.0,
            'duration_ms' => 100.0,
            'meta' => [],
        ];

        $span = Span::fromArray($data);
        $this->assertNull($span->parent_id);
    }

    public function test_from_array_tolerates_missing_fields(): void
    {
        $span = Span::fromArray([]);

        $this->assertSame('', $span->id);
        $this->assertNull($span->parent_id);
        $this->assertSame('unknown', $span->name);
        $this->assertSame(Span::TYPE_PHP, $span->type);
        $this->assertSame('unknown', $span->source);
        $this->assertSame(0.0, $span->start_ms);
        $this->assertSame(0.0, $span->duration_ms);
        $this->assertSame([], $span->meta);
    }

    public function test_from_array_tolerates_non_scalar_fields_without_warnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $span = Span::fromArray([
                'id'          => ['bad'],
                'parent_id'   => ['bad'],
                'name'        => ['bad'],
                'type'        => ['bad'],
                'source'      => ['bad'],
                'start_ms'    => ['bad'],
                'duration_ms' => ['bad'],
                'meta'        => 'bad',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('', $span->id);
        $this->assertNull($span->parent_id);
        $this->assertSame('unknown', $span->name);
        $this->assertSame(Span::TYPE_PHP, $span->type);
        $this->assertSame('unknown', $span->source);
        $this->assertSame(0.0, $span->start_ms);
        $this->assertSame(0.0, $span->duration_ms);
        $this->assertSame([], $span->meta);
    }

    public function test_from_array_rejects_non_finite_timing_values(): void
    {
        $span = Span::fromArray([
            'start_ms'    => '1e9999',
            'duration_ms' => INF,
        ]);

        $this->assertSame(0.0, $span->start_ms);
        $this->assertSame(0.0, $span->duration_ms);
    }

    public function test_from_array_bounds_legacy_string_fields_and_meta(): void
    {
        $meta = [];
        for ($i = 0; $i < 60; $i++) {
            $meta['meta_key_' . $i . str_repeat('k', 100)] = str_repeat('value', 200);
        }

        $span = Span::fromArray([
            'id'          => str_repeat('i', 300),
            'parent_id'   => str_repeat('p', 300),
            'name'        => str_repeat('n', 400),
            'type'        => str_repeat('t', 80),
            'source'      => str_repeat('s', 300),
            'start_ms'    => 1.0,
            'duration_ms' => 2.0,
            'meta'        => $meta,
        ]);

        $this->assertSame(128, strlen($span->id));
        $this->assertSame(128, strlen((string) $span->parent_id));
        $this->assertSame(300, strlen($span->name));
        $this->assertSame(40, strlen($span->type));
        $this->assertSame(200, strlen($span->source));
        $this->assertCount(50, $span->meta);
        $first_key = array_key_first($span->meta);
        $this->assertIsString($first_key);
        $this->assertSame(80, strlen($first_key));
        $this->assertSame(500, strlen($span->meta[$first_key]));
    }
}
