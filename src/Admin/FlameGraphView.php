<?php

declare(strict_types=1);

namespace WPFlame\Admin;

use WPFlame\Config;
use WPFlame\Insights;
use WPFlame\Score;
use WPFlame\Storage;
use WPFlame\Trace;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FlameGraphView
{
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_IP_BYTES = 45;
    private const MAX_USER_AGENT_BYTES = 500;
    private const MAX_USER_LABEL_BYTES = 200;
    private const MAX_ROLE_LABEL_BYTES = 200;
    private const MAX_CACHE_BACKEND_BYTES = 120;

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
        $trace_method = $this->limit_string($trace->method, self::MAX_METHOD_BYTES);
        $trace_url = $this->limit_string($trace->url, self::MAX_URL_BYTES);

        echo '<div class="wrap">';
        echo '<h1>';
        echo '<a href="' . esc_url(admin_url('tools.php?page=wp-flame')) . '">&larr; ' . esc_html__('All Traces', 'wp-flame') . '</a>';
        echo ' &mdash; ' . esc_html($trace_method) . ' ' . esc_html($trace_url);
        echo '</h1>';

        // Route comparison: how does this request compare to the average for this URL?
        $route_stats = $this->route_stats($this->storage->get_route_stats($trace_url, 7));
        if ($route_stats !== null) {
            $route_path = $this->limit_string(explode('?', $trace_url, 2)[0], self::MAX_URL_BYTES);
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
            echo wp_kses_post(sprintf(
                /* translators: %1$s: avg duration, %2$s: min duration, %3$s: max duration, %4$d: trace count */
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
        $cache_summary = $this->cache_summary($trace->meta);
        if ($cache_summary !== null) {
            $hits = $cache_summary['hits'];
            $total = $cache_summary['total'];
            $ratio = $cache_summary['ratio'];
            $short_backend = $cache_summary['backend'];

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
        echo esc_html($trace_method) . ' ' . esc_html($trace_url) . ' &mdash; ' . esc_html(round($trace->total_ms)) . 'ms';
        echo '</div>';
        echo '</div>';

        echo $this->capture_report_html( $trace ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every dynamic value.

        $this->render_top_opportunities( $trace );

        // Request context: user, IP, user agent
        echo '<div class="wp-flame-request-context">';
        $request_context = $this->request_context($trace->meta);
        $ctx_user_id = $request_context['user_id'];
        if ($ctx_user_id > 0 && function_exists('get_userdata')) {
            $ctx_user = get_userdata($ctx_user_id);
            if ($ctx_user) {
                $ctx_roles = isset($ctx_user->roles) && is_array($ctx_user->roles)
                    ? $this->roles_label($ctx_user->roles)
                    : '';
                $ctx_display_name = $this->limit_string(Config::string_value($ctx_user->display_name ?? '', ''), self::MAX_USER_LABEL_BYTES);
                if ($ctx_display_name === '') {
                    $ctx_display_name = '#' . $ctx_user_id;
                }
                echo '<span>' . esc_html__('User:', 'wp-flame') . ' <strong>' . esc_html($ctx_display_name) . '</strong>';
                if ($ctx_roles !== '') {
                    echo ' (' . esc_html($ctx_roles) . ')';
                }
                echo '</span>';
            } else {
                echo '<span>' . esc_html__('User:', 'wp-flame') . ' ' . esc_html__('Anonymous', 'wp-flame') . '</span>';
            }
        } else {
            echo '<span>' . esc_html__('User:', 'wp-flame') . ' ' . esc_html__('Anonymous', 'wp-flame') . '</span>';
        }
        $ctx_ip = $request_context['ip_address'];
        if ($ctx_ip) {
            echo '<span>' . esc_html__('IP:', 'wp-flame') . ' <strong>' . esc_html($ctx_ip) . '</strong></span>';
        }
        $ctx_ua = $request_context['user_agent'];
        if ($ctx_ua) {
            echo '<span>' . esc_html__('User Agent:', 'wp-flame') . ' ' . esc_html($ctx_ua) . '</span>';
        }
        echo '</div>';

        // Score Breakdown section
        echo '<div class="wp-flame-score-breakdown">';
        echo '<h3>' . esc_html__('Score Breakdown', 'wp-flame') . '</h3>';
        if ( (int) ( $score['version'] ?? 1 ) < Score::VERSION ) {
            echo '<p class="description">' . esc_html__( 'Legacy Score v1: the stored overall score is preserved, but a historical factor snapshot was not available.', 'wp-flame' ) . '</p>';
        }
        foreach ($score['factors'] as $factor) {
            $status = Config::string_value( $factor['status'] ?? 'observed', 'observed' );
            if ( $status !== 'observed' ) {
                echo '<div class="wp-flame-score-factor">';
                echo '<span class="wp-flame-score-factor-label">' . esc_html($factor['label']) . '</span>';
                echo '<span class="wp-flame-score-factor-value">' . esc_html__( 'Unknown', 'wp-flame' ) . '</span>';
                echo '<p class="description">' . esc_html( Config::string_value( $factor['reason'] ?? $status, $status ) ) . '</p>';
                echo '</div>';
                continue;
            }
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
        $is_graphql_url = strpos($trace_url, '/graphql') !== false;
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
        echo '<section class="wp-flame-graph-tools" aria-labelledby="wp-flame-timeline-title">';
        echo '<h2 id="wp-flame-timeline-title">' . esc_html__( 'Technical timeline', 'wp-flame' ) . '</h2>';
        echo '<div class="wp-flame-graph-filters" role="search">';
        echo '<label>' . esc_html__( 'Search spans', 'wp-flame' ) . ' <input id="wp-flame-span-search" type="search" autocomplete="off" placeholder="' . esc_attr__( 'Callback, source, query, host…', 'wp-flame' ) . '"></label>';
        echo '<label>' . esc_html__( 'Type', 'wp-flame' ) . ' <select id="wp-flame-type-filter"><option value="">' . esc_html__( 'All types', 'wp-flame' ) . '</option></select></label>';
        echo '<label>' . esc_html__( 'Source', 'wp-flame' ) . ' <select id="wp-flame-source-filter"><option value="">' . esc_html__( 'All sources', 'wp-flame' ) . '</option></select></label>';
        echo '<span id="wp-flame-filter-status" role="status" aria-live="polite"></span>';
        echo '</div>';
        echo '<div id="wp-flame-breadcrumbs"></div>';
        echo '<div id="wp-flame-graph" role="region" aria-label="' . esc_attr__( 'Interactive request flame graph. Use Tab to select spans and Enter to inspect or zoom.', 'wp-flame' ) . '"></div>';
        echo '<div id="wp-flame-tooltip" style="display:none"></div>';
        echo '<aside id="wp-flame-span-detail" class="wp-flame-span-detail" tabindex="-1" aria-labelledby="wp-flame-span-detail-title">';
        echo '<h3 id="wp-flame-span-detail-title">' . esc_html__( 'Span evidence', 'wp-flame' ) . '</h3>';
        echo '<p>' . esc_html__( 'Select any span, including a leaf span, to inspect its supporting data.', 'wp-flame' ) . '</p>';
        echo '</aside></section>';

        echo '</div>';

        // Pass trace data to JS (wp_add_inline_script preserves numeric types;
        // wp_localize_script would convert all values to strings, breaking .toFixed() calls)
        wp_add_inline_script(
            'wp-flame-graph',
            'window.wpFlameTrace = ' . $this->encode_trace_for_script($trace) . ';',
            'before'
        );
    }

    private function render_top_opportunities( Trace $trace ): void
    {
        $environment = $trace->capture_report->environment_snapshot_id !== null
            ? $this->storage->get_environment_snapshot( $trace->capture_report->environment_snapshot_id )
            : null;
        $insights = Insights::analyze( $trace, $environment );
        $insights = Insights::normalize( apply_filters( 'wp_flame_insights', $insights, $trace ) );

        echo '<section class="wp-flame-insights wp-flame-top-opportunities" aria-labelledby="wp-flame-opportunities-title">';
        echo '<h2 id="wp-flame-opportunities-title">' . esc_html__( 'Top opportunities', 'wp-flame' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Start here. These conclusions only use evidence this capture could observe.', 'wp-flame' ) . '</p>';
        if ( $insights === [] ) {
            echo '<p>' . esc_html__( 'No supported issue crossed the current evidence thresholds. This does not prove the request is fully optimized; review capture completeness and the technical timeline below.', 'wp-flame' ) . '</p></section>';
            return;
        }

        foreach ( array_slice( $insights, 0, 5 ) as $index => $insight ) {
            $class = $insight['severity'] === 'warning' ? 'wp-flame-insight-warning' : 'wp-flame-insight-info';
            $needs_developer = in_array(
                $insight['action_type'],
                [
                    'optimize_query',
                    'repair_query',
                    'optimize_callback',
                    'deduplicate_or_cache_query',
                    'repair_http_dependency',
                    'defer_http',
                    'cache_or_defer_http',
                ],
                true
            );
            echo '<article class="wp-flame-insight ' . esc_attr( $class ) . '">';
            echo '<h3><span class="wp-flame-opportunity-rank">' . esc_html( (string) ( $index + 1 ) ) . '</span> ' . esc_html( $insight['title'] ) . '</h3>';
            echo '<p>' . esc_html( $insight['detail'] ) . '</p>';
            $evidence = [];
            if ( $insight['source'] !== '' ) {
                $evidence[] = $insight['source'] . ( $insight['source_version'] !== '' ? ' ' . $insight['source_version'] : '' );
            }
            if ( $insight['measured_impact_ms'] > 0 ) {
                $evidence[] = round( $insight['measured_impact_ms'], 1 ) . 'ms of measured work';
            }
            /* translators: %d: number of supporting span observations. */
            $evidence[] = sprintf( _n( '%d observation', '%d observations', $insight['evidence_count'], 'wp-flame' ), $insight['evidence_count'] );
            $evidence[] = ucfirst( $insight['confidence'] ) . ' confidence';
            echo '<p class="wp-flame-opportunity-evidence">' . esc_html( implode( ' · ', $evidence ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Help needed:', 'wp-flame' ) . '</strong> ' . esc_html( $needs_developer ? __( 'Developer or host recommended', 'wp-flame' ) : __( 'A site administrator can start', 'wp-flame' ) ) . '</p>';
            if ( is_array( $insight['remediation'] ) && ! empty( $insight['remediation']['next_action'] ) ) {
                echo '<p><strong>' . esc_html__( 'Safest next action:', 'wp-flame' ) . '</strong> ' . esc_html( $insight['remediation']['next_action'] ) . '</p>';
            }
            if ( $insight['verification'] !== '' ) {
                echo '<p><strong>' . esc_html__( 'Verify:', 'wp-flame' ) . '</strong> ' . esc_html( $insight['verification'] ) . '</p>';
            }
            echo '</article>';
        }
        echo '</section>';
    }

    private function encode_trace_for_script( \WPFlame\Trace $trace ): string
    {
        $data = $trace->toArray();
        $environment = $trace->capture_report->environment_snapshot_id !== null
            ? $this->storage->get_environment_snapshot( $trace->capture_report->environment_snapshot_id )
            : null;
        if ( $environment !== null && isset( $data['spans'] ) && is_array( $data['spans'] ) ) {
            foreach ( $data['spans'] as &$span ) {
                if ( ! is_array( $span ) ) {
                    continue;
                }
                $source = Config::string_value( $span['source'] ?? '', '' );
                $version = $source !== '' ? Insights::source_version( $source, $environment ) : null;
                if ( $version !== null ) {
                    $span['meta'] = isset( $span['meta'] ) && is_array( $span['meta'] ) ? $span['meta'] : [];
                    $span['meta']['source_version'] = $version;
                }
            }
            unset( $span );
        }
        $json = wp_json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return is_string( $json ) ? $json : '{}';
    }

    private function capture_report_html( Trace $trace ): string
    {
        $report = $trace->capture_report;
        $complete = empty( $report->incomplete_reasons )
            && $report->dropped_span_count === 0
            && $report->auto_closed_span_count === 0
            && $report->trimmed_span_count === 0
            && ! $report->trace_truncated;

        $html = '<section class="wp-flame-capture-report" aria-labelledby="wp-flame-capture-report-title">';
        $html .= '<h2 id="wp-flame-capture-report-title">' . esc_html__( 'Capture report', 'wp-flame' ) . '</h2>';
        $html .= '<p class="' . esc_attr( $complete ? 'wp-flame-capture-complete' : 'wp-flame-capture-incomplete' ) . '"><strong>';
        $html .= esc_html( $complete ? __( 'Complete for requested capabilities', 'wp-flame' ) : __( 'Incomplete capture', 'wp-flame' ) );
        $html .= '</strong></p>';
        $html .= '<dl>';
        $fields = [
            __( 'Mode', 'wp-flame' )                 => $report->instrumentation_mode,
            __( 'Origin', 'wp-flame' )               => $report->capture_origin,
            __( 'Request type', 'wp-flame' )         => $report->request_type,
            __( 'Capture began', 'wp-flame' )        => $report->capture_start_stage,
            __( 'Observed duration', 'wp-flame' )    => round( $report->observed_duration_ms, 2 ) . ' ms',
            __( 'Sampling probability', 'wp-flame' ) => round( $report->effective_sample_probability * 100, 4 ) . '%',
        ];
        if ( $report->unobserved_prebootstrap_ms !== null ) {
            $fields[ __( 'Estimated pre-capture gap', 'wp-flame' ) ] = round( $report->unobserved_prebootstrap_ms, 2 ) . ' ms';
        }
        foreach ( $fields as $label => $value ) {
            $html .= '<div><dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
        }
        $html .= '</dl>';

        $html .= '<h3>' . esc_html__( 'Telemetry capabilities', 'wp-flame' ) . '</h3><ul>';
        foreach ( $report->capabilities as $key => $capability ) {
            $label = ucwords( str_replace( '_', ' ', $key ) );
            $status = ucwords( str_replace( '_', ' ', $capability['status'] ) );
            $html .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $status );
            if ( $capability['reason'] !== '' ) {
                $html .= ' <small>(' . esc_html( str_replace( '_', ' ', $capability['reason'] ) ) . ')</small>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        if ( ! empty( $report->incomplete_reasons ) ) {
            $html .= '<h3>' . esc_html__( 'Why this trace is incomplete', 'wp-flame' ) . '</h3><ul>';
            foreach ( $report->incomplete_reasons as $reason ) {
                $html .= '<li>' . esc_html( str_replace( '_', ' ', $reason ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<p class="description">' . esc_html( sprintf(
            /* translators: %d: score algorithm version */
            __( 'Score version %d is directional. Factors that depend on unavailable telemetry must not be read as healthy zeroes.', 'wp-flame' ),
            $report->score_version
        ) ) . '</p>';
        $html .= '</section>';

        return $html;
    }

    /**
     * @param mixed $stats
     * @return array{avg_ms: float, min_ms: float, max_ms: float, count: int}|null
     */
    private function route_stats($stats): ?array
    {
        if (! is_array($stats)) {
            return null;
        }

        $min_ms = max(0.0, $this->number($stats['min_ms'] ?? 0, 0.0));
        $max_ms = max($min_ms, $this->number($stats['max_ms'] ?? 0, 0.0));

        return [
            'avg_ms' => max(0.0, $this->number($stats['avg_ms'] ?? 0, 0.0)),
            'min_ms' => $min_ms,
            'max_ms' => $max_ms,
            'count'  => Config::bounded_int($stats['count'] ?? 0, 0, 0, PHP_INT_MAX),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{user_id: int, ip_address: string, user_agent: string}
     */
    private function request_context(array $meta): array
    {
        return [
            'user_id'    => Config::bounded_int($meta['_row_user_id'] ?? 0, 0, 0, PHP_INT_MAX),
            'ip_address' => $this->limit_string(Config::string_value($meta['_row_ip_address'] ?? '', ''), self::MAX_IP_BYTES),
            'user_agent' => $this->limit_string(Config::string_value($meta['user_agent'] ?? '', ''), self::MAX_USER_AGENT_BYTES),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{hits: int, total: int, ratio: int, backend: string}|null
     */
    private function cache_summary(array $meta): ?array
    {
        if (! array_key_exists('cache_hits', $meta)) {
            return null;
        }

        $hits = Config::bounded_int($meta['cache_hits'], 0, 0, PHP_INT_MAX);
        $misses = Config::bounded_int($meta['cache_misses'] ?? 0, 0, 0, PHP_INT_MAX);
        $ratio_total = (float) $hits + (float) $misses;
        $total = $hits > PHP_INT_MAX - $misses ? PHP_INT_MAX : $hits + $misses;
        $ratio = $ratio_total > 0.0 ? (int) round(($hits / $ratio_total) * 100) : 0;
        $backend = $this->limit_string(Config::string_value($meta['cache_backend'] ?? 'WP_Object_Cache', 'WP_Object_Cache'), self::MAX_CACHE_BACKEND_BYTES);
        $short_backend = $backend === 'WP_Object_Cache'
            ? __('In-Memory', 'wp-flame')
            : str_replace('_Object_Cache', '', $backend);

        return [
            'hits'    => $hits,
            'total'   => $total,
            'ratio'   => $ratio,
            'backend' => $short_backend,
        ];
    }

    /**
     * @param mixed $value
     */
    private function number($value, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : $fallback;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $number = (float) trim($value);
            return is_finite($number) ? $number : $fallback;
        }

        return $fallback;
    }

    /**
     * @param array<int|string, mixed> $roles
     */
    private function roles_label(array $roles): string
    {
        $labels = [];

        foreach ($roles as $role) {
            $role = $this->limit_string(Config::string_value($role, ''), self::MAX_ROLE_LABEL_BYTES);
            if ($role !== '') {
                $labels[] = $role;
            }
        }

        return $this->limit_string(implode(', ', $labels), self::MAX_ROLE_LABEL_BYTES);
    }

    private function limit_string(string $value, int $max_bytes): string
    {
        if (strlen($value) <= $max_bytes) {
            return $value;
        }

        return substr($value, 0, $max_bytes);
    }
}
