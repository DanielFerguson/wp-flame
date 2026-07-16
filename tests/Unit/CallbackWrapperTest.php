<?php

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CallbackWrapper;
use WPFlame\Collector;
use WPFlame\Span;

class CallbackWrapperTest extends TestCase
{
    protected function tearDown(): void
    {
        Collector::reset();
    }

    private function make_wrapper($original, float $min_ms = 0.0): CallbackWrapper
    {
        return new CallbackWrapper(
            $original,
            Collector::instance(),
            'test_hook',
            10,
            'test_callback',
            ['type' => Span::TYPE_PLUGIN, 'source' => 'test-plugin'],
            $min_ms
        );
    }

    public function test_invoke_passes_args_to_original(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $received = null;
        $wrapper = $this->make_wrapper(function ($a, $b) use (&$received) {
            $received = [$a, $b];
            return 'result';
        });

        $result = $wrapper('hello', 'world');

        $this->assertSame(['hello', 'world'], $received);
        $this->assertSame('result', $result);
    }

    public function test_invoke_creates_span_above_threshold(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            usleep(2000); // 2ms
            return 'ok';
        }, 0.5);

        $wrapper();

        $trace = $collector->get_trace();
        $plugin_spans = array_filter($trace->spans, fn(Span $s) => $s->type === Span::TYPE_PLUGIN);
        $this->assertGreaterThanOrEqual(1, count($plugin_spans));
    }

    public function test_invoke_discards_span_below_threshold(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            return 'fast';
        }, 100.0); // 100ms threshold — will be below

        $wrapper();

        $trace = $collector->get_trace();
        $this->assertCount(0, $trace->spans);
    }

    public function test_get_original_returns_wrapped_callback(): void
    {
        $original = function () { return 'test'; };
        $wrapper = $this->make_wrapper($original);

        $this->assertSame($original, $wrapper->get_original());
    }

    public function test_invoke_closes_span_on_exception(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = $this->make_wrapper(function () {
            throw new \RuntimeException('test error');
        });

        try {
            $wrapper();
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Span stack should be empty (span was closed by finally block)
        // Verify by starting and ending another span successfully
        $id = $collector->start_span('After exception', Span::TYPE_CORE, 'test');
        $collector->end_span($id);

        $trace = $collector->get_trace();
        $names = array_map(fn($s) => $s->name, $trace->spans);
        $this->assertContains('After exception', $names);
    }

    public function test_span_meta_includes_hook_and_priority(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $wrapper = new CallbackWrapper(
            function () { usleep(2000); },
            $collector,
            'wp_head',
            20,
            'my_callback',
            ['type' => Span::TYPE_PLUGIN, 'source' => 'my-plugin'],
            0.0
        );

        $wrapper();

        $trace = $collector->get_trace();
        $this->assertSame('wp_head', $trace->spans[0]->meta['hook']);
        $this->assertSame(20, $trace->spans[0]->meta['priority']);
        $this->assertSame('my_callback', $trace->spans[0]->meta['callback']);
        $this->assertStringContainsString('CallbackWrapperTest.php', $trace->spans[0]->meta['caller_file']);
        $this->assertGreaterThan(0, $trace->spans[0]->meta['caller_line']);
    }

    public function test_source_containing_wp_flame_is_not_treated_as_self_observation(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));
        $wrapper = new CallbackWrapper(
            static function (): void {},
            $collector,
            'init',
            2,
            'theme_callback',
            ['type' => Span::TYPE_THEME, 'source' => 'child-theme:wp-flame-smoke-child'],
            0.0
        );

        $wrapper();

        $this->assertCount(1, $collector->get_trace()->spans);
        $this->assertSame('child-theme:wp-flame-smoke-child', $collector->get_trace()->spans[0]->source);
    }

    public function test_exact_wp_flame_source_bypasses_self_instrumentation(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));
        $wrapper = new CallbackWrapper(
            static function (): void {},
            $collector,
            'init',
            2,
            'self_callback',
            ['type' => Span::TYPE_PLUGIN, 'source' => 'wp-flame'],
            0.0
        );

        $wrapper();

        $this->assertCount(0, $collector->get_trace()->spans);
    }
}
