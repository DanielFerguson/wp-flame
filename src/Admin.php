<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin
{
    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_notices', [$this, 'render_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_management_page(
            __('WP Flame', 'wp-flame'),
            __('WP Flame', 'wp-flame'),
            'manage_options',
            'wp-flame',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-flame'));
        }

        $this->handle_delete();

        $trace_id = isset($_GET['trace_id']) ? sanitize_text_field(wp_unslash($_GET['trace_id'])) : '';

        if ($trace_id) {
            $view = new \WPFlame\Admin\FlameGraphView($this->storage);
            $view->render($trace_id);
        } else {
            $view = new \WPFlame\Admin\ListView($this->storage);
            $view->render();
        }
    }

    public function handle_delete(): void
    {
        if (! isset($_POST['wp_flame_delete_trace'])) {
            return;
        }

        $trace_id = sanitize_text_field(wp_unslash($_POST['wp_flame_delete_trace']));
        check_admin_referer('wp_flame_delete_' . $trace_id);

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'wp-flame'));
        }

        $this->storage->delete_trace($trace_id);

        set_transient('wp_flame_deleted_' . get_current_user_id(), true, 30);
        wp_safe_redirect(admin_url('tools.php?page=wp-flame'));
        exit;
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'tools_page_wp-flame') {
            return;
        }

        wp_enqueue_style(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/css/admin.css',
            [],
            WP_FLAME_VERSION
        );

        wp_enqueue_script(
            'wp-flame-admin',
            WP_FLAME_URL . 'assets/js/admin.js',
            [],
            WP_FLAME_VERSION,
            true
        );

        if (isset($_GET['trace_id'])) {
            wp_enqueue_script(
                'wp-flame-graph',
                WP_FLAME_URL . 'assets/js/flame-graph.js',
                [],
                WP_FLAME_VERSION,
                true
            );
        }
    }

    public function render_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $mu_file = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php' : '';
        if ($mu_file && ! file_exists($mu_file)) {
            echo '<div class="notice notice-warning"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: 1: source mu-plugin path, 2: destination directory */
                    __('<strong>WP Flame</strong> is running in limited mode &mdash; plugin load timing is unavailable. Copy <code>%1$s</code> to <code>%2$s</code> for full instrumentation.', 'wp-flame'),
                    'wp-flame/mu-plugin/wp-flame-early-hooks.php',
                    'wp-content/mu-plugins/'
                )
            );
            echo '</p></div>';
        }

        global $wpdb;
        if (! ($wpdb instanceof DB) && get_class($wpdb) !== 'wpdb') {
            echo '<div class="notice notice-info"><p>';
            echo wp_kses_post(__('<strong>WP Flame</strong>: DB query instrumentation is disabled &mdash; another plugin is modifying the database layer.', 'wp-flame'));
            echo '</p></div>';
        }
    }
}
