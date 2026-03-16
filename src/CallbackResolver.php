<?php

declare(strict_types=1);

namespace WPFlame;

class CallbackResolver
{
    /** @var array<string, string> */
    private static array $name_cache = [];

    /** @var array<string, array> */
    private static array $source_cache = [];

    /**
     * Resolve a human-readable name for a WordPress callback.
     */
    public static function resolve_name(string $callback_id, $callback): string
    {
        if (isset(self::$name_cache[$callback_id])) {
            return self::$name_cache[$callback_id];
        }

        try {
            $name = self::do_resolve_name($callback);
        } catch (\ReflectionException $e) {
            $name = $callback_id;
        }

        self::$name_cache[$callback_id] = $name;
        return $name;
    }

    /**
     * Resolve source attribution for a WordPress callback.
     *
     * @return array{type: string, source: string}
     */
    public static function resolve_source(string $callback_id, $callback, Collector $collector): array
    {
        if (isset(self::$source_cache[$callback_id])) {
            return self::$source_cache[$callback_id];
        }

        try {
            $filename = self::get_callback_filename($callback);
            $source = $collector->get_source_from_file($filename);
        } catch (\ReflectionException $e) {
            $source = ['type' => Span::TYPE_PHP, 'source' => 'unknown'];
        }

        self::$source_cache[$callback_id] = $source;
        return $source;
    }

    /**
     * Clear all caches. Called by Collector::reset() for test isolation.
     */
    public static function reset(): void
    {
        self::$name_cache = [];
        self::$source_cache = [];
    }

    private static function do_resolve_name($callback): string
    {
        // String static method: "ClassName::method"
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $parts = explode('::', $callback, 2);
            $ref = new \ReflectionMethod($parts[0], $parts[1]);
            return self::short_class_name($ref->getDeclaringClass()->getName()) . '::' . $ref->getName();
        }

        // Named function
        if (is_string($callback) && function_exists($callback)) {
            return $callback;
        }

        // Array method: [$object, 'method'] or ['ClassName', 'method']
        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            if (is_string($class) && ! class_exists($class)) {
                throw new \ReflectionException("Class '{$class}' does not exist");
            }
            return self::short_class_name($class) . '::' . $callback[1];
        }

        // Closure
        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            $file = $ref->getFileName();
            $line = $ref->getStartLine();
            return self::relative_path($file) . ':' . $line;
        }

        // Invocable object
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return self::short_class_name(get_class($callback)) . '::__invoke';
        }

        // Unknown — throw so the caller falls back to $callback_id
        throw new \ReflectionException('Unknown callback type');
    }

    private static function get_callback_filename($callback): string
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $parts = explode('::', $callback, 2);
            $ref = new \ReflectionMethod($parts[0], $parts[1]);
            return $ref->getFileName();
        }

        if (is_string($callback) && function_exists($callback)) {
            $ref = new \ReflectionFunction($callback);
            return $ref->getFileName();
        }

        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $ref = new \ReflectionMethod($callback[0], $callback[1]);
            return $ref->getFileName();
        }

        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            return $ref->getFileName();
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            $ref = new \ReflectionMethod($callback, '__invoke');
            return $ref->getFileName();
        }

        throw new \ReflectionException('Cannot determine filename for callback');
    }

    private static function short_class_name(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private static function relative_path(string $file): string
    {
        if (defined('WP_PLUGIN_DIR') && strpos($file, WP_PLUGIN_DIR) === 0) {
            return substr($file, strlen(WP_PLUGIN_DIR) + 1);
        }

        if (function_exists('get_template_directory') && strpos($file, get_template_directory()) === 0) {
            return substr($file, strlen(get_template_directory()) + 1);
        }

        if (defined('ABSPATH') && strpos($file, ABSPATH) === 0) {
            return substr($file, strlen(ABSPATH));
        }

        return basename($file);
    }
}
