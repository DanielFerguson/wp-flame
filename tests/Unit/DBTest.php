<?php

declare(strict_types=1);

namespace {
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

    class WPFlame_Typed_WPDB extends wpdb
    {
        public string $uninitialized;
    }
}

namespace WPFlame\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WPFlame\Collector;
    use WPFlame\DB;
    use WPFlame\Redactor;

    class DBTest extends TestCase
    {
        protected function tearDown(): void
        {
            Collector::reset();
        }

        public function test_from_wpdb_skips_uninitialized_typed_properties(): void
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);

            $db = DB::from_wpdb(new \WPFlame_Typed_WPDB(), $collector);

            $this->assertInstanceOf(DB::class, $db);
            $this->assertSame('wp_', $db->prefix);
        }

        public function test_query_tolerates_non_string_values_before_delegating_to_wpdb(): void
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);

            $original = new \wpdb();
            $db = DB::from_wpdb($original, $collector);
            $this->assertSame(0, $db->query(null));
            $this->assertNull($db->last_query);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('QUERY', $trace->spans[0]->name);
            $this->assertSame('', $trace->spans[0]->meta['query']);
        }

        public function test_query_delegates_original_non_string_value_to_wpdb(): void
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);

            $db = DB::from_wpdb(new \wpdb(), $collector);
            $query = ['unexpected'];
            $this->assertSame(0, $db->query($query));

            $this->assertSame($query, $db->last_query);
            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('QUERY', $trace->spans[0]->name);
            $this->assertSame('', $trace->spans[0]->meta['query']);
        }

        public function test_full_query_text_is_bounded_and_marked_when_truncated(): void
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);

            $db = DB::from_wpdb(new \wpdb(), $collector, true);
            $db->query('SELECT * FROM wp_posts WHERE post_content = "' . str_repeat('x', 10000) . '"');

            $trace = $collector->get_trace();

            $this->assertSame(Redactor::MAX_SQL_LABEL_BYTES, strlen($trace->spans[0]->meta['query']));
            $this->assertTrue($trace->spans[0]->meta['query_truncated']);
            $this->assertFalse($trace->spans[0]->meta['query_redacted']);
        }
    }
}
