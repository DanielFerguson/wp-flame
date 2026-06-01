<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\Admin\FlameGraphView;
use WPFlame\Span;
use WPFlame\Storage;
use WPFlame\Trace;

class FlameGraphViewTest extends TestCase
{
    public function test_inline_trace_json_hex_escapes_script_breakouts(): void
    {
        $trace = new Trace(
            'trace-1',
            '/test',
            'GET',
            '2026-06-01T00:00:00+00:00',
            1.0,
            1024,
            '8.3',
            '6.7',
            [
                new Span(
                    's1',
                    null,
                    '</script><script>alert(1)</script>',
                    Span::TYPE_PLUGIN,
                    'plugin',
                    0,
                    1
                ),
            ]
        );

        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'encode_trace_for_script');
        $method->setAccessible(true);

        $json = $method->invoke($view, $trace);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $json);
    }

    public function test_cache_summary_normalizes_malformed_meta_without_warnings(): void
    {
        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'cache_summary');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $summary = $method->invoke($view, [
                'cache_hits'    => ['bad'],
                'cache_misses'  => ['bad'],
                'cache_backend' => ['bad'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame([
            'hits'    => 0,
            'total'   => 0,
            'ratio'   => 0,
            'backend' => 'In-Memory',
        ], $summary);
    }

    public function test_route_stats_normalizes_malformed_storage_values_without_warnings(): void
    {
        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'route_stats');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $stats = $method->invoke($view, [
                'avg_ms' => '1e9999',
                'min_ms' => ['bad'],
                'max_ms' => '-10',
                'count'  => ['bad'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame([
            'avg_ms' => 0.0,
            'min_ms' => 0.0,
            'max_ms' => 0.0,
            'count'  => 0,
        ], $stats);
        $this->assertNull($method->invoke($view, 'not-a-row'));
    }

    public function test_request_context_normalizes_malformed_meta_without_warnings(): void
    {
        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'request_context');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $context = $method->invoke($view, [
                '_row_user_id'    => ['bad'],
                '_row_ip_address' => ['bad'],
                'user_agent'      => new \stdClass(),
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame([
            'user_id'    => 0,
            'ip_address' => '',
            'user_agent' => '',
        ], $context);
    }

    public function test_cache_summary_caps_pathological_totals(): void
    {
        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'cache_summary');
        $method->setAccessible(true);

        $summary = $method->invoke($view, [
            'cache_hits'   => PHP_INT_MAX,
            'cache_misses' => PHP_INT_MAX,
        ]);

        $this->assertSame(PHP_INT_MAX, $summary['total']);
        $this->assertSame(50, $summary['ratio']);
    }

    public function test_cache_summary_shortens_persistent_backend_name(): void
    {
        $view = new FlameGraphView($this->createMock(Storage::class));
        $method = new ReflectionMethod(FlameGraphView::class, 'cache_summary');
        $method->setAccessible(true);

        $summary = $method->invoke($view, [
            'cache_hits'    => 80,
            'cache_misses'  => 20,
            'cache_backend' => 'Redis_Object_Cache',
        ]);

        $this->assertSame([
            'hits'    => 80,
            'total'   => 100,
            'ratio'   => 80,
            'backend' => 'Redis',
        ], $summary);
    }
}
