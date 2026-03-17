<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Http
{
    private Collector $collector;

    /** @var array<string, string> Maps request key (md5 of url+method) → span ID */
    private array $pending_spans = [];

    public function __construct(Collector $collector)
    {
        $this->collector = $collector;

        add_filter('pre_http_request', [$this, 'on_pre_request'], 1, 3);
        add_filter('http_response', [$this, 'on_response'], 9999, 3);
    }

    /**
     * Filter: pre_http_request — runs before each external HTTP request.
     *
     * Must return $preempt unchanged; returning a non-false value would
     * short-circuit the actual HTTP request.
     *
     * @param mixed $preempt
     * @param array $parsed_args
     * @param string $url
     * @return mixed
     */
    public function on_pre_request($preempt, $parsed_args, $url)
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'unknown';

        $span_id = $this->collector->start_span(
            'HTTP ' . $host,
            Span::TYPE_HTTP,
            $this->get_caller_source(),
            [
                'url'    => $url,
                'method' => strtoupper($parsed_args['method'] ?? 'GET'),
            ]
        );

        // Key by URL + method to handle concurrent tracking
        $key = md5($url . ($parsed_args['method'] ?? 'GET'));
        $this->pending_spans[$key] = $span_id;

        return $preempt;
    }

    /**
     * Filter: http_response — runs after each external HTTP request completes.
     *
     * Must return $response unchanged.
     *
     * @param mixed $response
     * @param array $parsed_args
     * @param string $url
     * @return mixed
     */
    public function on_response($response, $parsed_args, $url)
    {
        $key = md5($url . ($parsed_args['method'] ?? 'GET'));

        if (!isset($this->pending_spans[$key])) {
            return $response;
        }

        $span_id = $this->pending_spans[$key];
        unset($this->pending_spans[$key]);

        // Add response metadata before closing the span
        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? wp_remote_retrieve_response_code($response)
            : 0;

        $this->collector->add_span_meta($span_id, [
            'status' => (int) $status_code,
        ]);

        $this->collector->end_span($span_id);

        return $response;
    }

    /**
     * Determine the source of the HTTP request via backtrace.
     *
     * Walks the call stack to find the first file outside wp-includes/,
     * wp-admin/, and the wp-flame plugin directory.
     */
    private function get_caller_source(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $wp_flame_dir = dirname(__DIR__);

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $file = $frame['file'];

            // Skip wp-includes, wp-admin, and our own plugin
            if (defined('ABSPATH')) {
                if (strpos($file, ABSPATH . 'wp-includes/') === 0) {
                    continue;
                }
                if (strpos($file, ABSPATH . 'wp-admin/') === 0) {
                    continue;
                }
            }
            if (strpos($file, $wp_flame_dir) === 0) {
                continue;
            }

            $source = $this->collector->get_source_from_file($file);
            return $source['source'];
        }

        return 'wordpress';
    }
}
