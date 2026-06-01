<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

if (! class_exists('\WP_Hook')) {
    eval('namespace { class WP_Hook { public $callbacks = []; } }');
}

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\CallbackInstrumentor;
use WPFlame\CallbackResolver;
use WPFlame\CallbackWrapper;
use WPFlame\Collector;
use WPFlame\Config;

class CallbackInstrumentorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp_filter']);
        Collector::reset();
        parent::tearDown();
    }

    public function test_wrap_callbacks_noops_when_wp_filter_is_missing(): void
    {
        unset($GLOBALS['wp_filter']);

        $collector = Collector::instance();
        $collector->start_request(1000.0);
        $instrumentor = new CallbackInstrumentor(new Config());
        $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
        $method->setAccessible(true);

        $method->invoke($instrumentor, $collector, 0.5);

        $this->assertTrue(true);
    }

    public function test_wrap_callbacks_skips_callbacks_with_reference_parameters(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $by_ref = static function (&$value): void {
            $value = 'changed';
        };
        $normal = static function ($value): string {
            return strtoupper((string) $value);
        };

        $hook = new \WP_Hook();
        $hook->callbacks = [
            10 => [
                'by_ref' => [
                    'function'      => $by_ref,
                    'accepted_args' => 1,
                ],
                'normal' => [
                    'function'      => $normal,
                    'accepted_args' => 1,
                ],
            ],
        ];
        $GLOBALS['wp_filter'] = [
            'test_hook' => $hook,
        ];

        $instrumentor = new CallbackInstrumentor(new Config());
        $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
        $method->setAccessible(true);

        $method->invoke($instrumentor, $collector, 0.5);

        $this->assertSame($by_ref, $hook->callbacks[10]['by_ref']['function']);
        $this->assertInstanceOf(CallbackWrapper::class, $hook->callbacks[10]['normal']['function']);
    }

    public function test_wrap_callbacks_skips_callbacks_returning_by_reference(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $value = 'original';
        $returns_ref = static function &() use (&$value): string {
            return $value;
        };
        $normal = static function (): string {
            return 'ok';
        };

        $hook = new \WP_Hook();
        $hook->callbacks = [
            10 => [
                'returns_ref' => [
                    'function'      => $returns_ref,
                    'accepted_args' => 0,
                ],
                'normal' => [
                    'function'      => $normal,
                    'accepted_args' => 0,
                ],
            ],
        ];
        $GLOBALS['wp_filter'] = [
            'test_hook' => $hook,
        ];

        $instrumentor = new CallbackInstrumentor(new Config());
        $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
        $method->setAccessible(true);

        $method->invoke($instrumentor, $collector, 0.5);

        $this->assertSame($returns_ref, $hook->callbacks[10]['returns_ref']['function']);
        $this->assertInstanceOf(CallbackWrapper::class, $hook->callbacks[10]['normal']['function']);
    }

    public function test_wrap_callbacks_skips_non_callable_entries(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $hook = new \WP_Hook();
        $hook->callbacks = [
            10 => [
                'missing' => [
                    'function'      => ['Missing_Class', 'method'],
                    'accepted_args' => 1,
                ],
            ],
        ];
        $GLOBALS['wp_filter'] = [
            'test_hook' => $hook,
        ];

        $instrumentor = new CallbackInstrumentor(new Config());
        $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
        $method->setAccessible(true);

        $method->invoke($instrumentor, $collector, 0.5);

        $this->assertSame(['Missing_Class', 'method'], $hook->callbacks[10]['missing']['function']);
    }

    public function test_wrap_callbacks_tolerates_malformed_accepted_args_without_warnings(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $normal = static function ($value): string {
            return strtoupper((string) $value);
        };

        $hook = new \WP_Hook();
        $hook->callbacks = [
            10 => [
                'normal' => [
                    'function'      => $normal,
                    'accepted_args' => ['bad'],
                ],
            ],
        ];
        $GLOBALS['wp_filter'] = [
            'test_hook' => $hook,
        ];

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $instrumentor = new CallbackInstrumentor(new Config());
            $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
            $method->setAccessible(true);
            $method->invoke($instrumentor, $collector, 0.5);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertInstanceOf(CallbackWrapper::class, $hook->callbacks[10]['normal']['function']);
    }

    public function test_wrap_callbacks_skips_malformed_hook_table_entries_without_warnings(): void
    {
        $collector = Collector::instance();
        $collector->start_request(1000.0);

        $normal = static function (): string {
            return 'ok';
        };

        $malformed_hook = new \WP_Hook();
        $malformed_hook->callbacks = 'not-an-array';

        $hook = new \WP_Hook();
        $hook->callbacks = [
            'bad-priority' => 'not-an-array',
            10 => [
                'bad-entry' => 'not-an-array',
                'missing-function' => [
                    'accepted_args' => 0,
                ],
                123 => [
                    'function'      => $normal,
                    'accepted_args' => 0,
                ],
            ],
        ];
        $GLOBALS['wp_filter'] = [
            'malformed_hook' => $malformed_hook,
            456 => $hook,
            'not-a-hook' => 'bad',
        ];

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $instrumentor = new CallbackInstrumentor(new Config());
            $method = new ReflectionMethod(CallbackInstrumentor::class, 'wrap_callbacks');
            $method->setAccessible(true);
            $method->invoke($instrumentor, $collector, 0.5);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertInstanceOf(CallbackWrapper::class, $hook->callbacks[10][123]['function']);
    }

    public function test_callback_resolver_detects_reference_parameters_within_accepted_args(): void
    {
        $by_ref_second = static function ($first, &$second): void {
        };

        $this->assertFalse(CallbackResolver::accepts_reference_parameters($by_ref_second, 1));
        $this->assertTrue(CallbackResolver::accepts_reference_parameters($by_ref_second, 2));
    }

    public function test_callback_resolver_detects_reference_returns(): void
    {
        $value = 'original';
        $returns_ref = static function &() use (&$value): string {
            return $value;
        };
        $normal = static function (): string {
            return 'ok';
        };

        $this->assertTrue(CallbackResolver::returns_reference($returns_ref));
        $this->assertFalse(CallbackResolver::returns_reference($normal));
    }
}
