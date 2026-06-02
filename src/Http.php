<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Http implements Instrumentor
{
    private const MAX_URL_BYTES = 2048;
    private const MAX_HOST_BYTES = 255;
    private const MAX_ERROR_CODE_BYTES = 80;
    private const MAX_ERROR_MESSAGE_BYTES = 500;
    private const MAX_PENDING_SPANS = 200;

    /** @var Collector */
    private $collector;
    private bool $full_url;

    /** @var array<string, string[]> Maps request key (md5 of url+method) to pending span IDs */
    private array $pending_spans = [];
    private int $pending_span_count = 0;

    public function __construct( bool $full_url = false )
    {
        $this->full_url = $full_url;
    }

    public function is_applicable(): bool
    {
        return true;
    }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;

        add_filter( 'pre_http_request', [ $this, 'on_pre_request' ], PHP_INT_MAX, 3 );
        add_filter( 'http_response', [ $this, 'on_response' ], PHP_INT_MAX, 3 );
        add_action( 'http_api_debug', [ $this, 'on_http_debug' ], PHP_INT_MAX, 5 );
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
        if ( false !== $preempt ) {
            return $preempt;
        }

        if ( $this->pending_span_count >= self::MAX_PENDING_SPANS ) {
            return $preempt;
        }

        $url_string = $this->limit_string( $this->string_value( $url, '' ), self::MAX_URL_BYTES );
        $method = $this->normalize_method( $parsed_args );
        $parsed = parse_url($url_string);
        $host = is_array($parsed) ? $this->limit_string( $this->string_value( $parsed['host'] ?? 'unknown', 'unknown' ), self::MAX_HOST_BYTES ) : 'unknown';

        $span_id = $this->collector->start_span(
            'HTTP ' . $host,
            Span::TYPE_HTTP,
            $this->get_caller_source(),
            $this->build_request_meta( $url_string, $method, $host )
        );
        if ( $span_id === '' ) {
            return $preempt;
        }

        // Key by URL + method to handle concurrent tracking
        $key = $this->request_key( $url_string, $method );
        $this->pending_spans[$key][] = $span_id;
        $this->pending_span_count++;

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
        $url_string = $this->limit_string( $this->string_value( $url, '' ), self::MAX_URL_BYTES );
        $method = $this->normalize_method( $parsed_args );
        $key = $this->request_key( $url_string, $method );

        if ( empty( $this->pending_spans[ $key ] ) ) {
            return $response;
        }

        $span_id = array_pop( $this->pending_spans[ $key ] );
        $this->pending_span_count = max( 0, $this->pending_span_count - 1 );
        if ( empty( $this->pending_spans[ $key ] ) ) {
            unset( $this->pending_spans[ $key ] );
        }

        // Add response metadata before closing the span
        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? wp_remote_retrieve_response_code($response)
            : 0;

        $this->collector->add_span_meta($span_id, [
            'status' => $this->normalize_status( $status_code ),
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
        if ( $context !== 'response' ) {
            return;
        }

        $url_string = $this->limit_string( $this->string_value( $url, '' ), self::MAX_URL_BYTES );
        $method = $this->normalize_method( $parsed_args );
        $key = $this->request_key( $url_string, $method );

        if ( empty( $this->pending_spans[ $key ] ) ) {
            return; // Already handled by on_response().
        }

        $span_id = array_pop( $this->pending_spans[ $key ] );
        $this->pending_span_count = max( 0, $this->pending_span_count - 1 );
        if ( empty( $this->pending_spans[ $key ] ) ) {
            unset( $this->pending_spans[ $key ] );
        }

        $parsed = parse_url( $url_string );
        $host   = is_array( $parsed ) ? $this->limit_string( $this->string_value( $parsed['host'] ?? 'unknown', 'unknown' ), self::MAX_HOST_BYTES ) : 'unknown';
        $meta   = $this->build_request_meta( $url_string, $method, $host );

        if ( is_wp_error( $response ) ) {
            $meta['status']          = 0;
            $meta['http_error_code'] = $this->limit_string(
                $this->string_value( $response->get_error_code(), '' ),
                self::MAX_ERROR_CODE_BYTES
            );
            if ( $this->full_url ) {
                $meta['http_error'] = $this->limit_string(
                    $this->string_value( $response->get_error_message(), '' ),
                    self::MAX_ERROR_MESSAGE_BYTES
                );
            }
        } else {
            $meta['status'] = $this->normalize_status( wp_remote_retrieve_response_code( $response ) );
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

    private function build_request_meta( string $url, string $method, string $host ): array
    {
        $meta = [
            'host'   => $host,
            'method' => $method,
        ];

        if ( $this->full_url ) {
            $meta['url'] = $url;
        }

        return $meta;
    }

    /**
     * @param mixed $parsed_args
     */
    private function normalize_method( $parsed_args ): string
    {
        $method = is_array( $parsed_args ) ? ( $parsed_args['method'] ?? 'GET' ) : 'GET';
        $method = $this->string_value( $method, 'GET' );
        $method = strtoupper( trim( $method ) );

        return $method !== '' ? substr( $method, 0, 20 ) : 'GET';
    }

    private function request_key( string $url, string $method ): string
    {
        return md5( $url . "\n" . $method );
    }

    /**
     * @param mixed $status
     */
    private function normalize_status( $status ): int
    {
        if ( is_int( $status ) ) {
            return max( 0, min( 599, $status ) );
        }

        if ( is_float( $status ) && is_finite( $status ) ) {
            return max( 0, min( 599, (int) $status ) );
        }

        if ( is_string( $status ) && is_numeric( trim( $status ) ) ) {
            $number = (float) trim( $status );
            return is_finite( $number ) ? max( 0, min( 599, (int) $number ) ) : 0;
        }

        return 0;
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }

    /**
     * @param mixed $value
     */
    private function string_value( $value, string $fallback ): string
    {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
            try {
                return (string) $value;
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        }

        return $fallback;
    }
}
