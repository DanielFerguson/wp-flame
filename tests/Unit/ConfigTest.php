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
}
