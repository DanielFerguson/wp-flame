<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\Config;
use WPFlame\Settings;
use WPFlame\Storage;

class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp_flame_test_options']);
        parent::tearDown();
    }

    /**
     * @dataProvider checkboxFieldProvider
     */
    public function test_boolean_fields_include_hidden_false_value(string $option, string $method): void
    {
        $GLOBALS['wp_flame_test_options'] = [
            $option => false,
        ];

        $html = $this->render_field($method);

        $this->assertStringContainsString(
            '<input type="hidden" name="' . $option . '" value="0">',
            $html
        );
        $this->assertStringContainsString(
            '<input type="checkbox" name="' . $option . '" value="1" >',
            $html
        );
        $this->assertStringNotContainsString("checked='checked'", $html);
    }

    public function test_checked_boolean_field_remains_checked(): void
    {
        $GLOBALS['wp_flame_test_options'] = [
            'wp_flame_track_ips' => true,
        ];

        $html = $this->render_field('render_field_track_ips');

        $this->assertStringContainsString(
            '<input type="checkbox" name="wp_flame_track_ips" value="1"  checked=\'checked\'>',
            $html
        );
    }

    public function test_boolean_field_does_not_check_false_string(): void
    {
        $GLOBALS['wp_flame_test_options'] = [
            'wp_flame_track_ips' => 'false',
        ];

        $html = $this->render_field('render_field_track_ips');

        $this->assertStringContainsString(
            '<input type="checkbox" name="wp_flame_track_ips" value="1" >',
            $html
        );
        $this->assertStringNotContainsString("checked='checked'", $html);
    }

    /**
     * @dataProvider booleanSanitizerProvider
     * @param mixed $value
     */
    public function test_boolean_sanitizer_handles_posted_checkbox_values($value, bool $expected): void
    {
        $this->assertSame($expected, Settings::sanitize_boolean($value));
    }

    public function test_numeric_setting_sanitizers_enforce_bounds(): void
    {
        $this->assertSame(1, Settings::sanitize_sample_rate(0));
        $this->assertSame(Config::MAX_SAMPLE_RATE, Settings::sanitize_sample_rate(Config::MAX_SAMPLE_RATE + 1));

        $this->assertSame(1, Settings::sanitize_retention_days(-7));
        $this->assertSame(Config::MAX_RETENTION_DAYS, Settings::sanitize_retention_days(Config::MAX_RETENTION_DAYS + 1));

        $this->assertSame(Config::MIN_MAX_SPANS, Settings::sanitize_max_spans(0));
        $this->assertSame(Config::MAX_MAX_SPANS, Settings::sanitize_max_spans(Config::MAX_MAX_SPANS + 1));

        $this->assertSame(Config::MIN_MAX_TRACE_BYTES, Settings::sanitize_max_trace_bytes(0));
        $this->assertSame(Config::MAX_MAX_TRACE_BYTES, Settings::sanitize_max_trace_bytes(Config::MAX_MAX_TRACE_BYTES + 1));
    }

    public function test_select_setting_sanitizers_normalize_values(): void
    {
        $stringable_audience = new class() {
            public function __toString(): string
            {
                return 'logged_in';
            }
        };
        $stringable_mode = new class() {
            public function __toString(): string
            {
                return 'deep';
            }
        };

        $this->assertSame('everyone', Settings::sanitize_trace_audience('everyone'));
        $this->assertSame('logged_in', Settings::sanitize_trace_audience($stringable_audience));
        $this->assertSame('admins', Settings::sanitize_trace_audience(['everyone']));
        $this->assertSame('admins', Settings::sanitize_trace_audience(new \stdClass()));

        $this->assertSame('safe', Settings::sanitize_instrumentation_mode('safe'));
        $this->assertSame('deep', Settings::sanitize_instrumentation_mode($stringable_mode));
        $this->assertSame('standard', Settings::sanitize_instrumentation_mode(['deep']));
        $this->assertSame('standard', Settings::sanitize_instrumentation_mode(new \stdClass()));
    }

    public function test_generic_numeric_sanitizers_ignore_malformed_values_without_warnings(): void
    {
        $this->assertSame(0.0, Settings::sanitize_non_negative_float(['bad']));
        $this->assertSame(0.0, Settings::sanitize_non_negative_float('not numeric'));
        $this->assertSame(2.5, Settings::sanitize_non_negative_float('2.5'));

        $this->assertSame(0, Settings::sanitize_non_negative_int(['bad']));
        $this->assertSame(0, Settings::sanitize_non_negative_int(-10));
        $this->assertSame(42, Settings::sanitize_non_negative_int('42'));
    }

    public function test_request_string_ignores_malformed_nonce_values_without_warnings(): void
    {
        $settings = new Settings($this->createMock(Storage::class));
        $method = new ReflectionMethod(Settings::class, 'request_string');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $result = $method->invoke($settings, ['_wpnonce' => ['bad']], '_wpnonce');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $result);
        $this->assertSame([], $warnings);
    }

    public function test_numeric_fields_render_defaults_for_malformed_option_values_without_warnings(): void
    {
        $GLOBALS['wp_flame_test_options'] = [
            'wp_flame_sample_rate'        => ['bad'],
            'wp_flame_retention_days'     => new \stdClass(),
            'wp_flame_min_callback_ms'    => INF,
            'wp_flame_budget_max_ms'      => NAN,
            'wp_flame_budget_max_queries' => '1e9999',
            'wp_flame_max_spans'          => ['bad'],
            'wp_flame_max_trace_bytes'    => new \stdClass(),
        ];

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $html = implode("\n", [
                $this->render_field('render_field_sample_rate'),
                $this->render_field('render_field_retention_days'),
                $this->render_field('render_field_min_callback_ms'),
                $this->render_field('render_field_budget_max_ms'),
                $this->render_field('render_field_budget_max_queries'),
                $this->render_field('render_field_max_spans'),
                $this->render_field('render_field_max_trace_bytes'),
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertStringContainsString('name="wp_flame_sample_rate" value="' . Config::DEFAULT_SAMPLE_RATE . '"', $html);
        $this->assertStringContainsString('name="wp_flame_retention_days" value="' . Config::DEFAULT_RETENTION_DAYS . '"', $html);
        $this->assertStringContainsString('name="wp_flame_min_callback_ms" value="0.5"', $html);
        $this->assertStringContainsString('name="wp_flame_budget_max_ms" value="500"', $html);
        $this->assertStringContainsString('name="wp_flame_budget_max_queries" value="100"', $html);
        $this->assertStringContainsString('name="wp_flame_max_spans" value="' . Config::DEFAULT_MAX_SPANS . '"', $html);
        $this->assertStringContainsString('name="wp_flame_max_trace_bytes" value="' . Config::DEFAULT_MAX_TRACE_BYTES . '"', $html);
    }

    public function test_select_fields_render_defaults_for_malformed_option_values_without_warnings(): void
    {
        $GLOBALS['wp_flame_test_options'] = [
            'wp_flame_trace_audience'       => ['bad'],
            'wp_flame_instrumentation_mode' => new \stdClass(),
        ];

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $audience = $this->render_field('render_field_trace_audience');
            $mode = $this->render_field('render_field_instrumentation_mode');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertStringContainsString('<option value="admins"  selected=\'selected\'>', $audience);
        $this->assertStringContainsString('<option value="standard"  selected=\'selected\'>', $mode);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function checkboxFieldProvider(): array
    {
        return [
            'enabled'            => ['wp_flame_enabled', 'render_field_enabled'],
            'sql'                => ['wp_flame_full_query_text', 'render_field_full_query_text'],
            'http'               => ['wp_flame_full_http_url', 'render_field_full_http_url'],
            'graphql'            => ['wp_flame_full_graphql_query', 'render_field_full_graphql_query'],
            'ips'                => ['wp_flame_track_ips', 'render_field_track_ips'],
            'users'              => ['wp_flame_track_users', 'render_field_track_users'],
            'user agents'        => ['wp_flame_track_user_agent', 'render_field_track_user_agent'],
        ];
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public function booleanSanitizerProvider(): array
    {
        return [
            'posted zero'  => ['0', false],
            'posted one'   => ['1', true],
            'false bool'   => [false, false],
            'true bool'    => [true, true],
            'off string'   => ['off', false],
            'on string'    => ['on', true],
            'false string' => ['false', false],
            'true string'  => ['true', true],
            'empty string' => ['', false],
        ];
    }

    private function render_field(string $method): string
    {
        $settings = new Settings($this->createMock(Storage::class));

        ob_start();
        $settings->{$method}();
        return (string) ob_get_clean();
    }
}
