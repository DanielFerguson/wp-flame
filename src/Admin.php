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
            $this->render_flame_graph_view($trace_id);
        } else {
            $this->render_list_view();
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

    private function render_list_view(): void
    {
        $filters = [];

        if (! empty($_GET['s'])) {
            $filters['url'] = sanitize_text_field(wp_unslash($_GET['s']));
        }
        if (! empty($_GET['min_duration'])) {
            $filters['min_duration'] = (float) wp_unslash($_GET['min_duration']);
        }

        $paged    = max(1, (int) wp_unslash($_GET['paged'] ?? 1));
        $per_page = 20;

        $filters['page']     = $paged;
        $filters['per_page'] = $per_page;

        $traces = $this->storage->list_traces($filters);
        $total  = $this->storage->count_traces($filters);
        $pages  = (int) ceil($total / $per_page);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WP Flame', 'wp-flame') . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('options-general.php?page=wp-flame-settings')) . '">' . esc_html__('Settings', 'wp-flame') . '</a></p>';

        $deleted_key = 'wp_flame_deleted_' . get_current_user_id();
        if (get_transient($deleted_key)) {
            delete_transient($deleted_key);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Trace deleted.', 'wp-flame') . '</p></div>';
        }

        $this->render_dashboard();

        // Search/filter form
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wp-flame">';
        echo '<div class="tablenav top"><div class="alignleft">';
        echo '<input type="search" name="s" value="' . esc_attr($filters['url'] ?? '') . '" placeholder="' . esc_attr__('Filter by URL...', 'wp-flame') . '">';
        echo ' <input type="number" name="min_duration" value="' . esc_attr(isset($filters['min_duration']) ? (string) $filters['min_duration'] : '') . '" placeholder="' . esc_attr__('Min ms...', 'wp-flame') . '" step="any" style="width:100px">';
        echo ' <input type="submit" class="button" value="' . esc_attr__('Filter', 'wp-flame') . '">';
        echo '</div></div>';
        echo '</form>';

        // Table
        echo '<table class="widefat striped wp-flame-traces">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('URL', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Method', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Duration', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Queries', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Memory', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Date', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Actions', 'wp-flame') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($traces)) {
            echo '<tr><td colspan="7">' . esc_html__('No traces found. Browse your site as an admin to generate traces.', 'wp-flame') . '</td></tr>';
        }

        foreach ($traces as $row) {
            $is_slow  = ((float) $row['total_ms'] > 500);
            $view_url = admin_url('tools.php?page=wp-flame&trace_id=' . urlencode($row['trace_id']));
            $mem_mb   = round((int) $row['peak_memory'] / 1048576, 1);

            echo $is_slow ? '<tr class="wp-flame-slow">' : '<tr>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($row['url']) . '</a></td>';
            echo '<td>' . esc_html($row['method']) . '</td>';
            echo '<td>' . esc_html(round((float) $row['total_ms'], 1)) . ' ms</td>';
            echo '<td>' . esc_html($row['query_count']) . '</td>';
            echo '<td>' . esc_html($mem_mb) . ' MB</td>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($view_url) . '">' . esc_html__('View', 'wp-flame') . '</a> | ';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('wp_flame_delete_' . $row['trace_id']);
            echo '<input type="hidden" name="wp_flame_delete_trace" value="' . esc_attr($row['trace_id']) . '">';
            echo '<button type="submit" class="button-link" onclick="return confirm(\'' . esc_js(__('Delete this trace?', 'wp-flame')) . '\')">' . esc_html__('Delete', 'wp-flame') . '</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $pagination = paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $paged,
            'total'   => $pages,
        ]);
        if ($pagination) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo wp_kses_post($pagination);
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_dashboard(): void
    {
        $stats        = $this->storage->get_aggregate_stats(7);
        $slowest_pages = $this->storage->get_slowest_pages(5, 7);

        // Trend indicator
        $trend       = '—';
        $trend_class = '';
        if ($stats['prev_avg_ms'] !== null) {
            $change = (($stats['avg_ms'] - $stats['prev_avg_ms']) / $stats['prev_avg_ms']) * 100;
            if ($change > 10) {
                $trend       = sprintf('↑ %d%%', round(abs($change)));
                $trend_class = 'wp-flame-trend-bad';
            } elseif ($change < -10) {
                $trend       = sprintf('↓ %d%%', round(abs($change)));
                $trend_class = 'wp-flame-trend-good';
            } else {
                $trend       = '~';
                $trend_class = '';
            }
        }

        // Slowest page for card display
        $slowest_page_url = ! empty($slowest_pages) ? $slowest_pages[0]['page_url'] : '—';
        $slowest_page_ms  = ! empty($slowest_pages) ? round((float) $slowest_pages[0]['avg_ms'], 1) : 0;

        // Summary stat cards
        echo '<div class="wp-flame-summary">';

        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('AVG LOAD TIME', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($stats['avg_ms'], 1)) . '<small>ms</small></span>';
        if ($trend !== '—' && $trend_class !== '') {
            echo '<span class="wp-flame-trend ' . esc_attr($trend_class) . '">' . esc_html($trend) . '</span>';
        } else {
            echo '<span class="wp-flame-trend">' . esc_html($trend) . '</span>';
        }
        echo '</div>';

        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('TRACES', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html((string) $stats['count']) . '</span>';
        echo '<span class="wp-flame-trend">' . esc_html__('last 7 days', 'wp-flame') . '</span>';
        echo '</div>';

        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('SLOWEST PAGE', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value" style="font-size:14px;word-break:break-all">' . esc_html($slowest_page_url) . '</span>';
        if ($slowest_page_ms > 0) {
            echo '<span class="wp-flame-trend">' . esc_html(sprintf(__('avg %s ms', 'wp-flame'), $slowest_page_ms)) . '</span>';
        }
        echo '</div>';

        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('AVG QUERIES', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($stats['avg_queries'], 1)) . '</span>';
        echo '<span class="wp-flame-trend">' . esc_html__('per request', 'wp-flame') . '</span>';
        echo '</div>';

        echo '</div>'; // .wp-flame-summary

        // Slowest callbacks ranking
        $slowest_callbacks = $this->get_slowest_callbacks(5);

        // Rankings tables
        echo '<div class="wp-flame-rankings">';

        // Slowest Pages ranking
        echo '<div class="wp-flame-ranking">';
        echo '<h3>' . esc_html__('Slowest Pages', 'wp-flame') . '</h3>';
        echo '<table>';
        if (empty($slowest_pages)) {
            echo '<tr><td colspan="2">' . esc_html__('No data yet.', 'wp-flame') . '</td></tr>';
        } else {
            foreach ($slowest_pages as $page) {
                echo '<tr>';
                echo '<td>' . esc_html($page['page_url']) . '</td>';
                echo '<td>' . esc_html(round((float) $page['avg_ms'], 1)) . ' ms';
                echo ' <span style="color:#c3c4c7">(' . esc_html((string) $page['hits']) . 'x)</span>';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</table>';
        echo '</div>'; // .wp-flame-ranking

        // Slowest Callbacks ranking
        echo '<div class="wp-flame-ranking">';
        echo '<h3>' . esc_html__('Slowest Callbacks', 'wp-flame') . '</h3>';
        echo '<table>';
        if (empty($slowest_callbacks)) {
            echo '<tr><td colspan="2">' . esc_html__('No data yet.', 'wp-flame') . '</td></tr>';
        } else {
            foreach ($slowest_callbacks as $name => $total_ms) {
                echo '<tr>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html(round((float) $total_ms, 1)) . ' ms</td>';
                echo '</tr>';
            }
        }
        echo '</table>';
        echo '</div>'; // .wp-flame-ranking

        echo '</div>'; // .wp-flame-rankings
    }

    /**
     * Build a top-N callback ranking by summing duration_ms across recent traces.
     *
     * @return array<string, float>
     */
    private function get_slowest_callbacks(int $limit = 5): array
    {
        $trace_data_blobs = $this->storage->get_recent_trace_data(50);
        $totals           = [];

        foreach ($trace_data_blobs as $blob) {
            $data = json_decode($blob, true);
            if (! is_array($data) || empty($data['spans'])) {
                continue;
            }

            foreach ($data['spans'] as $span) {
                // Only callback spans (those with a meta.hook key)
                if (empty($span['meta']['hook'])) {
                    continue;
                }

                $name = $span['name'] ?? '';
                if ($name === '') {
                    continue;
                }

                $totals[$name] = ($totals[$name] ?? 0.0) + (float) ($span['duration_ms'] ?? 0);
            }
        }

        arsort($totals);

        return array_slice($totals, 0, $limit, true);
    }

    private function render_flame_graph_view(string $trace_id): void
    {
        $trace = $this->storage->get_trace($trace_id);

        if (! $trace) {
            echo '<div class="wrap"><h1>' . esc_html__('WP Flame', 'wp-flame') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Trace not found.', 'wp-flame') . '</p></div></div>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>';
        echo '<a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '">&larr; ' . esc_html__('All Traces', 'wp-flame') . '</a>';
        echo ' &mdash; ' . esc_html($trace->method) . ' ' . esc_html($trace->url);
        echo '</h1>';

        // Summary stats bar — matches marketing mockup layout
        echo '<div class="wp-flame-summary">';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('TOTAL TIME', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($trace->total_ms)) . '<small>ms</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('DB QUERIES', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html($trace->query_count) . ' <small>(' . esc_html(round($trace->total_query_ms)) . 'ms)</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('PEAK MEMORY', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($trace->peak_memory / 1048576)) . '<small>MB</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat-right">';
        echo esc_html($trace->method) . ' ' . esc_html($trace->url) . ' &mdash; ' . esc_html(round($trace->total_ms)) . 'ms';
        echo '</div>';
        echo '</div>';

        // Color legend
        echo '<div class="wp-flame-legend">';
        $legend_items = [
            ['color' => '#6c7086', 'label' => __('Core', 'wp-flame')],
            ['color' => '#7c3aed', 'label' => __('Plugins', 'wp-flame')],
            ['color' => '#22c55e', 'label' => __('Theme', 'wp-flame')],
            ['color' => '#ef4444', 'label' => __('Database', 'wp-flame')],
            ['color' => '#f59e0b', 'label' => __('External HTTP', 'wp-flame')],
        ];
        foreach ($legend_items as $item) {
            echo '<span class="wp-flame-legend-item">';
            echo '<span class="wp-flame-legend-color" style="background:' . esc_attr($item['color']) . '"></span>';
            echo esc_html($item['label']);
            echo '</span>';
        }
        echo '</div>';

        // Flame graph container
        echo '<div id="wp-flame-breadcrumbs"></div>';
        echo '<div id="wp-flame-graph"></div>';
        echo '<div id="wp-flame-tooltip" style="display:none"></div>';

        $insights = \WPFlame\Insights::analyze($trace);
        if (!empty($insights)) {
            echo '<div class="wp-flame-insights">';
            echo '<h3>' . esc_html__('Insights', 'wp-flame') . '</h3>';
            foreach ($insights as $insight) {
                $class = $insight['severity'] === 'warning' ? 'wp-flame-insight-warning' : 'wp-flame-insight-info';
                echo '<div class="wp-flame-insight ' . esc_attr($class) . '">';
                echo '<strong>' . esc_html($insight['title']) . '</strong>';
                echo '<p>' . esc_html($insight['detail']) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>';

        // Pass trace data to JS (wp_add_inline_script preserves numeric types;
        // wp_localize_script would convert all values to strings, breaking .toFixed() calls)
        wp_add_inline_script(
            'wp-flame-graph',
            'window.wpFlameTrace = ' . wp_json_encode($trace->toArray()) . ';',
            'before'
        );
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
