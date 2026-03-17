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
            return true;
        }
    }

    if (!function_exists('WPFlame\add_action')) {
        function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
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
        }

        private function make_http(): Http
        {
            $http = new Http();
            $http->register( Collector::instance() );
            return $http;
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
            $this->assertSame('https://stripe.com/v1/charges', $span->meta['url']);
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
            $this->assertSame('https://api.example.com/data', $trace->spans[0]->meta['url']);
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
    }
}
