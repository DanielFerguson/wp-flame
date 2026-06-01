<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Config;

class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_get_returns_default_value(): void
    {
        $config = new Config();
        $result = $config->get('wp_flame_enabled', true);
        $this->assertTrue($result);
    }

    public function test_override_takes_precedence(): void
    {
        $config = new Config();
        $config->set_override('wp_flame_sample_rate', 5);
        $this->assertSame(5, $config->get('wp_flame_sample_rate', 1));
    }

    public function test_override_with_false_value(): void
    {
        $config = new Config();
        $config->set_override('wp_flame_enabled', false);
        $this->assertFalse($config->get('wp_flame_enabled', true));
    }

    public function test_instance_returns_same_object(): void
    {
        Config::reset();
        $a = Config::instance();
        $b = Config::instance();
        $this->assertSame($a, $b);
    }

    public function test_reset_clears_instance(): void
    {
        $a = Config::instance();
        Config::reset();
        $b = Config::instance();
        $this->assertNotSame($a, $b);
    }

    public function test_bounded_int_uses_default_for_non_numeric_values(): void
    {
        $this->assertSame(20, Config::bounded_int('not-numeric', 20, 1, 100));
    }

    public function test_bounded_int_clamps_to_minimum_and_maximum(): void
    {
        $this->assertSame(10, Config::bounded_int(0, 20, 10, 100));
        $this->assertSame(100, Config::bounded_int(999, 20, 10, 100));
    }

    public function test_bounded_int_uses_default_for_non_finite_numeric_values(): void
    {
        $this->assertSame(20, Config::bounded_int(INF, 20, 10, 100));
        $this->assertSame(20, Config::bounded_int(NAN, 20, 10, 100));
        $this->assertSame(20, Config::bounded_int('1e9999', 20, 10, 100));
    }

    public function test_string_value_normalizes_scalars_without_warning(): void
    {
        $this->assertSame('text', Config::string_value('text', 'fallback'));
        $this->assertSame('123', Config::string_value(123, 'fallback'));
        $this->assertSame('1', Config::string_value(true, 'fallback'));
        $this->assertSame('0', Config::string_value(false, 'fallback'));
    }

    public function test_string_value_uses_fallback_for_arrays_and_plain_objects(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $array = Config::string_value(['bad'], 'fallback');
            $object = Config::string_value(new \stdClass(), 'fallback');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('fallback', $array);
        $this->assertSame('fallback', $object);
    }

    /**
     * @dataProvider booleanProvider
     * @param mixed $value
     */
    public function test_boolean_normalizes_common_option_values($value, bool $expected): void
    {
        $this->assertSame($expected, Config::boolean($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public function booleanProvider(): array
    {
        return [
            'database zero'  => ['0', false],
            'database one'   => ['1', true],
            'cli false'     => ['false', false],
            'cli true'      => ['true', true],
            'off'           => ['off', false],
            'on'            => ['on', true],
            'empty'         => ['', false],
            'bool false'    => [false, false],
            'bool true'     => [true, true],
            'array'         => [['1'], false],
            'object'        => [new \stdClass(), false],
        ];
    }
}
