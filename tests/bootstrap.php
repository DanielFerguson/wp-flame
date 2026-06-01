<?php

$wp_flame_autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! file_exists($wp_flame_autoload)) {
    die("Run 'composer install' before running tests.\n");
}

require_once $wp_flame_autoload;

$is_integration = getenv('WP_TESTS_DIR') !== false;

if (! function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (! $is_integration) {
// Define ABSPATH so src/ ABSPATH guards don't exit during unit tests.
// Use the same path the CollectorTest expects for its core-file attribution test.
if (! defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        /** @var array<int, mixed> */
        public array $prepared_params = [];
        /** @var array<string, mixed> */
        public array $last_insert_data = [];
        /** @var mixed */
        public $last_query = '';
        public string $last_error = '';

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            $this->prepared_params = $args;
            return $query;
        }

        public function get_charset_collate(): string
        {
            return '';
        }

        public function get_results($query, $output = null)
        {
            $this->last_query = $query;
            return [];
        }

        public function get_row($query)
        {
            $this->last_query = $query;
            return false;
        }

        public function get_var($query)
        {
            $this->last_query = $query;
            return null;
        }

        public function get_col($query): array
        {
            $this->last_query = $query;
            return [];
        }

        public function query($query)
        {
            $this->last_query = $query;
            return 0;
        }

        public function insert($table, $data, $formats)
        {
            $this->last_insert_data = $data;
            return 1;
        }

        public function esc_like($text): string
        {
            return addcslashes((string) $text, '_%\\');
        }
    }
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
if (! function_exists('esc_attr')) {
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true): string
    {
        $result = (string) $checked === (string) $current ? ' checked=\'checked\'' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}
if (! function_exists('selected')) {
    function selected($selected, $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? ' selected=\'selected\'' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}
if (! function_exists('wp_kses_post')) {
    function wp_kses_post($data): string
    {
        return (string) $data;
    }
}
if (! function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}
if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        return trim(strip_tags((string) $value));
    }
}
if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}
if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, int $flags = 0, int $depth = 512)
    {
        return json_encode($value, $flags, $depth);
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( ! empty( $GLOBALS['wp_flame_test_apply_filters'][ $tag ] ) ) {
            $args = func_get_args();
            foreach ( $GLOBALS['wp_flame_test_apply_filters'][ $tag ] as $callback ) {
                $args[1] = $callback( ...array_slice( $args, 1 ) );
            }
            return $args[1];
        }
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
        if ( array_key_exists( $option, $GLOBALS['wp_flame_test_options'] ?? [] ) ) {
            return $GLOBALS['wp_flame_test_options'][ $option ];
        }

        return $default;
    }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ): bool {
        return $GLOBALS['wp_flame_test_current_user_can'] ?? true;
    }
}

if ( ! function_exists( 'get_user_by' ) ) {
    function get_user_by( $field, $value ) {
        if ( $field === 'email' && isset( $GLOBALS['wp_flame_test_users_by_email'][ $value ] ) ) {
            return (object) $GLOBALS['wp_flame_test_users_by_email'][ $value ];
        }

        return false;
    }
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
}

// If running integration suite, load WordPress test framework
if ($is_integration) {
    $wp_tests_dir = getenv('WP_TESTS_DIR');

    if (! file_exists($wp_tests_dir . '/includes/functions.php')) {
        fwrite(STDERR, "WordPress test framework not found at {$wp_tests_dir}\n");
        exit(1);
    }

    require_once $wp_tests_dir . '/includes/functions.php';

    // Load the plugin before WordPress finishes loading
    tests_add_filter('muplugins_loaded', function () {
        require dirname(__DIR__) . '/wp-flame.php';
    });

    require $wp_tests_dir . '/includes/bootstrap.php';
} elseif (! class_exists('WP_UnitTestCase')) {
    class WP_UnitTestCase extends \PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            $this->markTestSkipped('WordPress integration tests require WP_TESTS_DIR.');
        }
    }
}
