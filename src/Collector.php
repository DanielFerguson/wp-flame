<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Collector
{
    private const MAX_SOURCE_CACHE_ENTRIES = 1000;

    private static ?self $instance = null;

    private float $request_start = 0.0;
    private float $request_end = 0.0;
    private bool $initialized = false;
    private bool $stopped = false;
    private int $next_span_id = 0;
    private int $max_spans = 0;
    private int $dropped_span_count = 0;
    private int $span_close_mismatch_count = 0;

    /** @var array[] Lightweight stack entries: [id, name, type, source, start_ms, meta, parent_id, span_count_at_start] */
    private array $span_stack = [];

    /** @var Span[] Completed spans */
    private array $spans = [];

    /** @var array<string, array> File path to source attribution cache */
    private static array $source_cache = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$source_cache = [];
        CallbackResolver::reset();
    }

    public function start_request(float $microtime): void
    {
        $this->request_start = $microtime;
        $this->request_end = 0.0;
        $this->initialized = true;
        $this->stopped = false;
        $this->next_span_id = 0;
        $this->dropped_span_count = 0;
        $this->span_close_mismatch_count = 0;
        $this->span_stack = [];
        $this->spans = [];
    }

    public function set_limits(int $max_spans): void
    {
        $this->max_spans = max(0, $max_spans);
    }

    public function is_initialized(): bool
    {
        return $this->initialized;
    }

    public function start_span(string $name, string $type, string $source, array $meta = []): string
    {
        if ($this->stopped) {
            return '';
        }

        if ($this->span_limit_reached()) {
            $this->dropped_span_count++;
            return '';
        }

        $id = $this->generate_span_id();
        $parent_id = ! empty($this->span_stack)
            ? $this->span_stack[count($this->span_stack) - 1]['id']
            : null;

        $start_ms = (microtime(true) - $this->request_start) * 1000;

        $this->span_stack[] = [
            'id'                  => $id,
            'name'                => $name,
            'type'                => $type,
            'source'              => $source,
            'start_ms'            => $start_ms,
            'meta'                => $meta,
            'parent_id'           => $parent_id,
            'span_count_at_start' => count($this->spans),
        ];

        return $id;
    }

    public function end_span(?string $span_id = null): void
    {
        if ($span_id === '') {
            return;
        }

        $entry = $this->pop_span_entry( $span_id, 'end_span' );
        if ( $entry === null ) {
            return;
        }

        $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];

        $this->spans[] = new Span(
            $entry['id'],
            $entry['parent_id'],
            $entry['name'],
            $entry['type'],
            $entry['source'],
            $entry['start_ms'],
            max(0.0, $duration_ms),
            $entry['meta']
        );
    }

    /**
     * Create a span with pre-computed timing (for log_query_custom_data).
     * Accepts absolute microtime start and duration in seconds.
     * Parent is determined from the current span stack.
     */
    public function add_completed_span(
        string $name,
        string $type,
        string $source,
        float $abs_start,
        float $duration_sec,
        array $meta = []
    ): string {
        if ($this->stopped) {
            return '';
        }

        if ($this->span_limit_reached()) {
            $this->dropped_span_count++;
            return '';
        }

        $id = $this->generate_span_id();
        $parent_id = ! empty($this->span_stack)
            ? $this->span_stack[count($this->span_stack) - 1]['id']
            : null;

        $start_ms = ($abs_start - $this->request_start) * 1000;
        $duration_ms = $duration_sec * 1000;

        $this->spans[] = new Span(
            $id,
            $parent_id,
            $name,
            $type,
            $source,
            max(0.0, $start_ms),
            max(0.0, $duration_ms),
            $meta
        );

        return $id;
    }

    /**
     * End a span with a minimum duration threshold.
     * Discards spans below threshold unless they have retained child spans.
     */
    public function end_span_filtered(?string $span_id, float $min_ms): void
    {
        if ($span_id === '') {
            return;
        }

        $entry = $this->pop_span_entry( $span_id, 'end_span_filtered' );
        if ( $entry === null ) {
            return;
        }

        $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];
        $duration_ms = max(0.0, $duration_ms);

        $has_retained_children = count($this->spans) > ($entry['span_count_at_start'] ?? 0);

        if ($duration_ms >= $min_ms || $has_retained_children) {
            $this->spans[] = new Span(
                $entry['id'],
                $entry['parent_id'],
                $entry['name'],
                $entry['type'],
                $entry['source'],
                $entry['start_ms'],
                $duration_ms,
                $entry['meta']
            );
        }
    }

    /**
     * Safety net: close all remaining open spans on the stack.
     * Each auto-closed span gets ['auto_closed' => true] in its meta.
     */
    public function close_open_spans(): void
    {
        while (! empty($this->span_stack)) {
            $entry = array_pop($this->span_stack);
            if ( $this->completed_span_limit_reached() ) {
                $this->dropped_span_count++;
                continue;
            }

            $duration_ms = ((microtime(true) - $this->request_start) * 1000) - $entry['start_ms'];
            $meta = $entry['meta'];
            $meta['auto_closed'] = true;

            $this->spans[] = new Span(
                $entry['id'],
                $entry['parent_id'],
                $entry['name'],
                $entry['type'],
                $entry['source'],
                $entry['start_ms'],
                max(0.0, $duration_ms),
                $meta
            );
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $capture_report
     */
    public function get_trace(array $meta = [], array $capture_report = []): Trace
    {
        $request_end = $this->request_end > 0.0 ? $this->request_end : microtime(true);
        $total_ms = ($request_end - $this->request_start) * 1000;
        if ($this->dropped_span_count > 0) {
            $meta['wp_flame_dropped_spans'] = $this->dropped_span_count;
            $capture_report['dropped_span_count'] = $this->dropped_span_count;
            $capture_report['incomplete_reasons'] = $this->append_incomplete_reason(
                $capture_report['incomplete_reasons'] ?? [],
                'dropped_spans'
            );
        }
        if ( $this->span_close_mismatch_count > 0 ) {
            $meta['wp_flame_span_close_mismatches'] = $this->span_close_mismatch_count;
            $capture_report['incomplete_reasons'] = $this->append_incomplete_reason(
                $capture_report['incomplete_reasons'] ?? [],
                'span_close_mismatch'
            );
        }

        $auto_closed_span_count = 0;
        foreach ( $this->spans as $span ) {
            if ( ! empty( $span->meta['auto_closed'] ) ) {
                $auto_closed_span_count++;
            }
        }
        if ( $auto_closed_span_count > 0 ) {
            $capture_report['auto_closed_span_count'] = $auto_closed_span_count;
            $capture_report['incomplete_reasons'] = $this->append_incomplete_reason(
                $capture_report['incomplete_reasons'] ?? [],
                'auto_closed_spans'
            );
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? Config::string_value(wp_unslash($_SERVER['REQUEST_URI']), '/')
            : '/';
        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? Config::string_value(wp_unslash($_SERVER['REQUEST_METHOD']), 'GET')
            : 'GET';

        return new Trace(
            self::generate_uuid(),
            Redactor::redact_request_uri($request_uri),
            sanitize_text_field($request_method),
            gmdate('c'),
            $total_ms,
            (int) memory_get_peak_usage(true),
            PHP_VERSION,
            function_exists('get_bloginfo') ? get_bloginfo('version', 'raw') : '',
            $this->spans,
            $meta,
            $capture_report
        );
    }

    public function request_start(): float
    {
        return $this->request_start;
    }

    public function is_stopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @param mixed $reasons
     * @return array<int, mixed>
     */
    private function append_incomplete_reason( $reasons, string $reason ): array
    {
        $reasons = is_array( $reasons ) ? array_values( $reasons ) : [];
        if ( ! in_array( $reason, $reasons, true ) ) {
            $reasons[] = $reason;
        }

        return $reasons;
    }

    /**
     * Pop only the requested top-of-stack span. An out-of-order or unknown ID
     * is recorded as incomplete capture evidence and leaves the stack intact.
     *
     * @return array<string, mixed>|null
     */
    private function pop_span_entry( ?string $span_id, string $operation ): ?array
    {
        if ( $this->stopped || empty( $this->span_stack ) ) {
            return null;
        }

        $top_index = count( $this->span_stack ) - 1;
        $top = $this->span_stack[ $top_index ];
        if ( $span_id === null || $top['id'] === $span_id ) {
            return array_pop( $this->span_stack );
        }

        $this->span_close_mismatch_count++;
        error_log( sprintf(
            'WP Flame: %s() refused out-of-order span close — requested "%s", open top is "%s"',
            $operation,
            $span_id,
            $top['id']
        ) );

        return null;
    }

    public function add_span_meta(string $span_id, array $additional_meta): void
    {
        if ($this->stopped) {
            return;
        }

        for ($i = count($this->span_stack) - 1; $i >= 0; $i--) {
            if ($this->span_stack[$i]['id'] === $span_id) {
                $this->span_stack[$i]['meta'] = array_merge(
                    $this->span_stack[$i]['meta'],
                    $additional_meta
                );
                break;
            }
        }
    }

    public function stop(?float $microtime = null): void
    {
        if ( ! $this->stopped ) {
            $this->request_end = $microtime ?? microtime(true);
        }

        $this->stopped = true;
    }

    /**
     * Generate a UUID v4 using random_bytes (no WordPress dependency).
     */
    private static function generate_uuid(): string
    {
        $bytes = random_bytes(16);
        // Set version (4) and variant (10xx)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }

    private function generate_span_id(): string
    {
        $this->next_span_id++;

        return 's' . base_convert((string) $this->next_span_id, 10, 36);
    }

    private function span_limit_reached(): bool
    {
        return $this->max_spans > 0 && (count($this->spans) + count($this->span_stack)) >= $this->max_spans;
    }

    private function completed_span_limit_reached(): bool
    {
        return $this->max_spans > 0 && count($this->spans) >= $this->max_spans;
    }

    /**
     * Resolve a file path to a source attribution array.
     *
     * @return array{type: string, source: string}
     */
    public function get_source_from_file(string $file_path): array
    {
        if (isset(self::$source_cache[$file_path])) {
            return self::$source_cache[$file_path];
        }

        $result = ['type' => Span::TYPE_PHP, 'source' => basename($file_path)];

        // Check if file is in a plugin
        $plugin_dir     = self::directory_constant( 'WP_PLUGIN_DIR' );
        $mu_plugin_dir  = self::directory_constant( 'WPMU_PLUGIN_DIR' );
        $content_dir    = self::directory_constant( 'WP_CONTENT_DIR' );
        $template_dir   = function_exists( 'get_template_directory' ) ? Config::string_value( get_template_directory(), '' ) : '';
        $stylesheet_dir = function_exists( 'get_stylesheet_directory' ) ? Config::string_value( get_stylesheet_directory(), '' ) : '';
        $abspath        = self::directory_constant( 'ABSPATH' );

        if ( $content_dir === '' && $abspath !== '' ) {
            $content_dir = rtrim( $abspath, '/\\' ) . '/wp-content';
        }

        if ( $content_dir !== '' && self::is_supported_drop_in( $file_path, $content_dir ) ) {
            $result = [
                'type'   => Span::TYPE_PLUGIN,
                'source' => 'drop-in:' . basename( $file_path, '.php' ),
            ];
            // Check mu-plugins before regular plugins in case a site uses custom overlapping roots.
        } elseif ( $mu_plugin_dir !== '' && self::path_is_inside_directory( $file_path, $mu_plugin_dir ) ) {
            $relative = substr( $file_path, strlen( $mu_plugin_dir ) + 1 );
            $result = [
                'type'   => Span::TYPE_PLUGIN,
                'source' => 'mu-plugin:' . explode( '/', $relative, 2 )[0],
            ];
        } elseif ( $plugin_dir !== '' && self::path_is_inside_directory( $file_path, $plugin_dir ) ) {
            $relative = substr( $file_path, strlen( $plugin_dir ) + 1 );
            $parts = explode( '/', $relative, 2 );
            $result = [
                'type'   => Span::TYPE_PLUGIN,
                'source' => $parts[0],
            ];
            // A child theme lives outside get_template_directory(), so test it first.
        } elseif ( $stylesheet_dir !== '' && $stylesheet_dir !== $template_dir && self::path_is_inside_directory( $file_path, $stylesheet_dir ) ) {
            $result = [
                'type'   => Span::TYPE_THEME,
                'source' => 'child-theme:' . basename( $stylesheet_dir ),
            ];
        } elseif ( $template_dir !== '' && self::path_is_inside_directory( $file_path, $template_dir ) ) {
            $result = [
                'type'   => Span::TYPE_THEME,
                'source' => 'parent-theme:' . basename( $template_dir ),
            ];
            // Check if file is WordPress core.
        } elseif ( $abspath !== '' && self::path_is_inside_directory( $file_path, $abspath ) ) {
            $result = [
                'type'   => Span::TYPE_CORE,
                'source' => 'wordpress',
            ];
        }

        if ( count( self::$source_cache ) < self::MAX_SOURCE_CACHE_ENTRIES ) {
            self::$source_cache[$file_path] = $result;
        }

        return $result;
    }

    private static function directory_constant(string $name): string
    {
        if (! defined($name)) {
            return '';
        }

        return Config::string_value(constant($name), '');
    }

    private static function path_is_inside_directory(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        return $path === $directory || strpos($path, $directory . '/') === 0;
    }

    private static function is_supported_drop_in( string $file_path, string $content_dir ): bool
    {
        $file_path   = str_replace( '\\', '/', $file_path );
        $content_dir = rtrim( str_replace( '\\', '/', $content_dir ), '/' );
        if ( dirname( $file_path ) !== $content_dir ) {
            return false;
        }

        return in_array( basename( $file_path ), [ 'advanced-cache.php', 'db.php', 'object-cache.php', 'sunrise.php' ], true );
    }
}
