<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\CallbackResolver;
use WPFlame\Collector;
use WPFlame\Span;

// Test fixtures
function wp_flame_test_function() { return 'test'; }

class WPFlameTestClass
{
    public function instance_method() { return 'test'; }
    public static function static_method() { return 'test'; }
}

class WPFlameInvocableClass
{
    public function __invoke() { return 'test'; }
}

class CallbackResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CallbackResolver::reset();
        Collector::reset();
    }

    public function test_resolve_name_for_named_function(): void
    {
        $name = CallbackResolver::resolve_name(
            'WPFlame\\Tests\\Unit\\wp_flame_test_function',
            'WPFlame\\Tests\\Unit\\wp_flame_test_function'
        );
        $this->assertSame('WPFlame\\Tests\\Unit\\wp_flame_test_function', $name);
    }

    public function test_resolve_name_for_array_instance_method(): void
    {
        $obj = new WPFlameTestClass();
        $name = CallbackResolver::resolve_name('test-id', [$obj, 'instance_method']);
        $this->assertSame('WPFlameTestClass::instance_method', $name);
    }

    public function test_resolve_name_for_array_static_method(): void
    {
        $name = CallbackResolver::resolve_name('test-id', [WPFlameTestClass::class, 'static_method']);
        $this->assertSame('WPFlameTestClass::static_method', $name);
    }

    public function test_resolve_name_for_string_static_method(): void
    {
        $name = CallbackResolver::resolve_name('test-id', 'WPFlame\\Tests\\Unit\\WPFlameTestClass::static_method');
        $this->assertSame('WPFlameTestClass::static_method', $name);
    }

    public function test_resolve_name_for_closure(): void
    {
        $closure = function () { return 'test'; };
        $name = CallbackResolver::resolve_name('test-id', $closure);

        // Should contain filename and line number
        $this->assertStringContainsString('CallbackResolverTest.php', $name);
        $this->assertMatchesRegularExpression('/:\d+$/', $name);
    }

    public function test_resolve_name_for_invocable_object(): void
    {
        $obj = new WPFlameInvocableClass();
        $name = CallbackResolver::resolve_name('test-id', $obj);
        $this->assertSame('WPFlameInvocableClass::__invoke', $name);
    }

    public function test_resolve_name_for_invalid_callable_falls_back(): void
    {
        $name = CallbackResolver::resolve_name('fallback-id', ['NonExistentClass', 'method']);
        $this->assertSame('fallback-id', $name);
    }

    public function test_resolve_name_caches_result(): void
    {
        $obj = new WPFlameTestClass();
        $first = CallbackResolver::resolve_name('cache-test', [$obj, 'instance_method']);
        $second = CallbackResolver::resolve_name('cache-test', [$obj, 'instance_method']);
        $this->assertSame($first, $second);
    }

    public function test_resolve_source_returns_type_and_source(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $obj = new WPFlameTestClass();
        $source = CallbackResolver::resolve_source('test-id', [$obj, 'instance_method'], $collector);

        $this->assertArrayHasKey('type', $source);
        $this->assertArrayHasKey('source', $source);
        $this->assertIsString($source['type']);
        $this->assertIsString($source['source']);
    }

    public function test_resolve_source_for_invalid_callable_returns_unknown(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $source = CallbackResolver::resolve_source('test-id', ['NonExistentClass', 'method'], $collector);

        $this->assertSame(Span::TYPE_PHP, $source['type']);
        $this->assertSame('unknown', $source['source']);
    }

    public function test_directory_constant_helper_returns_empty_string_for_missing_constants(): void
    {
        $method = new ReflectionMethod(CallbackResolver::class, 'directory_constant');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke(null, 'WP_FLAME_MISSING_CALLBACK_DIRECTORY'));
    }

    public function test_path_check_requires_directory_boundary(): void
    {
        $method = new ReflectionMethod(CallbackResolver::class, 'path_is_inside_directory');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, '/var/www/html/wp-content/plugins/foo/plugin.php', '/var/www/html/wp-content/plugins'));
        $this->assertFalse($method->invoke(null, '/var/www/html/wp-content/plugins-extra/foo.php', '/var/www/html/wp-content/plugins'));
    }

    public function test_reset_clears_caches(): void
    {
        $obj = new WPFlameTestClass();
        CallbackResolver::resolve_name('reset-test', [$obj, 'instance_method']);

        CallbackResolver::reset();

        // After reset, the same call should still work (just re-resolved)
        $name = CallbackResolver::resolve_name('reset-test', [$obj, 'instance_method']);
        $this->assertSame('WPFlameTestClass::instance_method', $name);
    }
}
