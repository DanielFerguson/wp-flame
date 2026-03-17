<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Http implements Instrumentor
{
    /** @var Collector */
    private $collector;

    /** @var array<string, string> Maps request key (md5 of url+method) → span ID */
    private array $pending_spans = [];

    public function is_applicable(): bool
    {
        return true;
    }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;

        add_filter( 'pre_http_request', [ $this, 'on_pre_request' ], 1, 3 );
        add_filter( 'http_response', [ $this, 'on_response' ], 9999, 3 );
        add_action( 'http_api_debug', [ $this, 'on_http_debug' ], 9999, 5 );
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
     * Fallback cleanup for HTTP requests where http_response did not fire
     * (WP_Error responses — timeouts, DNS failures, etc.).
     *
     * @param mixed  $response    Response or WP_Error.
     * @param string $context     'response' for WP_Http requests.
     * @param string $class       HTTP transport class name.
     * @param array  $parsed_args Request arguments.
     * @param string $url         Request URL.
     * @return void
     */
    public function on_http_debug( $response, $context, $class, $parsed_args, $url ): void
    {
        $key = md5( $url . ( $parsed_args['method'] ?? 'GET' ) );

        if ( ! isset( $this->pending_spans[ $key ] ) ) {
            return; // Already handled by on_response().
        }

        $span_id = $this->pending_spans[ $key ];
        unset( $this->pending_spans[ $key ] );

        $meta = [
            'url'    => $url,
            'method' => $parsed_args['method'] ?? 'GET',
        ];

        if ( is_wp_error( $response ) ) {
            $meta['status']     = 0;
            $meta['http_error'] = $response->get_error_message();
        } else {
            $meta['status'] = (int) wp_remote_retrieve_response_code( $response );
        }

        $this->collector->add_span_meta( $span_id, $meta );
        $this->collector->end_span( $span_id );
    }

    /**
     * Determine the source of the HTTP request via backtrace.
     */
    private function get_caller_source(): string
    {
        return SourceResolver::from_backtrace( $this->collector, 1 );
    }
}
