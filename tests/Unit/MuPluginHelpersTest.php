<?php

declare(strict_types=1);

namespace {
    if (! function_exists('is_multisite')) {
        function is_multisite(): bool
        {
            return false;
        }
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;

    class MuPluginHelpersTest extends TestCase
    {
        private function loadMuPlugin(): void
        {
            require_once dirname(__DIR__, 2) . '/mu-plugin/wp-flame-early-hooks.php';
        }

        public function test_mu_bounded_int_uses_default_for_non_finite_numeric_values(): void
        {
            $this->loadMuPlugin();

            $this->assertSame(20, \wp_flame_mu_bounded_int(INF, 20, 10, 100));
            $this->assertSame(20, \wp_flame_mu_bounded_int(NAN, 20, 10, 100));
            $this->assertSame(20, \wp_flame_mu_bounded_int('1e9999', 20, 10, 100));
        }

        public function test_mu_force_cookie_does_not_bypass_early_guards_without_auth_signal(): void
        {
            $this->loadMuPlugin();

            $this->assertFalse(\wp_flame_mu_force_cookie_can_bypass_early_guards(
                ['wp_flame_force_trace' => 'nonce-value'],
                []
            ));
        }

        public function test_mu_force_cookie_can_bypass_early_guards_with_non_cookie_auth_signal(): void
        {
            $this->loadMuPlugin();

            $this->assertTrue(\wp_flame_mu_force_cookie_can_bypass_early_guards(
                ['wp_flame_force_trace' => 'nonce-value'],
                ['HTTP_AUTHORIZATION' => 'Bearer token']
            ));
        }

        public function test_mu_force_cookie_rejects_malformed_values_before_auth_signal_check(): void
        {
            $this->loadMuPlugin();

            $this->assertFalse(\wp_flame_mu_force_cookie_can_bypass_early_guards(
                ['wp_flame_force_trace' => ['nonce-value']],
                ['HTTP_AUTHORIZATION' => 'Bearer token']
            ));
            $this->assertFalse(\wp_flame_mu_force_cookie_can_bypass_early_guards(
                ['wp_flame_force_trace' => str_repeat('a', 129)],
                ['HTTP_AUTHORIZATION' => 'Bearer token']
            ));
        }

        public function test_mu_plugin_option_match_supports_list_and_keyed_shapes(): void
        {
            $this->loadMuPlugin();

            $this->assertTrue(\wp_flame_mu_plugin_option_matches(
                ['wp-flame/wp-flame.php'],
                'wp-flame/wp-flame.php'
            ));
            $this->assertTrue(\wp_flame_mu_plugin_option_matches(
                ['custom-wp-flame/wp-flame.php' => 1780000000],
                'custom-wp-flame/wp-flame.php'
            ));
            $this->assertTrue(\wp_flame_mu_plugin_option_matches(
                ['wp-flame/wp-flame.php' => null],
                ''
            ));
            $this->assertFalse(\wp_flame_mu_plugin_option_matches(
                ['other-plugin/plugin.php' => 1780000000],
                'custom-wp-flame/wp-flame.php'
            ));
        }
    }
}
