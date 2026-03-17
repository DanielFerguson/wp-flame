<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Collector
{
    private static ?self $instance = null;

    private float $request_start = 0.0;
    private bool $initialized = false;
    private bool $stopped = false;

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
        $this->initialized = true;
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

        $id = self::generate_uuid();
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
        if ($this->stopped || empty($this->span_stack)) {
            return;
        }

        $entry = array_pop($this->span_stack);

        if ($span_id !== null && $entry['id'] !== $span_id) {
            error_log(sprintf(
                'WP Flame: end_span() ID mismatch — expected "%s", got "%s"',
                $span_id,
                $entry['id']
            ));
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

        $id = self::generate_uuid();
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
        if ($this->stopped || empty($this->span_stack)) {
            return;
        }

        $entry = array_pop($this->span_stack);

        if ($span_id !== null && $entry['id'] !== $span_id) {
            error_log(sprintf(
                'WP Flame: end_span_filtered() ID mismatch — expected "%s", got "%s"',
                $span_id,
                $entry['id']
            ));
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

    public function get_trace(array $meta = []): Trace
    {
        $total_ms = (microtime(true) - $this->request_start) * 1000;

        return new Trace(
            self::generate_uuid(),
            isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/',
            isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET',
            gmdate('c'),
            $total_ms,
            (int) memory_get_peak_usage(true),
            PHP_VERSION,
            function_exists('get_bloginfo') ? get_bloginfo('version', 'raw') : '',
            $this->spans,
            $meta
        );
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

    public function stop(): void
    {
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
        if (defined('WP_PLUGIN_DIR') && strpos($file_path, WP_PLUGIN_DIR) === 0) {
            $relative = substr($file_path, strlen(WP_PLUGIN_DIR) + 1);
            $parts = explode('/', $relative, 2);
            $result = ['type' => Span::TYPE_PLUGIN, 'source' => $parts[0]];
        }
        // Check if file is in mu-plugins
        elseif (defined('WPMU_PLUGIN_DIR') && strpos($file_path, WPMU_PLUGIN_DIR) === 0) {
            $relative = substr($file_path, strlen(WPMU_PLUGIN_DIR) + 1);
            $result = ['type' => Span::TYPE_PLUGIN, 'source' => 'mu:' . explode('/', $relative, 2)[0]];
        }
        // Check if file is in a theme
        elseif (function_exists('get_template_directory') && strpos($file_path, get_template_directory()) === 0) {
            $result = ['type' => Span::TYPE_THEME, 'source' => basename(get_template_directory())];
        }
        // Check if file is WordPress core
        elseif (defined('ABSPATH') && strpos($file_path, ABSPATH) === 0) {
            $result = ['type' => Span::TYPE_CORE, 'source' => 'wordpress'];
        }

        self::$source_cache[$file_path] = $result;
        return $result;
    }
}
