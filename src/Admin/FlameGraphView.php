<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\Insights;
use WPFlame\Score;
use WPFlame\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FlameGraphView
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function render( string $trace_id ): void
    {
        $trace = $this->storage->get_trace($trace_id);

        if (! $trace) {
            echo '<div class="wrap"><h1>' . esc_html__('WP Flame', 'wp-flame') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Trace not found.', 'wp-flame') . '</p></div></div>';
            return;
        }

        $score = Score::calculate($trace);

        echo '<div class="wrap">';
        echo '<h1>';
        echo '<a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '">&larr; ' . esc_html__('All Traces', 'wp-flame') . '</a>';
        echo ' &mdash; ' . esc_html($trace->method) . ' ' . esc_html($trace->url);
        echo '</h1>';

        // Route comparison: how does this request compare to the average for this URL?
        $route_stats = $this->storage->get_route_stats($trace->url, 7);
        if ($route_stats) {
            $route_path = explode('?', $trace->url, 2)[0];
            $diff_ms    = $trace->total_ms - $route_stats['avg_ms'];
            $diff_pct   = $route_stats['avg_ms'] > 0 ? round(($diff_ms / $route_stats['avg_ms']) * 100) : 0;

            echo '<div class="wp-flame-route-comparison">';
            if ($diff_pct > 10) {
                $diff_class = 'wp-flame-trend-bad';
                /* translators: %1$s: percentage, %2$s: route path */
                $diff_text = sprintf(esc_html__('%1$d%% slower than average for %2$s', 'wp-flame'), abs($diff_pct), $route_path);
            } elseif ($diff_pct < -10) {
                $diff_class = 'wp-flame-trend-good';
                /* translators: %1$s: percentage, %2$s: route path */
                $diff_text = sprintf(esc_html__('%1$d%% faster than average for %2$s', 'wp-flame'), abs($diff_pct), $route_path);
            } else {
                $diff_class = '';
                /* translators: %s: route path */
                $diff_text = sprintf(esc_html__('Typical for %s', 'wp-flame'), $route_path);
            }

            echo '<span class="' . esc_attr($diff_class) . '"><strong>' . esc_html($diff_text) . '</strong></span>';
            echo '<span class="wp-flame-route-stats">';
            /* translators: %1$s: avg duration, %2$s: min duration, %3$s: max duration, %4$d: trace count */
            echo wp_kses_post(sprintf(
                __('Route avg: <strong>%1$sms</strong> &middot; Min: %2$sms &middot; Max: %3$sms &middot; %4$d traces', 'wp-flame'),
                esc_html((string) $route_stats['avg_ms']),
                esc_html((string) $route_stats['min_ms']),
                esc_html((string) $route_stats['max_ms']),
                $route_stats['count']
            ));
            echo '</span>';
            echo '</div>';
        }

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
        // Cache stat card (if cache data available)
        if (isset($trace->meta['cache_hits'])) {
            $hits = (int) $trace->meta['cache_hits'];
            $misses = (int) ($trace->meta['cache_misses'] ?? 0);
            $total = $hits + $misses;
            $ratio = $total > 0 ? round(($hits / $total) * 100) : 0;
            $backend = $trace->meta['cache_backend'] ?? 'WP_Object_Cache';
            // Show short backend name
            $short_backend = $backend === 'WP_Object_Cache' ? __('In-Memory', 'wp-flame') : str_replace('_Object_Cache', '', $backend);

            echo '<div class="wp-flame-stat">';
            echo '<span class="wp-flame-stat-label">' . esc_html__('CACHE', 'wp-flame') . '</span>';
            echo '<span class="wp-flame-stat-value">' . esc_html($ratio) . '<small>%</small></span>';
            echo '<span class="wp-flame-trend">' . esc_html($hits) . '/' . esc_html($total) . ' · ' . esc_html($short_backend) . '</span>';
            echo '</div>';
        }

        // Score stat card
        echo '<div class="wp-flame-stat wp-flame-score-card" style="border-left:4px solid ' . esc_attr($score['color']) . '">';
        echo '<span class="wp-flame-stat-label">' . esc_html__('SCORE', 'wp-flame') . '</span>';
        echo '<span class="wp-flame-stat-value">' . esc_html((string) $score['score']) . '<small>' . esc_html($score['grade']) . '</small></span>';
        echo '</div>';

        echo '<div class="wp-flame-stat-right">';
        echo esc_html($trace->method) . ' ' . esc_html($trace->url) . ' &mdash; ' . esc_html(round($trace->total_ms)) . 'ms';
        echo '</div>';
        echo '</div>';

        // Request context: user, IP, user agent
        echo '<div class="wp-flame-request-context">';
        $ctx_user_id = (int) ($trace->meta['_row_user_id'] ?? 0);
        if ($ctx_user_id > 0 && function_exists('get_userdata')) {
            $ctx_user = get_userdata($ctx_user_id);
            if ($ctx_user) {
                $ctx_roles = implode(', ', $ctx_user->roles);
                echo '<span>' . esc_html__('User:', 'wp-flame') . ' <strong>' . esc_html($ctx_user->display_name) . '</strong> (' . esc_html($ctx_roles) . ')</span>';
            } else {
                echo '<span>' . esc_html__('User:', 'wp-flame') . ' ' . esc_html__('Anonymous', 'wp-flame') . '</span>';
            }
        } else {
            echo '<span>' . esc_html__('User:', 'wp-flame') . ' ' . esc_html__('Anonymous', 'wp-flame') . '</span>';
        }
        $ctx_ip = $trace->meta['_row_ip_address'] ?? '';
        if ($ctx_ip) {
            echo '<span>' . esc_html__('IP:', 'wp-flame') . ' <strong>' . esc_html($ctx_ip) . '</strong></span>';
        }
        $ctx_ua = $trace->meta['user_agent'] ?? '';
        if ($ctx_ua) {
            echo '<span>' . esc_html__('User Agent:', 'wp-flame') . ' ' . esc_html($ctx_ua) . '</span>';
        }
        echo '</div>';

        // Score Breakdown section
        echo '<div class="wp-flame-score-breakdown">';
        echo '<h3>' . esc_html__('Score Breakdown', 'wp-flame') . '</h3>';
        foreach ($score['factors'] as $factor) {
            $factor_color = Score::grade((int) round($factor['score']))['color'];
            echo '<div class="wp-flame-score-factor">';
            echo '<span class="wp-flame-score-factor-label">' . esc_html($factor['label']) . '</span>';
            echo '<span class="wp-flame-score-factor-value">' . esc_html($factor['value']) . '</span>';
            echo '<div class="wp-flame-score-factor-bar">';
            echo '<div class="wp-flame-score-factor-fill" style="width:' . esc_attr((string) $factor['score']) . '%;background:' . esc_attr($factor_color) . '"></div>';
            echo '</div>';
            echo '<span class="wp-flame-score-factor-score">' . esc_html((string) $factor['score']) . '/100 <span style="color:#c3c4c7">(' . esc_html((string) $factor['weight']) . '%)</span></span>';
            echo '</div>';
        }
        echo '</div>';

        // Limited instrumentation notice for Tier 2 GraphQL traces
        $is_graphql_url = strpos($trace->url, '/graphql') !== false;
        $has_resolver_spans = false;
        if ($is_graphql_url) {
            foreach ($trace->spans as $span) {
                if (isset($span->meta['type_name'])) {
                    $has_resolver_spans = true;
                    break;
                }
            }
            if (!$has_resolver_spans) {
                echo '<div class="notice notice-info inline" style="margin: 10px 0"><p>';
                echo esc_html__('Limited instrumentation — resolver detail requires WPGraphQL.', 'wp-flame');
                echo '</p></div>';
            }
        }

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
        $insights = apply_filters( 'wp_flame_insights', $insights, $trace );
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
}
