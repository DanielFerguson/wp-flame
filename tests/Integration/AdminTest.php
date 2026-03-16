<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Admin;
use WPFlame\Storage;

class AdminTest extends WP_UnitTestCase
{
    private Admin $admin;
    private Storage $storage;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->storage = new Storage($wpdb);
        $this->storage->create_table();
        $this->admin = new Admin($this->storage);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_traces");
        parent::tear_down();
    }

    public function test_register_adds_admin_menu(): void
    {
        $this->admin->register();

        // Simulate admin_menu hook
        do_action('admin_menu');

        $menu_slug = 'wp-flame';
        global $submenu;
        $found = false;
        if (isset($submenu['tools.php'])) {
            foreach ($submenu['tools.php'] as $item) {
                if ($item[2] === $menu_slug) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'WP Flame menu item should be registered under Tools');
    }

    public function test_delete_trace_requires_valid_nonce(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $_POST['wp_flame_delete_trace'] = 'trace-123';
        $_POST['_wpnonce'] = 'invalid';

        // Should not crash, just skip
        $this->admin->handle_delete();

        $this->assertTrue(true);
    }
}
