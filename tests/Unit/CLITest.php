<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static function log($message): void
            {
                $GLOBALS['wp_flame_test_cli_messages'][] = ['log', $message];
            }

            public static function success($message): void
            {
                $GLOBALS['wp_flame_test_cli_messages'][] = ['success', $message];
            }

            public static function error($message): void
            {
                $GLOBALS['wp_flame_test_cli_messages'][] = ['error', $message];
            }

            public static function confirm($message): void
            {
                $GLOBALS['wp_flame_test_cli_messages'][] = ['confirm', $message];
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (! function_exists('WP_CLI\Utils\format_items')) {
        function format_items($format, $items, $fields): void
        {
            $GLOBALS['wp_flame_test_cli_format_items'][] = [$format, $items, $fields];
        }
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPFlame\CLI;
    use WPFlame\Config;
    use WPFlame\Storage;

    class CLITest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['wp_flame_test_cli_messages'], $GLOBALS['wp_flame_test_cli_format_items'], $GLOBALS['wp_flame_test_options']);
            Config::reset();
            parent::tearDown();
        }

        public function test_list_traces_bounds_limit_argument(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['list_traces'])
                ->getMock();

            $storage->expects($this->once())
                ->method('list_traces')
                ->with($this->callback(function (array $filters): bool {
                    return $filters['per_page'] === 200 && $filters['page'] === 1;
                }))
                ->willReturn([]);

            $cli = new CLI($storage);
            $cli->list_traces([], ['limit' => 999999]);

            $this->assertSame([['log', 'No traces found.']], $GLOBALS['wp_flame_test_cli_messages']);
        }

        public function test_list_traces_normalizes_malformed_storage_rows_without_warnings(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['list_traces'])
                ->getMock();

            $storage->expects($this->once())
                ->method('list_traces')
                ->willReturn([
                    [
                        'trace_id'    => ['bad'],
                        'url'         => new class {
                            public function __toString(): string
                            {
                                return '/checkout';
                            }
                        },
                        'method'      => ['bad'],
                        'total_ms'    => '1e9999',
                        'query_count' => ['bad'],
                        'peak_memory' => '-100',
                        'created_at'  => ['bad'],
                    ],
                    'not-a-row',
                ]);

            $warnings = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $cli = new CLI($storage);
                $cli->list_traces([], ['format' => 'json']);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame('json', $GLOBALS['wp_flame_test_cli_format_items'][0][0]);
            $this->assertSame([
                [
                    'trace_id' => '',
                    'url'      => '/checkout',
                    'method'   => '',
                    'duration' => '0ms',
                    'queries'  => 0,
                    'memory'   => '0MB',
                    'date'     => '',
                ],
                [
                    'trace_id' => '',
                    'url'      => '',
                    'method'   => '',
                    'duration' => '0ms',
                    'queries'  => 0,
                    'memory'   => '0MB',
                    'date'     => '',
                ],
            ], $GLOBALS['wp_flame_test_cli_format_items'][0][1]);
        }

        public function test_list_traces_bounds_large_storage_row_strings(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['list_traces'])
                ->getMock();

            $storage->method('list_traces')
                ->willReturn([
                    [
                        'trace_id'    => str_repeat('t', 300),
                        'url'         => '/' . str_repeat('u', 3000),
                        'method'      => str_repeat('M', 100),
                        'total_ms'    => 12.5,
                        'query_count' => 1,
                        'peak_memory' => 1048576,
                        'created_at'  => str_repeat('d', 100),
                    ],
                ]);

            $cli = new CLI($storage);
            $cli->list_traces([], ['format' => 'json']);

            $row = $GLOBALS['wp_flame_test_cli_format_items'][0][1][0];

            $this->assertSame(128, strlen($row['trace_id']));
            $this->assertSame(2048, strlen($row['url']));
            $this->assertSame(20, strlen($row['method']));
            $this->assertSame(64, strlen($row['date']));
        }

        public function test_show_requires_trace_id_without_warning(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get_trace'])
                ->getMock();
            $storage->expects($this->never())->method('get_trace');

            $warnings = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $cli = new CLI($storage);
                $cli->show([], []);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([['error', 'Missing trace ID.']], $GLOBALS['wp_flame_test_cli_messages']);
        }

        public function test_show_bounds_trace_id_before_lookup(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get_trace'])
                ->getMock();

            $storage->expects($this->once())
                ->method('get_trace')
                ->with(str_repeat('t', 128))
                ->willReturn(null);

            $cli = new CLI($storage);
            $cli->show([str_repeat('t', 300)], []);

            $this->assertSame([['error', 'Trace not found: ' . str_repeat('t', 128)]], $GLOBALS['wp_flame_test_cli_messages']);
        }

        public function test_prune_bounds_explicit_days_argument(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['prune_old'])
                ->getMock();

            $storage->expects($this->once())
                ->method('prune_old')
                ->with(Config::MAX_RETENTION_DAYS);

            $cli = new CLI($storage);
            $cli->prune([], ['days' => Config::MAX_RETENTION_DAYS + 1000]);
        }

        public function test_prune_bounds_retention_option_default(): void
        {
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_retention_days' => Config::MAX_RETENTION_DAYS + 1000,
            ];

            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['prune_old'])
                ->getMock();

            $storage->expects($this->once())
                ->method('prune_old')
                ->with(Config::MAX_RETENTION_DAYS);

            $cli = new CLI($storage);
            $cli->prune([], []);
        }
    }
}
