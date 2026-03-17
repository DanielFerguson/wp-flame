<?php

declare(strict_types=1);

namespace WPFlame;

class CallbackInstrumentor implements Instrumentor
{
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
        $min_ms = (float) $this->config->get( 'wp_flame_min_callback_ms', 0.5 );

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
    }

    /**
     * Wrap all registered callbacks with timing instrumentation.
     *
     * Iterates all hooks in $wp_filter and replaces each callback's function
     * entry with a CallbackWrapper that adds span timing.
     */
    private function wrap_callbacks( Collector $collector, float $min_ms ): void
    {
        foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook_instance ) {
            if ( ! ( $hook_instance instanceof \WP_Hook ) ) {
                continue;
            }

            foreach ( $hook_instance->callbacks as $priority => $priority_callbacks ) {
                foreach ( $priority_callbacks as $id => $the_ ) {
                    // Skip already-wrapped callbacks
                    if ( $the_['function'] instanceof CallbackWrapper ) {
                        continue;
                    }

                    $original = $the_['function'];

                    // Skip our own plugin's callbacks to avoid self-instrumentation
                    $source = CallbackResolver::resolve_source( $id, $original, $collector );
                    if ( strpos( $source['source'], 'wp-flame' ) !== false || $source['source'] === 'wordpress-apm-plugin' ) {
                        continue;
                    }

                    $name = CallbackResolver::resolve_name( $id, $original );

                    $hook_instance->callbacks[ $priority ][ $id ]['function'] = new CallbackWrapper(
                        $original, $collector, $hook_name, (int) $priority,
                        $name, $source, $min_ms
                    );
                }
            }
        }
    }
}
