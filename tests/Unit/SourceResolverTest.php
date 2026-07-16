<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\SourceResolver;
use WPFlame\Collector;

class SourceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    public function test_from_backtrace_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_backtrace($collector);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $result = SourceResolver::from_trace_array($collector, $trace);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_with_empty_trace_returns_wordpress(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_trace_array($collector, []);
        $this->assertSame('wordpress', $result);

        $collector->reset();
    }

    public function test_from_trace_array_skips_malformed_file_values(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_trace_array($collector, [
            ['file' => ['not-a-path']],
            ['file' => null],
        ]);

        $this->assertSame('wordpress', $result);

        $collector->reset();
    }

    public function test_context_returns_bounded_relative_caller_evidence(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));
        $context = SourceResolver::context_from_trace_array($collector, [
            ['file' => WP_CONTENT_DIR . '/plugins/shop/src/Checkout.php', 'line' => 42],
        ]);

        $this->assertSame('plugins/shop/src/Checkout.php', $context['caller_file']);
        $this->assertSame(42, $context['caller_line']);
        $this->assertIsString($context['source']);
    }

    public function test_from_backtrace_bounds_skip_and_depth_arguments(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $this->assertIsString(SourceResolver::from_backtrace($collector, -10, -20));
        $this->assertIsString(SourceResolver::from_backtrace($collector, 1000000, 1000000));

        $collector->reset();
    }

    public function test_from_trace_array_bounds_skip_argument(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $trace = [
            ['file' => '/var/www/html/wp-content/plugins/example/plugin.php'],
        ];

        $this->assertSame('wordpress', SourceResolver::from_trace_array($collector, $trace, 1000000));

        $collector->reset();
    }

    public function test_empty_source_directory_does_not_match_absolute_paths(): void
    {
        $method = new ReflectionMethod(SourceResolver::class, 'path_is_inside_directory');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, '/var/www/html/wp-content/plugins/shop/plugin.php', ''));
    }
}
