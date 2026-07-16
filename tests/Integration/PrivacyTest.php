<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Config;
use WPFlame\Privacy;
use WPFlame\Span;
use WPFlame\Storage;
use WPFlame\Trace;

class PrivacyTest extends WP_UnitTestCase
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
        foreach (['flame_traces', 'flame_sessions', 'flame_environments'] as $suffix) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}");
        }
        Config::reset();
        parent::tear_down();
    }

    public function test_export_includes_complete_sensitive_trace_categories(): void
    {
        $user_id = self::factory()->user->create(['user_email' => 'privacy@example.com']);
        $span = new Span('s1', null, 'SELECT', Span::TYPE_DB, 'test-plugin', 0.0, 2.0, [
            'query'         => "SELECT * FROM wp_users WHERE email = 'privacy@example.com'",
            'http_url'      => 'https://api.example.com/customer/privacy@example.com?token=secret',
            'graphql_query' => 'query Customer { customer(email: "privacy@example.com") { id } }',
        ]);
        $trace = new Trace(
            'privacy-trace', '/account/[redacted]', 'GET', '2026-07-16T00:00:00Z',
            20.0, 1024, PHP_VERSION, '6.8', [$span], ['user_agent' => 'Private Browser'], [
                'request_type' => 'frontend',
                'route_key'    => 'GET /account/{redacted}',
            ]
        );
        $this->assertTrue($this->storage->save_trace($trace, null, $user_id, '203.0.113.10')->success);

        $export = (new Privacy($this->storage))->export_user_data('privacy@example.com');
        $this->assertCount(1, $export['data']);
        $values = [];
        foreach ($export['data'][0]['data'] as $item) {
            $values[$item['name']] = $item['value'];
        }

        $this->assertSame((string) $user_id, $values['User ID']);
        $this->assertSame('203.0.113.10', $values['IP Address']);
        $this->assertSame('Private Browser', $values['User Agent']);
        $this->assertStringContainsString('privacy@example.com', $values['Complete trace data (JSON)']);
        $this->assertStringContainsString('token=secret', $values['Complete trace data (JSON)']);
    }

    public function test_eraser_deletes_user_traces_in_resumable_batches(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create(['user_email' => 'erase@example.com']);
        $table = $wpdb->prefix . 'flame_traces';
        for ($i = 0; $i <= Storage::PRIVACY_DELETE_BATCH_LIMIT; $i++) {
            $wpdb->insert($table, [
                'trace_id'   => "erase-{$i}",
                'user_id'    => $user_id,
                'trace_data' => '{}',
            ]);
        }

        $privacy = new Privacy($this->storage);
        $first = $privacy->erase_user_data('erase@example.com');
        $this->assertSame(Storage::PRIVACY_DELETE_BATCH_LIMIT, $first['items_removed']);
        $this->assertFalse($first['done']);
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE user_id = {$user_id}"));

        $second = $privacy->erase_user_data('erase@example.com', 2);
        $this->assertSame(1, $second['items_removed']);
        $this->assertTrue($second['done']);
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE user_id = {$user_id}"));
    }

    public function test_sensitive_runtime_option_requires_shared_acknowledgement(): void
    {
        $config = Config::instance();
        $config->set_override('wp_flame_full_query_text', true);
        $config->set_override('wp_flame_sensitive_data_acknowledged', false);
        $this->assertFalse(wp_flame_sensitive_capture_enabled($config, 'wp_flame_full_query_text'));

        $config->set_override('wp_flame_sensitive_data_acknowledged', true);
        $this->assertTrue(wp_flame_sensitive_capture_enabled($config, 'wp_flame_full_query_text'));
        $this->assertFalse(wp_flame_sensitive_capture_enabled($config, 'unrecognized_option'));
    }
}
