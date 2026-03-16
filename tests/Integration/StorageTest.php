<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Span;
use WPFlame\Storage;
use WPFlame\Trace;

class StorageTest extends WP_UnitTestCase
{
    private Storage $storage;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->storage = new Storage($wpdb);
        $this->storage->create_table();
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_traces");
        parent::tear_down();
    }

    private function make_trace(string $id = 'trace-1', string $url = '/test', float $total_ms = 100.0): Trace
    {
        $spans = [
            new Span('s1', null, 'Bootstrap', Span::TYPE_CORE, 'wordpress', 0.0, 50.0),
            new Span('s2', 's1', 'SELECT', Span::TYPE_DB, 'test-plugin', 50.0, 10.0, ['query' => 'SELECT 1']),
        ];

        return new Trace($id, $url, 'GET', '2026-03-16T12:00:00+00:00', $total_ms, 16777216, '8.1.0', '6.4.2', $spans);
    }

    public function test_create_table_creates_flame_traces_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        $this->assertSame($table, $result);
    }

    public function test_save_and_get_trace_round_trip(): void
    {
        $trace = $this->make_trace();
        $this->storage->save_trace($trace);

        $retrieved = $this->storage->get_trace('trace-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('trace-1', $retrieved->id);
        $this->assertSame('/test', $retrieved->url);
        $this->assertSame('GET', $retrieved->method);
        $this->assertSame(100.0, $retrieved->total_ms);
        $this->assertCount(2, $retrieved->spans);
        $this->assertSame('Bootstrap', $retrieved->spans[0]->name);
        $this->assertSame(1, $retrieved->query_count);
    }

    public function test_get_trace_returns_null_for_nonexistent(): void
    {
        $result = $this->storage->get_trace('does-not-exist');
        $this->assertNull($result);
    }

    public function test_delete_trace_removes_row(): void
    {
        $this->storage->save_trace($this->make_trace());
        $this->storage->delete_trace('trace-1');

        $this->assertNull($this->storage->get_trace('trace-1'));
    }

    public function test_list_traces_returns_lightweight_rows(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/page-1', 100.0));
        $this->storage->save_trace($this->make_trace('t2', '/page-2', 200.0));

        $rows = $this->storage->list_traces([]);

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('trace_id', $rows[0]);
        $this->assertArrayHasKey('url', $rows[0]);
        $this->assertArrayHasKey('total_ms', $rows[0]);
        $this->assertArrayNotHasKey('trace_data', $rows[0]);
    }

    public function test_list_traces_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}", "/page-{$i}"));
        }

        $page1 = $this->storage->list_traces(['per_page' => 2, 'page' => 1]);
        $page2 = $this->storage->list_traces(['per_page' => 2, 'page' => 2]);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
    }

    public function test_count_traces_returns_total(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}"));
        }

        $this->assertSame(3, $this->storage->count_traces([]));
    }

    public function test_list_traces_filter_by_min_duration(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/fast', 50.0));
        $this->storage->save_trace($this->make_trace('t2', '/slow', 500.0));

        $rows = $this->storage->list_traces(['min_duration' => 100.0]);

        $this->assertCount(1, $rows);
        $this->assertSame('/slow', $rows[0]['url']);
    }

    public function test_list_traces_filter_by_url(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/admin/settings'));
        $this->storage->save_trace($this->make_trace('t2', '/shop/product'));

        $rows = $this->storage->list_traces(['url' => 'admin']);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('admin', $rows[0]['url']);
    }

    public function test_prune_old_deletes_expired_traces(): void
    {
        global $wpdb;
        $this->storage->save_trace($this->make_trace());

        // Manually backdate the trace
        $wpdb->update(
            $wpdb->prefix . 'flame_traces',
            ['created_at' => '2020-01-01 00:00:00'],
            ['trace_id' => 'trace-1']
        );

        $this->storage->prune_old(7);

        $this->assertNull($this->storage->get_trace('trace-1'));
    }
}
