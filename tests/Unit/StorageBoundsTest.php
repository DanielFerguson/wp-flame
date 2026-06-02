<?php

declare(strict_types=1);

namespace {
    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (! function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return '2026-06-01 00:00:00';
        }
    }

    if (! class_exists('wpdb')) {
        class wpdb
        {
            public string $prefix = 'wp_';
            /** @var array<int, mixed> */
            public array $prepared_params = [];
            /** @var array<string, mixed> */
            public array $last_insert_data = [];
            /** @var mixed */
            public $last_query = '';
            public string $last_error = '';

            public function prepare($query, ...$args)
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }

                $this->prepared_params = $args;
                return $query;
            }

            public function get_results($query, $output = null)
            {
                $this->last_query = $query;
                return [];
            }

            public function get_col($query)
            {
                $this->last_query = $query;
                return [];
            }

            public function get_row($query)
            {
                $this->last_query = $query;
                return false;
            }

            public function query($query)
            {
                $this->last_query = $query;
                return 0;
            }

            public function insert($table, $data, $formats)
            {
                $this->last_insert_data = $data;
                return 1;
            }
        }
    }

    class WPFlame_TimeBreakdown_WPDB extends wpdb
    {
        /** @var mixed */
        public $row_result = false;
        /** @var mixed */
        public $var_result = null;
        /** @var mixed */
        public $query_result = 0;
        /** @var mixed */
        public $delete_result = 0;

        public function get_row($query, $output = null)
        {
            $this->last_query = $query;
            return $this->row_result;
        }

        public function get_var($query)
        {
            $this->last_query = $query;
            return $this->var_result;
        }

        public function query($query)
        {
            $this->last_query = $query;
            return $this->query_result;
        }

        public function delete($table, $where, $where_format = null)
        {
            return $this->delete_result;
        }
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPFlame\Config;
    use WPFlame\Span;
    use WPFlame\Storage;
    use WPFlame\Trace;

    class StorageBoundsTest extends TestCase
    {
        protected function tearDown(): void
        {
            Config::reset();
            parent::tearDown();
        }

        public function test_list_traces_caps_per_page(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->list_traces([
                'per_page' => 9999,
                'page'     => 1,
            ]);

            $this->assertSame([200, 0], array_slice($wpdb->prepared_params, -2));
        }

        public function test_list_traces_uses_default_for_invalid_per_page(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->list_traces([
                'per_page' => 'not-a-number',
                'page'     => 1,
            ]);

            $this->assertSame([20, 0], array_slice($wpdb->prepared_params, -2));
        }

        public function test_list_traces_caps_page_offset(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->list_traces([
                'per_page' => 20,
                'page'     => 999999999,
            ]);

            $this->assertSame([20, 199980], array_slice($wpdb->prepared_params, -2));
        }

        public function test_list_traces_ignores_malformed_filter_values_without_warnings(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $storage->list_traces([
                    'url'          => ['bad'],
                    'min_duration' => ['10'],
                    'max_duration' => ['100'],
                    'after'        => ['bad'],
                    'before'       => ['bad'],
                    'method'       => ['GET'],
                    'user_id'      => ['1'],
                    'ip_address'   => ['127.0.0.1'],
                    'min_score'    => ['0'],
                    'max_score'    => ['100'],
                    'orderby'      => ['created_at'],
                    'order'        => ['ASC'],
                    'per_page'     => 20,
                    'page'         => 1,
                ]);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([20, 0], $wpdb->prepared_params);
        }

        public function test_list_traces_ignores_non_finite_duration_filters_without_warnings(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $storage->list_traces([
                    'min_duration' => '1e9999',
                    'max_duration' => INF,
                    'per_page'     => 20,
                    'page'         => 1,
                ]);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([20, 0], $wpdb->prepared_params);
        }

        public function test_count_and_purge_results_are_bounded(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->var_result = ['bad'];
            $wpdb->query_result = ['bad'];
            $storage = new Storage($wpdb);

            $this->assertSame(0, $storage->count_traces([]));
            $this->assertSame(0, $storage->purge_all());
        }

        public function test_delete_traces_by_user_result_is_bounded(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $storage = new Storage($wpdb);

            $wpdb->delete_result = -5;
            $this->assertSame(0, $storage->delete_traces_by_user(123));

            $wpdb->delete_result = ['bad'];
            $this->assertSame(0, $storage->delete_traces_by_user(123));
        }

        public function test_prune_old_clamps_days_to_one(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->prune_old(-30);

            $this->assertSame([1], $wpdb->prepared_params);
        }

        public function test_prune_old_caps_days_to_retention_maximum(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->prune_old(Config::MAX_RETENTION_DAYS + 1000);

            $this->assertSame([Config::MAX_RETENTION_DAYS], $wpdb->prepared_params);
        }

        public function test_aggregate_stats_tolerates_missing_rows(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $stats = $storage->get_aggregate_stats();

            $this->assertSame(0.0, $stats['avg_ms']);
            $this->assertSame(0, $stats['count']);
            $this->assertSame(0.0, $stats['avg_queries']);
            $this->assertNull($stats['prev_avg_ms']);
        }

        public function test_aggregate_stats_normalizes_malformed_rows_without_warnings(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'avg_ms'      => '-10',
                'count'       => ['bad'],
                'avg_queries' => '1e9999',
            ];
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $stats = $storage->get_aggregate_stats();
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([
                'avg_ms'      => 0.0,
                'count'       => 0,
                'avg_queries' => 0.0,
                'prev_avg_ms' => 0.0,
            ], $stats);
        }

        public function test_aggregate_stats_caps_days_before_building_sql_intervals(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->get_aggregate_stats(Config::MAX_RETENTION_DAYS + 1000);

            $this->assertSame([Config::MAX_RETENTION_DAYS * 2, Config::MAX_RETENTION_DAYS], $wpdb->prepared_params);
        }

        public function test_slowest_pages_bounds_limit_and_days(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->get_slowest_pages(999, Config::MAX_RETENTION_DAYS + 1000);

            $this->assertSame([Config::MAX_RETENTION_DAYS, 50], $wpdb->prepared_params);
        }

        public function test_average_score_clamps_malformed_and_out_of_range_values(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $storage = new Storage($wpdb);

            $wpdb->var_result = '1e9999';
            $this->assertNull($storage->get_avg_score());

            $wpdb->var_result = '150';
            $this->assertSame(100.0, $storage->get_avg_score());
        }

        public function test_recent_trace_data_bounds_limit_and_trace_blob_size(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->get_recent_trace_data(999, Config::MAX_MAX_TRACE_BYTES + 1000);

            $this->assertStringContainsString('LENGTH(trace_data) <= %d', (string) $wpdb->last_query);
            $this->assertSame([Config::MAX_MAX_TRACE_BYTES, 50], $wpdb->prepared_params);
        }

        public function test_recent_trace_data_uses_defaults_for_malformed_bounds_without_warnings(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $storage->get_recent_trace_data(['bad'], ['bad']);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([Storage::DASHBOARD_MAX_TRACE_BYTES, Storage::DASHBOARD_TRACE_DATA_LIMIT], $wpdb->prepared_params);
        }

        public function test_response_time_distribution_normalizes_malformed_counts_without_warnings(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'b0' => ['bad'],
                'b1' => '1e9999',
                'b2' => '-3',
                'b3' => '4',
            ];
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $distribution = $storage->get_response_time_distribution();
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([0, 0, 0, 4], array_column(array_slice($distribution, 0, 4), 'count'));
        }

        public function test_route_stats_bounds_route_and_days(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);

            $storage->get_route_stats('/' . str_repeat('a', 3000), Config::MAX_RETENTION_DAYS + 1000);

            $this->assertSame(2048, strlen($wpdb->prepared_params[0]));
            $this->assertSame(Config::MAX_RETENTION_DAYS, $wpdb->prepared_params[1]);
        }

        public function test_route_stats_normalizes_malformed_row_values_without_warnings(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'avg_ms'      => '1e9999',
                'min_ms'      => '-5',
                'max_ms'      => '-10',
                'avg_queries' => ['bad'],
                'count'       => '2',
            ];
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $stats = $storage->get_route_stats('/checkout');
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertSame([
                'avg_ms'      => 0.0,
                'min_ms'      => 0.0,
                'max_ms'      => 0.0,
                'avg_queries' => 0.0,
                'count'       => 2,
            ], $stats);
        }

        public function test_storage_stats_normalize_malformed_count_and_size(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'count' => ['bad'],
                'bytes' => '-20',
            ];
            $storage = new Storage($wpdb);

            $this->assertSame([
                'count' => 0,
                'bytes' => 0,
            ], $storage->get_stats());
        }

        public function test_get_trace_normalizes_row_metadata_without_warnings(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'trace_data' => json_encode([
                    'id'             => 'trace-1',
                    'url'            => '/checkout',
                    'method'         => 'GET',
                    'timestamp'      => '2026-06-01T00:00:00+00:00',
                    'total_ms'       => 100.0,
                    'peak_memory'    => 1024,
                    'php_version'    => '8.3',
                    'wp_version'     => '6.7',
                    'query_count'    => 0,
                    'total_query_ms' => 0,
                    'meta'           => [],
                    'spans'          => [],
                ]),
                'user_id'    => '1e9999',
                'ip_address' => ['bad'],
                'score'      => '150',
                'created_at' => ['bad'],
            ];
            $storage = new Storage($wpdb);
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $trace = $storage->get_trace('trace-1');
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $this->assertNotNull($trace);
            $this->assertSame(0, $trace->meta['_row_user_id']);
            $this->assertSame('', $trace->meta['_row_ip_address']);
            $this->assertSame(100, $trace->meta['_row_score']);
            $this->assertSame('', $trace->meta['_row_created_at']);
        }

        public function test_get_trace_refuses_oversized_legacy_trace_json_before_decode(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = (object) [
                'trace_data' => str_repeat('{', Config::MAX_MAX_TRACE_BYTES + 1),
                'user_id'    => 0,
                'ip_address' => '',
                'score'      => null,
                'created_at' => '',
            ];
            $storage = new Storage($wpdb);

            $this->assertNull($storage->get_trace('trace-1'));
        }

        public function test_save_trace_bounds_indexed_column_lengths(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $trace = new Trace(
                'trace-1',
                '/' . str_repeat('a', 3000) . '?token=secret',
                'VERYLONGMETHODNAME',
                '2026-06-01T00:00:00+00:00',
                1.0,
                1024,
                '8.3',
                '6.7',
                [new Span('s1', null, 'Root', Span::TYPE_CORE, 'wordpress', 0, 1)]
            );

            $storage->save_trace($trace, null, 0, str_repeat('1', 100));

            $this->assertSame(2048, strlen($wpdb->last_insert_data['url']));
            $this->assertSame(2048, strlen($wpdb->last_insert_data['url_path']));
            $this->assertSame(10, strlen($wpdb->last_insert_data['method']));
            $this->assertSame(45, strlen($wpdb->last_insert_data['ip_address']));
        }

        public function test_save_trace_clamps_score_to_valid_range(): void
        {
            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $trace = new Trace(
                'trace-1',
                '/test',
                'GET',
                '2026-06-01T00:00:00+00:00',
                1.0,
                1024,
                '8.3',
                '6.7',
                []
            );

            $storage->save_trace($trace, 999);

            $this->assertSame(100, $wpdb->last_insert_data['score']);
        }

        public function test_time_breakdown_clamps_and_normalizes_values(): void
        {
            $wpdb = new \WPFlame_TimeBreakdown_WPDB();
            $wpdb->row_result = [
                'avg_db_ms'   => '40.5',
                'avg_http_ms' => '-10',
                'avg_php_ms'  => '-25',
            ];
            $storage = new Storage($wpdb);

            $breakdown = $storage->get_time_breakdown(Config::MAX_RETENTION_DAYS + 1000);

            $this->assertStringContainsString('GREATEST(total_ms', (string) $wpdb->last_query);
            $this->assertSame([Config::MAX_RETENTION_DAYS], $wpdb->prepared_params);
            $this->assertSame([
                'avg_db_ms'   => 40.5,
                'avg_http_ms' => 0.0,
                'avg_php_ms'  => 0.0,
            ], $breakdown);
        }

        public function test_save_trace_clamps_zero_trace_size_setting_to_bounded_minimum(): void
        {
            Config::instance()->set_override('wp_flame_max_trace_bytes', 0);

            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $trace = new Trace(
                'trace-1',
                '/oversized',
                'GET',
                '2026-06-01T00:00:00+00:00',
                1.0,
                1024,
                '8.3',
                '6.7',
                [
                    new Span('s1', null, str_repeat('A', Config::MIN_MAX_TRACE_BYTES * 2), Span::TYPE_CORE, 'wordpress', 0, 1),
                ]
            );

            $storage->save_trace($trace);

            $this->assertLessThanOrEqual(Config::MIN_MAX_TRACE_BYTES, strlen($wpdb->last_insert_data['trace_data']));

            $stored = json_decode($wpdb->last_insert_data['trace_data'], true);
            $this->assertIsArray($stored);
            $this->assertSame(300, strlen($stored['spans'][0]['name']));
        }

        public function test_save_trace_trims_spans_when_normalized_payload_still_exceeds_storage_limit(): void
        {
            Config::instance()->set_override('wp_flame_max_trace_bytes', 0);

            $spans = [];
            for ($i = 0; $i < 160; $i++) {
                $spans[] = new Span(
                    'span-' . $i,
                    null,
                    str_repeat('N', 1000),
                    Span::TYPE_CORE,
                    str_repeat('source-', 100),
                    (float) $i,
                    1.0,
                    ['detail' => str_repeat('D', 1000)]
                );
            }

            $wpdb = new \wpdb();
            $storage = new Storage($wpdb);
            $trace = new Trace(
                'trace-1',
                '/oversized',
                'GET',
                '2026-06-01T00:00:00+00:00',
                1.0,
                1024,
                '8.3',
                '6.7',
                $spans
            );

            $storage->save_trace($trace);

            $this->assertLessThanOrEqual(Config::MIN_MAX_TRACE_BYTES, strlen($wpdb->last_insert_data['trace_data']));

            $stored = json_decode($wpdb->last_insert_data['trace_data'], true);
            $this->assertIsArray($stored);
            $this->assertTrue($stored['meta']['wp_flame_trace_truncated']);
            $this->assertSame(160, $stored['meta']['wp_flame_original_span_count']);
            $this->assertLessThan(160, $stored['meta']['wp_flame_stored_span_count']);
            $this->assertCount($stored['meta']['wp_flame_stored_span_count'], $stored['spans']);
        }
    }
}
