<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GraphQL implements Instrumentor
{
    private const ROOT_TYPES = ['rootquery', 'rootmutation', 'rootsubscription'];
    private const MAX_OPERATION_NAME_BYTES = 128;
    private const MAX_RESOLVER_NAME_BYTES = 128;
    private const MAX_GRAPHQL_QUERY_BYTES = 65536;
    private const MAX_ENDPOINT_BYTES = 160;
    private const MAX_REQUEST_PATH_BYTES = 2048;

    /** @var Collector */
    private $collector;
    /** @var bool */
    private $full_query_text;
    private bool $full_graphql_query;
    private bool $allow_db = true;
    private bool $current_operation_hook_seen = false;

    /** @var array<string, string[]> Stack of span IDs per field key for alias handling */
    private array $resolver_span_stacks = [];

    /** @var string[] */
    private array $operation_span_stack = [];

    /** @var callable|null Stored for remove_filter() in deactivate() */
    private $db_hook_callback = null;
    private bool $wpgraphql_hooks_registered = false;

    public function __construct( bool $full_query_text = false, bool $full_graphql_query = false, bool $allow_db = true )
    {
        $this->full_query_text    = $full_query_text;
        $this->full_graphql_query = $full_graphql_query;
        $this->allow_db           = $allow_db;
    }

    public function is_applicable(): bool
    {
        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? Config::string_value( wp_unslash( $_SERVER['REQUEST_URI'] ), '' )
            : '';
        $uri = isset( $_SERVER['REQUEST_URI'] )
            ? rtrim( $this->limit_string( Config::string_value( parse_url( $request_uri, PHP_URL_PATH ), '' ), self::MAX_REQUEST_PATH_BYTES ), '/' )
            : '';
        return self::is_graphql_endpoint(
            $uri,
            self::normalize_endpoint( apply_filters( 'graphql_endpoint', 'graphql' ) )
        );
    }

    public function register( Collector $collector ): void
    {
        $this->collector = $collector;
        if ( $this->allow_db ) {
            $this->register_db_hooks();
        }
        // Applicability is already constrained to the configured GraphQL
        // endpoint. Register operation/resolver hooks immediately so current
        // WPGraphQL versions cannot fire them before an init-time constant check.
        $this->activate_wpgraphql_hooks();
    }

    public function requires_savequeries(): bool
    {
        return $this->allow_db;
    }

    /**
     * Check if a request path matches a GraphQL endpoint.
     * Extracted as static method for testability from wp-flame.php.
     * Expects $request_path to already have trailing slash stripped.
     */
    public static function is_graphql_endpoint(string $request_path, string $endpoint = 'graphql'): bool
    {
        $endpoint = trim( $endpoint, '/' );
        if ($request_path === '') {
            return false;
        }
        if ( $endpoint === '' ) {
            return false;
        }

        return $request_path === '/' . $endpoint
            || substr($request_path, -strlen('/' . $endpoint)) === '/' . $endpoint;
    }

    /**
     * @param mixed $endpoint
     */
    private static function normalize_endpoint( $endpoint ): string
    {
        if ( is_string( $endpoint ) || is_int( $endpoint ) || is_float( $endpoint ) ) {
            return self::limit_static_string( (string) $endpoint, self::MAX_ENDPOINT_BYTES );
        }

        if ( is_object( $endpoint ) && method_exists( $endpoint, '__toString' ) ) {
            try {
                return self::limit_static_string( (string) $endpoint, self::MAX_ENDPOINT_BYTES );
            } catch ( \Throwable $e ) {
                return 'graphql';
            }
        }

        return 'graphql';
    }

    private static function limit_static_string( string $value, int $max_bytes ): string
    {
        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }

    /**
     * Activate WPGraphQL-specific resolver and operation hooks (Tier 1 only).
     */
    public function activate_wpgraphql_hooks(): void
    {
        if ( $this->wpgraphql_hooks_registered ) {
            return;
        }

        $this->wpgraphql_hooks_registered = true;
        $this->register_operation_hooks();
        $this->register_resolver_hooks();
    }

    /**
     * Remove DB hooks registered by the constructor.
     * Note: does NOT remove WPGraphQL hooks — only called before activate_wpgraphql_hooks().
     */
    public function deactivate(): void
    {
        if ($this->db_hook_callback !== null) {
            remove_filter('log_query_custom_data', $this->db_hook_callback, 10);
            $this->db_hook_callback = null;
        }
    }

    private function register_db_hooks(): void
    {
        $this->db_hook_callback = function ($query_data, $query, $query_time, $query_callstack, $query_start) {
            if ( $this->collector->is_stopped() ) {
                return $query_data;
            }
            $query_string = is_scalar( $query ) || ( is_object( $query ) && method_exists( $query, '__toString' ) )
                ? (string) $query
                : '';
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            $source = $this->get_caller_source($backtrace);
            $query_text = Redactor::sql_label( $query_string, $this->full_query_text );

            $this->collector->add_completed_span(
                $this->extract_query_type($query_string),
                Span::TYPE_DB,
                $source,
                $this->number_value( $query_start, 0.0 ),
                max( 0.0, $this->number_value( $query_time, 0.0 ) ),
                [
                    'query'          => $query_text,
                    'query_hash'     => md5( Redactor::normalize_sql( $query_string ) ),
                    'query_redacted' => ! $this->full_query_text,
                ] + ( $this->full_query_text && strlen( $query_string ) > Redactor::MAX_SQL_LABEL_BYTES
                    ? [ 'query_truncated' => true ]
                    : [] )
            );

            return $query_data;
        };
        add_filter('log_query_custom_data', $this->db_hook_callback, 10, 5);
    }

    private function register_operation_hooks(): void
    {
        // Current WPGraphQL operation hook (one call per parsed operation).
        add_action('do_graphql_request', function ( $query, $operation ) {
            $this->current_operation_hook_seen = true;
            $this->start_operation( $query, $operation );
        }, 10, 2);

        // Compatibility hook for older WPGraphQL releases. Avoid a duplicate
        // span if both generations of hooks fire for one operation.
        add_action('graphql_process_request', function ($wp_graphql) {
            try {
                if ( $this->collector->is_stopped() ) {
                    return;
                }
                if ( $this->current_operation_hook_seen && ! empty( $this->operation_span_stack ) ) {
                    return;
                }
                $query = is_object( $wp_graphql ) && method_exists( $wp_graphql, 'get_query' ) ? $wp_graphql->get_query() : '';
                $operation = is_object( $wp_graphql ) && method_exists( $wp_graphql, 'get_operation_name' ) ? $wp_graphql->get_operation_name() : '';
                $this->start_operation( $query, $operation );
            } catch (\Throwable $e) {
                // Don't break GraphQL processing
            }
        }, 10, 1);

        add_filter('graphql_return_response', function ($response) {
            try {
                if ( ! empty( $this->operation_span_stack ) ) {
                    $this->collector->end_span( array_pop( $this->operation_span_stack ) );
                }
            } catch (\Throwable $e) {
                // Don't break GraphQL response
            }
            return $response;
        }, 10, 1);
    }

    /**
     * @param mixed $query
     * @param mixed $operation
     */
    private function start_operation( $query, $operation ): void
    {
        if ( $this->collector->is_stopped() ) {
            return;
        }

        $query = Config::string_value( $query, '' );
        $operation_name = $this->limit_string( Config::string_value( $operation, 'anonymous' ), self::MAX_OPERATION_NAME_BYTES );
        $operation_name = $operation_name !== '' ? $operation_name : 'anonymous';
        $GLOBALS['wp_flame_graphql_operation_name'] = $operation_name;
        $operation_span_id = $this->collector->start_span( "GraphQL: {$operation_name}", Span::TYPE_CORE, 'wpgraphql' );
        if ( $operation_span_id !== '' ) {
            $this->operation_span_stack[] = $operation_span_id;
        }
        $meta = [
            'graphql_operation'    => $operation_name,
            'graphql_query_length' => strlen( $query ),
        ];
        if ( $this->full_graphql_query ) {
            $meta['graphql_query'] = $this->limit_string( $query, self::MAX_GRAPHQL_QUERY_BYTES );
            if ( strlen( $query ) > self::MAX_GRAPHQL_QUERY_BYTES ) {
                $meta['graphql_query_truncated'] = true;
            }
        }
        $this->collector->add_span_meta( $operation_span_id, $meta );
    }

    private function register_resolver_hooks(): void
    {
        add_filter('graphql_pre_resolve_field', function ($default, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
            try {
                if ( $this->collector->is_stopped() ) {
                    return $default;
                }
                $type_name = $this->limit_string( Config::string_value( $type_name, '' ), self::MAX_RESOLVER_NAME_BYTES );
                $field_key = $this->limit_string( Config::string_value( $field_key, '' ), self::MAX_RESOLVER_NAME_BYTES );
                if ( $field_key === '' || ! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
                    return $default;
                }

                $key = strtolower($type_name) . '.' . $field_key;
                $resolver_source = [
                    'type'   => Span::TYPE_PLUGIN,
                    'source' => 'wpgraphql',
                ];
                $resolver_name = '';
                if ( is_callable( $field_resolver ) ) {
                    $resolver_source = CallbackResolver::resolve_source( 'graphql:' . $key, $field_resolver, $this->collector );
                    $resolver_name   = CallbackResolver::resolve_name( 'graphql:' . $key, $field_resolver );
                }

                $span_id = $this->collector->start_span(
                    "{$type_name}.{$field_key}",
                    Config::string_value( $resolver_source['type'] ?? Span::TYPE_PLUGIN, Span::TYPE_PLUGIN ),
                    Config::string_value( $resolver_source['source'] ?? 'wpgraphql', 'wpgraphql' ),
                    [
                        'type_name' => $type_name,
                        'field_key' => $field_key,
                        'hook'      => "graphql:{$type_name}.{$field_key}",
                    ] + ( $resolver_name !== '' ? [ 'callback' => $this->limit_string( $resolver_name, self::MAX_RESOLVER_NAME_BYTES ) ] : [] )
                );

                $this->resolver_span_stacks[$key][] = $span_id;
            } catch (\Throwable $e) {
                // Don't break field resolution
            }
            return $default;
        }, 10, 9);

        add_filter('graphql_resolve_field', function ($result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
            try {
                if ( $this->collector->is_stopped() ) {
                    return $result;
                }
                $type_name = $this->limit_string( Config::string_value( $type_name, '' ), self::MAX_RESOLVER_NAME_BYTES );
                $field_key = $this->limit_string( Config::string_value( $field_key, '' ), self::MAX_RESOLVER_NAME_BYTES );
                if ( $field_key === '' || ! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
                    return $result;
                }

                $key = strtolower($type_name) . '.' . $field_key;
                if (! empty($this->resolver_span_stacks[$key])) {
                    $span_id = array_pop($this->resolver_span_stacks[$key]);
                    $this->collector->end_span($span_id);
                }
            } catch (\Throwable $e) {
                // Don't break field resolution
            }
            return $result;
        }, 10, 9);
    }

    /**
     * Extract SQL verb (SELECT, INSERT, etc.) for span name.
     */
    private function extract_query_type(string $query): string
    {
        $query = ltrim($query);
        $first = strtok($query, " \t\n\r");
        if ( ! is_string( $first ) || $first === '' ) {
            return 'QUERY';
        }

        $first_word = strtoupper($first);
        $known_types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'SHOW', 'SET'];

        return in_array($first_word, $known_types, true) ? $first_word : 'QUERY';
    }

    /**
     * Determine query source via backtrace.
     */
    private function get_caller_source( array $backtrace ): string
    {
        return SourceResolver::from_trace_array( $this->collector, $backtrace );
    }

    /**
     * @param mixed $value
     */
    private function number_value( $value, float $fallback ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            $number = (float) $value;
            return is_finite( $number ) ? $number : $fallback;
        }

        if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
            $number = (float) trim( $value );
            return is_finite( $number ) ? $number : $fallback;
        }

        return $fallback;
    }

    private function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
