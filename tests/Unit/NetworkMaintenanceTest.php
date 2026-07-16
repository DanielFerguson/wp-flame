<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_site_option')) {
        function get_site_option($key, $default = false)
        {
            return $GLOBALS['wp_flame_test_site_options'][$key] ?? $default;
        }
    }

    if (! function_exists('update_site_option')) {
        function update_site_option($key, $value): bool
        {
            $GLOBALS['wp_flame_test_site_options'][$key] = $value;
            return true;
        }
    }

    if (! function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook)
        {
            return $GLOBALS['wp_flame_test_scheduled'][$hook] ?? false;
        }
    }

    if (! function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook): bool
        {
            $GLOBALS['wp_flame_test_scheduled'][$hook] = $timestamp;
            return true;
        }
    }

    if (! function_exists('get_sites')) {
        function get_sites($args = []): array
        {
            $sites = $GLOBALS['wp_flame_test_sites'] ?? [];
            return array_slice($sites, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 100));
        }
    }

    if (! function_exists('switch_to_blog')) {
        function switch_to_blog($blog_id): bool
        {
            $GLOBALS['wp_flame_test_blog_stack'][] = (int) $blog_id;
            return true;
        }
    }

    if (! function_exists('restore_current_blog')) {
        function restore_current_blog(): bool
        {
            array_pop($GLOBALS['wp_flame_test_blog_stack']);
            return true;
        }
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPFlame\NetworkMaintenance;

    class NetworkMaintenanceTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wp_flame_test_site_options'] = [];
            $GLOBALS['wp_flame_test_scheduled'] = [];
            $GLOBALS['wp_flame_test_blog_stack'] = [];
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['wp_flame_test_site_options'],
                $GLOBALS['wp_flame_test_scheduled'],
                $GLOBALS['wp_flame_test_blog_stack'],
                $GLOBALS['wp_flame_test_sites']
            );
            parent::tearDown();
        }

        public function test_network_operation_is_batched_and_retries_incomplete_site(): void
        {
            $GLOBALS['wp_flame_test_sites'] = range(1, 25);
            $visits = [];
            NetworkMaintenance::start('migrate');

            $operator = static function (string $operation, int $blog_id) use (&$visits): array {
                $visits[] = $blog_id;
                if ($blog_id === 3 && count(array_keys($visits, 3, true)) === 1) {
                    return ['status' => 'retry'];
                }

                return ['status' => 'complete'];
            };

            $first = NetworkMaintenance::run_batch($operator);
            $this->assertSame('pending', $first['status']);
            $this->assertSame(10, $first['cursor']);
            $this->assertSame([3], $first['retry_sites']);

            NetworkMaintenance::run_batch($operator);
            $third = NetworkMaintenance::run_batch($operator);
            $this->assertSame('pending', $third['status']);
            $this->assertTrue($third['enumeration_complete']);

            $complete = NetworkMaintenance::run_batch($operator);
            $this->assertSame('complete', $complete['status']);
            $this->assertSame(26, $complete['processed']);
            $this->assertSame([], $complete['retry_sites']);
            $this->assertSame([], $GLOBALS['wp_flame_test_blog_stack']);
        }

        public function test_network_operation_records_failure_and_restores_blog_context(): void
        {
            $GLOBALS['wp_flame_test_sites'] = [1, 2];
            NetworkMaintenance::start('prune');

            $state = NetworkMaintenance::run_batch(static function (string $operation, int $blog_id): array {
                if ($blog_id === 2) {
                    throw new \RuntimeException('database unavailable');
                }

                return ['status' => 'complete'];
            });

            $this->assertSame('complete_with_failures', $state['status']);
            $this->assertCount(1, $state['failures']);
            $this->assertSame(2, $state['failures'][0]['blog_id']);
            $this->assertSame([], $GLOBALS['wp_flame_test_blog_stack']);
        }
    }
}
