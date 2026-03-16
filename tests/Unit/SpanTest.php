<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
}
