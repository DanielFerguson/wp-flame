<?php

declare(strict_types=1);

namespace WPFlame;

class Config
{
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
}
