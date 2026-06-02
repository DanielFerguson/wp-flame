<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings
{
    private const MAX_REQUEST_STRING_BYTES = 2048;

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
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_trace_audience', [
            'type'              => 'string',
            'default'           => 'admins',
            'sanitize_callback' => [self::class, 'sanitize_trace_audience'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_sample_rate', [
            'type'              => 'integer',
            'default'           => Config::DEFAULT_SAMPLE_RATE,
            'sanitize_callback' => [self::class, 'sanitize_sample_rate'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_instrumentation_mode', [
            'type'              => 'string',
            'default'           => 'standard',
            'sanitize_callback' => [self::class, 'sanitize_instrumentation_mode'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_retention_days', [
            'type'              => 'integer',
            'default'           => Config::DEFAULT_RETENTION_DAYS,
            'sanitize_callback' => [self::class, 'sanitize_retention_days'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_min_callback_ms', [
            'type'              => 'number',
            'default'           => 0.5,
            'sanitize_callback' => [self::class, 'sanitize_non_negative_float'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_full_query_text', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_full_http_url', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_full_graphql_query', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_budget_max_ms', [
            'type'              => 'integer',
            'default'           => 500,
            'sanitize_callback' => [self::class, 'sanitize_non_negative_int'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_budget_max_queries', [
            'type'              => 'integer',
            'default'           => 100,
            'sanitize_callback' => [self::class, 'sanitize_non_negative_int'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_max_spans', [
            'type'              => 'integer',
            'default'           => Config::DEFAULT_MAX_SPANS,
            'sanitize_callback' => [self::class, 'sanitize_max_spans'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_max_trace_bytes', [
            'type'              => 'integer',
            'default'           => Config::DEFAULT_MAX_TRACE_BYTES,
            'sanitize_callback' => [self::class, 'sanitize_max_trace_bytes'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_track_ips', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_track_users', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
        ]);
        register_setting('wp_flame_settings', 'wp_flame_track_user_agent', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_boolean'],
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
            'wp_flame_instrumentation_mode',
            __('Instrumentation Mode', 'wp-flame'),
            [$this, 'render_field_instrumentation_mode'],
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
            'wp_flame_full_http_url',
            __('Full HTTP URLs', 'wp-flame'),
            [$this, 'render_field_full_http_url'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_full_graphql_query',
            __('Full GraphQL Query Text', 'wp-flame'),
            [$this, 'render_field_full_graphql_query'],
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

        add_settings_field(
            'wp_flame_track_users',
            __('Track User IDs', 'wp-flame'),
            [$this, 'render_field_track_users'],
            'wp-flame-settings',
            'wp_flame_general'
        );

        add_settings_field(
            'wp_flame_track_user_agent',
            __('Track User Agents', 'wp-flame'),
            [$this, 'render_field_track_user_agent'],
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

        add_settings_field(
            'wp_flame_max_spans',
            __('Max spans per trace', 'wp-flame'),
            [$this, 'render_field_max_spans'],
            'wp-flame-settings',
            'wp_flame_budget'
        );

        add_settings_field(
            'wp_flame_max_trace_bytes',
            __('Max trace JSON size', 'wp-flame'),
            [$this, 'render_field_max_trace_bytes'],
            'wp-flame-settings',
            'wp_flame_budget'
        );
    }

    public function render_field_enabled(): void
    {
        $value = get_option('wp_flame_enabled', true);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_enabled', $value);
        echo ' ' . esc_html__('Enable tracing', 'wp-flame');
        echo '</label>';
    }

    public function render_field_trace_audience(): void
    {
        $value = self::sanitize_trace_audience(get_option('wp_flame_trace_audience', 'admins'));
        echo '<select name="wp_flame_trace_audience">';
        echo '<option value="admins" ' . selected($value, 'admins', false) . '>' . esc_html__('Admins only', 'wp-flame') . '</option>';
        echo '<option value="logged_in" ' . selected($value, 'logged_in', false) . '>' . esc_html__('Logged-in users', 'wp-flame') . '</option>';
        echo '<option value="everyone" ' . selected($value, 'everyone', false) . '>' . esc_html__('Everyone', 'wp-flame') . '</option>';
        echo '</select>';
    }

    public function render_field_sample_rate(): void
    {
        $value = self::sanitize_sample_rate(get_option('wp_flame_sample_rate', Config::DEFAULT_SAMPLE_RATE));
        echo '<input type="number" name="wp_flame_sample_rate" value="' . esc_attr((string) $value) . '" min="1" max="' . esc_attr((string) Config::MAX_SAMPLE_RATE) . '" class="small-text">';
        echo '<p class="description">' . esc_html__('Trace 1 in every N requests. Set to 1 to trace every request.', 'wp-flame') . '</p>';
    }

    public function render_field_instrumentation_mode(): void
    {
        $value = self::sanitize_instrumentation_mode(get_option('wp_flame_instrumentation_mode', 'standard'));
        echo '<select name="wp_flame_instrumentation_mode">';
        echo '<option value="safe" ' . selected($value, 'safe', false) . '>' . esc_html__('Safe - lifecycle and HTTP only', 'wp-flame') . '</option>';
        echo '<option value="standard" ' . selected($value, 'standard', false) . '>' . esc_html__('Standard - add database spans', 'wp-flame') . '</option>';
        echo '<option value="deep" ' . selected($value, 'deep', false) . '>' . esc_html__('Deep - wrap callbacks during debugging', 'wp-flame') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Use Safe mode for maximum plugin compatibility. Deep mode provides callback-level detail but mutates WordPress hook callbacks and should be used for focused debugging.', 'wp-flame') . '</p>';
    }

    public function render_field_retention_days(): void
    {
        $value = self::sanitize_retention_days(get_option('wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS));
        echo '<input type="number" name="wp_flame_retention_days" value="' . esc_attr((string) $value) . '" min="1" max="' . esc_attr((string) Config::MAX_RETENTION_DAYS) . '" class="small-text">';
        echo '<p class="description">' . esc_html__('Number of days to keep traces before automatic deletion.', 'wp-flame') . '</p>';
    }

    public function render_field_min_callback_ms(): void
    {
        $value = self::non_negative_float(get_option('wp_flame_min_callback_ms', 0.5), 0.5);
        echo '<input type="number" name="wp_flame_min_callback_ms" value="' . esc_attr((string) $value) . '" min="0" step="0.1" class="small-text">';
        echo '<p class="description">' . esc_html__('Minimum callback duration to record (in milliseconds).', 'wp-flame') . '</p>';
    }

    public function render_field_full_query_text(): void
    {
        $value = get_option('wp_flame_full_query_text', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_full_query_text', $value);
        echo ' ' . esc_html__('Record full SQL query text', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . wp_kses_post(__('When enabled, SQL query text is stored with each trace, bounded to protect storage. This may increase storage usage. <strong>Privacy notice:</strong> full query text may contain personal data (email addresses, usernames, etc.) embedded in query values. Enable only in development or with appropriate data handling policies.', 'wp-flame')) . '</p>';
    }

    public function render_field_full_http_url(): void
    {
        $value = get_option('wp_flame_full_http_url', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_full_http_url', $value);
        echo ' ' . esc_html__('Record full external HTTP URLs', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . wp_kses_post(__('By default WP Flame stores only the external host name. <strong>Privacy notice:</strong> full URLs may include tokens, emails, or other personal data in paths or query strings.', 'wp-flame')) . '</p>';
    }

    public function render_field_full_graphql_query(): void
    {
        $value = get_option('wp_flame_full_graphql_query', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_full_graphql_query', $value);
        echo ' ' . esc_html__('Record full GraphQL query text', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . wp_kses_post(__('By default WP Flame stores only operation name and query length. When enabled, very large GraphQL queries are truncated to protect storage. <strong>Privacy notice:</strong> GraphQL query text may include identifiers or user-provided values.', 'wp-flame')) . '</p>';
    }

    public function render_field_track_ips(): void
    {
        $value = get_option('wp_flame_track_ips', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_track_ips', $value);
        echo ' ' . esc_html__('Record IP addresses with traces', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Record the IP address of each traced request. Disable for GDPR compliance. IPs are deleted with traces according to your retention policy.', 'wp-flame') . '</p>';
    }

    public function render_field_track_users(): void
    {
        $value = get_option('wp_flame_track_users', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_track_users', $value);
        echo ' ' . esc_html__('Record logged-in user IDs with traces', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . esc_html__('By default WP Flame stores traces without a user ID. Enable this only when user-level filtering and privacy export/erasure by user are required.', 'wp-flame') . '</p>';
    }

    public function render_field_track_user_agent(): void
    {
        $value = get_option('wp_flame_track_user_agent', false);
        echo '<label>';
        $this->render_checkbox_input('wp_flame_track_user_agent', $value);
        echo ' ' . esc_html__('Record browser user-agent strings with traces', 'wp-flame');
        echo '</label>';
        echo '<p class="description">' . esc_html__('User-agent strings can be unique enough to identify a browser. They are omitted unless this setting is enabled.', 'wp-flame') . '</p>';
    }

    private function render_checkbox_input(string $name, $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(Config::boolean($value), true, false) . '>';
    }

    public function render_field_budget_max_ms(): void
    {
        $value = Config::bounded_int(get_option('wp_flame_budget_max_ms', 500), 500, 0, PHP_INT_MAX);
        echo '<input type="number" name="wp_flame_budget_max_ms" value="' . esc_attr((string) $value) . '" min="0" class="small-text">';
        echo '<p class="description">' . esc_html__('Alert when any traced request exceeds this duration. Set to 0 to disable.', 'wp-flame') . '</p>';
    }

    public function render_field_budget_max_queries(): void
    {
        $value = Config::bounded_int(get_option('wp_flame_budget_max_queries', 100), 100, 0, PHP_INT_MAX);
        echo '<input type="number" name="wp_flame_budget_max_queries" value="' . esc_attr((string) $value) . '" min="0" class="small-text">';
        echo '<p class="description">' . esc_html__('Alert when any traced request exceeds this query count. Set to 0 to disable.', 'wp-flame') . '</p>';
    }

    public function render_field_max_spans(): void
    {
        $value = self::sanitize_max_spans(get_option('wp_flame_max_spans', Config::DEFAULT_MAX_SPANS));
        echo '<input type="number" name="wp_flame_max_spans" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) Config::MIN_MAX_SPANS) . '" max="' . esc_attr((string) Config::MAX_MAX_SPANS) . '" class="small-text">';
        echo '<p class="description">' . esc_html__('Stop recording new spans after this limit to protect large WooCommerce, Elementor, and admin requests.', 'wp-flame') . '</p>';
    }

    public function render_field_max_trace_bytes(): void
    {
        $value = self::sanitize_max_trace_bytes(get_option('wp_flame_max_trace_bytes', Config::DEFAULT_MAX_TRACE_BYTES));
        echo '<input type="number" name="wp_flame_max_trace_bytes" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) Config::MIN_MAX_TRACE_BYTES) . '" max="' . esc_attr((string) Config::MAX_MAX_TRACE_BYTES) . '" step="1024" class="regular-text">';
        echo '<p class="description">' . esc_html__('Maximum stored trace JSON size in bytes. Oversized traces are trimmed before storage.', 'wp-flame') . '</p>';
    }

    /**
     * WordPress posts unchecked checkboxes as the hidden string "0".
     * Plain `(bool) "0"` is true in PHP, so normalize explicit boolean tokens.
     *
     * @param mixed $value
     */
    public static function sanitize_boolean( $value ): bool
    {
        return Config::boolean( $value );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_non_negative_float( $value ): float
    {
        return self::non_negative_float( $value, 0.0 );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_non_negative_int( $value ): int
    {
        return Config::bounded_int( $value, 0, 0, PHP_INT_MAX );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_sample_rate( $value ): int
    {
        return Config::bounded_int( $value, Config::DEFAULT_SAMPLE_RATE, 1, Config::MAX_SAMPLE_RATE );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_trace_audience( $value ): string
    {
        $value = Config::string_value( $value, 'admins' );

        return in_array( $value, [ 'admins', 'logged_in', 'everyone' ], true ) ? $value : 'admins';
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_instrumentation_mode( $value ): string
    {
        $value = Config::string_value( $value, 'standard' );

        return in_array( $value, [ 'safe', 'standard', 'deep' ], true ) ? $value : 'standard';
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_retention_days( $value ): int
    {
        return Config::bounded_int( $value, Config::DEFAULT_RETENTION_DAYS, 1, Config::MAX_RETENTION_DAYS );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_max_spans( $value ): int
    {
        return Config::bounded_int( $value, Config::DEFAULT_MAX_SPANS, Config::MIN_MAX_SPANS, Config::MAX_MAX_SPANS );
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_max_trace_bytes( $value ): int
    {
        return Config::bounded_int(
            $value,
            Config::DEFAULT_MAX_TRACE_BYTES,
            Config::MIN_MAX_TRACE_BYTES,
            Config::MAX_MAX_TRACE_BYTES
        );
    }

    /**
     * @param mixed $value
     */
    private static function non_negative_float( $value, float $default ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $float = (float) $value;
            return is_finite( $float ) ? max( 0.0, $float ) : $default;
        }

        $value = Config::string_value( $value, '' );
        if ( ! is_numeric( trim( $value ) ) ) {
            return $default;
        }

        $float = (float) trim( $value );

        return is_finite( $float ) ? max( 0.0, $float ) : $default;
    }

    public function handle_purge(): void
    {
        $nonce = $this->request_string($_POST, '_wpnonce');
        if ($nonce === '' || ! wp_verify_nonce($nonce, 'wp_flame_purge')) {
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

    /**
     * @param array<string, mixed> $source
     */
    private function request_string(array $source, string $key): string
    {
        if (! array_key_exists($key, $source)) {
            return '';
        }

        return $this->limit_string(
            sanitize_text_field(Config::string_value(wp_unslash($source[$key]), '')),
            self::MAX_REQUEST_STRING_BYTES
        );
    }

    private function limit_string(string $value, int $max_bytes): string
    {
        if (strlen($value) <= $max_bytes) {
            return $value;
        }

        return substr($value, 0, $max_bytes);
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
        echo '<button type="submit" class="button button-secondary wp-flame-confirm-submit" data-wp-flame-confirm="' . esc_attr__('Are you sure you want to delete all traces? This cannot be undone.', 'wp-flame') . '">' . esc_html__('Purge All Traces', 'wp-flame') . '</button>';
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
