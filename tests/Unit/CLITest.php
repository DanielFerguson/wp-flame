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

            public static function print_value($data, $args = []): void
            {
                $GLOBALS['wp_flame_test_cli_print_value'][] = [$data, $args];
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
            unset(
                $GLOBALS['wp_flame_test_cli_messages'],
                $GLOBALS['wp_flame_test_cli_format_items'],
                $GLOBALS['wp_flame_test_cli_print_value'],
                $GLOBALS['wp_flame_test_options']
            );
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

        public function test_list_traces_falls_back_for_malformed_format_argument(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['list_traces'])
                ->getMock();

            $storage->method('list_traces')
                ->willReturn([
                    [
                        'trace_id'    => 'trace-1',
                        'url'         => '/checkout',
                        'method'      => 'GET',
                        'total_ms'    => 12.5,
                        'query_count' => 1,
                        'peak_memory' => 1048576,
                        'created_at'  => '2026-06-02 00:00:00',
                    ],
                ]);

            $cli = new CLI($storage);
            $cli->list_traces([], ['format' => ['bad']]);

            $this->assertSame('table', $GLOBALS['wp_flame_test_cli_format_items'][0][0]);
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

        public function test_show_falls_back_to_json_for_unsupported_format(): void
        {
            $trace = new \WPFlame\Trace(
                'trace-1',
                '/checkout',
                'GET',
                '2026-06-02T00:00:00+00:00',
                12.5,
                1024,
                '8.3',
                '6.7',
                []
            );

            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get_trace'])
                ->getMock();

            $storage->method('get_trace')->willReturn($trace);

            $cli = new CLI($storage);
            $cli->show(['trace-1'], ['format' => 'table']);

            $this->assertArrayNotHasKey('wp_flame_test_cli_print_value', $GLOBALS);
            $this->assertSame('log', $GLOBALS['wp_flame_test_cli_messages'][0][0]);
            $this->assertStringContainsString('"id": "trace-1"', $GLOBALS['wp_flame_test_cli_messages'][0][1]);
        }

        public function test_prune_bounds_explicit_days_argument(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['run_retention_cleanup'])
                ->getMock();

            $storage->expects($this->once())
                ->method('run_retention_cleanup')
                ->with(Config::MAX_RETENTION_DAYS, 5000)
                ->willReturn(['deleted' => 42, 'backlog' => false]);

            $cli = new CLI($storage);
            $cli->prune([], ['days' => Config::MAX_RETENTION_DAYS + 1000]);

            $this->assertSame(
                [['success', 'Pruned 42 trace(s) older than ' . Config::MAX_RETENTION_DAYS . ' day(s).']],
                $GLOBALS['wp_flame_test_cli_messages']
            );
        }

        public function test_prune_bounds_retention_option_default(): void
        {
            $GLOBALS['wp_flame_test_options'] = [
                'wp_flame_retention_days' => Config::MAX_RETENTION_DAYS + 1000,
            ];

            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['run_retention_cleanup'])
                ->getMock();

            $storage->expects($this->once())
                ->method('run_retention_cleanup')
                ->with(Config::MAX_RETENTION_DAYS, 5000)
                ->willReturn(['deleted' => Storage::PRUNE_BATCH_LIMIT, 'backlog' => true]);

            $cli = new CLI($storage);
            $cli->prune([], []);

            $this->assertSame(
                [
                    ['success', 'Pruned ' . Storage::PRUNE_BATCH_LIMIT . ' trace(s) older than ' . Config::MAX_RETENTION_DAYS . ' day(s).'],
                    ['log', 'Expired traces remain. Rerun with --until-complete or increase --max-runs.'],
                ],
                $GLOBALS['wp_flame_test_cli_messages']
            );
        }

        public function test_migrate_until_complete_runs_bounded_steps(): void
        {
            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['maybe_upgrade'])
                ->getMock();

            $storage->expects($this->exactly(2))
                ->method('maybe_upgrade')
                ->willReturnOnConsecutiveCalls(
                    ['status' => 'pending', 'current' => 1, 'target' => 4, 'processed' => 500, 'message' => 'more'],
                    ['status' => 'complete', 'current' => 4, 'target' => 4, 'processed' => 10, 'message' => '']
                );

            $cli = new CLI($storage);
            $cli->migrate([], ['until-complete' => true, 'max-runs' => 2]);

            $this->assertSame(
                [['success', 'WP Flame schema is current at version 4. Processed 510 legacy row(s).']],
                $GLOBALS['wp_flame_test_cli_messages']
            );
        }

        public function test_support_bundle_is_redacted_by_default(): void
        {
            $GLOBALS['wp_flame_test_options'] = [
                'active_plugins'                       => [ 'store/store.php', 'builder/builder.php' ],
                'wp_flame_schema_version'              => Storage::SCHEMA_VERSION,
                'wp_flame_enabled'                     => true,
                'wp_flame_instrumentation_mode'        => 'standard',
                'wp_flame_sensitive_data_acknowledged' => true,
                'wp_flame_mu_plugin_state'             => 'current',
            ];

            $storage = $this->getMockBuilder(Storage::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get_storage_health', 'migration_health', 'rollup_health', 'table_health', 'persistence_health'])
                ->getMock();
            $storage->method('get_storage_health')->willReturn([
                'count' => 12,
                'bytes' => 3456,
                'quota' => ['reached' => false],
            ]);
            $storage->method('migration_health')->willReturn([
                'status' => 'complete',
                'current' => Storage::SCHEMA_VERSION,
                'target' => Storage::SCHEMA_VERSION,
            ]);
            $storage->method('rollup_health')->willReturn(['pending' => 2]);
            $storage->method('table_health')->willReturn(['status' => 'ready', 'message' => 'secret-path']);
            $storage->method('persistence_health')->willReturn([
                'count' => 0,
                'last_status' => 'stored',
                'last_at' => '2026-07-16 00:00:00',
            ]);

            $cli = new CLI($storage);
            $cli->support_bundle([], []);

            $payload = json_decode($GLOBALS['wp_flame_test_cli_messages'][0][1], true);
            $this->assertIsArray($payload);
            $this->assertSame('wp-flame-support.v1', $payload['schema']);
            $this->assertTrue($payload['redacted']);
            $this->assertFalse($payload['components']['details_included']);
            $this->assertSame(2, $payload['components']['active_plugin_count']);
            $this->assertFalse($payload['external_requests']['licensing_present']);
            $encoded = json_encode($payload);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('store/store.php', $encoded);
            $this->assertStringNotContainsString('secret-path', $encoded);
            foreach (['site_url', 'trace_rows', 'request_paths', 'sql', 'http_urls', 'user_ids', 'ip_addresses', 'license_data'] as $excluded) {
                $this->assertContains($excluded, $payload['excludes']);
            }
        }
    }
}
