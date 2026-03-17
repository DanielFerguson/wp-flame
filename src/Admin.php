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
        if (isset($_GET['max_duration']) && $_GET['max_duration'] !== '') {
            $filters['max_duration'] = (float) wp_unslash($_GET['max_duration']);
        }
        if (! empty($_GET['method'])) {
            $filters['method'] = sanitize_text_field(wp_unslash($_GET['method']));
        }
        if (! empty($_GET['type'])) {
            $type = sanitize_text_field(wp_unslash($_GET['type']));
            if ($type === 'cron') {
                $filters['url'] = 'wp-cron.php';
            } elseif ($type === 'ajax') {
                $filters['url'] = 'admin-ajax.php';
            }
        }
        if (! empty($_GET['orderby'])) {
            $filters['orderby'] = sanitize_text_field(wp_unslash($_GET['orderby']));
        }
        if (! empty($_GET['order'])) {
            $filters['order'] = sanitize_text_field(wp_unslash($_GET['order']));
        }

        $paged    = max(1, (int) wp_unslash($_GET['paged'] ?? 1));
        $per_page = 20;

        $filters['page']     = $paged;
        $filters['per_page'] = $per_page;

        $traces = $this->storage->list_traces($filters);
        $total  = $this->storage->count_traces($filters);
        $pages  = (int) ceil($total / $per_page);

        echo '<div class="wrap">';
        echo '<h1><svg width="24" height="24" viewBox="0 0 32 32" fill="none" style="vertical-align:middle;margin-right:6px"><rect x="3" y="24" width="26" height="6" rx="2" fill="#FF9632"/><rect x="5" y="17" width="22" height="6" rx="2" fill="#F07A18"/><rect x="7" y="10" width="18" height="6" rx="2" fill="#E84D30"/><rect x="10" y="3" width="12" height="6" rx="2" fill="#C23520"/></svg>' . esc_html__('WP Flame', 'wp-flame') . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('options-general.php?page=wp-flame-settings')) . '">' . esc_html__('Settings', 'wp-flame') . '</a></p>';

        $deleted_key = 'wp_flame_deleted_' . get_current_user_id();
        if (get_transient($deleted_key)) {
            delete_transient($deleted_key);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Trace deleted.', 'wp-flame') . '</p></div>';
        }

        $this->render_dashboard();

        // Active duration filter indicator
        if (isset($filters['min_duration']) && isset($filters['max_duration'])) {
            $clear_url = admin_url('tools.php?page=wp-flame');
            echo '<div class="notice notice-info inline" style="margin:8px 0"><p>';
            /* translators: %1$s: min duration, %2$s: max duration */
            echo wp_kses_post(sprintf(
                __('Showing traces between <strong>%1$sms</strong> and <strong>%2$sms</strong>.', 'wp-flame'),
                esc_html(round((float) $filters['min_duration'])),
                esc_html(round((float) $filters['max_duration']))
            ));
            echo ' <a href="' . esc_url($clear_url) . '">' . esc_html__('Clear filter', 'wp-flame') . '</a>';
            echo '</p></div>';
        } elseif (isset($filters['min_duration']) && !isset($filters['max_duration'])) {
            $clear_url = admin_url('tools.php?page=wp-flame');
            echo '<div class="notice notice-info inline" style="margin:8px 0"><p>';
            /* translators: %s: min duration */
            echo wp_kses_post(sprintf(
                __('Showing traces slower than <strong>%sms</strong>.', 'wp-flame'),
                esc_html(round((float) $filters['min_duration']))
            ));
            echo ' <a href="' . esc_url($clear_url) . '">' . esc_html__('Clear filter', 'wp-flame') . '</a>';
            echo '</p></div>';
        }

        // Search/filter form
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wp-flame">';
        echo '<div class="tablenav top"><div class="alignleft">';
        $current_type = isset($_GET['type']) ? sanitize_text_field(wp_unslash($_GET['type'])) : '';
        echo ' <select name="type" style="height:30px;vertical-align:top">';
        echo '<option value="">' . esc_html__('All Types', 'wp-flame') . '</option>';
        echo '<option value="cron"' . selected($current_type, 'cron', false) . '>' . esc_html__('Cron', 'wp-flame') . '</option>';
        echo '<option value="ajax"' . selected($current_type, 'ajax', false) . '>' . esc_html__('AJAX', 'wp-flame') . '</option>';
        echo '</select>';
        echo '<input type="search" name="s" value="' . esc_attr($filters['url'] ?? '') . '" placeholder="' . esc_attr__('Filter by URL...', 'wp-flame') . '">';
        $current_method = $filters['method'] ?? '';
        echo ' <select name="method" style="height:30px;vertical-align:top">';
        echo '<option value="">' . esc_html__('All Methods', 'wp-flame') . '</option>';
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
            echo '<option value="' . esc_attr($m) . '"' . selected($current_method, $m, false) . '>' . esc_html($m) . '</option>';
        }
        echo '</select>';
        echo ' <input type="number" name="min_duration" value="' . esc_attr(isset($filters['min_duration']) ? (string) $filters['min_duration'] : '') . '" placeholder="' . esc_attr__('Min ms...', 'wp-flame') . '" step="any" style="width:100px">';
        echo ' <input type="submit" class="button" value="' . esc_attr__('Filter', 'wp-flame') . '">';
        echo '</div></div>';
        echo '</form>';

        // Table with sortable columns
        $current_orderby = $filters['orderby'] ?? 'created_at';
        $current_order   = strtoupper($filters['order'] ?? 'DESC');
        $base_args       = ['page' => 'wp-flame'];
        if (! empty($filters['url'])) $base_args['s'] = $filters['url'];
        if (! empty($filters['method'])) $base_args['method'] = $filters['method'];
        if (isset($filters['min_duration'])) $base_args['min_duration'] = $filters['min_duration'];
        if (isset($filters['max_duration'])) $base_args['max_duration'] = $filters['max_duration'];

        $sortable_columns = [
            'total_ms'    => __('Duration', 'wp-flame'),
            'query_count' => __('Queries', 'wp-flame'),
            'peak_memory' => __('Memory', 'wp-flame'),
            'created_at'  => __('Date', 'wp-flame'),
        ];

        echo '<table class="widefat striped wp-flame-traces">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('URL', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Method', 'wp-flame') . '</th>';

        foreach ($sortable_columns as $col => $label) {
            $is_active  = ($current_orderby === $col);
            $next_order = ($is_active && $current_order === 'DESC') ? 'ASC' : 'DESC';
            $sort_url   = add_query_arg(array_merge($base_args, ['orderby' => $col, 'order' => $next_order]), admin_url('tools.php'));
            $arrow      = $is_active ? ($current_order === 'DESC' ? ' ▾' : ' ▴') : '';
            echo '<th><a href="' . esc_url($sort_url) . '" style="text-decoration:none;color:inherit">' . esc_html($label) . $arrow . '</a></th>';
        }

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

        // Chart 2: Time Breakdown Bar
        $trace_data_blobs = $this->storage->get_recent_trace_data(50);
        $type_totals      = ['core' => 0.0, 'plugin' => 0.0, 'theme' => 0.0, 'db' => 0.0, 'http' => 0.0];

        foreach ($trace_data_blobs as $blob) {
            $data = json_decode($blob, true);
            if (! is_array($data) || empty($data['spans'])) {
                continue;
            }
            foreach ($data['spans'] as $span) {
                $type = $span['type'] ?? '';
                if (array_key_exists($type, $type_totals)) {
                    $type_totals[$type] += (float) ($span['duration_ms'] ?? 0);
                }
            }
        }

        $total_time = array_sum($type_totals) ?: 1;

        $colors = [
            'core'   => '#6c7086',
            'plugin' => '#7c3aed',
            'theme'  => '#22c55e',
            'db'     => '#ef4444',
            'http'   => '#f59e0b',
        ];
        $labels = [
            'core'   => __('Core', 'wp-flame'),
            'plugin' => __('Plugins', 'wp-flame'),
            'theme'  => __('Theme', 'wp-flame'),
            'db'     => __('Database', 'wp-flame'),
            'http'   => __('HTTP', 'wp-flame'),
        ];

        echo '<div class="wp-flame-breakdown">';
        echo '<div class="wp-flame-breakdown-bar">';
        foreach ($type_totals as $type => $ms) {
            $pct = ($ms / $total_time) * 100;
            if ($pct < 0.5) {
                continue;
            }
            echo '<div class="wp-flame-breakdown-segment" style="width:' . esc_attr(round($pct, 1)) . '%;background:' . esc_attr($colors[$type] ?? '#999') . '" title="' . esc_attr($labels[$type] ?? $type) . ': ' . esc_attr(round($pct, 1)) . '%"></div>';
        }
        echo '</div>';
        echo '<div class="wp-flame-breakdown-labels">';
        foreach ($type_totals as $type => $ms) {
            $pct = ($ms / $total_time) * 100;
            if ($pct < 1) {
                continue;
            }
            echo '<span class="wp-flame-breakdown-label"><span style="background:' . esc_attr($colors[$type] ?? '#999') . '" class="wp-flame-legend-color"></span>' . esc_html($labels[$type] ?? $type) . ' ' . esc_html(round($pct)) . '%</span>';
        }
        echo '</div>';
        echo '</div>'; // .wp-flame-breakdown

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

        // Chart 3: Response Time Distribution Histogram
        $distribution = $this->storage->get_response_time_distribution(7);
        $max_count    = max(array_column($distribution, 'count')) ?: 1;

        echo '<div class="wp-flame-ranking">';
        echo '<h3>' . esc_html__('Response Time Distribution', 'wp-flame') . '</h3>';
        echo '<div class="wp-flame-histogram">';
        foreach ($distribution as $bucket) {
            $pct = ($bucket['count'] / $max_count) * 100;
            $filter_url = add_query_arg([
                'page'         => 'wp-flame',
                'min_duration' => $bucket['min'],
                'max_duration' => $bucket['max'] < 999999 ? $bucket['max'] : '',
            ], admin_url('tools.php'));
            $is_active = isset($filters['min_duration'])
                && (float) $filters['min_duration'] === (float) $bucket['min']
                && isset($filters['max_duration'])
                && (float) $filters['max_duration'] === (float) $bucket['max'];
            $row_class = 'wp-flame-histogram-row' . ($is_active ? ' wp-flame-histogram-active' : '');
            echo '<a href="' . esc_url($filter_url) . '" class="' . esc_attr($row_class) . '">';
            echo '<span class="wp-flame-histogram-label">' . esc_html($bucket['label']) . '</span>';
            echo '<div class="wp-flame-histogram-bar-container">';
            echo '<div class="wp-flame-histogram-bar" style="width:' . esc_attr(round($pct, 1)) . '%"></div>';
            echo '</div>';
            echo '<span class="wp-flame-histogram-count">' . esc_html($bucket['count']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
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
