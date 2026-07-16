<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\NetworkMaintenance;
use WPFlame\Storage;

/** @group multisite */
class NetworkMaintenanceTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite WordPress test run required.');
        }

        for ($i = 0; $i < 11; $i++) {
            self::factory()->blog->create();
        }
    }

    public function tear_down(): void
    {
        if (is_multisite()) {
            delete_site_option(NetworkMaintenance::STATE_OPTION);
            wp_clear_scheduled_hook(NetworkMaintenance::CRON_HOOK);
        }
        parent::tear_down();
    }

    public function test_activate_migrate_prune_and_deactivate_are_bounded_and_resumable(): void
    {
        $site_count = count(get_sites(['fields' => 'ids', 'number' => 0]));
        $this->assertGreaterThan(NetworkMaintenance::BATCH_SIZE, $site_count);

        $activation = $this->run_to_completion('activate');
        $this->assertSame('complete', $activation['status']);
        $this->assertSame($site_count, $activation['processed']);

        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
            switch_to_blog((int) $blog_id);
            try {
                global $wpdb;
                $table = $wpdb->prefix . 'flame_traces';
                $this->assertContains('trace_id', $wpdb->get_col("SHOW COLUMNS FROM {$table}"));
                update_option('wp_flame_schema_version', Storage::SCHEMA_VERSION - 1);
            } finally {
                restore_current_blog();
            }
        }

        $migration = $this->run_to_completion('migrate');
        $this->assertSame('complete', $migration['status']);
        $this->assertSame($site_count, $migration['processed']);

        $prune = $this->run_to_completion('prune');
        $this->assertSame('complete', $prune['status']);
        $this->assertSame($site_count, $prune['processed']);

        $deactivation = $this->run_to_completion('deactivate');
        $this->assertSame('complete', $deactivation['status']);
        $this->assertSame($site_count, $deactivation['processed']);
    }

    /** @return array<string, mixed> */
    private function run_to_completion(string $operation): array
    {
        NetworkMaintenance::start($operation);
        $state = [];
        for ($run = 0; $run < 20; $run++) {
            $state = NetworkMaintenance::run_batch('wp_flame_network_site_operation');
            if (($state['status'] ?? '') !== 'pending') {
                return $state;
            }
        }

        $this->fail('Network maintenance did not complete within 20 bounded batches.');
    }
}
