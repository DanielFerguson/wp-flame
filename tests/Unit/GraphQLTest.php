<?php

namespace WPFlame {
    // Track filter registrations for test assertions
    $GLOBALS['wp_flame_test_filters'] = [];

    if (!function_exists('WPFlame\add_filter')) {
        function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            $GLOBALS['wp_flame_test_filters'][$hook_name][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
            return true;
        }
    }

    if (!function_exists('WPFlame\remove_filter')) {
        function remove_filter($hook_name, $callback, $priority = 10): bool
        {
            if (isset($GLOBALS['wp_flame_test_filters'][$hook_name])) {
                foreach ($GLOBALS['wp_flame_test_filters'][$hook_name] as $i => $entry) {
                    if ($entry['callback'] === $callback && $entry['priority'] === $priority) {
                        unset($GLOBALS['wp_flame_test_filters'][$hook_name][$i]);
                        return true;
                    }
                }
            }
            return false;
        }
    }

    if (!function_exists('WPFlame\add_action')) {
        function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            return add_filter($hook_name, $callback, $priority, $accepted_args);
        }
    }

    if (!function_exists('WPFlame\get_option')) {
        function get_option(string $option, $default = false)
        {
            return $GLOBALS['wp_flame_test_options'][$option] ?? $default;
        }
    }
}

namespace WPFlame\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use WPFlame\Collector;
    use WPFlame\GraphQL;
    use WPFlame\Redactor;
    use WPFlame\Span;

    class GraphQLTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
            unset($GLOBALS['wp_flame_test_apply_filters']['graphql_endpoint'], $_SERVER['REQUEST_URI']);
        }

        protected function tearDown(): void
        {
            Collector::reset();
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
            unset($GLOBALS['wp_flame_test_apply_filters']['graphql_endpoint'], $_SERVER['REQUEST_URI']);
        }

        private function make_collector(): Collector
        {
            $collector = Collector::instance();
            $collector->start_request(1000.0);
            return $collector;
        }

        public function test_register_registers_log_query_custom_data_hook(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $this->assertArrayHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['log_query_custom_data']);
        }

        public function test_deactivate_removes_log_query_custom_data_hook(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $this->assertNotEmpty($GLOBALS['wp_flame_test_filters']['log_query_custom_data']);

            $gql->deactivate();

            $remaining = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
            $this->assertEmpty($remaining);
        }

        public function test_deactivate_twice_is_safe(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $gql->deactivate();
            $gql->deactivate(); // Should not throw

            $this->assertTrue(true);
        }

        public function test_db_hook_creates_span_with_correct_timing(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $filters = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
            $this->assertNotEmpty($filters);

            $callback = $filters[0]['callback'];

            $callback(
                [],                          // $query_data
                'SELECT * FROM wp_posts',    // $query
                0.005,                       // $query_time (5ms)
                'some_function',             // $query_callstack
                1000.100                     // $query_start (100ms after request)
            );

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);

            $span = $trace->spans[0];
            $this->assertSame('SELECT', $span->name);
            $this->assertSame(Span::TYPE_DB, $span->type);
            $this->assertEqualsWithDelta(100.0, $span->start_ms, 0.01);
            $this->assertEqualsWithDelta(5.0, $span->duration_ms, 0.01);
            $this->assertStringContainsString('SELECT * FROM wp_posts', $span->meta['query']);
            $this->assertArrayHasKey('query_hash', $span->meta);
            $this->assertTrue($span->meta['query_redacted']);
        }

        public function test_db_hook_uses_generic_name_for_empty_query(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
            $callback([], '', 0.001, '', 1000.0);

            $trace = $collector->get_trace();
            $this->assertSame('QUERY', $trace->spans[0]->name);
        }

        public function test_db_hook_tolerates_non_string_query_values(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
            $callback([], null, 0.001, '', 1000.0);

            $trace = $collector->get_trace();
            $this->assertSame('QUERY', $trace->spans[0]->name);
            $this->assertSame('', $trace->spans[0]->meta['query']);
        }

        public function test_db_hook_tolerates_malformed_timing_values(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
            $callback([], 'SELECT 1', ['invalid'], '', ['invalid']);

            $trace = $collector->get_trace();
            $this->assertSame('SELECT', $trace->spans[0]->name);
            $this->assertSame(0.0, $trace->spans[0]->start_ms);
            $this->assertSame(0.0, $trace->spans[0]->duration_ms);
        }

        public function test_db_hook_normalizes_query_when_full_text_disabled(): void
        {
            $GLOBALS['wp_flame_test_options']['wp_flame_full_query_text'] = false;

            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

            $query = "SELECT * FROM wp_users WHERE user_email = 'customer@example.com' AND ID IN (1,2,3)";
            $callback([], $query, 0.001, '', 1000.0);

            $trace = $collector->get_trace();
            $this->assertSame('SELECT * FROM wp_users WHERE user_email = ? AND ID IN (?)', $trace->spans[0]->meta['query']);
            $this->assertTrue($trace->spans[0]->meta['query_redacted']);
        }

        public function test_db_hook_keeps_full_query_when_setting_enabled(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL(true);
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

            $long_query = 'SELECT ' . str_repeat('x', 300) . ' FROM wp_posts';
            $callback([], $long_query, 0.001, '', 1000.0);

            $trace = $collector->get_trace();
            $this->assertSame(strlen($long_query), strlen($trace->spans[0]->meta['query']));
            $this->assertFalse($trace->spans[0]->meta['query_redacted']);
        }

        public function test_db_hook_bounds_full_query_text_when_setting_enabled(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL(true);
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
            $callback(
                [],
                'SELECT * FROM wp_posts WHERE post_content = "' . str_repeat('x', 10000) . '"',
                0.001,
                '',
                1000.0
            );

            $trace = $collector->get_trace();

            $this->assertSame(Redactor::MAX_SQL_LABEL_BYTES, strlen($trace->spans[0]->meta['query']));
            $this->assertTrue($trace->spans[0]->meta['query_truncated']);
            $this->assertFalse($trace->spans[0]->meta['query_redacted']);
        }

        public function test_no_db_spans_after_deactivation(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

            // First call works
            $callback([], 'SELECT 1', 0.001, '', 1000.0);

            $gql->deactivate();

            // In real WordPress, remove_filter prevents future calls.
            // Our test stub removes it from the array.
            $remaining = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'] ?? [];
            $this->assertEmpty($remaining);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans); // Only the first call
        }

        public function test_activate_wpgraphql_hooks_registers_operation_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $this->assertArrayHasKey('do_graphql_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
        }

        public function test_activate_wpgraphql_hooks_is_idempotent(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $gql->activate_wpgraphql_hooks();
            $gql->activate_wpgraphql_hooks();

            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['do_graphql_request']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['graphql_process_request']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['graphql_return_response']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field']);
            $this->assertCount(1, $GLOBALS['wp_flame_test_filters']['graphql_resolve_field']);
        }

        public function test_register_lazily_registers_endpoint_operation_and_resolver_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $this->assertArrayHasKey('do_graphql_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
        }

        public function test_operation_span_created_and_closed(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            // Simulate graphql_process_request
            $wp_graphql = new class {
                public function get_query(): string { return '{ posts { nodes { title } } }'; }
                public function get_operation_name(): ?string { return 'GetPosts'; }
            };

            $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $process_callback($wp_graphql);

            // Simulate graphql_return_response
            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response = ['data' => ['posts' => []]];
            $result = $response_callback($response);

            // Response must be returned unchanged
            $this->assertSame($response, $result);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('GraphQL: GetPosts', $trace->spans[0]->name);
            $this->assertSame(Span::TYPE_CORE, $trace->spans[0]->type);
            $this->assertSame('wpgraphql', $trace->spans[0]->source);
            $this->assertSame('GetPosts', $trace->spans[0]->meta['graphql_operation']);
            $this->assertArrayHasKey('graphql_query_length', $trace->spans[0]->meta);
            $this->assertArrayNotHasKey('graphql_query', $trace->spans[0]->meta);
        }

        public function test_operation_query_text_requires_opt_in(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL(false, true);
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $wp_graphql = new class {
                public function get_query(): string { return '{ viewer { id email } }'; }
                public function get_operation_name(): ?string { return 'Viewer'; }
            };

            $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $process_callback($wp_graphql);

            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response_callback([]);

            $trace = $collector->get_trace();
            $this->assertSame('{ viewer { id email } }', $trace->spans[0]->meta['graphql_query']);
        }

        public function test_batched_operations_use_a_lifo_span_stack(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $operation = static function (string $name): object {
                return new class($name) {
                    private string $name;
                    public function __construct(string $name) { $this->name = $name; }
                    public function get_query(): string { return '{ viewer { id } }'; }
                    public function get_operation_name(): string { return $this->name; }
                };
            };
            $process = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $respond = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];

            $process($operation('First'));
            $process($operation('Second'));
            $respond([]);
            $respond([]);

            $trace = $collector->get_trace();
            $this->assertCount(2, $trace->spans);
            $this->assertSame('GraphQL: Second', $trace->spans[0]->name);
            $this->assertSame('GraphQL: First', $trace->spans[1]->name);
        }

        public function test_operation_query_text_is_bounded_when_opted_in(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL(false, true);
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $wp_graphql = new class {
                public function get_query(): string { return 'query Huge { posts(where: { search: "' . str_repeat('x', 70000) . '" }) { nodes { id } } }'; }
                public function get_operation_name(): string { return 'Huge'; }
            };

            $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $process_callback($wp_graphql);

            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response_callback([]);

            $trace = $collector->get_trace();

            $this->assertSame(65536, strlen($trace->spans[0]->meta['graphql_query']));
            $this->assertTrue($trace->spans[0]->meta['graphql_query_truncated']);
            $this->assertGreaterThan(65536, $trace->spans[0]->meta['graphql_query_length']);
        }

        public function test_graphql_return_response_returns_response_without_operation_span(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            // Call graphql_return_response without prior graphql_process_request
            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response = ['errors' => [['message' => 'Validation failed']]];
            $result = $response_callback($response);

            $this->assertSame($response, $result);
            $trace = $collector->get_trace();
            $this->assertCount(0, $trace->spans);
        }

        public function test_anonymous_operation_name(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $wp_graphql = new class {
                public function get_query(): string { return '{ posts { nodes { title } } }'; }
                public function get_operation_name(): ?string { return null; }
            };

            $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $process_callback($wp_graphql);

            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response_callback([]);

            $trace = $collector->get_trace();
            $this->assertSame('GraphQL: anonymous', $trace->spans[0]->name);
        }

        public function test_operation_name_is_bounded_before_storage(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $wp_graphql = new class {
                public function get_query(): string { return '{ posts { nodes { title } } }'; }
                public function get_operation_name(): string { return str_repeat('A', 300); }
            };

            $process_callback = $GLOBALS['wp_flame_test_filters']['graphql_process_request'][0]['callback'];
            $process_callback($wp_graphql);

            $response_callback = $GLOBALS['wp_flame_test_filters']['graphql_return_response'][0]['callback'];
            $response_callback([]);

            $trace = $collector->get_trace();
            $this->assertSame('GraphQL: ' . str_repeat('A', 128), $trace->spans[0]->name);
            $this->assertSame(str_repeat('A', 128), $trace->spans[0]->meta['graphql_operation']);
        }

        public function test_activate_wpgraphql_hooks_registers_resolver_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $this->assertArrayHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
        }

        public function test_root_field_creates_resolver_span(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

            $default = null;
            $result_value = ['data' => []];

            $pre_result = $pre_resolve($default, null, [], null, null, 'RootQuery', 'posts', null, null);
            $this->assertNull($pre_result); // Must return $default unchanged

            $resolve_result = $resolve($result_value, null, [], null, null, 'RootQuery', 'posts', null, null);
            $this->assertSame($result_value, $resolve_result); // Must return $result unchanged

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);

            $span = $trace->spans[0];
            $this->assertSame('RootQuery.posts', $span->name);
            $this->assertSame(Span::TYPE_PLUGIN, $span->type);
            $this->assertSame('wpgraphql', $span->source);
            $this->assertSame('graphql:RootQuery.posts', $span->meta['hook']);
            $this->assertSame('RootQuery', $span->meta['type_name']);
            $this->assertSame('posts', $span->meta['field_key']);
        }

        public function test_root_field_names_are_bounded_before_storage(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];
            $long_field = str_repeat('p', 300);

            $pre_resolve(null, null, [], null, null, 'RootQuery', $long_field, null, null);
            $resolve(['data' => []], null, [], null, null, 'RootQuery', $long_field, null, null);

            $trace = $collector->get_trace();
            $bounded_field = str_repeat('p', 128);
            $this->assertSame('RootQuery.' . $bounded_field, $trace->spans[0]->name);
            $this->assertSame($bounded_field, $trace->spans[0]->meta['field_key']);
            $this->assertSame('graphql:RootQuery.' . $bounded_field, $trace->spans[0]->meta['hook']);
        }

        public function test_callable_root_resolver_records_callback_and_source(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];
            $resolver = static function (): array { return []; };

            $pre_resolve(null, null, [], null, null, 'RootQuery', 'products', null, $resolver);
            $resolve([], null, [], null, null, 'RootQuery', 'products', null, $resolver);

            $span = $collector->get_trace()->spans[0];
            $this->assertArrayHasKey('callback', $span->meta);
            $this->assertNotSame('wpgraphql', $span->source);
        }

        public function test_non_root_field_does_not_create_span(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

            $pre_resolve(null, null, [], null, null, 'Post', 'title', null, null);
            $resolve('Hello World', null, [], null, null, 'Post', 'title', null, null);

            $trace = $collector->get_trace();
            $this->assertCount(0, $trace->spans);
        }

        public function test_aliased_root_fields_resolve_correctly_via_stack(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

            $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);
            $resolve(['first batch'], null, [], null, null, 'RootQuery', 'posts', null, null);

            $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);
            $resolve(['second batch'], null, [], null, null, 'RootQuery', 'posts', null, null);

            $trace = $collector->get_trace();
            $this->assertCount(2, $trace->spans);
            $this->assertSame('RootQuery.posts', $trace->spans[0]->name);
            $this->assertSame('RootQuery.posts', $trace->spans[1]->name);
            $this->assertNotSame($trace->spans[0]->id, $trace->spans[1]->id);
        }

        public function test_root_mutation_creates_span(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];

            $pre_resolve(null, null, [], null, null, 'RootMutation', 'createPost', null, null);
            $resolve(['id' => 1], null, [], null, null, 'RootMutation', 'createPost', null, null);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('RootMutation.createPost', $trace->spans[0]->name);
        }

        public function test_resolver_hooks_ignore_malformed_type_and_field_values_without_warnings(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];
            $warnings = [];

            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $pre_result = $pre_resolve('default', null, [], null, null, ['RootQuery'], ['posts'], null, null);
                $resolve_result = $resolve('result', null, [], null, null, ['RootQuery'], ['posts'], null, null);
            } finally {
                restore_error_handler();
            }

            $this->assertSame('default', $pre_result);
            $this->assertSame('result', $resolve_result);
            $this->assertSame([], $warnings);
            $this->assertCount(0, $collector->get_trace()->spans);
        }

        // -----------------------------------------------------------------------
        // URL heuristic tests
        // -----------------------------------------------------------------------

        public function test_url_heuristic_matches_graphql_endpoint(): void
        {
            $this->assertTrue(GraphQL::is_graphql_endpoint('/graphql'));
            $this->assertTrue(GraphQL::is_graphql_endpoint('/wp/graphql'));
            $this->assertTrue(GraphQL::is_graphql_endpoint('/index.php/graphql'));
        }

        public function test_url_heuristic_rejects_non_graphql_urls(): void
        {
            $this->assertFalse(GraphQL::is_graphql_endpoint('/my-page/graphql-tools'));
            $this->assertFalse(GraphQL::is_graphql_endpoint('/docs/graphql-api'));
            $this->assertFalse(GraphQL::is_graphql_endpoint('/'));
            $this->assertFalse(GraphQL::is_graphql_endpoint('/wp-admin/'));
            $this->assertFalse(GraphQL::is_graphql_endpoint(''));
        }

        public function test_url_heuristic_custom_endpoint(): void
        {
            $this->assertTrue(GraphQL::is_graphql_endpoint('/api', 'api'));
            $this->assertTrue(GraphQL::is_graphql_endpoint('/api', '/api'));
            $this->assertFalse(GraphQL::is_graphql_endpoint('/api', ''));
            $this->assertFalse(GraphQL::is_graphql_endpoint('/graphql', 'api'));
        }

        public function test_url_heuristic_trailing_slash_handled_by_caller(): void
        {
            // wp-flame.php calls rtrim($path, '/') before passing to is_graphql_endpoint.
            // Verify the rtrim + method combination works for trailing-slash URLs:
            $path_with_slash = rtrim('/graphql/', '/');
            $this->assertTrue(GraphQL::is_graphql_endpoint($path_with_slash));

            $nested_with_slash = rtrim('/wp/graphql/', '/');
            $this->assertTrue(GraphQL::is_graphql_endpoint($nested_with_slash));
        }

        public function test_is_applicable_falls_back_when_endpoint_filter_returns_malformed_value(): void
        {
            $_SERVER['REQUEST_URI'] = '/graphql';
            $GLOBALS['wp_flame_test_apply_filters']['graphql_endpoint'][] = function (): array {
                return ['invalid'];
            };

            $this->assertTrue((new GraphQL())->is_applicable());
        }

        public function test_is_applicable_tolerates_malformed_request_uri(): void
        {
            $_SERVER['REQUEST_URI'] = ['invalid'];

            $this->assertFalse((new GraphQL())->is_applicable());
        }

        public function test_is_applicable_bounds_custom_endpoint_filter_values(): void
        {
            $_SERVER['REQUEST_URI'] = '/graphql';
            $GLOBALS['wp_flame_test_apply_filters']['graphql_endpoint'][] = function (): string {
                return str_repeat('custom-endpoint-', 100);
            };

            $this->assertFalse((new GraphQL())->is_applicable());
        }

        public function test_is_applicable_bounds_request_path_before_endpoint_matching(): void
        {
            $_SERVER['REQUEST_URI'] = '/' . str_repeat('a', 3000) . '/graphql';

            $this->assertFalse((new GraphQL())->is_applicable());
        }

        // -----------------------------------------------------------------------
        // Current endpoint mode
        // -----------------------------------------------------------------------

        public function test_endpoint_mode_registers_db_operation_and_resolver_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            // DB hooks are active
            $this->assertArrayHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);

            $this->assertArrayHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('do_graphql_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
        }

        public function test_db_hooks_are_skipped_when_db_capture_is_disabled(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL(false, false, false);
            $gql->register($collector);

            $this->assertArrayNotHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);
        }

        // -----------------------------------------------------------------------
        // DB span parent-child with resolver as parent
        // -----------------------------------------------------------------------

        public function test_db_span_parent_is_resolver_span_when_on_stack(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);
            $gql->activate_wpgraphql_hooks();

            // Start a resolver span (simulating graphql_pre_resolve_field)
            $pre_resolve = $GLOBALS['wp_flame_test_filters']['graphql_pre_resolve_field'][0]['callback'];
            $pre_resolve(null, null, [], null, null, 'RootQuery', 'posts', null, null);

            // Simulate a DB query during resolver execution
            $db_callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];
            $db_callback([], 'SELECT * FROM wp_posts', 0.003, '', 1000.050);

            // End the resolver span
            $resolve = $GLOBALS['wp_flame_test_filters']['graphql_resolve_field'][0]['callback'];
            $resolve(['data'], null, [], null, null, 'RootQuery', 'posts', null, null);

            $trace = $collector->get_trace();
            $this->assertCount(2, $trace->spans);

            // Find spans by type
            $db_span = null;
            $resolver_span = null;
            foreach ($trace->spans as $span) {
                if ($span->type === Span::TYPE_DB) { $db_span = $span; }
                if ($span->type === Span::TYPE_PLUGIN) { $resolver_span = $span; }
            }

            $this->assertNotNull($db_span);
            $this->assertNotNull($resolver_span);
            $this->assertSame($resolver_span->id, $db_span->parent_id);
        }
    }
}
