<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Config
{
    public const DEFAULT_SAMPLE_RATE = 1;
    public const MAX_SAMPLE_RATE = 1000000;
    public const DEFAULT_RETENTION_DAYS = 7;
    public const MAX_RETENTION_DAYS = 365;
    public const DEFAULT_MAX_SPANS = 2000;
    public const MIN_MAX_SPANS = 100;
    public const MAX_MAX_SPANS = 5000;
    public const DEFAULT_MAX_TRACE_BYTES = 1048576;
    public const MIN_MAX_TRACE_BYTES = 65536;
    public const MAX_MAX_TRACE_BYTES = 8388608;
    public const DEFAULT_STORAGE_QUOTA_ROWS = 10000;
    public const MIN_STORAGE_QUOTA_ROWS = 100;
    public const MAX_STORAGE_QUOTA_ROWS = 1000000;
    public const DEFAULT_STORAGE_QUOTA_MB = 512;
    public const MIN_STORAGE_QUOTA_MB = 16;
    public const MAX_STORAGE_QUOTA_MB = 10240;

    /** @var self|null */
    private static $instance;

    /** @var array<string, mixed> */
    private $overrides = [];

    /**
     * Get the global Config instance.
     * @return self
     */
    public static function instance(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get a configuration value.
     * Overrides take precedence over stored options.
     *
     * @param string $key     Option name (e.g. 'wp_flame_sample_rate').
     * @param mixed  $default Default value if neither override nor option exists.
     * @return mixed
     */
    public function get( string $key, $default = false )
    {
        if ( array_key_exists( $key, $this->overrides ) ) {
            return $this->overrides[ $key ];
        }

        return get_option( $key, $default );
    }

    /**
     * Set a per-request override.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set_override( string $key, $value ): void
    {
        $this->overrides[ $key ] = $value;
    }

    /**
     * Normalize WordPress option values into strict booleans.
     *
     * @param mixed $value
     */
    public static function boolean( $value ): bool
    {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        if ( ! is_string( $value ) ) {
            if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
                try {
                    $value = (string) $value;
                } catch ( \Throwable $e ) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
    }

    /**
     * Normalize arbitrary option/filter values into a display/runtime string
     * without triggering PHP array/object conversion warnings.
     *
     * @param mixed $value
     */
    public static function string_value( $value, string $fallback = '' ): string
    {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
            try {
                return (string) $value;
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }

    /**
     * Normalize integer settings that protect runtime or storage overhead.
     *
     * @param mixed $value
     */
    public static function bounded_int( $value, int $default, int $min, int $max ): int
    {
        if ( is_int( $value ) ) {
            $number = $value;
        } elseif ( is_float( $value ) ) {
            $number = is_finite( $value ) ? (int) $value : $default;
        } elseif ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $float  = (float) trim( $value );
            $number = is_finite( $float ) ? (int) $float : $default;
        } else {
            $number = $default;
        }

        return min( $max, max( $min, $number ) );
    }
}
