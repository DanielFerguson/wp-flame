<?php

$wp_flame_autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! file_exists($wp_flame_autoload)) {
    die("Run 'composer install' before running tests.\n");
}

require_once $wp_flame_autoload;

// Define ABSPATH so src/ ABSPATH guards don't exit during unit tests.
// Use the same path the CollectorTest expects for its core-file attribution test.
if (! defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// ---------------------------------------------------------------------------
// WordPress i18n function stubs for unit tests (no WP loaded).
// Each function returns the original string so assertions on string content
// continue to work exactly as before.
// ---------------------------------------------------------------------------
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag ) {
        // No-op in unit tests.
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'get_user_by' ) ) {
    function get_user_by( $field, $value ) { return false; }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof \WP_Error;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}

// If running integration suite, load WordPress test framework
$is_integration = getenv('WP_TESTS_DIR') !== false;

if ($is_integration) {
    $wp_tests_dir = getenv('WP_TESTS_DIR');

    if (! file_exists($wp_tests_dir . '/includes/functions.php')) {
        die("WordPress test framework not found at {$wp_tests_dir}\n");
    }

    // Load the plugin before WordPress finishes loading
    tests_add_filter('muplugins_loaded', function () {
        require dirname(__DIR__) . '/wp-flame.php';
    });

    require $wp_tests_dir . '/includes/bootstrap.php';
}
