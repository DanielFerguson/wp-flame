<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\Insights;
use WPFlame\Score;
use WPFlame\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ListView
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function render(): void
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
            } elseif ($type === 'rest') {
                $filters['url'] = 'wp-json';
            } elseif ($type === 'graphql') {
                $filters['url'] = '/graphql';
            }
        }
        if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
            $filters['user_id'] = (int) wp_unslash($_GET['user_id']);
        }
        if (! empty($_GET['ip_address'])) {
            $filters['ip_address'] = sanitize_text_field(wp_unslash($_GET['ip_address']));
        }
        if (! empty($_GET['grade'])) {
            $grade = strtoupper(sanitize_text_field(wp_unslash($_GET['grade'])));
            $grade_ranges = [
                'A' => [90, 100],
                'B' => [80, 89],
                'C' => [70, 79],
                'D' => [60, 69],
                'F' => [0, 59],
            ];
            if (isset($grade_ranges[$grade])) {
                $filters['min_score'] = $grade_ranges[$grade][0];
                $filters['max_score'] = $grade_ranges[$grade][1];
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

        $this->render_dashboard($filters);

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

        if (isset($filters['user_id'])) {
            $clear_url   = admin_url('tools.php?page=wp-flame');
            $user_label  = esc_html((string) $filters['user_id']);
            if ($filters['user_id'] === 0) {
                $user_label = esc_html__('Anonymous', 'wp-flame');
            } elseif (function_exists('get_userdata')) {
                $filter_user = get_userdata($filters['user_id']);
                if ($filter_user) {
                    $user_label = esc_html($filter_user->display_name);
                }
            }
            echo '<div class="notice notice-info inline" style="margin:8px 0"><p>';
            /* translators: %s: user display name or ID */
            echo wp_kses_post(sprintf(
                __('Showing traces for user <strong>%s</strong>.', 'wp-flame'),
                $user_label
            ));
            echo ' <a href="' . esc_url($clear_url) . '">' . esc_html__('Clear filter', 'wp-flame') . '</a>';
            echo '</p></div>';
        }

        if (! empty($filters['ip_address'])) {
            $clear_url = admin_url('tools.php?page=wp-flame');
            echo '<div class="notice notice-info inline" style="margin:8px 0"><p>';
            /* translators: %s: IP address */
            echo wp_kses_post(sprintf(
                __('Showing traces from IP <strong>%s</strong>.', 'wp-flame'),
                esc_html($filters['ip_address'])
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
        echo '<option value="rest"' . selected($current_type, 'rest', false) . '>' . esc_html__('REST API', 'wp-flame') . '</option>';
        echo '<option value="graphql"' . selected($current_type, 'graphql', false) . '>' . esc_html__('GraphQL', 'wp-flame') . '</option>';
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

        // Grade filter
        $current_grade = isset($_GET['grade']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['grade']))) : '';
        echo ' <select name="grade" style="height:30px;vertical-align:top">';
        echo '<option value="">' . esc_html__('All Grades', 'wp-flame') . '</option>';
        foreach (['A', 'B', 'C', 'D', 'F'] as $g) {
            echo '<option value="' . esc_attr($g) . '"' . selected($current_grade, $g, false) . '>' . esc_html($g) . '</option>';
        }
        echo '</select>';

        // User filter
        $current_user_filter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) wp_unslash($_GET['user_id']) : '';
        $distinct_users = $this->storage->get_distinct_users();
        echo ' <select name="user_id" style="height:30px;vertical-align:top">';
        echo '<option value="">' . esc_html__('All Users', 'wp-flame') . '</option>';
        foreach ($distinct_users as $u) {
            $uid = (int) $u['user_id'];
            if ($uid === 0) {
                $label = __('Anonymous', 'wp-flame');
            } else {
                $user_data = get_userdata($uid);
                $label = $user_data ? $user_data->display_name : '#' . $uid;
            }
            $label .= ' (' . (int) $u['request_count'] . ')';
            $sel = ($current_user_filter !== '' && $current_user_filter === $uid) ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $uid) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        // IP filter
        $current_ip_filter = isset($_GET['ip_address']) ? sanitize_text_field(wp_unslash($_GET['ip_address'])) : '';
        $distinct_ips = $this->storage->get_distinct_ips();
        echo ' <select name="ip_address" style="height:30px;vertical-align:top">';
        echo '<option value="">' . esc_html__('All IPs', 'wp-flame') . '</option>';
        foreach ($distinct_ips as $ip_row) {
            $ip = (string) $ip_row['ip_address'];
            $ip_label = $ip . ' (' . (int) $ip_row['request_count'] . ')';
            echo '<option value="' . esc_attr($ip) . '"' . selected($current_ip_filter, $ip, false) . '>' . esc_html($ip_label) . '</option>';
        }
        echo '</select>';

        echo ' <input type="submit" class="button" value="' . esc_attr__('Filter', 'wp-flame') . '">';
        echo ' <a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '" class="button">' . esc_html__('Clear', 'wp-flame') . '</a>';
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
            'score'       => __('Score', 'wp-flame'),
            'total_ms'    => __('Duration', 'wp-flame'),
            'query_count' => __('Queries', 'wp-flame'),
            'peak_memory' => __('Memory', 'wp-flame'),
            'created_at'  => __('Date', 'wp-flame'),
        ];

        echo '<table class="widefat striped wp-flame-traces">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('URL', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('Method', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('User', 'wp-flame') . '</th>';
        echo '<th>' . esc_html__('IP', 'wp-flame') . '</th>';

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
            echo '<tr><td colspan="10">' . esc_html__('No traces found. Browse your site as an admin to generate traces.', 'wp-flame') . '</td></tr>';
        }

        foreach ($traces as $row) {
            $is_slow  = ((float) $row['total_ms'] > 500);
            $view_url = admin_url('tools.php?page=wp-flame&trace_id=' . urlencode($row['trace_id']));
            $mem_mb   = round((int) $row['peak_memory'] / 1048576, 1);

            // Resolve user display info
            $row_user_id = (int) ($row['user_id'] ?? 0);
            if ($row_user_id > 0 && function_exists('get_userdata')) {
                $row_user = get_userdata($row_user_id);
                if ($row_user) {
                    $row_user_roles = implode(', ', $row_user->roles);
                    $user_filter_url = add_query_arg(['page' => 'wp-flame', 'user_id' => $row_user_id], admin_url('tools.php'));
                    $user_cell = '<a href="' . esc_url($user_filter_url) . '">' . esc_html($row_user->display_name) . '</a> <span style="color:#999">(' . esc_html($row_user_roles) . ')</span>';
                } else {
                    $user_filter_url = add_query_arg(['page' => 'wp-flame', 'user_id' => $row_user_id], admin_url('tools.php'));
                    $user_cell = '<a href="' . esc_url($user_filter_url) . '">#' . esc_html((string) $row_user_id) . '</a>';
                }
            } else {
                $user_filter_url = add_query_arg(['page' => 'wp-flame', 'user_id' => 0], admin_url('tools.php'));
                $user_cell = '<a href="' . esc_url($user_filter_url) . '">' . esc_html__('Anonymous', 'wp-flame') . '</a>';
            }

            // Resolve IP display info
            $row_ip = (string) ($row['ip_address'] ?? '');
            if ($row_ip !== '') {
                $ip_filter_url = add_query_arg(['page' => 'wp-flame', 'ip_address' => $row_ip], admin_url('tools.php'));
                $ip_cell = '<a href="' . esc_url($ip_filter_url) . '">' . esc_html($row_ip) . '</a>';
            } else {
                $ip_cell = '&mdash;';
            }

            echo $is_slow ? '<tr class="wp-flame-slow">' : '<tr>';
            echo '<td><a href="' . esc_url($view_url) . '">' . esc_html($row['url']) . '</a></td>';
            echo '<td>' . esc_html($row['method']) . '</td>';
            echo '<td>' . wp_kses_post($user_cell) . '</td>';
            echo '<td>' . wp_kses_post($ip_cell) . '</td>';
            if ($row['score'] !== null) {
                $badge_grade = Score::grade((int) $row['score']);
                echo '<td><span class="wp-flame-score-badge" style="background:' . esc_attr($badge_grade['color']) . '">' . esc_html((string) $row['score']) . '</span></td>';
            } else {
                echo '<td>&mdash;</td>';
            }
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

    private function render_dashboard(array $filters): void
    {
        $stats        = $this->storage->get_aggregate_stats(7);
        $slowest_pages = $this->storage->get_slowest_pages(5, 7);
        $avg_score    = $this->storage->get_avg_score(7);

        // Trend indicator
        $trend       = '—';
        $trend_class = '';
        if ($stats['prev_avg_ms'] !== null && $stats['prev_avg_ms'] > 0) {
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

        // AVG SCORE stat card
        if ($avg_score !== null) {
            $avg_grade = Score::grade((int) round($avg_score));
            echo '<div class="wp-flame-stat wp-flame-score-card" style="border-left:4px solid ' . esc_attr($avg_grade['color']) . '">';
            echo '<span class="wp-flame-stat-label">' . esc_html__('AVG SCORE', 'wp-flame') . '</span>';
            echo '<span class="wp-flame-stat-value" style="color:' . esc_attr($avg_grade['color']) . '">' . esc_html((string) $avg_score) . '<small>' . esc_html($avg_grade['grade']) . '</small></span>';
            echo '<span class="wp-flame-trend">' . esc_html__('last 7 days', 'wp-flame') . '</span>';
            echo '</div>';
        }

        echo '</div>'; // .wp-flame-summary

        // Fetch and decode trace data once; reuse for breakdown bar and slowest callbacks.
        $trace_data_blobs   = $this->storage->get_recent_trace_data(50);
        $decoded_traces     = [];
        foreach ($trace_data_blobs as $blob) {
            $data = json_decode($blob, true);
            if (is_array($data) && ! empty($data['spans'])) {
                $decoded_traces[] = $data;
            }
        }

        // Chart 2: Time Breakdown Bar
        $type_totals = ['core' => 0.0, 'plugin' => 0.0, 'theme' => 0.0, 'db' => 0.0, 'http' => 0.0];

        foreach ($decoded_traces as $data) {
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

        // Slowest callbacks ranking (reuse already-decoded traces)
        $slowest_callbacks = $this->get_slowest_callbacks(5, $decoded_traces);

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

        // Second rankings row: Top Users and Top IPs
        $top_users = $this->storage->get_top_users(5, 7);
        $top_ips   = $this->storage->get_top_ips(5, 7);

        echo '<div class="wp-flame-rankings">';

        // Top Users by Load
        echo '<div class="wp-flame-ranking">';
        echo '<h3>' . esc_html__('Top Users by Load', 'wp-flame') . '</h3>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th style="text-align:left;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('User', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Reqs', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Avg', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Total', 'wp-flame') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($top_users)) {
            echo '<tr><td colspan="4">' . esc_html__('No data yet.', 'wp-flame') . '</td></tr>';
        } else {
            foreach ($top_users as $user_row) {
                $tu_id   = (int) $user_row['user_id'];
                $tu_url  = add_query_arg(['page' => 'wp-flame', 'user_id' => $tu_id], admin_url('tools.php'));
                if ($tu_id > 0 && function_exists('get_userdata')) {
                    $tu_user = get_userdata($tu_id);
                    $tu_name = $tu_user ? $tu_user->display_name : '#' . $tu_id;
                    if ($tu_user) {
                        $tu_roles = implode(', ', $tu_user->roles);
                        $tu_name  = $tu_user->display_name . ' (' . $tu_roles . ')';
                    }
                } else {
                    $tu_name = __('Anonymous', 'wp-flame');
                    $tu_url  = add_query_arg(['page' => 'wp-flame', 'user_id' => 0], admin_url('tools.php'));
                }
                $tu_total_s = round((float) $user_row['total_ms'] / 1000, 1);
                echo '<tr style="cursor:pointer" onclick="window.location=\'' . esc_url($tu_url) . '\'">';
                echo '<td><a href="' . esc_url($tu_url) . '" style="text-decoration:none;color:inherit">' . esc_html($tu_name) . '</a></td>';
                echo '<td style="text-align:right">' . esc_html((string) $user_row['request_count']) . '</td>';
                echo '<td style="text-align:right">' . esc_html(round((float) $user_row['avg_ms'])) . 'ms</td>';
                echo '<td style="text-align:right">' . esc_html((string) $tu_total_s) . 's</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>'; // .wp-flame-ranking

        // Top IPs by Requests
        echo '<div class="wp-flame-ranking">';
        echo '<h3>' . esc_html__('Top IPs by Requests', 'wp-flame') . '</h3>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th style="text-align:left;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('IP Address', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Reqs', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Avg', 'wp-flame') . '</th>';
        echo '<th style="text-align:right;font-size:11px;color:#646970;padding:0 0 6px">' . esc_html__('Total', 'wp-flame') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($top_ips)) {
            echo '<tr><td colspan="4">' . esc_html__('No data yet.', 'wp-flame') . '</td></tr>';
        } else {
            foreach ($top_ips as $ip_row) {
                $ti_ip  = (string) $ip_row['ip_address'];
                $ti_url = add_query_arg(['page' => 'wp-flame', 'ip_address' => $ti_ip], admin_url('tools.php'));
                $ti_total_s = round((float) $ip_row['total_ms'] / 1000, 1);
                echo '<tr style="cursor:pointer" onclick="window.location=\'' . esc_url($ti_url) . '\'">';
                echo '<td><a href="' . esc_url($ti_url) . '" style="text-decoration:none;color:inherit">' . esc_html($ti_ip) . '</a></td>';
                echo '<td style="text-align:right">' . esc_html((string) $ip_row['request_count']) . '</td>';
                echo '<td style="text-align:right">' . esc_html(round((float) $ip_row['avg_ms'])) . 'ms</td>';
                echo '<td style="text-align:right">' . esc_html((string) $ti_total_s) . 's</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>'; // .wp-flame-ranking

        echo '</div>'; // .wp-flame-rankings (second row)

        // Fetch recent traces for abuse pattern detection (url and ip_address are sufficient)
        $recent_trace_rows = $this->storage->list_traces(['per_page' => 200, 'page' => 1]);

        // Abuse detection insights
        $abuse_insights = \WPFlame\Insights::analyze_dashboard($top_users, $top_ips, $recent_trace_rows);
        $abuse_insights = apply_filters( 'wp_flame_insights', $abuse_insights, null );
        if (! empty($abuse_insights)) {
            echo '<div class="wp-flame-insights">';
            echo '<h3>' . esc_html__('Abuse Detection', 'wp-flame') . '</h3>';
            foreach ($abuse_insights as $insight) {
                $class = $insight['severity'] === 'warning' ? 'wp-flame-insight-warning' : 'wp-flame-insight-info';
                echo '<div class="wp-flame-insight ' . esc_attr($class) . '">';
                echo '<strong>' . esc_html($insight['title']) . '</strong>';
                echo '<p>' . nl2br(esc_html($insight['detail'])) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }
    }

    /**
     * Build a top-N callback ranking by summing duration_ms across pre-decoded traces.
     *
     * @param array<int, array<string, mixed>> $decoded_traces Already-decoded trace data arrays.
     * @return array<string, float>
     */
    private function get_slowest_callbacks(int $limit = 5, array $decoded_traces = []): array
    {
        $totals = [];

        foreach ($decoded_traces as $data) {
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
}
