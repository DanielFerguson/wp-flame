<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GraphQL
{
    private const ROOT_TYPES = ['rootquery', 'rootmutation', 'rootsubscription'];

    private Collector $collector;
    private bool $full_query_text;

    /** @var array<string, string[]> Stack of span IDs per field key for alias handling */
    private array $resolver_span_stacks = [];

    private ?string $operation_span_id = null;

    /** @var callable|null Stored for remove_filter() in deactivate() */
    private $db_hook_callback = null;

    public function __construct(Collector $collector)
    {
        $this->collector = $collector;
        $this->full_query_text = (bool) get_option('wp_flame_full_query_text', false);
        $this->register_db_hooks();
    }

    /**
     * Check if a request path matches a GraphQL endpoint.
     * Extracted as static method for testability from wp-flame.php.
     * Expects $request_path to already have trailing slash stripped.
     */
    public static function is_graphql_endpoint(string $request_path, string $endpoint = 'graphql'): bool
    {
        if ($request_path === '') {
            return false;
        }
        return $request_path === '/' . $endpoint
            || substr($request_path, -strlen('/' . $endpoint)) === '/' . $endpoint;
    }

    /**
     * Activate WPGraphQL-specific resolver and operation hooks (Tier 1 only).
     */
    public function activate_wpgraphql_hooks(): void
    {
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
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            $source = $this->get_caller_source($backtrace);
            $query_text = $this->full_query_text ? $query : substr($query, 0, 200);

            $this->collector->add_completed_span(
                $this->extract_query_type($query),
                Span::TYPE_DB,
                $source,
                (float) $query_start,
                (float) $query_time,
                ['query' => $query_text]
            );

            return $query_data;
        };
        add_filter('log_query_custom_data', $this->db_hook_callback, 10, 5);
    }

    private function register_operation_hooks(): void
    {
        add_action('graphql_process_request', function ($wp_graphql) {
            try {
                $query = $wp_graphql->get_query();
                $operation_name = $wp_graphql->get_operation_name() ?: 'anonymous';
                $this->operation_span_id = $this->collector->start_span(
                    "GraphQL: {$operation_name}",
                    Span::TYPE_CORE,
                    'wpgraphql'
                );
                $this->collector->add_span_meta($this->operation_span_id, [
                    'graphql_operation' => $operation_name,
                    'graphql_query' => substr($query, 0, 500),
                ]);
            } catch (\Throwable $e) {
                // Don't break GraphQL processing
            }
        }, 10, 1);

        add_filter('graphql_return_response', function ($response) {
            try {
                if ($this->operation_span_id !== null) {
                    $this->collector->end_span($this->operation_span_id);
                    $this->operation_span_id = null;
                }
            } catch (\Throwable $e) {
                // Don't break GraphQL response
            }
            return $response;
        }, 10, 1);
    }

    private function register_resolver_hooks(): void
    {
        add_filter('graphql_pre_resolve_field', function ($default, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
            try {
                if (! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
                    return $default;
                }

                $span_id = $this->collector->start_span(
                    "{$type_name}.{$field_key}",
                    Span::TYPE_PLUGIN,
                    'wpgraphql',
                    [
                        'type_name' => $type_name,
                        'field_key' => $field_key,
                        'hook'      => "graphql:{$type_name}.{$field_key}",
                    ]
                );

                $key = strtolower($type_name) . '.' . $field_key;
                $this->resolver_span_stacks[$key][] = $span_id;
            } catch (\Throwable $e) {
                // Don't break field resolution
            }
            return $default;
        }, 10, 9);

        add_filter('graphql_resolve_field', function ($result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver) {
            try {
                if (! in_array(strtolower($type_name), self::ROOT_TYPES, true)) {
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
        $first_word = strtoupper(strtok($query, " \t\n\r"));
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
}
