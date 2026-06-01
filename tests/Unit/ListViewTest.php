<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPFlame\Admin\ListView;
use WPFlame\Storage;

class ListViewTest extends TestCase
{
    public function test_filters_from_request_normalizes_valid_scalar_filters(): void
    {
        $filters = $this->filters_from_request([
            's'            => '/shop?token=secret',
            'min_duration' => '50.5',
            'max_duration' => '500',
            'method'       => 'post',
            'user_id'      => '123',
            'ip_address'   => str_repeat('1', 100),
            'grade'        => 'b',
            'orderby'      => 'total_ms',
            'order'        => 'asc',
            'paged'        => '3',
        ]);

        $this->assertSame('/shop?token=secret', $filters['url']);
        $this->assertSame(50.5, $filters['min_duration']);
        $this->assertSame(500.0, $filters['max_duration']);
        $this->assertSame('POST', $filters['method']);
        $this->assertSame(123, $filters['user_id']);
        $this->assertSame(45, strlen($filters['ip_address']));
        $this->assertSame(80, $filters['min_score']);
        $this->assertSame(89, $filters['max_score']);
        $this->assertSame('total_ms', $filters['orderby']);
        $this->assertSame('ASC', $filters['order']);
        $this->assertSame(3, $filters['page']);
        $this->assertSame(20, $filters['per_page']);
    }

    public function test_filters_from_request_bounds_large_search_values(): void
    {
        $filters = $this->filters_from_request([
            's' => '/' . str_repeat('checkout', 400),
        ]);

        $this->assertSame(2048, strlen($filters['url']));
    }

    public function test_filters_from_request_ignores_malformed_array_values_without_warnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $filters = $this->filters_from_request([
                's'            => ['unexpected'],
                'min_duration' => ['10'],
                'method'       => ['GET'],
                'user_id'      => ['1'],
                'paged'        => ['2'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertArrayNotHasKey('url', $filters);
        $this->assertArrayNotHasKey('min_duration', $filters);
        $this->assertArrayNotHasKey('method', $filters);
        $this->assertArrayNotHasKey('user_id', $filters);
        $this->assertSame(1, $filters['page']);
        $this->assertSame(20, $filters['per_page']);
    }

    public function test_filters_from_request_ignores_non_finite_duration_values(): void
    {
        $filters = $this->filters_from_request([
            'min_duration' => '1e9999',
            'max_duration' => 'NaN',
        ]);

        $this->assertArrayNotHasKey('min_duration', $filters);
        $this->assertArrayNotHasKey('max_duration', $filters);
    }

    public function test_filters_from_request_type_overrides_search_filter(): void
    {
        $filters = $this->filters_from_request([
            's'    => '/checkout',
            'type' => 'rest',
        ]);

        $this->assertSame('wp-json', $filters['url']);
    }

    public function test_slowest_callbacks_skips_malformed_trace_fragments(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'get_slowest_callbacks');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $callbacks = $method->invoke($view, 5, [
                ['spans' => 'not-an-array'],
                ['spans' => ['not-a-span']],
                [
                    'spans' => [
                        [
                            'name'        => ['bad'],
                            'duration_ms' => ['bad'],
                            'meta'        => ['hook' => ['bad']],
                        ],
                        [
                            'name'        => 'Plugin::callback',
                            'duration_ms' => 12.5,
                            'meta'        => ['hook' => 'init'],
                        ],
                    ],
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(['Plugin::callback' => 12.5], $callbacks);
    }

    public function test_slowest_callbacks_rejects_non_finite_duration_values(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'get_slowest_callbacks');
        $method->setAccessible(true);

        $callbacks = $method->invoke($view, 5, [
            [
                'spans' => [
                    [
                        'name'        => 'Plugin::bad',
                        'duration_ms' => '1e9999',
                        'meta'        => ['hook' => 'init'],
                    ],
                    [
                        'name'        => 'Plugin::ok',
                        'duration_ms' => 10.0,
                        'meta'        => ['hook' => 'init'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['Plugin::ok' => 10.0, 'Plugin::bad' => 0.0], $callbacks);
    }

    public function test_slowest_callbacks_bounds_callback_names_before_aggregating(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'get_slowest_callbacks');
        $method->setAccessible(true);

        $callbacks = $method->invoke($view, 5, [
            [
                'spans' => [
                    [
                        'name'        => str_repeat('A', 300),
                        'duration_ms' => 10.0,
                        'meta'        => ['hook' => 'init'],
                    ],
                    [
                        'name'        => str_repeat('A', 250),
                        'duration_ms' => 5.0,
                        'meta'        => ['hook' => 'init'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([str_repeat('A', 200) => 15.0], $callbacks);
    }

    public function test_trace_row_normalizes_malformed_storage_values_without_warnings(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'trace_row');
        $method->setAccessible(true);
        $warnings = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $row = $method->invoke($view, [
                'trace_id'    => ['bad'],
                'url'         => new class {
                    public function __toString(): string
                    {
                        return '/checkout';
                    }
                },
                'method'      => ['bad'],
                'user_id'     => '1e9999',
                'ip_address'  => ['bad'],
                'score'       => '1e9999',
                'total_ms'    => '-25',
                'query_count' => ['bad'],
                'peak_memory' => '2048',
                'created_at'  => ['bad'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('', $row['trace_id']);
        $this->assertSame('/checkout', $row['url']);
        $this->assertSame('', $row['method']);
        $this->assertSame(0, $row['user_id']);
        $this->assertSame('', $row['ip_address']);
        $this->assertNull($row['score']);
        $this->assertSame(0.0, $row['total_ms']);
        $this->assertSame(0, $row['query_count']);
        $this->assertSame(2048, $row['peak_memory']);
        $this->assertSame('', $row['created_at']);
    }

    public function test_trace_row_bounds_stored_display_values(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'trace_row');
        $method->setAccessible(true);

        $row = $method->invoke($view, [
            'trace_id'    => str_repeat('t', 300),
            'url'         => '/' . str_repeat('checkout/', 400),
            'method'      => str_repeat('METHOD', 20),
            'user_id'     => 1,
            'ip_address'  => str_repeat('1', 100),
            'score'       => 80,
            'total_ms'    => 10,
            'query_count' => 2,
            'peak_memory' => 2048,
            'created_at'  => str_repeat('2026-06-01 ', 20),
        ]);

        $this->assertSame(128, strlen($row['trace_id']));
        $this->assertSame(2048, strlen($row['url']));
        $this->assertSame(20, strlen($row['method']));
        $this->assertSame(45, strlen($row['ip_address']));
        $this->assertSame(64, strlen($row['created_at']));
    }

    public function test_user_labels_are_bounded_before_rendering(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $name_method = new ReflectionMethod(ListView::class, 'user_display_name');
        $name_method->setAccessible(true);
        $roles_method = new ReflectionMethod(ListView::class, 'user_roles_label');
        $roles_method->setAccessible(true);

        $user = (object) [
            'display_name' => str_repeat('Name', 100),
            'roles'        => [
                str_repeat('administrator', 40),
                str_repeat('editor', 40),
            ],
        ];

        $this->assertSame(200, strlen($name_method->invoke($view, $user, 123)));
        $this->assertSame(200, strlen($roles_method->invoke($view, $user)));
    }

    public function test_distribution_rows_are_normalized_before_dashboard_rendering(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'normalize_distribution');
        $method->setAccessible(true);

        $rows = $method->invoke($view, [
            'not-a-row',
            [
                'label' => ['bad'],
                'min'   => '10.4',
                'max'   => '1e9999',
                'count' => ['bad'],
            ],
        ]);

        $this->assertSame([
            [
                'label' => '>10ms',
                'min'   => 10.4,
                'max'   => 999999.0,
                'count' => 0,
            ],
        ], $rows);
    }

    public function test_dashboard_aggregate_values_are_bounded_before_rendering(): void
    {
        $view = new ListView($this->createMock(Storage::class));
        $stats_method = new ReflectionMethod(ListView::class, 'aggregate_stats');
        $stats_method->setAccessible(true);
        $score_method = new ReflectionMethod(ListView::class, 'average_score');
        $score_method->setAccessible(true);

        $stats = $stats_method->invoke($view, [
            'count'       => ['bad'],
            'avg_ms'      => '-10',
            'avg_queries' => '1e9999',
            'prev_avg_ms' => '-5',
        ]);

        $this->assertSame([
            'count'       => 0,
            'avg_ms'      => 0.0,
            'avg_queries' => 0.0,
            'prev_avg_ms' => null,
        ], $stats);
        $this->assertNull($score_method->invoke($view, '1e9999'));
        $this->assertSame(100.0, $score_method->invoke($view, 150));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function filters_from_request(array $request): array
    {
        $view = new ListView($this->createMock(Storage::class));
        $method = new ReflectionMethod(ListView::class, 'filters_from_request');
        $method->setAccessible(true);

        return $method->invoke($view, $request);
    }
}
