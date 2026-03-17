<?php

declare(strict_types=1);

namespace WPFlame;

class Settings
{
    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_wp_flame_purge', [$this, 'handle_purge']);
    }

    public function add_settings_page(): void
    {
        add_options_page(
            'WP Flame Settings',
            'WP Flame',
            'manage_options',
            'wp-flame-settings',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        // Register settings
        register_setting('wp_flame_settings', 'wp_flame_enabled', [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_trace_audience', [
            'type'              => 'string',
            'default'           => 'admins',
            'sanitize_callback' => function ($value) {
                $allowed = ['admins', 'logged_in', 'everyone'];
                return in_array($value, $allowed, true) ? $value : 'admins';
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_sample_rate', [
            'type'              => 'integer',
            'default'           => 1,
            'sanitize_callback' => function ($value) {
                return max(1, intval($value));
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_retention_days', [
            'type'              => 'integer',
            'default'           => 7,
            'sanitize_callback' => function ($value) {
                return max(1, intval($value));
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_min_callback_ms', [
            'type'              => 'number',
            'default'           => 0.5,
            'sanitize_callback' => function ($value) {
                return max(0.0, floatval($value));
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_full_query_text', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);

        // Section
        add_settings_section(
            'wp_flame_general',
            'General Settings',
            '__return_false',
            'wp-flame-settings'
        );

        // Fields
        add_settings_field(
            'wp_flame_enabled',
            'Enable Tracing',
            [$this, 'render_field_enabled'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_trace_audience',
            'Trace Audience',
            [$this, 'render_field_trace_audience'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_sample_rate',
            'Sample Rate',
            [$this, 'render_field_sample_rate'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_retention_days',
            'Retention Period',
            [$this, 'render_field_retention_days'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_min_callback_ms',
            'Minimum Callback Duration',
            [$this, 'render_field_min_callback_ms'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_full_query_text',
            'Full SQL Query Text',
            [$this, 'render_field_full_query_text'],
            'wp-flame-settings',
            'wp_flame_general'
        );
    }

    public function render_field_enabled(): void
    {
        $value = get_option('wp_flame_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="wp_flame_enabled" value="1" ' . checked($value, true, false) . '>';
        echo ' Enable tracing';
        echo '</label>';
    }

    public function render_field_trace_audience(): void
    {
        $value = get_option('wp_flame_trace_audience', 'admins');
        echo '<select name="wp_flame_trace_audience">';
        echo '<option value="admins" ' . selected($value, 'admins', false) . '>Admins only</option>';
        echo '<option value="logged_in" ' . selected($value, 'logged_in', false) . '>Logged-in users</option>';
        echo '<option value="everyone" ' . selected($value, 'everyone', false) . '>Everyone</option>';
        echo '</select>';
    }

    public function render_field_sample_rate(): void
    {
        $value = (int) get_option('wp_flame_sample_rate', 1);
        echo '<input type="number" name="wp_flame_sample_rate" value="' . esc_attr((string) $value) . '" min="1" class="small-text">';
        echo '<p class="description">Trace 1 in every N requests. Set to 1 to trace every request.</p>';
    }

    public function render_field_retention_days(): void
    {
        $value = (int) get_option('wp_flame_retention_days', 7);
        echo '<input type="number" name="wp_flame_retention_days" value="' . esc_attr((string) $value) . '" min="1" class="small-text">';
        echo '<p class="description">Number of days to keep traces before automatic deletion.</p>';
    }

    public function render_field_min_callback_ms(): void
    {
        $value = (float) get_option('wp_flame_min_callback_ms', 0.5);
        echo '<input type="number" name="wp_flame_min_callback_ms" value="' . esc_attr((string) $value) . '" min="0" step="0.1" class="small-text">';
        echo '<p class="description">Minimum callback duration to record (in milliseconds).</p>';
    }

    public function render_field_full_query_text(): void
    {
        $value = get_option('wp_flame_full_query_text', false);
        echo '<label>';
        echo '<input type="checkbox" name="wp_flame_full_query_text" value="1" ' . checked($value, true, false) . '>';
        echo ' Record full SQL query text';
        echo '</label>';
        echo '<p class="description">When enabled, the complete SQL query is stored with each trace. This may increase storage usage.</p>';
    }

    public function handle_purge(): void
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_flame_purge')) {
            wp_die('Security check failed.');
        }

        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        $this->storage->purge_all();

        set_transient('wp_flame_purged_' . get_current_user_id(), true, 30);
        wp_safe_redirect(admin_url('options-general.php?page=wp-flame-settings'));
        exit;
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $stats = $this->storage->get_stats();
        $size_mb = round($stats['bytes'] / 1048576, 2);

        echo '<div class="wrap">';
        echo '<h1>WP Flame Settings</h1>';

        settings_errors('wp_flame_settings');

        $purged_key = 'wp_flame_purged_' . get_current_user_id();
        if (get_transient($purged_key)) {
            delete_transient($purged_key);
            echo '<div class="notice notice-success is-dismissible"><p>All traces have been purged.</p></div>';
        }

        // Storage info box
        echo '<div class="card" style="max-width:600px;padding:12px 16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">Storage</h2>';
        echo '<p>';
        echo '<strong>Stored traces:</strong> ' . esc_html((string) $stats['count']) . '<br>';
        echo '<strong>Total size:</strong> ' . esc_html((string) $size_mb) . ' MB';
        echo '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wp_flame_purge">';
        wp_nonce_field('wp_flame_purge');
        echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Are you sure you want to delete all traces? This cannot be undone.\')">Purge All Traces</button>';
        echo '</form>';
        echo '</div>';

        // Settings form
        echo '<form method="post" action="options.php">';
        settings_fields('wp_flame_settings');
        do_settings_sections('wp-flame-settings');
        submit_button();
        echo '</form>';

        echo '</div>';
    }
}
