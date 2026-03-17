<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
            __('WP Flame Settings', 'wp-flame'),
            __('WP Flame', 'wp-flame'),
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
        register_setting('wp_flame_settings', 'wp_flame_budget_max_ms', [
            'type'              => 'integer',
            'default'           => 500,
            'sanitize_callback' => function ($value) {
                return max(0, intval($value));
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_budget_max_queries', [
            'type'              => 'integer',
            'default'           => 100,
            'sanitize_callback' => function ($value) {
                return max(0, intval($value));
            },
        ]);
        register_setting('wp_flame_settings', 'wp_flame_track_ips', [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);

        // Section
        add_settings_section(
            'wp_flame_general',
            __('General Settings', 'wp-flame'),
            '__return_false',
            'wp-flame-settings'
        );

        // Fields
        add_settings_field(
            'wp_flame_enabled',
            __('Enable Tracing', 'wp-flame'),
            [$this, 'render_field_enabled'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_trace_audience',
            __('Trace Audience', 'wp-flame'),
            [$this, 'render_field_trace_audience'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_sample_rate',
            __('Sample Rate', 'wp-flame'),
            [$this, 'render_field_sample_rate'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_retention_days',
            __('Retention Period', 'wp-flame'),
            [$this, 'render_field_retention_days'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_min_callback_ms',
            __('Minimum Callback Duration', 'wp-flame'),
            [$this, 'render_field_min_callback_ms'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_full_query_text',
            __('Full SQL Query Text', 'wp-flame'),
            [$this, 'render_field_full_query_text'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_track_ips',
            __('Track IP addresses', 'wp-flame'),
            [$this, 'render_field_track_ips'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        // Performance Budget section
        add_settings_section(
            'wp_flame_budget',
            __('Performance Budget', 'wp-flame'),
            '__return_false',
            'wp-flame-settings'
        );

        add_settings_field(
            'wp_flame_budget_max_ms',
            __('Max page load time (ms)', 'wp-flame'),
            [$this, 'render_field_budget_max_ms'],
            'wp-flame-settings',
            'wp_flame_budget'
        );

        add_settings_field(
            'wp_flame_budget_max_queries',
            __('Max database queries', 'wp-flame'),
            [$this, 'render_field_budget_max_queries'],
            'wp-flame-settings',
            'wp_flame_budget'
        );
    }

    public function render_field_enabled(): void
    {
        $value = get_option('wp_flame_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="wp_flame_enabled" value="1" ' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Enable tracing', 'wp-flame');
        echo '</label>';
    }

    public function render_field_trace_audience(): void
    {
        $value = get_option('wp_flame_trace_audience', 'admins');
        echo '<select name="wp_flame_trace_audience">';
        echo '<option value="admins" ' . selected($value, 'admins', false) . '>' . esc_html__('Admins only', 'wp-flame') . '</option>';
        echo '<option value="logged_in" ' . selected($value, 'logged_in', false) . '>' . esc_html__('Logged-in users', 'wp-flame') . '</option>';
        echo '<option value="everyone" ' . selected($value, 'everyone', false) . '>' . esc_html__('Everyone', 'wp-flame') . '</option>';
        echo '</select>';
    }

    public function render_field_sample_rate(): void
    {
        $value = (int) get_option('wp_flame_sample_rate', 1);
        echo '<input type="number" name="wp_flame_sample_rate" value="' . esc_attr((string) $value) . '" min="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Trace 1 in every N requests. Set to 1 to trace every request.', 'wp-flame') . '</p>';
    }

    public function render_field_retention_days(): void
    {
        $value = (int) get_option('wp_flame_retention_days', 7);
        echo '<input type="number" name="wp_flame_retention_days" value="' . esc_attr((string) $value) . '" min="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Number of days to keep traces before automatic deletion.', 'wp-flame') . '</p>';
    }

    public function render_field_min_callback_ms(): void
    {
        $value = (float) get_option('wp_flame_min_callback_ms', 0.5);
        echo '<input type="number" name="wp_flame_min_callback_ms" value="' . esc_attr((string) $value) . '" min="0" step="0.1" class="small-text">';
        echo '<p class="description">' . esc_html__('Minimum callback duration to record (in milliseconds).', 'wp-flame') . '</p>';
    }

    public function render_field_full_query_text(): void
    {
        $value = get_option('wp_flame_full_query_text', false);
        echo '<label>';
        echo '<input type="checkbox" name="wp_flame_full_query_text" value="1" ' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Record full SQL query text', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, the complete SQL query is stored with each trace. This may increase storage usage. <strong>Privacy notice:</strong> full query text may contain personal data (email addresses, usernames, etc.) embedded in query values. Enable only in development or with appropriate data handling policies.', 'wp-flame') . '</p>';
    }

    public function render_field_track_ips(): void
    {
        $value = get_option('wp_flame_track_ips', true);
        echo '<label>';
        echo '<input type="checkbox" name="wp_flame_track_ips" value="1" ' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Record IP addresses with traces', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Record the IP address of each traced request. Disable for GDPR compliance. IPs are deleted with traces according to your retention policy.', 'wp-flame') . '</p>';
    }

    public function render_field_budget_max_ms(): void
    {
        $value = (int) get_option('wp_flame_budget_max_ms', 500);
        echo '<input type="number" name="wp_flame_budget_max_ms" value="' . esc_attr((string) $value) . '" min="0" class="small-text">';
        echo '<p class="description">' . esc_html__('Alert when any traced request exceeds this duration. Set to 0 to disable.', 'wp-flame') . '</p>';
    }

    public function render_field_budget_max_queries(): void
    {
        $value = (int) get_option('wp_flame_budget_max_queries', 100);
        echo '<input type="number" name="wp_flame_budget_max_queries" value="' . esc_attr((string) $value) . '" min="0" class="small-text">';
        echo '<p class="description">' . esc_html__('Alert when any traced request exceeds this query count. Set to 0 to disable.', 'wp-flame') . '</p>';
    }

    public function handle_purge(): void
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_flame_purge')) {
            wp_die(esc_html__('Security check failed.', 'wp-flame'));
        }

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wp-flame'));
        }

        $this->storage->purge_all();

        set_transient('wp_flame_purged_' . get_current_user_id(), true, 30);
        wp_safe_redirect(admin_url('options-general.php?page=wp-flame-settings'));
        exit;
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-flame'));
        }

        $stats = $this->storage->get_stats();
        $size_mb = round($stats['bytes'] / 1048576, 2);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WP Flame Settings', 'wp-flame') . '</h1>';

        settings_errors('wp_flame_settings');

        $purged_key = 'wp_flame_purged_' . get_current_user_id();
        if (get_transient($purged_key)) {
            delete_transient($purged_key);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All traces have been purged.', 'wp-flame') . '</p></div>';
        }

        // Storage info box
        echo '<div class="card" style="max-width:600px;padding:12px 16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Storage', 'wp-flame') . '</h2>';
        echo '<p>';
        echo '<strong>' . esc_html__('Stored traces:', 'wp-flame') . '</strong> ' . esc_html((string) $stats['count']) . '<br>';
        echo '<strong>' . esc_html__('Total size:', 'wp-flame') . '</strong> ' . esc_html((string) $size_mb) . ' MB';
        echo '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wp_flame_purge">';
        wp_nonce_field('wp_flame_purge');
        echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete all traces? This cannot be undone.', 'wp-flame')) . '\')">' . esc_html__('Purge All Traces', 'wp-flame') . '</button>';
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
