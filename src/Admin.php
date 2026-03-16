<?php

declare(strict_types=1);

namespace WPFlame;

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
            'WP Flame',
            'WP Flame',
            'manage_options',
            'wp-flame',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
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

        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_flame_delete')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        $trace_id = sanitize_text_field(wp_unslash($_POST['wp_flame_delete_trace']));
        $this->storage->delete_trace($trace_id);

        wp_safe_redirect(admin_url('tools.php?page=wp-flame&deleted=1'));
        exit;
    }

    private function render_list_view(): void
    {
        $filters = [];

        if (! empty($_GET['s'])) {
            $filters['url'] = sanitize_text_field(wp_unslash($_GET['s']));
        }
        if (! empty($_GET['min_duration'])) {
            $filters['min_duration'] = (float) $_GET['min_duration'];
        }

        $paged    = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 20;

        $filters['page']     = $paged;
        $filters['per_page'] = $per_page;

        $traces = $this->storage->list_traces($filters);
        $total  = $this->storage->count_traces($filters);
        $pages  = (int) ceil($total / $per_page);

        echo '<div class="wrap">';
        echo '<h1>WP Flame</h1>';

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Trace deleted.</p></div>';
        }

        // Search/filter form
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wp-flame">';
        echo '<div class="tablenav top"><div class="alignleft">';
        echo '<input type="search" name="s" value="' . esc_attr($filters['url'] ?? '') . '" placeholder="Filter by URL...">';
        echo ' <input type="number" name="min_duration" value="' . esc_attr(isset($filters['min_duration']) ? (string) $filters['min_duration'] : '') . '" placeholder="Min ms..." step="any" style="width:100px">';
        echo ' <input type="submit" class="button" value="Filter">';
        echo '</div></div>';
        echo '</form>';

        // Table
        echo '<table class="widefat striped wp-flame-traces">';
        echo '<thead><tr>';
        echo '<th>URL</th><th>Method</th><th>Duration</th><th>Queries</th><th>Memory</th><th>Date</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($traces)) {
            echo '<tr><td colspan="7">No traces found. Browse your site as an admin to generate traces.</td></tr>';
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
            echo '<a href="' . esc_url($view_url) . '">View</a> | ';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('wp_flame_delete');
            echo '<input type="hidden" name="wp_flame_delete_trace" value="' . esc_attr($row['trace_id']) . '">';
            echo '<button type="submit" class="button-link" onclick="return confirm(\'Delete this trace?\')">Delete</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ]);
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_flame_graph_view(string $trace_id): void
    {
        $trace = $this->storage->get_trace($trace_id);

        if (! $trace) {
            echo '<div class="wrap"><h1>WP Flame</h1>';
            echo '<div class="notice notice-error"><p>Trace not found.</p></div></div>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>';
        echo '<a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '">&larr; All Traces</a>';
        echo ' &mdash; ' . esc_html($trace->method) . ' ' . esc_html($trace->url);
        echo '</h1>';

        // Summary stats bar — matches marketing mockup layout
        echo '<div class="wp-flame-summary">';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">TOTAL TIME</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($trace->total_ms)) . '<small>ms</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">DB QUERIES</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html($trace->query_count) . ' <small>(' . esc_html(round($trace->total_query_ms)) . 'ms)</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat">';
        echo '<span class="wp-flame-stat-label">PEAK MEMORY</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html(round($trace->peak_memory / 1048576)) . '<small>MB</small></span>';
        echo '</div>';
        echo '<div class="wp-flame-stat-right">';
        echo esc_html($trace->method) . ' ' . esc_html($trace->url) . ' &mdash; ' . esc_html(round($trace->total_ms)) . 'ms';
        echo '</div>';
        echo '</div>';

        // Color legend
        echo '<div class="wp-flame-legend">';
        $legend_items = [
            ['color' => '#6c7086', 'label' => 'Core'],
            ['color' => '#7c3aed', 'label' => 'Plugins'],
            ['color' => '#22c55e', 'label' => 'Theme'],
            ['color' => '#ef4444', 'label' => 'Database'],
            ['color' => '#f59e0b', 'label' => 'External HTTP'],
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
            echo '<h3>Insights</h3>';
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
            echo '<strong>WP Flame</strong> is running in limited mode &mdash; plugin load timing is unavailable. ';
            echo 'Copy <code>wp-flame/mu-plugin/wp-flame-early-hooks.php</code> to <code>wp-content/mu-plugins/</code> for full instrumentation.';
            echo '</p></div>';
        }

        global $wpdb;
        if (! ($wpdb instanceof DB) && get_class($wpdb) !== 'wpdb') {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>WP Flame</strong>: DB query instrumentation is disabled &mdash; another plugin is modifying the database layer.';
            echo '</p></div>';
        }
    }
}
