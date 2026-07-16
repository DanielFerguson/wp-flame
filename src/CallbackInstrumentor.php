<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CallbackInstrumentor implements Instrumentor
{
    private const MAX_HOOK_NAME_BYTES = 160;
    private const MAX_CALLBACK_ID_BYTES = 220;

    /** @var Config */
    private $config;
    /** @var Collector */
    private $collector;

    public function __construct( Config $config )
    {
        $this->config = $config;
    }

    public function is_applicable(): bool
    {
        return true;
    }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;
        $min_ms_value = Config::string_value( $this->config->get( 'wp_flame_min_callback_ms', 0.5 ), '0.5' );
        $min_ms_float = is_numeric( trim( $min_ms_value ) ) ? (float) trim( $min_ms_value ) : 0.5;
        $min_ms = is_finite( $min_ms_float ) ? max( 0.0, $min_ms_float ) : 0.5;

        // Register callback wrapping at plugins_loaded priority 1 and init priority 1
        add_action( 'plugins_loaded', function () use ( $collector, $min_ms ) {
            if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
                return;
            }
            $this->wrap_callbacks( $collector, $min_ms );
        }, 1 );

        add_action( 'init', function () use ( $collector, $min_ms ) {
            if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
                return;
            }
            $this->wrap_callbacks( $collector, $min_ms );
        }, 1 );

        add_action( 'template_redirect', function () use ( $collector, $min_ms ) {
            if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
                return;
            }
            $this->wrap_callbacks( $collector, $min_ms );
        }, 0 );

        add_action( 'admin_init', function () use ( $collector, $min_ms ) {
            if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
                return;
            }
            $this->wrap_callbacks( $collector, $min_ms );
        }, 0 );

        add_action( 'rest_api_init', function () use ( $collector, $min_ms ) {
            if ( ! empty( $GLOBALS['wp_flame_skip_callback_wrapping'] ) ) {
                return;
            }
            $this->wrap_callbacks( $collector, $min_ms );
        }, 0 );
    }

    /**
     * Wrap all registered callbacks with timing instrumentation.
     *
     * Iterates all hooks in $wp_filter and replaces each callback's function
     * entry with a CallbackWrapper that adds span timing.
     */
    private function wrap_callbacks( Collector $collector, float $min_ms ): void
    {
        if ( empty( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
            return;
        }

        foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook_instance ) {
            if ( ! ( $hook_instance instanceof \WP_Hook ) ) {
                continue;
            }

            if ( ! is_array( $hook_instance->callbacks ) ) {
                continue;
            }

            $hook_name = $this->bounded_label( $hook_name, self::MAX_HOOK_NAME_BYTES, 'unknown_hook' );

            foreach ( $hook_instance->callbacks as $priority => $priority_callbacks ) {
                if ( ! is_array( $priority_callbacks ) ) {
                    continue;
                }

                foreach ( $priority_callbacks as $id => $the_ ) {
                    if ( ! is_array( $the_ ) || ! array_key_exists( 'function', $the_ ) ) {
                        continue;
                    }

                    // Skip already-wrapped callbacks
                    if ( $the_['function'] instanceof CallbackWrapper ) {
                        continue;
                    }

                    $original = $the_['function'];
                    if ( ! is_callable( $original ) ) {
                        $this->record_wrap_limitation( 'non_callable' );
                        continue;
                    }

                    $accepted_args = Config::bounded_int( $the_['accepted_args'] ?? PHP_INT_MAX, PHP_INT_MAX, 0, PHP_INT_MAX );
                    if ( CallbackResolver::accepts_reference_parameters( $original, $accepted_args ) ) {
                        $this->record_wrap_limitation( 'reference_parameter' );
                        continue;
                    }

                    if ( CallbackResolver::returns_reference( $original ) ) {
                        $this->record_wrap_limitation( 'reference_return' );
                        continue;
                    }

                    $callback_id = $this->callback_cache_id( $id );

                    $hook_instance->callbacks[ $priority ][ $id ]['function'] = new CallbackWrapper(
                        $original, $collector, $hook_name, Config::bounded_int( $priority, 0, -1000000, 1000000 ),
                        '', [], $min_ms, $callback_id
                    );
                }
            }
        }
    }

    /**
     * @param mixed $value
     */
    private function callback_cache_id( $value ): string
    {
        $value = Config::string_value( $value, '' );
        if ( strlen( $value ) <= self::MAX_CALLBACK_ID_BYTES ) {
            return $value;
        }

        return substr( $value, 0, self::MAX_CALLBACK_ID_BYTES - 41 ) . '#' . sha1( $value );
    }

    /**
     * @param mixed $value
     */
    private function bounded_label( $value, int $max_bytes, string $fallback ): string
    {
        $value = Config::string_value( $value, $fallback );
        if ( $value === '' ) {
            $value = $fallback;
        }

        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }

    private function record_wrap_limitation( string $reason ): void
    {
        if ( ! isset( $GLOBALS['wp_flame_callback_wrap_limitations'] ) || ! is_array( $GLOBALS['wp_flame_callback_wrap_limitations'] ) ) {
            $GLOBALS['wp_flame_callback_wrap_limitations'] = [];
        }
        $current = Config::bounded_int( $GLOBALS['wp_flame_callback_wrap_limitations'][ $reason ] ?? 0, 0, 0, 1000000 );
        $GLOBALS['wp_flame_callback_wrap_limitations'][ $reason ] = min( 1000000, $current + 1 );
    }
}
