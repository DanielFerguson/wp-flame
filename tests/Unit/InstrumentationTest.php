<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Collector;
use WPFlame\DbInstrumentor;
use WPFlame\GraphQL;
use WPFlame\Instrumentation;
use WPFlame\Instrumentor;

class InstrumentationTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    public function test_disabled_plugin_never_instruments(): void
    {
        $this->assertFalse(Instrumentation::should_instrument(false, 'everyone', 1, true, true, true, true, 1));
    }

    public function test_cron_bypasses_audience_and_sampling(): void
    {
        $this->assertTrue(Instrumentation::should_instrument(true, 'admins', 100, false, true, false, false, 100));
    }

    public function test_force_trace_bypasses_audience_and_sampling(): void
    {
        $this->assertTrue(Instrumentation::should_instrument(true, 'admins', 100, true, false, false, false, 100));
    }

    public function test_admin_audience_requires_manage_options(): void
    {
        $this->assertFalse(Instrumentation::should_instrument(true, 'admins', 1, false, false, true, false, 1));
        $this->assertTrue(Instrumentation::should_instrument(true, 'admins', 1, false, false, true, true, 1));
    }

    public function test_logged_in_audience_requires_logged_in_user(): void
    {
        $this->assertFalse(Instrumentation::should_instrument(true, 'logged_in', 1, false, false, false, false, 1));
        $this->assertTrue(Instrumentation::should_instrument(true, 'logged_in', 1, false, false, true, false, 1));
    }

    public function test_everyone_audience_uses_deterministic_sampling(): void
    {
        $this->assertTrue(Instrumentation::should_instrument(true, 'everyone', 3, false, false, false, false, 1));
        $this->assertFalse(Instrumentation::should_instrument(true, 'everyone', 3, false, false, false, false, 2));
    }

    public function test_sample_miss_still_blocks_admin_audience(): void
    {
        $this->assertFalse(Instrumentation::should_instrument(true, 'admins', 10, false, false, true, true, 7));
    }

    public function test_force_trace_and_cron_bypass_sample_miss(): void
    {
        $this->assertTrue(Instrumentation::should_instrument(true, 'admins', 10, true, false, false, false, 7));
        $this->assertTrue(Instrumentation::should_instrument(true, 'admins', 10, false, true, false, false, 7));
    }

    public function test_force_trace_cookie_requires_admin_capability_and_valid_nonce(): void
    {
        $verifier = function (string $nonce): bool {
            return $nonce === 'valid-nonce';
        };

        $this->assertTrue(Instrumentation::is_force_trace_request('valid-nonce', true, true, $verifier));
        $this->assertFalse(Instrumentation::is_force_trace_request('invalid-nonce', true, true, $verifier));
        $this->assertFalse(Instrumentation::is_force_trace_request('valid-nonce', false, true, $verifier));
        $this->assertFalse(Instrumentation::is_force_trace_request('valid-nonce', true, false, $verifier));
        $this->assertFalse(Instrumentation::is_force_trace_request(['valid-nonce'], true, true, $verifier));
    }

    public function test_network_active_plugin_detection_supports_sitewide_option_shape(): void
    {
        $this->assertTrue(Instrumentation::is_network_active_plugin(
            'wp-flame/wp-flame.php',
            ['wp-flame/wp-flame.php' => 1780000000]
        ));
    }

    public function test_network_active_plugin_detection_is_conservative_for_keyed_null_values(): void
    {
        $this->assertTrue(Instrumentation::is_network_active_plugin(
            'wp-flame/wp-flame.php',
            ['wp-flame/wp-flame.php' => null]
        ));
    }

    public function test_network_active_plugin_detection_supports_list_shape(): void
    {
        $this->assertTrue(Instrumentation::is_network_active_plugin(
            'wp-flame/wp-flame.php',
            ['wp-flame/wp-flame.php']
        ));
    }

    public function test_network_active_plugin_detection_rejects_missing_or_empty_plugin_file(): void
    {
        $this->assertFalse(Instrumentation::is_network_active_plugin('', ['wp-flame/wp-flame.php' => 1780000000]));
        $this->assertFalse(Instrumentation::is_network_active_plugin(
            'wp-flame/wp-flame.php',
            ['other-plugin/plugin.php' => 1780000000]
        ));
    }

    public function test_site_active_plugin_detection_supports_list_and_keyed_shapes(): void
    {
        $this->assertTrue(Instrumentation::is_site_active_plugin(
            'wp-flame/wp-flame.php',
            ['wp-flame/wp-flame.php']
        ));
        $this->assertTrue(Instrumentation::is_site_active_plugin(
            'wp-flame/wp-flame.php',
            ['wp-flame/wp-flame.php' => null]
        ));
    }

    public function test_site_active_plugin_detection_rejects_missing_or_empty_plugin_file(): void
    {
        $this->assertFalse(Instrumentation::is_site_active_plugin('', ['wp-flame/wp-flame.php']));
        $this->assertFalse(Instrumentation::is_site_active_plugin(
            'wp-flame/wp-flame.php',
            ['other-plugin/plugin.php']
        ));
    }

    public function test_request_identity_omits_personal_data_by_default(): void
    {
        $identity = Instrumentation::request_identity(
            false,
            false,
            false,
            123,
            '203.0.113.10',
            'Mozilla/5.0 Test'
        );

        $this->assertSame(0, $identity['user_id']);
        $this->assertSame('', $identity['ip_address']);
        $this->assertSame([], $identity['meta']);
    }

    public function test_request_identity_includes_explicit_opt_ins(): void
    {
        $identity = Instrumentation::request_identity(
            true,
            true,
            true,
            123,
            '203.0.113.10',
            'Mozilla/5.0 Test'
        );

        $this->assertSame(123, $identity['user_id']);
        $this->assertSame('203.0.113.10', $identity['ip_address']);
        $this->assertSame('Mozilla/5.0 Test', $identity['meta']['user_agent']);
    }

    public function test_request_identity_truncates_user_agent(): void
    {
        $identity = Instrumentation::request_identity(
            false,
            false,
            true,
            0,
            '',
            str_repeat('a', 600)
        );

        $this->assertSame(500, strlen($identity['meta']['user_agent']));
    }

    public function test_client_ip_uses_only_trusted_headers(): void
    {
        $server = [
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
            'REMOTE_ADDR'          => '203.0.113.10',
        ];

        $this->assertSame('203.0.113.10', Instrumentation::client_ip_from_server($server, ['REMOTE_ADDR']));
    }

    public function test_client_ip_supports_explicit_trusted_proxy_headers(): void
    {
        $server = [
            'HTTP_CF_CONNECTING_IP' => '198.51.100.44',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.45, 198.51.100.46',
            'REMOTE_ADDR'           => '203.0.113.10',
        ];

        $this->assertSame(
            '198.51.100.44',
            Instrumentation::client_ip_from_server($server, ['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'])
        );
        $this->assertSame(
            '198.51.100.45',
            Instrumentation::client_ip_from_server($server, ['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'])
        );
    }

    public function test_client_ip_ignores_malformed_header_values_without_warnings(): void
    {
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $ip = Instrumentation::client_ip_from_server(
                [
                    'HTTP_X_FORWARDED_FOR' => ['198.51.100.45'],
                    'REMOTE_ADDR'          => '203.0.113.10',
                ],
                ['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR']
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame('203.0.113.10', $ip);
        $this->assertSame([], $warnings);
    }

    public function test_cache_meta_reads_public_counters(): void
    {
        $cache = new class {
            public int $cache_hits = 12;
            public int $cache_misses = 3;
        };

        $meta = Instrumentation::cache_meta($cache);

        $this->assertSame(12, $meta['cache_hits']);
        $this->assertSame(3, $meta['cache_misses']);
        $this->assertSame(get_class($cache), $meta['cache_backend']);
    }

    public function test_cache_meta_normalizes_malformed_public_counters_without_warnings(): void
    {
        $cache = new class {
            /** @var mixed */
            public $cache_hits = ['bad'];
            /** @var mixed */
            public $cache_misses = '1e9999';
        };
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $meta = Instrumentation::cache_meta($cache);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(0, $meta['cache_hits']);
        $this->assertSame(0, $meta['cache_misses']);
    }

    public function test_cache_meta_ignores_non_public_counters(): void
    {
        $cache = new class {
            protected int $cache_hits = 12;
            private int $cache_misses = 3;
        };

        $meta = Instrumentation::cache_meta($cache);

        $this->assertSame(0, $meta['cache_hits']);
        $this->assertSame(0, $meta['cache_misses']);
        $this->assertSame(get_class($cache), $meta['cache_backend']);
    }

    public function test_cache_meta_ignores_non_objects(): void
    {
        $this->assertSame([], Instrumentation::cache_meta(null));
    }

    public function test_graphql_registration_skips_db_replacement_until_tier_selection(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $db = new TestDbInstrumentor();
        $graphql = new TestGraphQL(true);
        $other = new TestOtherInstrumentor();
        $savequeries_defined = false;

        $result = Instrumentation::register_instrumentors(
            [$db, $graphql, $other],
            $collector,
            function () use (&$savequeries_defined): void {
                $savequeries_defined = true;
            }
        );

        $this->assertTrue($result['graphql_active']);
        $this->assertTrue($savequeries_defined);
        $this->assertTrue($graphql->registered);
        $this->assertFalse($db->registered);
        $this->assertTrue($other->registered);
    }

    public function test_graphql_registration_does_not_define_savequeries_when_not_required(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $graphql = new TestGraphQL(true, false);
        $savequeries_defined = false;

        $result = Instrumentation::register_instrumentors(
            [$graphql],
            $collector,
            function () use (&$savequeries_defined): void {
                $savequeries_defined = true;
            }
        );

        $this->assertTrue($result['graphql_active']);
        $this->assertFalse($savequeries_defined);
        $this->assertTrue($graphql->registered);
    }

    public function test_db_registers_when_graphql_is_not_applicable(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $db = new TestDbInstrumentor();
        $graphql = new TestGraphQL(false);

        $result = Instrumentation::register_instrumentors([$graphql, $db], $collector);

        $this->assertFalse($result['graphql_active']);
        $this->assertFalse($graphql->registered);
        $this->assertTrue($db->registered);
    }
}

class TestGraphQL extends GraphQL
{
    public bool $registered = false;
    private bool $applicable;
    private bool $requires_savequeries;

    public function __construct(bool $applicable, bool $requires_savequeries = true)
    {
        $this->applicable = $applicable;
        $this->requires_savequeries = $requires_savequeries;
    }

    public function is_applicable(): bool
    {
        return $this->applicable;
    }

    public function register(Collector $collector): void
    {
        $this->registered = true;
    }

    public function requires_savequeries(): bool
    {
        return $this->requires_savequeries;
    }
}

class TestDbInstrumentor extends DbInstrumentor
{
    public bool $registered = false;

    public function __construct()
    {
    }

    public function is_applicable(): bool
    {
        return true;
    }

    public function register(Collector $collector): void
    {
        $this->registered = true;
    }
}

class TestOtherInstrumentor implements Instrumentor
{
    public bool $registered = false;

    public function is_applicable(): bool
    {
        return true;
    }

    public function register(Collector $collector): void
    {
        $this->registered = true;
    }
}
