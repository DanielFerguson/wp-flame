<?php

declare(strict_types=1);

namespace WPFlame;

class SourceResolver
{
    private const MAX_BACKTRACE_DEPTH = 50;

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
        $context = self::from_backtrace_context( $collector, $skip, $depth );

        return $context['source'];
    }

    /**
     * @return array{source: string, caller_file: string, caller_line: int}
     */
    public static function from_backtrace_context( Collector $collector, int $skip = 0, int $depth = 25 ): array
    {
        $skip  = max( 0, min( self::MAX_BACKTRACE_DEPTH, $skip ) );
        $depth = max( 1, min( self::MAX_BACKTRACE_DEPTH, $depth ) );
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $depth );

        return self::context_from_trace_array( $collector, $trace, $skip + 1 );
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
        $context = self::context_from_trace_array( $collector, $trace, $skip );

        return $context['source'];
    }

    /**
     * Resolve source plus a privacy-bounded, content-relative caller location.
     *
     * @return array{source: string, caller_file: string, caller_line: int}
     */
    public static function context_from_trace_array( Collector $collector, array $trace, int $skip = 0 ): array
    {
        $skip = max( 0, min( self::MAX_BACKTRACE_DEPTH, $skip ) );
        $wp_flame_dir = defined( 'WP_FLAME_DIR' ) ? WP_FLAME_DIR : dirname( __DIR__ );

        foreach ( array_slice( $trace, $skip ) as $frame ) {
            if ( ! isset( $frame['file'] ) ) {
                continue;
            }

            $file = Config::string_value( $frame['file'], '' );
            if ( $file === '' ) {
                continue;
            }

            // Skip wp-includes, wp-admin, and our own plugin
            if ( defined( 'ABSPATH' ) ) {
                if ( strpos( $file, ABSPATH . 'wp-includes/' ) === 0 ) {
                    continue;
                }
                if ( strpos( $file, ABSPATH . 'wp-admin/' ) === 0 ) {
                    continue;
                }
            }
            if ( self::path_is_inside_directory( $file, $wp_flame_dir ) ) {
                continue;
            }

            $source = $collector->get_source_from_file( $file );
            $caller_file = basename( str_replace( '\\', '/', $file ) );
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $content_dir = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/';
                $normalized_file = str_replace( '\\', '/', $file );
                if ( strpos( $normalized_file, $content_dir ) === 0 ) {
                    $caller_file = substr( $normalized_file, strlen( $content_dir ) );
                }
            }

            return [
                'source'      => Config::string_value( $source['source'] ?? 'unknown', 'unknown' ),
                'caller_file' => substr( $caller_file, 0, 300 ),
                'caller_line' => Config::bounded_int( $frame['line'] ?? 0, 0, 0, 10000000 ),
            ];
        }

        return [
            'source'      => 'wordpress',
            'caller_file' => '',
            'caller_line' => 0,
        ];
    }

    private static function path_is_inside_directory( string $path, string $directory ): bool
    {
        $path      = str_replace( '\\', '/', $path );
        $directory = rtrim( str_replace( '\\', '/', $directory ), '/' );
        if ( $directory === '' ) {
            return false;
        }

        return $path === $directory || strpos( $path, $directory . '/' ) === 0;
    }
}
