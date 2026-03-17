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
    use WPFlame\Span;

    class GraphQLTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
        }

        protected function tearDown(): void
        {
            Collector::reset();
            $GLOBALS['wp_flame_test_filters'] = [];
            $GLOBALS['wp_flame_test_options'] = [];
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
        }

        public function test_db_hook_truncates_query_when_full_text_disabled(): void
        {
            $GLOBALS['wp_flame_test_options']['wp_flame_full_query_text'] = false;

            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $callback = $GLOBALS['wp_flame_test_filters']['log_query_custom_data'][0]['callback'];

            $long_query = 'SELECT ' . str_repeat('x', 300) . ' FROM wp_posts';
            $callback([], $long_query, 0.001, '', 1000.0);

            $trace = $collector->get_trace();
            $this->assertSame(200, strlen($trace->spans[0]->meta['query']));
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

            $this->assertArrayHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
        }

        public function test_register_does_not_register_operation_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            $this->assertArrayNotHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayNotHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
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

        // -----------------------------------------------------------------------
        // Tier 2 mode test
        // -----------------------------------------------------------------------

        public function test_tier2_mode_has_db_hooks_but_no_resolver_hooks(): void
        {
            $collector = $this->make_collector();
            $gql = new GraphQL();
            $gql->register($collector);

            // DB hooks are active
            $this->assertArrayHasKey('log_query_custom_data', $GLOBALS['wp_flame_test_filters']);

            // But NO resolver or operation hooks
            $this->assertArrayNotHasKey('graphql_pre_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayNotHasKey('graphql_resolve_field', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayNotHasKey('graphql_process_request', $GLOBALS['wp_flame_test_filters']);
            $this->assertArrayNotHasKey('graphql_return_response', $GLOBALS['wp_flame_test_filters']);
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
