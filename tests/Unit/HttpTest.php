<?php

// WordPress function stubs for unit testing (no WordPress loaded).
//
// add_filter() is defined in the WPFlame namespace because Http.php calls it
// without a leading backslash — PHP resolves unqualified function calls to the
// current namespace first, then falls back to global.
//
// wp_remote_retrieve_response_code() is a global WordPress function, so it is
// defined in the global namespace. Http.php calls function_exists() with a plain
// string, which always checks the global namespace.

namespace WPFlame {
    if (!function_exists('WPFlame\add_filter')) {
        function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            $GLOBALS['wp_flame_test_filters'][$hook_name][] = [
                'callback'      => $callback,
                'priority'      => $priority,
                'accepted_args' => $accepted_args,
            ];
            $GLOBALS['wp_flame_http_hook_registrations'][] = [
                'type'          => 'filter',
                'hook'          => $hook_name,
                'priority'      => $priority,
                'accepted_args' => $accepted_args,
            ];

            return true;
        }
    }

    if (!function_exists('WPFlame\add_action')) {
        function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            $GLOBALS['wp_flame_test_filters'][$hook_name][] = [
                'callback'      => $callback,
                'priority'      => $priority,
                'accepted_args' => $accepted_args,
            ];
            $GLOBALS['wp_flame_http_hook_registrations'][] = [
                'type'          => 'action',
                'hook'          => $hook_name,
                'priority'      => $priority,
                'accepted_args' => $accepted_args,
            ];

            return true;
        }
    }
}

namespace {
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            if (is_array($response) && isset($response['response']['code'])) {
                return $response['response']['code'];
            }
            return 0;
        }
    }
}

namespace WPFlame\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use WPFlame\Collector;
    use WPFlame\Http;
    use WPFlame\Span;

    class HttpTest extends TestCase
    {
        protected function tearDown(): void
        {
            Collector::reset();
            unset($GLOBALS['wp_flame_test_filters']);
            unset($GLOBALS['wp_flame_http_hook_registrations']);
        }

        private function make_http(): Http
        {
            $http = new Http();
            $http->register( Collector::instance() );
            return $http;
        }

        public function test_registers_http_hooks_at_last_priority_to_reduce_late_preempt_leaks(): void
        {
            $GLOBALS['wp_flame_test_filters'] = [];

            $http = new Http();
            $http->register(Collector::instance());

            $this->assertSame(PHP_INT_MAX, $GLOBALS['wp_flame_test_filters']['pre_http_request'][0]['priority']);
            $this->assertSame(3, $GLOBALS['wp_flame_test_filters']['pre_http_request'][0]['accepted_args']);
            $this->assertSame(PHP_INT_MAX, $GLOBALS['wp_flame_test_filters']['http_response'][0]['priority']);
            $this->assertSame(3, $GLOBALS['wp_flame_test_filters']['http_response'][0]['accepted_args']);
            $this->assertSame(PHP_INT_MAX, $GLOBALS['wp_flame_test_filters']['http_api_debug'][0]['priority']);
            $this->assertSame(5, $GLOBALS['wp_flame_test_filters']['http_api_debug'][0]['accepted_args']);
        }

        public function test_on_pre_request_creates_span_and_returns_preempt_unchanged(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $preempt = false;
            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/v1/endpoint';

            $result = $http->on_pre_request($preempt, $parsed_args, $url);

            // Must return $preempt unchanged
            $this->assertSame($preempt, $result);

            // A span should now be open on the stack — end it and check
            $trace_before_end = $collector->get_trace();
            // No completed spans yet because the span is still open
            $this->assertCount(0, $trace_before_end->spans);
        }

        public function test_on_pre_request_creates_http_type_span(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'POST'];
            $url = 'https://stripe.com/v1/charges';

            $http->on_pre_request(false, $parsed_args, $url);

            // Close the span via on_response
            $response = ['response' => ['code' => 200]];
            $http->on_response($response, $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);

            $span = $trace->spans[0];
            $this->assertSame(Span::TYPE_HTTP, $span->type);
            $this->assertSame('HTTP stripe.com', $span->name);
            $this->assertSame('stripe.com', $span->meta['host']);
            $this->assertArrayNotHasKey('url', $span->meta);
            $this->assertSame('POST', $span->meta['method']);
        }

        public function test_on_response_ends_span_and_returns_response_unchanged(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/status';
            $response = ['response' => ['code' => 200], 'body' => 'OK'];

            $http->on_pre_request(false, $parsed_args, $url);
            $result = $http->on_response($response, $parsed_args, $url);

            // Must return $response unchanged
            $this->assertSame($response, $result);

            // Span should now be completed
            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame(200, $trace->spans[0]->meta['status']);
        }

        public function test_on_response_includes_status_in_span_meta(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/data';
            $response = ['response' => ['code' => 404]];

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response($response, $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame(404, $trace->spans[0]->meta['status']);
            $this->assertSame('api.example.com', $trace->spans[0]->meta['host']);
            $this->assertArrayNotHasKey('url', $trace->spans[0]->meta);
            $this->assertSame('GET', $trace->spans[0]->meta['method']);
        }

        public function test_on_response_with_no_pending_span_is_handled_gracefully(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/endpoint';
            $response = ['response' => ['code' => 200]];

            // Call on_response without a prior on_pre_request — should not throw
            $result = $http->on_response($response, $parsed_args, $url);

            // Must still return $response unchanged
            $this->assertSame($response, $result);

            // No spans should have been created or corrupted
            $trace = $collector->get_trace();
            $this->assertCount(0, $trace->spans);
        }

        public function test_on_pre_request_returns_preempt_true_unchanged(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            // Simulate another plugin short-circuiting the request
            $preempt = ['body' => 'cached', 'response' => ['code' => 200]];
            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/cached';

            $result = $http->on_pre_request($preempt, $parsed_args, $url);

            // Must return $preempt exactly as-is (not modify it)
            $this->assertSame($preempt, $result);

            $trace = $collector->get_trace();
            $this->assertCount(0, $trace->spans);
        }

        public function test_full_url_can_be_recorded_when_enabled(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = new Http(true);
            $http->register($collector);

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/private?token=secret';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response(['response' => ['code' => 200]], $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertSame($url, $trace->spans[0]->meta['url']);
            $this->assertSame('api.example.com', $trace->spans[0]->meta['host']);
        }

        public function test_full_url_capture_is_bounded(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = new Http(true);
            $http->register($collector);

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/' . str_repeat('path-segment/', 300);

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response(['response' => ['code' => 200]], $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertLessThanOrEqual(2048, strlen($trace->spans[0]->meta['url']));
            $this->assertSame('api.example.com', $trace->spans[0]->meta['host']);
        }

        public function test_url_without_host_defaults_to_unknown(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'not-a-valid-url';
            $response = ['response' => ['code' => 0]];

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response($response, $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('HTTP unknown', $trace->spans[0]->name);
        }

        public function test_malformed_url_defaults_to_unknown_host(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'http://';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response(['response' => ['code' => 0]], $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('HTTP unknown', $trace->spans[0]->name);
        }

        public function test_malformed_filter_inputs_do_not_break_http_tracking(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $warnings = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING || $errno === E_NOTICE) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $http->on_pre_request(false, ['method' => ['bad']], ['bad-url']);
                $http->on_response(['response' => ['code' => 200]], ['method' => ['bad']], ['bad-url']);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $warnings);
            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame('HTTP unknown', $trace->spans[0]->name);
            $this->assertSame('GET', $trace->spans[0]->meta['method']);
        }

        public function test_reentrant_same_url_requests_keep_both_spans(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/reentrant';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_response(['response' => ['code' => 200]], $parsed_args, $url);
            $http->on_response(['response' => ['code' => 201]], $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(2, $trace->spans);
            $this->assertSame(200, $trace->spans[0]->meta['status']);
            $this->assertSame(201, $trace->spans[1]->meta['status']);
        }

        public function test_http_debug_ignores_non_response_contexts(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/data';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_http_debug(null, 'transport_internal', 'WP_Http_Curl', $parsed_args, $url);

            $trace = $collector->get_trace();
            $this->assertCount(0, $trace->spans);

            $http->on_response(['response' => ['code' => 200]], $parsed_args, $url);
            $trace = $collector->get_trace();
            $this->assertCount(1, $trace->spans);
            $this->assertSame(200, $trace->spans[0]->meta['status']);
        }

        public function test_http_error_message_is_omitted_by_default(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = $this->make_http();

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/private?token=secret';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_http_debug(
                new \WP_Error('http_request_failed', 'Failed for https://api.example.com/private?token=secret'),
                'response',
                'WP_Http_Curl',
                $parsed_args,
                $url
            );

            $trace = $collector->get_trace();
            $this->assertSame(0, $trace->spans[0]->meta['status']);
            $this->assertSame('http_request_failed', $trace->spans[0]->meta['http_error_code']);
            $this->assertArrayNotHasKey('http_error', $trace->spans[0]->meta);
        }

        public function test_http_error_message_requires_full_url_opt_in(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = new Http(true);
            $http->register($collector);

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/private?token=secret';
            $message = 'Failed for https://api.example.com/private?token=secret';

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_http_debug(
                new \WP_Error('http_request_failed', $message),
                'response',
                'WP_Http_Curl',
                $parsed_args,
                $url
            );

            $trace = $collector->get_trace();
            $this->assertSame($message, $trace->spans[0]->meta['http_error']);
            $this->assertSame($url, $trace->spans[0]->meta['url']);
        }

        public function test_http_error_code_and_message_are_bounded_when_recorded(): void
        {
            $collector = Collector::instance();
            $collector->start_request(microtime(true));

            $http = new Http(true);
            $http->register($collector);

            $parsed_args = ['method' => 'GET'];
            $url = 'https://api.example.com/private?token=secret';
            $code = 'http_request_failed_' . str_repeat('code', 100);
            $message = 'Failed for ' . str_repeat('https://api.example.com/private?token=secret ', 40);

            $http->on_pre_request(false, $parsed_args, $url);
            $http->on_http_debug(
                new \WP_Error($code, $message),
                'response',
                'WP_Http_Curl',
                $parsed_args,
                $url
            );

            $trace = $collector->get_trace();
            $this->assertLessThanOrEqual(80, strlen($trace->spans[0]->meta['http_error_code']));
            $this->assertLessThanOrEqual(500, strlen($trace->spans[0]->meta['http_error']));
            $this->assertStringStartsWith('http_request_failed_', $trace->spans[0]->meta['http_error_code']);
            $this->assertStringStartsWith('Failed for https://api.example.com/private', $trace->spans[0]->meta['http_error']);
        }
    }
}
