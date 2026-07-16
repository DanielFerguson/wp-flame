<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Privacy
{
    private const MAX_TRACE_ID_BYTES = 128;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_DURATION_BYTES = 64;
    private const MAX_DATE_BYTES = 64;
    private const MAX_IP_BYTES = 45;
    private const MAX_USER_AGENT_BYTES = 500;
    private const EXPORT_PAGE_SIZE = 10;

    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
        add_action( 'admin_init', [ $this, 'add_policy_content' ] );
    }

    public function add_policy_content(): void
    {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        wp_add_privacy_policy_content( 'WP Flame', wp_kses_post( self::suggested_policy_text() ) );
    }

    public static function suggested_policy_text(): string
    {
        return '<p class="privacy-policy-tutorial">WP Flame records local performance traces when an authorized site operator starts a manual capture or enables an optional sampling policy. A trace can include a redacted request path, request method and time, duration, memory, WordPress lifecycle spans, database query fingerprints, external request host names, callback/source attribution, environment versions, capability status, and diagnostic recommendations.</p>'
            . '<p>By default, WP Flame does not record user IDs, IP addresses, user-agent strings, full SQL text, full external URLs, or full GraphQL query text. These categories require an explicit sensitive-data acknowledgement and separate opt-in settings. SQL comments are removed before storage. Request query values and identifier-like path segments are redacted, while a separate normalized route key is used for performance grouping.</p>'
            . '<p>Trace data remains in this WordPress database and is not sent to WP Flame or another external service. Traces become eligible for bounded automatic deletion after the configured retention period and are also subject to configurable row and byte ceilings. Administrators can delete individual traces or purge all trace data.</p>'
            . '<p>When user-ID capture is enabled, WordPress privacy export and erasure requests include or delete traces linked to that user in bounded batches. Traces recorded without a user ID cannot be reliably associated with an email address; use retention controls or the administrator purge control when anonymous trace deletion is required.</p>';
    }

    public function register_exporter( array $exporters ): array
    {
        $exporters['wp-flame'] = $this->get_exporter();
        return $exporters;
    }

    public function register_eraser( array $erasers ): array
    {
        $erasers['wp-flame'] = $this->get_eraser();
        return $erasers;
    }

    public function get_exporter(): array
    {
        return [
            'exporter_friendly_name' => 'WP Flame Performance Data',
            'callback'               => [ $this, 'export_user_data' ],
        ];
    }

    public function get_eraser(): array
    {
        return [
            'eraser_friendly_name' => 'WP Flame Performance Data',
            'callback'             => [ $this, 'erase_user_data' ],
        ];
    }

    public function export_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user || empty( $user->ID ) ) {
            return [ 'data' => [], 'done' => true ];
        }

        $user_id = Config::bounded_int( $user->ID, 0, 0, PHP_INT_MAX );
        if ( $user_id <= 0 ) {
            return [ 'data' => [], 'done' => true ];
        }

        $page = max( 1, $page );
        $traces = $this->storage->list_traces( [
            'user_id'  => $user_id,
            'per_page' => self::EXPORT_PAGE_SIZE,
            'page'     => $page,
        ] );

        $export_items = [];
        foreach ( $traces as $trace ) {
            if ( ! is_array( $trace ) ) {
                continue;
            }

            $trace_id = self::limit_string( self::string_value( $trace['trace_id'] ?? '', '' ), self::MAX_TRACE_ID_BYTES );
            if ( $trace_id === '' ) {
                continue;
            }

            $item_data = [
                [
                    'name'  => 'User ID',
                    'value' => (string) $user_id,
                ],
                [ 'name' => 'URL', 'value' => self::limit_string( self::string_value( $trace['url'] ?? '', '' ), self::MAX_URL_BYTES ) ],
                [ 'name' => 'Method', 'value' => self::limit_string( self::string_value( $trace['method'] ?? '', '' ), self::MAX_METHOD_BYTES ) ],
                [ 'name' => 'Duration (ms)', 'value' => self::limit_string( self::string_value( $trace['total_ms'] ?? '', '' ), self::MAX_DURATION_BYTES ) ],
                [ 'name' => 'Date', 'value' => self::limit_string( self::string_value( $trace['created_at'] ?? '', '' ), self::MAX_DATE_BYTES ) ],
                [ 'name' => 'IP Address', 'value' => self::limit_string( self::string_value( $trace['ip_address'] ?? '', '' ), self::MAX_IP_BYTES ) ],
                [
                    'name'  => 'Request Type',
                    'value' => self::limit_string( self::string_value( $trace['request_type'] ?? '', '' ), 40 ),
                ],
                [
                    'name'  => 'Route Key',
                    'value' => self::limit_string( self::string_value( $trace['route_key'] ?? '', '' ), 512 ),
                ],
            ];

            $trace_detail = $this->storage->get_trace( $trace_id );
            if ( $trace_detail instanceof Trace ) {
                $user_agent = self::limit_string(
                    self::string_value( $trace_detail->meta['user_agent'] ?? '', '' ),
                    self::MAX_USER_AGENT_BYTES
                );
                if ( $user_agent !== '' ) {
                    $item_data[] = [ 'name' => 'User Agent', 'value' => $user_agent ];
                }

                $trace_json = wp_json_encode( $trace_detail->toArray(), JSON_INVALID_UTF8_SUBSTITUTE );
                if ( is_string( $trace_json ) ) {
                    $item_data[] = [
                        'name'  => 'Complete trace data (JSON)',
                        'value' => self::limit_string( $trace_json, Config::MAX_MAX_TRACE_BYTES ),
                    ];
                }
            }

            $export_items[] = [
                'group_id'          => 'wp-flame-traces',
                'group_label'       => 'WP Flame Performance Traces',
                'group_description' => 'Performance trace data collected by WP Flame.',
                'item_id'           => 'trace-' . $trace_id,
                'data'              => $item_data,
            ];
        }

        return [
            'data' => $export_items,
            'done' => count( $traces ) < self::EXPORT_PAGE_SIZE,
        ];
    }

    public function erase_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user || empty( $user->ID ) ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [ 'WP Flame cannot associate anonymous traces with an email address. Use retention or the administrator purge control if those traces must be removed.' ],
                'done'           => true,
            ];
        }

        $user_id = Config::bounded_int( $user->ID, 0, 0, PHP_INT_MAX );
        if ( $user_id <= 0 ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $deleted = Config::bounded_int( $this->storage->delete_traces_by_user( $user_id ), 0, 0, PHP_INT_MAX );

        $done = $deleted < Storage::PRIVACY_DELETE_BATCH_LIMIT;
        return [
            'items_removed'  => $deleted,
            'items_retained' => 0,
            'messages'       => $done
                ? [ 'Any WP Flame traces stored without a user ID cannot be associated with this email address; retention and administrator purge controls apply to anonymous traces.' ]
                : [],
            'done'           => $done,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function string_value( $value, string $fallback ): string
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

    private static function limit_string( string $value, int $max_bytes ): string
    {
        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        return substr( $value, 0, $max_bytes );
    }
}
