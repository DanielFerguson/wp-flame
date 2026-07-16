<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CallbackWrapper
{
    /** @var callable */
    private $original;
    private Collector $collector;
    private string $hook_name;
    private int $priority;
    private string $span_name;
    private array $span_source;
    private float $min_duration_ms;
    private string $callback_id;
    /** @var array{caller_file: string, caller_line: int} */
    private array $span_location = [
        'caller_file' => '',
        'caller_line' => 0,
    ];

    /**
     * @param callable $original       The original callback to wrap
     * @param Collector $collector     The span collector
     * @param string $hook_name        WordPress hook name
     * @param int $priority            Hook priority
     * @param string $span_name        Pre-resolved callback name
     * @param array $span_source       Pre-resolved ['type' => ..., 'source' => ...]
     * @param float $min_duration_ms   Minimum duration to retain span
     * @param string $callback_id      Bounded callback identifier used for lazy metadata resolution
     */
    public function __construct(
        $original,
        Collector $collector,
        string $hook_name,
        int $priority,
        string $span_name,
        array $span_source,
        float $min_duration_ms,
        string $callback_id = ''
    ) {
        $this->original        = $original;
        $this->collector       = $collector;
        $this->hook_name       = $hook_name;
        $this->priority        = $priority;
        $this->span_name       = $span_name;
        $this->span_source     = $span_source;
        $this->min_duration_ms = $min_duration_ms;
        $this->callback_id      = $callback_id;
    }

    /**
     * Invoke the wrapped callback with span timing.
     *
     * @return mixed
     */
    public function __invoke(...$args)
    {
        $this->resolve_metadata();
        if ( $this->is_wp_flame_source() ) {
            return call_user_func_array( $this->original, $args );
        }

        $meta = [
            'hook'     => $this->hook_name,
            'priority' => $this->priority,
            'callback' => $this->span_name,
        ];
        if ( $this->span_location['caller_file'] !== '' ) {
            $meta['caller_file'] = $this->span_location['caller_file'];
            $meta['caller_line'] = $this->span_location['caller_line'];
        }
        $span_id = $this->collector->start_span(
            $this->span_name,
            $this->span_source['type'],
            $this->span_source['source'],
            $meta
        );

        $result = null;
        try {
            $result = call_user_func_array($this->original, $args);
        } finally {
            $this->collector->end_span_filtered($span_id, $this->min_duration_ms);
        }

        return $result;
    }

    private function is_wp_flame_source(): bool
    {
        $source = $this->span_source['source'];
        if ( $source === 'wordpress-apm-plugin' ) {
            return true;
        }

        $plugin_dir = defined( 'WP_FLAME_DIR' )
            ? Config::string_value( WP_FLAME_DIR, '' )
            : dirname( __DIR__ );
        $plugin_slug = basename( rtrim( str_replace( '\\', '/', $plugin_dir ), '/' ) );

        return $plugin_slug !== '' && $source === $plugin_slug;
    }

    private function resolve_metadata(): void
    {
        if ( $this->span_name === '' ) {
            $this->span_name = CallbackResolver::resolve_name( $this->callback_id, $this->original );
        }
        if ( empty( $this->span_source ) ) {
            $this->span_source = CallbackResolver::resolve_source( $this->callback_id, $this->original, $this->collector );
        }
        if ( $this->span_location['caller_file'] === '' ) {
            $this->span_location = CallbackResolver::resolve_location( $this->callback_id, $this->original );
        }

        $this->span_name = $this->span_name !== '' ? $this->span_name : ( $this->callback_id !== '' ? $this->callback_id : 'callback' );
        $this->span_source = [
            'type'   => Config::string_value( $this->span_source['type'] ?? Span::TYPE_PHP, Span::TYPE_PHP ),
            'source' => Config::string_value( $this->span_source['source'] ?? 'unknown', 'unknown' ),
        ];
    }

    /**
     * Get the original unwrapped callback.
     *
     * @return callable
     */
    public function get_original()
    {
        return $this->original;
    }
}
