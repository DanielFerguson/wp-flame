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
