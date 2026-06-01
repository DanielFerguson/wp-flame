<?php

declare(strict_types=1);

namespace {
    if (! class_exists('wpdb')) {
        class wpdb
        {
        }
    }

    class WPFlame_Custom_WPDB extends wpdb
    {
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use WPFlame\Admin;
    use WPFlame\Storage;

    class AdminTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset(
                $GLOBALS['wpdb'],
                $GLOBALS['wp_flame_test_current_user_can'],
                $GLOBALS['wp_flame_test_options']
            );
            parent::tearDown();
        }

        public function test_render_notices_tolerates_malformed_wpdb_global(): void
        {
            $GLOBALS['wpdb'] = 'not-a-database-object';
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_instrumentation_mode' => 'standard',
            ];

            $html = $this->render_notices();

            $this->assertStringContainsString('DB query instrumentation is disabled', $html);
            $this->assertStringContainsString('database layer is unavailable or modified', $html);
        }

        public function test_render_notices_suppresses_db_notice_in_safe_mode(): void
        {
            $GLOBALS['wpdb'] = new \WPFlame_Custom_WPDB();
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_instrumentation_mode' => 'safe',
            ];

            $html = $this->render_notices();

            $this->assertStringNotContainsString('DB query instrumentation is disabled', $html);
        }

        public function test_render_notices_shows_custom_db_notice_in_standard_mode(): void
        {
            $GLOBALS['wpdb'] = new \WPFlame_Custom_WPDB();
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_instrumentation_mode' => 'standard',
            ];

            $html = $this->render_notices();

            $this->assertStringContainsString('DB query instrumentation is disabled', $html);
        }

        public function test_render_notices_skips_db_notice_for_normal_wpdb(): void
        {
            $GLOBALS['wpdb'] = new \wpdb();
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_instrumentation_mode' => 'standard',
            ];

            $html = $this->render_notices();

            $this->assertStringNotContainsString('DB query instrumentation is disabled', $html);
        }

        public function test_render_notices_skips_for_users_without_access(): void
        {
            $GLOBALS['wp_flame_test_current_user_can'] = false;
            $GLOBALS['wpdb'] = 'not-a-database-object';
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_instrumentation_mode' => 'standard',
            ];

            $this->assertSame('', $this->render_notices());
        }

        public function test_request_string_ignores_malformed_non_scalar_values_without_warnings(): void
        {
            $admin = new Admin($this->createMock(Storage::class));
            $method = new ReflectionMethod(Admin::class, 'request_string');
            $method->setAccessible(true);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $result = $method->invoke($admin, ['trace_id' => ['bad']], 'trace_id');
            } finally {
                restore_error_handler();
            }

            $this->assertSame('', $result);
            $this->assertSame([], $warnings);
        }

        private function render_notices(): string
        {
            $admin = new Admin($this->createMock(Storage::class));

            ob_start();
            $admin->render_notices();
            return (string) ob_get_clean();
        }
    }
}
