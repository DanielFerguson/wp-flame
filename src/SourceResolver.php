<?php

declare(strict_types=1);

namespace WPFlame;

class SourceResolver
{
    /**
     * Capture a backtrace and resolve the calling source.
     *
     * @param Collector $collector
     * @param int       $skip  Additional frames to skip beyond the resolver itself.
     * @param int       $depth Max backtrace depth.
     * @return string Source attribution string.
     */
    public static function from_backtrace( Collector $collector, int $skip = 0, int $depth = 25 ): string
    {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $depth );

        return self::from_trace_array( $collector, $trace, $skip + 1 );
    }

    /**
     * Resolve calling source from a pre-captured backtrace.
     *
     * @param Collector $collector
     * @param array     $trace Pre-captured backtrace array.
     * @param int       $skip  Frames to skip.
     * @return string Source attribution string.
     */
    public static function from_trace_array( Collector $collector, array $trace, int $skip = 0 ): string
    {
        $wp_flame_dir = defined( 'WP_FLAME_DIR' ) ? WP_FLAME_DIR : dirname( __DIR__ );

        foreach ( array_slice( $trace, $skip ) as $frame ) {
            if ( ! isset( $frame['file'] ) ) {
                continue;
            }

            $file = $frame['file'];

            // Skip wp-includes, wp-admin, and our own plugin
            if ( defined( 'ABSPATH' ) ) {
                if ( strpos( $file, ABSPATH . 'wp-includes/' ) === 0 ) {
                    continue;
                }
                if ( strpos( $file, ABSPATH . 'wp-admin/' ) === 0 ) {
                    continue;
                }
            }
            if ( strpos( $file, $wp_flame_dir ) === 0 ) {
                continue;
            }

            $source = $collector->get_source_from_file( $file );
            return $source['source'];
        }

        return 'wordpress';
    }
}
