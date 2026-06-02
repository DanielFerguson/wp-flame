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
            'per_page' => 50,
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
                [ 'name' => 'URL', 'value' => self::limit_string( self::string_value( $trace['url'] ?? '', '' ), self::MAX_URL_BYTES ) ],
                [ 'name' => 'Method', 'value' => self::limit_string( self::string_value( $trace['method'] ?? '', '' ), self::MAX_METHOD_BYTES ) ],
                [ 'name' => 'Duration (ms)', 'value' => self::limit_string( self::string_value( $trace['total_ms'] ?? '', '' ), self::MAX_DURATION_BYTES ) ],
                [ 'name' => 'Date', 'value' => self::limit_string( self::string_value( $trace['created_at'] ?? '', '' ), self::MAX_DATE_BYTES ) ],
                [ 'name' => 'IP Address', 'value' => self::limit_string( self::string_value( $trace['ip_address'] ?? '', '' ), self::MAX_IP_BYTES ) ],
            ];

            if ( Config::boolean( Config::instance()->get( 'wp_flame_track_user_agent', false ) ) ) {
                $trace_detail = $this->storage->get_trace( $trace_id );
                if ( $trace_detail instanceof Trace ) {
                    $user_agent = self::limit_string(
                        self::string_value( $trace_detail->meta['user_agent'] ?? '', '' ),
                        self::MAX_USER_AGENT_BYTES
                    );
                    if ( $user_agent !== '' ) {
                        $item_data[] = [ 'name' => 'User Agent', 'value' => $user_agent ];
                    }
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
            'done' => count( $traces ) < 50,
        ];
    }

    public function erase_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user || empty( $user->ID ) ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
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

        return [
            'items_removed'  => $deleted,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
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
