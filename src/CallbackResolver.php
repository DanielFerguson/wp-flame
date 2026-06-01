<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Return true when a callback declares by-reference parameters that would be
     * observed by WordPress for the registered accepted-args count.
     *
     * Wrapping those callbacks changes the call boundary and can alter reference
     * semantics, so deep instrumentation should leave them untouched.
     */
    public static function accepts_reference_parameters($callback, int $accepted_args): bool
    {
        if ( $accepted_args <= 0 ) {
            return false;
        }

        try {
            $ref = self::get_callback_reflection($callback);
        } catch (\ReflectionException $e) {
            return false;
        }

        foreach ($ref->getParameters() as $index => $parameter) {
            if ($index >= $accepted_args) {
                break;
            }

            if ($parameter->isPassedByReference()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return true when a callback returns by reference.
     *
     * CallbackWrapper::__invoke() cannot preserve return-by-reference behavior
     * for arbitrary callbacks, so Deep mode should leave these callbacks
     * untouched for compatibility.
     */
    public static function returns_reference( $callback ): bool
    {
        try {
            return self::get_callback_reflection( $callback )->returnsReference();
        } catch (\ReflectionException $e) {
            return false;
        }
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
        $ref = self::get_callback_reflection($callback);
        $filename = $ref->getFileName();

        // getFileName() returns false for built-in PHP functions (trim, array_map, etc.)
        if ($filename === false) {
            throw new \ReflectionException('Cannot determine filename for callback (built-in or internal)');
        }

        return $filename;
    }

    private static function get_callback_reflection($callback): \ReflectionFunctionAbstract
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $parts = explode('::', $callback, 2);
            return new \ReflectionMethod($parts[0], $parts[1]);
        }

        if (is_string($callback) && function_exists($callback)) {
            return new \ReflectionFunction($callback);
        }

        if (is_array($callback) && isset($callback[0], $callback[1])) {
            return new \ReflectionMethod($callback[0], $callback[1]);
        }

        if ($callback instanceof \Closure) {
            return new \ReflectionFunction($callback);
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return new \ReflectionMethod($callback, '__invoke');
        }

        throw new \ReflectionException('Unknown callback type');
    }

    private static function short_class_name(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private static function relative_path(string $file): string
    {
        if (defined('WP_PLUGIN_DIR') && self::path_is_inside_directory($file, WP_PLUGIN_DIR)) {
            return substr($file, strlen(WP_PLUGIN_DIR) + 1);
        }

        if (function_exists('get_template_directory') && self::path_is_inside_directory($file, get_template_directory())) {
            return substr($file, strlen(get_template_directory()) + 1);
        }

        if (defined('ABSPATH') && self::path_is_inside_directory($file, ABSPATH)) {
            return substr($file, strlen(ABSPATH));
        }

        return basename($file);
    }

    private static function path_is_inside_directory(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        return $path === $directory || strpos($path, $directory . '/') === 0;
    }
}
